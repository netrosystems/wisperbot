<?php

namespace App\Modules\AI\Services\Agent;

use App\Modules\AI\Exceptions\AiCreditsException;
use App\Modules\AI\Models\AiChatbot;
use App\Modules\AI\Models\AiKbChunk;
use App\Modules\AI\Models\AiKnowledgeBase;
use App\Modules\AI\Services\ChatbotRunner;
use App\Modules\AI\Services\ChatReplyOptions;
use App\Modules\AI\Services\KnowledgeRetrievalService;
use App\Modules\AI\Services\Llm\LlmResponse;
use App\Modules\AI\Services\Llm\LlmTurn;

/**
 * Smart Bot 2.0 answer engine (Phase 1.1), used by ChatbotRunner for bots on
 * engine v2 (AiChatbot::usesEngineV2()); after Cerqle's AnswersWithEngineV2.
 *
 * Part of ChatbotRunner so v2 reuses what v1 already does well (the free
 * gates before it, retrieval, video answers, order details, fallbacks and
 * diagnostics) and changes how an answer is planned, asked for and checked:
 *  - greetings and thanks are always answered for free;
 *  - a follow-up is rewritten into a standalone question before searching;
 *  - the bot's answer mode (Strict / Balanced / Flexible) and reply length
 *    shape the prompt;
 *  - the reply says what kind of answer it is and which passages it used;
 *  - figures are enforced, a full answer must quote what answers it, and a
 *    small support check rejects a reply the knowledge does not state;
 *    a rejected reply is written again once;
 *  - a reply that answers only part of a question, or offers a person, gets
 *    a second look with rewritten search queries;
 *  - one charge per answer (LlmTurn); a question back or an offer of a
 *    person is sent but not charged; a clarifying question is never asked
 *    twice in a row.
 *
 * @mixin ChatbotRunner
 */
trait AnswersWithEngineV2
{
    /** @var array<string,mixed> What this turn did, for its diagnostics row. */
    private array $v2Trace = [];

    /**
     * @param  array<int,array<string,mixed>>  $history
     * @return array<string,mixed>
     */
    private function answerV2(AiChatbot $bot, ?AiKnowledgeBase $kb, int $workspaceId, ?int $revisionId, string $message, array $history, string $idempotencyKey, ?int $conversationId, mixed $contact, bool $throwProviderErrors): array
    {
        $this->v2Trace = [];
        $message = trim($message);
        $mode = $this->engineV2Mode($bot, $kb);
        $this->v2Trace['mode'] = $mode;

        // Small talk is free in every language, whatever the routing switch says.
        if (! $this->businessAwareEnabled() && ($smallTalk = $this->turnRouter->conversationalResult($message, $kb, $bot->tone))) {
            $this->recordV2($bot, $workspaceId, $revisionId, 'answer', [], 'conversation', ['answer_origin' => 'conversation', 'intent' => $smallTalk['intent'], 'credit_result' => 'zero_cost']);

            return $smallTalk;
        }

        $orderSummary = $this->orderSummary($workspaceId, $contact->id ?? null);
        if ($orderSummary === null && $this->isAccountSpecific($message)) {
            $this->recordV2($bot, $workspaceId, $revisionId, 'handoff', [], 'account_specific', ['answer_origin' => 'fallback', 'credit_result' => 'not_charged']);

            return $this->withAnswerMetadata([
                'reply' => 'I can help with general information, but a team member needs to check your account or order details. Would you like me to connect you?',
                'tokens_used' => 0,
                'resources' => [],
            ], 'fallback', [], 'fallback');
        }

        $results = $this->v2Search($kb, $workspaceId, $message, $revisionId, $history, $throwProviderErrors);
        $searched = $results;
        // A small knowledge base is read whole: the answer never depends on the
        // search finding the right passage.
        $whole = $this->wholeKnowledge($kb, $workspaceId, $revisionId, $results);
        if ($whole !== null) {
            $results = $whole['results'];
        }
        // Strict answers only from the knowledge: with nothing close enough,
        // there is nothing to send the model, and the fallback costs nothing.
        if ($mode === 'strict' && $results === []) {
            $this->recordV2($bot, $workspaceId, $revisionId, 'handoff', $results, 'no_context', ['answer_origin' => 'fallback', 'credit_result' => 'not_charged']);

            return $this->unsupportedResult($bot);
        }

        $turn = null;
        try {
            $turn = $this->llmGateway->beginTurn($workspaceId, 'chatbot_reply', $idempotencyKey, $bot->id, $conversationId);
            if ($turn->replay !== null) {
                $this->recordV2($bot, $workspaceId, $revisionId, 'answer', [], 'replayed', ['answer_origin' => $turn->replay['answer_origin'] ?? null, 'credit_result' => 'charged_once']);

                return $turn->replay;
            }

            $plain = $this->v2History($history);
            $planner = app(QueryPlanner::class);
            // After a clarifying question, the reply only makes sense with the
            // question it answers: always fold the two together.
            // With the whole knowledge in the prompt there is nothing left to search for.
            if ($kb && $whole === null && ($planner->needsPlanning($message, $plain) || ($this->lastReplyAskedQuestion($history) && $plain !== []))) {
                $plan = $planner->plan($turn, $message, $plain);
                $this->v2Trace['planner'] = ['planned' => $plan['planned'], 'queries' => $plan['queries']];
                $results = $this->v2SearchMany($kb, $workspaceId, $plan['queries'], $results, $revisionId, $history);
            }

            return $this->generateV2($turn, $bot, $kb, $mode, $message, $history, $plain, $results, $workspaceId, $revisionId, $contact, $orderSummary, $searched, $whole['closest'] ?? null);
        } catch (\Throwable $error) {
            $turn?->abort($error instanceof AiCreditsException ? $error->errorCode : 'provider_failed');
            $this->recordV2($bot, $workspaceId, $revisionId, 'fallback', $results, $error instanceof AiCreditsException ? 'credits_unavailable' : 'provider_error', [
                'answer_origin' => 'fallback',
                'credit_result' => $turn ? 'refunded' : 'not_charged',
            ], $turn);
            if ($throwProviderErrors) {
                throw $error;
            }

            return $this->withAnswerMetadata(['reply' => $bot->fallback_reply ?: null, 'tokens_used' => 0, 'resources' => []], 'fallback', [], 'fallback');
        }
    }

    /**
     * @param  array<int,array<string,mixed>>  $history
     * @param  array<int,array{role:string,content:string}>  $plain
     * @param  array<int,array{chunk:AiKbChunk,score:float}>  $results
     * @param  array<int,array{chunk:AiKbChunk,score:float}>  $searched  What the search found (the same as $results unless the whole knowledge is read)
     * @param  list<int>|null  $closest  With the whole knowledge: the numbers of the passages the search placed closest
     * @return array<string,mixed>
     */
    private function generateV2(LlmTurn $turn, AiChatbot $bot, ?AiKnowledgeBase $kb, string $mode, string $message, array $history, array $plain, array $results, int $workspaceId, ?int $revisionId, mixed $contact, ?string $orderSummary, array $searched = [], ?array $closest = null): array
    {
        $length = $bot->replyLength();
        $channel = $this->turnChannel === 'api' ? null : $this->turnChannel;
        $words = $length['words'] * ($channel === 'email' ? 2 : 1);
        $options = app(ReplyContractV2::class)->requestOptions($length['max_tokens'], $bot->kb_exact_wording ? 0.2 : 0.4);
        $context = ['bot' => $bot, 'kb' => $kb, 'mode' => $mode, 'message' => $message, 'plain' => $plain, 'channel' => $channel, 'words' => $words,
            'options' => $options, 'workspace_id' => $workspaceId, 'contact' => $contact, 'order_summary' => $orderSummary,
            'searched' => $searched, 'closest' => $closest];

        $attempt = $this->attemptV2($turn, $context, $results);
        if ($attempt['parsed'] === null) {
            $reason = $attempt['empty'] ? 'empty_reply' : 'rejected_reply';
            $turn->abort($reason);
            if ($attempt['reason'] !== null) {
                $this->v2Trace['rejected_because'] = mb_substr($attempt['reason'], 0, 300);
            }
            $this->recordV2($bot, $workspaceId, $revisionId, 'fallback', $results, $reason, ['answer_origin' => 'fallback', 'credit_result' => 'refunded'], $turn);

            return $this->unsupportedResult($bot);
        }

        if ($this->worthASecondLook($kb, $attempt['parsed'], $results)) {
            [$attempt, $results] = $this->secondLook($turn, $context, $results, $attempt, $history);
        }
        $parsed = $attempt['parsed'];
        if ($attempt['shadow'] !== []) {
            $this->v2Trace['validator_shadow'] = array_slice($attempt['shadow'], 0, 10);
        }
        $this->v2Trace['answer_kind'] = $parsed['answer_kind'];
        $this->v2Trace['used_sources'] = $parsed['used_sources'];
        $usedChunks = array_values(array_filter(array_map(fn (int $number) => $results[$number - 1] ?? null, $parsed['used_sources'])));
        $this->v2Trace['used_chunk_ids'] = array_map(fn (array $result) => (int) $result['chunk']->id, $usedChunks);

        // Never two clarifying questions in a row: the second time, the fallback.
        if ($parsed['answer_kind'] === 'clarification' && $this->lastReplyAskedQuestion($history)) {
            $turn->abort('clarified_twice');
            $this->recordV2($bot, $workspaceId, $revisionId, 'fallback', $results, 'clarified_twice', ['answer_origin' => 'fallback', 'credit_result' => 'refunded'], $turn);

            return $this->unsupportedResult($bot);
        }

        $reply = $this->capWords($parsed['reply'], $words);
        $choices = config('chatbot.quick_replies_enabled') ? $parsed['quick_replies'] : [];
        $shaped = app(ChatReplyOptions::class)->parse((string) json_encode(['reply' => $reply, 'quick_replies' => $choices], JSON_UNESCAPED_UNICODE))
            ?? ['reply' => $reply, 'display_body' => $reply, 'quick_replies' => []];

        // Offering a person is not an answer: it is sent, but not charged.
        if ($parsed['answer_kind'] === 'handoff') {
            $turn->abort('model_handoff');
            $this->recordV2($bot, $workspaceId, $revisionId, 'handoff', $results, 'model_handoff', ['answer_origin' => 'fallback', 'credit_result' => 'refunded'], $turn);

            return $this->withAnswerMetadata($shaped + ['tokens_used' => $turn->tokensUsed(), 'resources' => []], 'fallback', [], 'fallback');
        }

        $used = array_values(array_filter(array_map(fn (int $number) => $results[$number - 1] ?? null, $parsed['used_sources'])));
        $origin = in_array($parsed['answer_kind'], ['answer', 'partial'], true) && $used !== [] ? 'knowledge_base' : 'business_guidance';
        $responseMode = $parsed['answer_kind'] === 'clarification' ? 'clarification' : 'answer';
        $result = $this->withAnswerMetadata($shaped + ['tokens_used' => $turn->tokensUsed(), 'resources' => []], $origin, [], $responseMode);
        $video = $attempt['video'];
        $videoLeads = $video['chunk_id'] !== null && $video['chunk_id'] === (int) (($used[0] ?? $searched[0] ?? $results[0] ?? null)['chunk']->id ?? 0)
            && $video['score'] >= $this->retrievalPolicy->privateAnswering()['video_match_threshold'];
        $result['resources'] = $this->resourcesForReply($video['resources'], $attempt['raw'], $result, $responseMode, $videoLeads);
        $result = $this->withoutVideoLinks($result);

        // A question back is a step toward an answer, not one: sent, not charged.
        if ($parsed['answer_kind'] === 'clarification' && app(AnswerValidator::class)->isBareQuestion($parsed)) {
            $turn->abort('model_clarification');
            $this->recordV2($bot, $workspaceId, $revisionId, 'answer', $results, 'model_clarification', ['answer_origin' => $origin, 'response_mode' => 'clarification', 'credit_result' => 'refunded'], $turn);

            return $result;
        }

        $turn->finish($result);
        $this->recordV2($bot, $workspaceId, $revisionId, 'answer', $results, isset($this->v2Trace['regenerated_because']) ? 'answered_regenerated' : 'answered', [
            'answer_origin' => $origin,
            'response_mode' => $responseMode,
            'credit_result' => 'charged_once',
        ], $turn);

        return $result;
    }

    /**
     * One answer for these passages: asked for, checked, and asked again once
     * if a check rejects it.
     *
     * @param  array<string,mixed>  $context
     * @param  array<int,array{chunk:AiKbChunk,score:float}>  $results
     * @return array{empty:bool,parsed:array<string,mixed>|null,shadow:list<string>,reason:?string,raw:string,video:array<string,mixed>}
     */
    private function attemptV2(LlmTurn $turn, array $context, array $results): array
    {
        /** @var AiChatbot $bot */
        $bot = $context['bot'];
        // Videos are offered from what the search found, never from the whole knowledge.
        $videoCandidates = $context['closest'] !== null ? $context['searched'] : $results;
        $video = $this->selectVideoResource(
            array_map(fn (array $result) => ['chunk' => $result['chunk'], 'rank_score' => $result['score']], $videoCandidates),
            array_map(fn (array $result) => (int) $result['chunk']->id, $videoCandidates),
            $bot,
            $context['workspace_id'],
        );
        $notes = trim(($context['order_summary'] !== null ? "Order details for this customer (use them only if they ask about their order):\n".$context['order_summary'] : '')
            ."\n\n".($video['resources'] !== [] ? trim($video['instructions']) : ''));
        $name = trim((string) (($context['contact']->first_name ?? '').' '.($context['contact']->last_name ?? '')));
        $prompt = app(PromptBuilder::class)->build($bot, $context['kb'], $context['mode'], $results, $context['channel'], $context['words'],
            $this->anonymousContact($context['contact']) ? null : $name, $notes, $context['closest']);
        $messages = array_merge(
            [['role' => 'system', 'content' => $prompt['static']]],
            $context['plain'],
            [['role' => 'system', 'content' => $prompt['excerpts']], ['role' => 'user', 'content' => $context['message']]],
        );

        $response = $turn->step($messages, $context['options'], 'generate');
        [$parsed, $verdict] = $this->checkedV2($turn, $response, $context, $results);
        if ($parsed !== null && $verdict['result'] === 'passed') {
            return ['empty' => false, 'parsed' => $parsed, 'shadow' => $verdict['shadow'], 'reason' => null, 'raw' => $response->content, 'video' => $video];
        }
        $empty = trim($response->content) === '' || ($parsed === null && $verdict['reason'] === null);
        $reason = $verdict['reason'] ?? 'The reply was empty or not in the required format.';

        // Written again once, told why.
        $messages[0]['content'] .= "\n\nYour previous reply was rejected: ".$reason
            .' Answer again using only facts that appear in the knowledge excerpts, the business profile or the conversation above.';
        try {
            $retry = $turn->step($messages, $context['options'], 'regenerate');
        } catch (\Throwable) {
            return ['empty' => $empty, 'parsed' => null, 'shadow' => [], 'reason' => $reason, 'raw' => '', 'video' => $video];
        }
        [$retryParsed, $retryVerdict] = $this->checkedV2($turn, $retry, $context, $results);
        if ($retryParsed === null || $retryVerdict['result'] !== 'passed') {
            return ['empty' => $empty && trim($retry->content) === '', 'parsed' => null, 'shadow' => [], 'reason' => $retryVerdict['reason'] ?? $reason, 'raw' => '', 'video' => $video];
        }
        $this->v2Trace['regenerated_because'] = mb_substr($reason, 0, 300);

        return ['empty' => false, 'parsed' => $retryParsed, 'shadow' => $retryVerdict['shadow'], 'reason' => null, 'raw' => $retry->content, 'video' => $video];
    }

    /**
     * Parses a reply and runs every check on it: the validator, then (for a
     * reply that states facts) the support check.
     *
     * @param  array<string,mixed>  $context
     * @param  array<int,array{chunk:AiKbChunk,score:float}>  $results
     * @return array{0:array<string,mixed>|null,1:array{result:string,reason:?string,shadow:list<string>}}
     */
    private function checkedV2(LlmTurn $turn, LlmResponse $response, array $context, array $results): array
    {
        $this->v2Trace['finish_reason'] = $response->finishReason;
        $parsed = app(ReplyContractV2::class)->parse($response->content);
        if ($parsed === null) {
            return [null, ['result' => 'rejected', 'reason' => null, 'shadow' => []]];
        }
        /** @var AiChatbot $bot */
        $bot = $context['bot'];
        $details = $this->v2Details($bot, $context['kb'], $context['order_summary']);
        // The customer's own words count as support; the bot's earlier replies
        // do not, or one invented figure would vouch for the next.
        $allowed = implode("\n", array_filter(array_merge(
            [$details],
            array_map(fn (array $turn) => $turn['role'] === 'user' ? $turn['content'] : null, $context['plain']),
        )));
        $verdict = app(AnswerValidator::class)->check($parsed, $results, $context['message'], $allowed, $context['mode'] === 'strict');
        if ($verdict['result'] !== 'passed' || ! config('chatbot.v2_support_check')) {
            return [$parsed, $verdict];
        }

        $check = app(SupportCheck::class)->check($turn, $parsed, $results, $context['message'], $context['plain'], $details);
        if (! $check['checked']) {
            return [$parsed, $verdict];
        }
        $this->v2Trace['support_check'][] = array_filter(['supported' => $check['supported'], 'unsupported' => $check['unsupported']], fn ($value) => $value !== null);
        if ($check['supported']) {
            return [$parsed, $verdict];
        }

        return [$parsed, [
            'result' => 'rejected',
            'reason' => 'The reply tells the customer something the knowledge does not state ('.$check['unsupported'].'). Say only what the knowledge states, '
                .'say plainly that you cannot confirm the rest, and do not answer yes or no to what it does not settle (answer_kind "partial" or "handoff").',
            'shadow' => $verdict['shadow'],
        ]];
    }

    /**
     * Only a reply that fell short, once per turn (a planned follow-up already
     * searched with several queries), and only when the knowledge holds more
     * than the bot was shown.
     *
     * @param  array<string,mixed>  $parsed
     * @param  array<int,array{chunk:AiKbChunk,score:float}>  $results
     */
    private function worthASecondLook(?AiKnowledgeBase $kb, array $parsed, array $results): bool
    {
        if (! $kb || ! in_array($parsed['answer_kind'], ['partial', 'handoff'], true) || isset($this->v2Trace['planner']) || isset($this->v2Trace['full_context'])) {
            return false;
        }

        return AiKbChunk::where('kb_id', $kb->id)->count() > count($results);
    }

    /**
     * Searches again with rewritten queries and answers again only when that
     * finds passages the first answer was not shown; keeps the new answer only
     * when it answers more. Part of the same turn: never a second charge.
     *
     * @param  array<string,mixed>  $context
     * @param  array<int,array{chunk:AiKbChunk,score:float}>  $results
     * @param  array{empty:bool,parsed:array<string,mixed>|null,shadow:list<string>,reason:?string,raw:string,video:array<string,mixed>}  $first
     * @param  array<int,array<string,mixed>>  $history
     * @return array{0:array{empty:bool,parsed:array<string,mixed>|null,shadow:list<string>,reason:?string,raw:string,video:array<string,mixed>},1:array<int,array{chunk:AiKbChunk,score:float}>}
     */
    private function secondLook(LlmTurn $turn, array $context, array $results, array $first, array $history): array
    {
        try {
            $plan = app(QueryPlanner::class)->plan($turn, $context['message'], $context['plain']);
            $wider = $this->v2SearchMany($context['kb'], $context['workspace_id'], $plan['queries'], $results, null, $history, $this->v2Limit() + 3);
        } catch (\Throwable) {
            return [$first, $results];
        }
        $seen = array_map(fn (array $result) => (int) $result['chunk']->id, $results);
        $new = array_filter($wider, fn (array $result) => ! in_array((int) $result['chunk']->id, $seen, true));
        $this->v2Trace['second_look'] = ['queries' => $plan['queries'], 'new_passages' => count($new)];
        if ($new === []) {
            return [$first, $results];
        }

        $second = $this->attemptV2($turn, $context, $wider);
        $rank = ['answer' => 3, 'partial' => 2, 'guidance' => 1, 'clarification' => 0, 'handoff' => 0];
        if ($second['parsed'] === null || $rank[$second['parsed']['answer_kind']] <= $rank[$first['parsed']['answer_kind'] ?? 'handoff']) {
            return [$first, $results];
        }
        $this->v2Trace['second_look']['improved'] = true;

        return [$second, $wider];
    }

    /**
     * Passages for one query, from whichever retrieval the platform runs
     * (hybrid or the original vector search), each with its score. Below
     * `chatbot.v2_min_score` a passage is noise and is not shown. Planned
     * queries are not translated: the planner already writes an English one.
     *
     * @param  array<int,array<string,mixed>>  $history
     * @return array<int,array{chunk:AiKbChunk,score:float}>
     */
    private function v2Search(?AiKnowledgeBase $kb, int $workspaceId, string $query, ?int $revisionId, array $history, bool $throwProviderErrors = false, bool $translate = true): array
    {
        if (! $kb || trim($query) === '') {
            return [];
        }
        $policy = $this->retrievalPolicy->privateAnswering();
        $candidates = [];
        try {
            $translation = $translate ? $this->knowledgeRetrieval->englishSearchQuery($workspaceId, $query) : null;
            if (config('knowledge_base.hybrid_retrieval_enabled')) {
                $candidates = $this->knowledgeRetrieval->retrieve($kb, $workspaceId, $query, $history, $policy['max_context_chunks'], $revisionId,
                    $policy['answer_threshold'], $policy['max_context_tokens'], $translation)['candidates'];
            } else {
                foreach (array_filter([$query, $translation]) as $search) {
                    $embedding = $this->queryEmbedding($workspaceId, $search);
                    if ($embedding !== []) {
                        $candidates = array_merge($candidates, $this->retrieveContext((int) $kb->id, $embedding, $search,
                            $policy['max_context_chunks'], $revisionId, $policy['answer_threshold'], $policy['max_context_tokens'])['candidates']);
                    }
                }
            }
        } catch (\Throwable $error) {
            if ($throwProviderErrors) {
                throw $error;
            }
            $this->v2Trace['retrieval_error'] = true;
        }

        return $this->bestPassages($candidates, $this->v2Limit());
    }

    /**
     * @param  list<string>  $queries
     * @param  array<int,array{chunk:AiKbChunk,score:float}>  $results
     * @param  array<int,array<string,mixed>>  $history
     * @return array<int,array{chunk:AiKbChunk,score:float}>
     */
    private function v2SearchMany(?AiKnowledgeBase $kb, int $workspaceId, array $queries, array $results, ?int $revisionId, array $history, ?int $limit = null): array
    {
        $candidates = array_map(fn (array $result) => ['chunk' => $result['chunk'], 'rank_score' => $result['score']], $results);
        foreach ($queries as $query) {
            foreach ($this->v2Search($kb, $workspaceId, $query, $revisionId, $history, translate: false) as $result) {
                $candidates[] = ['chunk' => $result['chunk'], 'rank_score' => $result['score']];
            }
        }

        return $this->bestPassages($candidates, $limit ?? $this->v2Limit());
    }

    /**
     * The best score per passage, near-duplicates removed, best first.
     *
     * @param  array<int,array<string,mixed>>  $candidates
     * @return array<int,array{chunk:AiKbChunk,score:float}>
     */
    private function bestPassages(array $candidates, int $limit): array
    {
        $minimum = (float) config('chatbot.v2_min_score', 0.30);
        $best = [];
        foreach ($candidates as $candidate) {
            $chunk = $candidate['chunk'] ?? null;
            $score = (float) ($candidate['rank_score'] ?? $candidate['score'] ?? 0);
            if (! $chunk instanceof AiKbChunk || $score < $minimum || trim((string) $chunk->content) === '') {
                continue;
            }
            if (! isset($best[$chunk->id]) || $score > $best[$chunk->id]['score']) {
                $best[$chunk->id] = ['chunk' => $chunk, 'score' => $score];
            }
        }
        usort($best, fn (array $a, array $b) => $b['score'] <=> $a['score']);

        $kept = [];
        $seen = [];
        foreach ($best as $result) {
            $words = KnowledgeRetrievalService::passageWords((string) $result['chunk']->content);
            if (KnowledgeRetrievalService::duplicatesAny($words, $seen)) {
                continue;
            }
            $seen[] = $words;
            $result['chunk']->loadMissing('document');
            $kept[] = $result;
            if (count($kept) >= $limit) {
                break;
            }
        }

        return $kept;
    }

    /**
     * Every live passage of a knowledge base small enough to send whole, in
     * document order, each with its search score (0 when the search did not
     * find it), and the numbers of the passages the search placed closest.
     * Null when it is larger than `chatbot.v2_full_context_max_tokens`
     * (0 turns this off).
     *
     * @param  array<int,array{chunk:AiKbChunk,score:float}>  $searched
     * @return array{results:array<int,array{chunk:AiKbChunk,score:float}>,closest:list<int>}|null
     */
    private function wholeKnowledge(?AiKnowledgeBase $kb, int $workspaceId, ?int $revisionId, array $searched): ?array
    {
        $max = (int) config('chatbot.v2_full_context_max_tokens', 0);
        if ($max <= 0 || ! $kb || (int) $kb->workspace_id !== $workspaceId) {
            return null;
        }
        // The stored counts are characters ÷ 4: a cheap first test before any content is read.
        if ((int) $this->embedStore->liveChunks((int) $kb->id, $revisionId)->sum('tokens') > $max) {
            return null;
        }
        $chunks = $this->embedStore->liveChunks((int) $kb->id, $revisionId)->with('document')
            ->orderBy('document_id')->orderBy('ord')->orderBy('id')->limit(2000)->get();
        $tokens = (int) $chunks->sum(fn (AiKbChunk $chunk) => $this->estimatedTokens((string) $chunk->content));
        if ($chunks->isEmpty() || $tokens > $max) {
            return null;
        }

        $scores = [];
        foreach ($searched as $result) {
            $scores[(int) $result['chunk']->id] = (float) $result['score'];
        }
        $results = [];
        $closest = [];
        foreach ($chunks->values() as $index => $chunk) {
            $results[] = ['chunk' => $chunk, 'score' => $scores[(int) $chunk->id] ?? 0.0];
            if (isset($scores[(int) $chunk->id])) {
                $closest[$index + 1] = $scores[(int) $chunk->id];
            }
        }
        arsort($closest);
        $this->v2Trace['full_context'] = ['passages' => count($results), 'tokens' => $tokens];

        return ['results' => $results, 'closest' => array_slice(array_keys($closest), 0, $this->v2Limit())];
    }

    /**
     * Characters ÷ 4 fits English, but Bangla, Arabic or Chinese take about a
     * token per character or two: every character outside ASCII counts as one.
     */
    private function estimatedTokens(string $text): int
    {
        $wide = (int) preg_match_all('/[^\x00-\x7F]/u', $text);

        return (int) ceil((mb_strlen($text) - $wide) / 4) + $wide;
    }

    private function v2Limit(): int
    {
        return $this->retrievalPolicy->privateAnswering()['max_context_chunks'];
    }

    /** Strict / Balanced / Flexible from the bot's answer scope; Balanced needs a business profile. */
    private function engineV2Mode(AiChatbot $bot, ?AiKnowledgeBase $kb): string
    {
        $mode = PromptBuilder::mode($bot->answer_scope);

        // Without the business's name and purpose, "about the business" has no
        // meaning to judge by, so Balanced stays with what the knowledge says.
        return $mode === 'balanced' && ! $this->turnRouter->hasMeaningfulProfile($kb) ? 'strict' : $mode;
    }

    /** Business profile, order details and the bot's own instructions: what may be stated besides the knowledge. */
    private function v2Details(AiChatbot $bot, ?AiKnowledgeBase $kb, ?string $orderSummary): string
    {
        return implode("\n", array_filter([
            $kb ? $this->turnRouter->profileText($kb) : null,
            $orderSummary,
            $bot->system_prompt,
        ]));
    }

    /**
     * The last ten customer and bot turns, text only.
     *
     * @param  array<int,array<string,mixed>>  $history
     * @return array<int,array{role:string,content:string}>
     */
    private function v2History(array $history): array
    {
        $turns = array_values(array_filter($history, fn ($turn) => in_array($turn['role'] ?? null, ['user', 'assistant'], true)
            && trim((string) ($turn['content'] ?? '')) !== ''));

        return array_map(fn (array $turn): array => [
            'role' => (string) $turn['role'],
            'content' => mb_substr(trim((string) $turn['content']), 0, 2000),
        ], array_slice($turns, -10));
    }

    /** @param array<int,array<string,mixed>> $history */
    private function lastReplyAskedQuestion(array $history): bool
    {
        $last = collect($history)->last(fn ($turn) => ($turn['role'] ?? null) === 'assistant');

        return is_array($last) && ($last['response_mode'] ?? null) === 'clarification';
    }

    /** "Where is my order", "cancel my subscription": the customer's own records. */
    private function isAccountSpecific(string $message): bool
    {
        if (preg_match('/\b(where\s+is\s+my|status\s+of\s+my|track\s+my|order\s*#|refund\s+my|my\s+(order|delivery|parcel|package|payment|refund)\s+(is|was|has|hasn|didn|did|never|still|not)|amar\s+(order|account|payment|refund))\b/iu', $message)) {
            return true;
        }
        if (preg_match('/^\s*(how|what|which|can|could|should|is|are|do|does)\b/iu', $message)) {
            return false;
        }

        return (bool) preg_match('/\b(my\s+(order|account|subscription|invoice|payment|delivery|tracking|profile)|cancel\s+my|change\s+my)\b/iu', $message);
    }

    /** The prompt asks for about $words; the hard stop is half again, at a sentence end. */
    private function capWords(string $reply, int $words): string
    {
        $limit = (int) ceil($words * 1.5);
        $parts = preg_split('/\s+/u', trim($reply)) ?: [];
        if (count($parts) <= $limit) {
            return trim($reply);
        }
        $truncated = implode(' ', array_slice($parts, 0, $limit));
        if (preg_match('/^(.*[.!?؟。！？।])\s/su', $truncated.' ', $matches)) {
            return trim($matches[1]);
        }

        return rtrim($truncated, " \t\n\r\0\x0B,;:").'…';
    }

    /**
     * @param  array<int,array{chunk:AiKbChunk,score:float}>  $results
     * @param  array<string,mixed>  $metadata
     */
    private function recordV2(AiChatbot $bot, int $workspaceId, ?int $revisionId, string $decision, array $results, string $reason, array $metadata, ?LlmTurn $turn = null): void
    {
        // The closest passages only: with the whole knowledge read, that is
        // what the search found, not every passage.
        $closestFirst = $results;
        usort($closestFirst, fn (array $a, array $b) => $b['score'] <=> $a['score']);
        $this->v2Trace['passages'] = array_map(fn (array $result) => [
            'chunk_id' => (int) $result['chunk']->id,
            'score' => round((float) $result['score'], 4),
        ], array_slice(array_filter($closestFirst, fn (array $result) => $result['score'] > 0 || ! isset($this->v2Trace['full_context'])), 0, 10));
        $this->recordDiagnostic($bot, $workspaceId, $revisionId, $decision, null, [
            'best_score' => $results[0]['score'] ?? null,
            'passages_used' => count($results),
        ], $turn?->tokensUsed() ?? 0, $metadata + [
            'reason_code' => $reason,
            'engine' => 'v2',
            'trace' => $this->v2Trace,
            'model' => $turn?->model(),
            'finish_reason' => $this->v2Trace['finish_reason'] ?? null,
            'latency_ms' => $turn && $turn->latencyMs() > 0 ? $turn->latencyMs() : null,
        ]);
    }
}
