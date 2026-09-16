<?php

namespace App\Modules\AI\Services;

use App\Modules\AI\Models\AiKbChunk;
use App\Modules\AI\Models\AiKbEmbeddingCache;
use App\Modules\AI\Models\AiKnowledgeBase;

class KnowledgeRetrievalService
{
    public function __construct(
        private readonly LlmGateway $llm,
        private readonly EmbeddingStore $embeddings,
        private readonly BusinessAwareTurnRouter $turnRouter,
    ) {}

    /**
     * @param  array<int,array{role?:string,content?:string,answer_origin?:string,response_mode?:string,quick_replies?:array<int,mixed>}>  $history
     * @return array{context:string,candidates:array<int,array<string,mixed>>,best_score:float,semantic_score:float,lexical_score:float,passages_used:int,context_tokens:int,response_mode:string,retrieval_strategy:string,acceptance_reason:string,query_embedding:array<int,float|int>,research_query:string,repeated_clarification:bool}
     */
    public function retrieve(
        AiKnowledgeBase $knowledgeBase,
        int $workspaceId,
        string $message,
        array $history,
        int $limit,
        ?int $revisionId,
        float $answerThreshold,
        int $maxTokens,
    ): array {
        $limit = max(1, min($limit, 10));
        $search = $this->searchQueries($message, $history, $knowledgeBase);
        $queries = $search['queries'];
        $queryEmbeddings = $this->queryEmbeddings($workspaceId, $queries);
        $primaryEmbedding = $queryEmbeddings[0] ?? [];
        $candidateMap = [];

        foreach ($queryEmbeddings as $embedding) {
            foreach ($this->embeddings->search((int) $knowledgeBase->id, $embedding, min(30, max(12, $limit * 4)), $revisionId) as $result) {
                $chunk = $result['chunk'];
                $id = (int) $chunk->id;
                $candidateMap[$id] ??= ['chunk' => $chunk, 'semantic_score' => -1.0, 'lexical_seed' => 0.0];
                $candidateMap[$id]['semantic_score'] = max($candidateMap[$id]['semantic_score'], (float) ($result['score'] ?? 0));
            }
        }

        $queryTerms = $this->terms(implode(' ', $queries));
        foreach ($this->embeddings->lexicalSearch((int) $knowledgeBase->id, $queryTerms, 24, $revisionId) as $result) {
            $chunk = $result['chunk'];
            $id = (int) $chunk->id;
            $candidateMap[$id] ??= ['chunk' => $chunk, 'semantic_score' => 0.0, 'lexical_seed' => 0.0];
            $candidateMap[$id]['lexical_seed'] = max($candidateMap[$id]['lexical_seed'], (float) $result['score']);
        }

        $candidates = array_values(array_map(function (array $candidate) use ($queryTerms): array {
            /** @var AiKbChunk $chunk */
            $chunk = $candidate['chunk'];
            $searchable = implode(' ', [
                (string) $chunk->section_label,
                (string) $chunk->document?->title,
                $chunk->content,
            ]);
            $candidateTerms = $this->terms($searchable);
            $lexical = $queryTerms === [] ? 0.0 : count(array_intersect($queryTerms, $candidateTerms)) / count($queryTerms);
            $lexical = max($lexical, (float) $candidate['lexical_seed']);
            $fuzzy = $this->fuzzyScore($queryTerms, $candidateTerms);
            $semantic = max(0.0, min(1.0, (float) $candidate['semantic_score']));
            $boost = min(0.18, ($lexical * 0.12) + ($fuzzy * 0.06));

            return $candidate + [
                'score' => $semantic,
                'semantic_score' => $semantic,
                'lexical_score' => $lexical,
                'fuzzy_score' => $fuzzy,
                // Exact wording may improve ranking, but never reduces meaning.
                'rank_score' => min(1.0, $semantic + $boost),
            ];
        }, $candidateMap));

        usort($candidates, fn (array $left, array $right): int => $right['rank_score'] <=> $left['rank_score']);
        $best = $candidates[0] ?? null;
        $answerThreshold = max(0.35, min(0.95, $answerThreshold));
        $clarifyThreshold = max(
            (float) config('knowledge_base.clarification_min_threshold', 0.38),
            $answerThreshold - (float) config('knowledge_base.clarification_threshold_margin', 0.20),
        );
        $explicitIntent = $this->hasExplicitIntent($message);

        $strong = array_values(array_filter($candidates, fn (array $candidate): bool => $candidate['semantic_score'] >= $answerThreshold
            || ($candidate['semantic_score'] >= max($clarifyThreshold, $answerThreshold - 0.08)
                && $candidate['rank_score'] >= $answerThreshold
                && ($candidate['lexical_score'] >= 0.20 || $candidate['fuzzy_score'] >= 0.72))
            || ($explicitIntent
                && $candidate['semantic_score'] >= $clarifyThreshold
                && $candidate['lexical_score'] >= 0.55
                && $candidate['rank_score'] >= max(0.50, $clarifyThreshold + 0.10))
        ));
        $medium = array_values(array_filter($candidates, fn (array $candidate): bool => $candidate['semantic_score'] >= max(0.48, $clarifyThreshold)
            || ($candidate['semantic_score'] >= $clarifyThreshold
                && ($candidate['lexical_score'] > 0 || $candidate['fuzzy_score'] >= 0.68))
            || ($candidate['rank_score'] >= $clarifyThreshold && $candidate['lexical_score'] >= 0.35)
        ));

        $repeatedClarification = $this->hasRecentClarification($history);
        $mode = $strong !== [] ? 'answer' : ($medium !== [] && ! $repeatedClarification ? 'clarification' : 'fallback');
        $selected = $mode === 'answer' ? $strong : ($mode === 'clarification' ? $medium : []);
        $selected = array_slice($selected, 0, $mode === 'clarification' ? min(2, $limit) : $limit);
        $passages = [];
        $characters = 0;
        foreach ($selected as $candidate) {
            /** @var AiKbChunk $chunk */
            $chunk = $candidate['chunk'];
            $chunk->loadMissing('document');
            $focused = $this->focusedPassage($chunk->content, $queryTerms);
            if ($focused === '') {
                continue;
            }
            $title = trim((string) $chunk->document?->title);
            $source = trim((string) $chunk->document?->source_ref);
            $label = $title !== '' ? 'Source: '.$title : 'Knowledge passage';
            if (filter_var($source, FILTER_VALIDATE_URL)) {
                $label .= ' ('.$source.')';
            }
            if ($chunk->section_label) {
                $label .= ' — '.$chunk->section_label;
            }
            $passage = '['.$label."]\n".$focused;
            if ($passages !== [] && $characters + mb_strlen($passage) > $maxTokens * 4) {
                break;
            }
            $passages[] = $passage;
            $characters += mb_strlen($passage);
        }

        return [
            'context' => implode("\n\n---\n\n", $passages),
            'candidates' => array_slice($candidates, 0, max(12, $limit)),
            'best_score' => (float) ($best['rank_score'] ?? 0),
            'semantic_score' => (float) ($best['semantic_score'] ?? 0),
            'lexical_score' => (float) ($best['lexical_score'] ?? 0),
            'passages_used' => count($passages),
            'context_tokens' => (int) ceil($characters / 4),
            'response_mode' => $passages === [] ? 'fallback' : $mode,
            'retrieval_strategy' => $search['used_context'] ? 'hybrid_contextual' : 'hybrid_current_turn',
            'acceptance_reason' => match (true) {
                $strong !== [] => 'strong_evidence',
                $medium !== [] && ! $repeatedClarification => 'grounded_clarification',
                $repeatedClarification => 'clarification_already_asked',
                default => 'insufficient_evidence',
            },
            'query_embedding' => $primaryEmbedding,
            'research_query' => $search['used_context'] ? (string) end($queries) : $message,
            'repeated_clarification' => $repeatedClarification,
        ];
    }

    /**
     * @param  array<int,array{role?:string,content?:string,answer_origin?:string,response_mode?:string,quick_replies?:array<int,mixed>}>  $history
     * @return array{queries:array<int,string>,used_context:bool}
     */
    private function searchQueries(string $message, array $history, AiKnowledgeBase $knowledgeBase): array
    {
        $message = trim($message);
        $queries = [$message];
        $compact = $this->compactSeparatedTerms($message);
        if ($compact !== '' && mb_strtolower($compact) !== mb_strtolower($message)) {
            $queries[] = $compact;
        }

        if (! $this->isContextualContinuation($message, $history)) {
            return ['queries' => array_values(array_unique($queries)), 'used_context' => false];
        }

        $substantive = array_values(array_filter($history, function (array $turn) use ($knowledgeBase): bool {
            $content = trim((string) ($turn['content'] ?? ''));
            if ($content === '' || in_array($turn['answer_origin'] ?? null, ['conversation', 'fallback'], true)) {
                return false;
            }
            if (($turn['response_mode'] ?? null) === 'fallback' || $this->looksLikeFallback($content)) {
                return false;
            }

            return $this->turnRouter->conversationalResult($content, $knowledgeBase) === null;
        }));
        $previous = end($substantive);
        if (is_array($previous) && trim((string) ($previous['content'] ?? '')) !== '') {
            $queries[] = mb_substr(trim((string) $previous['content']), 0, 400)."\nCustomer follow-up: ".$message;
        }

        return [
            'queries' => array_values(array_unique($queries)),
            'used_context' => is_array($previous),
        ];
    }

    private function isContinuation(string $message): bool
    {
        $terms = $this->terms($message);

        return count($terms) <= 2 && (bool) preg_match(
            '/^(?:yes|no|yeah|nope|it|that|this|same|both|first|second|option|supported|not supported|ঠিক আছে|হ্যাঁ|না|জি)\b/iu',
            trim($message),
        );
    }

    /**
     * Strong wording evidence can answer only when the customer expressed a
     * recognizable task or question. A bare noun plus "want" remains ambiguous
     * and should still receive one clarification.
     */
    private function hasExplicitIntent(string $message): bool
    {
        return (bool) preg_match(
            '/(?:\b(?:how|where|when|which|why|can i|do i|want to|need to|help me|buy|purchase|order|choose|select|install|activate|recharge|refill|refund|pay|cancel|change|compare|troubleshoot|fix|kivabe|kibhabe|kinte)\b|কীভাবে|কিভাবে|কিনতে|কিনবো)/iu',
            trim($message),
        );
    }

    /** @param array<int,array<string,mixed>> $history */
    private function isContextualContinuation(string $message, array $history): bool
    {
        if ($this->isContinuation($message)) {
            return true;
        }
        $normalized = $this->normalize($message);
        foreach (array_reverse(array_slice($history, -4)) as $turn) {
            if (($turn['role'] ?? null) !== 'assistant') {
                continue;
            }
            foreach ($turn['quick_replies'] ?? [] as $choice) {
                $label = is_array($choice) ? ($choice['label'] ?? '') : $choice;
                if (is_string($label) && $this->normalize($label) === $normalized) {
                    return true;
                }
            }
            if (($turn['response_mode'] ?? null) === 'clarification'
                && count($this->terms($message)) <= 6) {
                return true;
            }
        }

        return false;
    }

    /** @param array<int,array{response_mode?:string}> $history */
    private function hasRecentClarification(array $history): bool
    {
        foreach (array_slice($history, -4) as $turn) {
            if (($turn['response_mode'] ?? null) === 'clarification') {
                return true;
            }
        }

        return false;
    }

    private function looksLikeFallback(string $text): bool
    {
        return (bool) preg_match('/(?:could not find|do not have (?:a )?(?:verified|proper) answer|human help|another agent|connect you with a person)/iu', $text);
    }

    /**
     * @param  array<int,string>  $queries
     * @return array<int,array<int,float|int>>
     */
    private function queryEmbeddings(int $workspaceId, array $queries): array
    {
        $model = (string) config('ai_credits.managed.embedding_model', 'text-embedding-3-small');
        $resolved = [];
        $missing = [];
        foreach ($queries as $index => $query) {
            $hash = hash('sha256', $this->normalize($query));
            $cached = AiKbEmbeddingCache::where('content_hash', $hash)
                ->where('model', $model)
                ->where(fn ($builder) => $builder->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->first();
            if ($cached && $cached->embedding !== []) {
                $resolved[$index] = $cached->embedding;
            } else {
                $missing[$index] = ['query' => $query, 'hash' => $hash];
            }
        }
        if ($missing !== []) {
            $generated = $this->llm->embed($workspaceId, array_column($missing, 'query'));
            foreach (array_keys($missing) as $position => $index) {
                $embedding = $generated[$position] ?? [];
                if ($embedding === []) {
                    continue;
                }
                $resolved[$index] = $embedding;
                AiKbEmbeddingCache::updateOrCreate(
                    ['content_hash' => $missing[$index]['hash'], 'model' => $model],
                    ['embedding' => $embedding, 'expires_at' => now()->addDays((int) config('knowledge_base.query_embedding_cache_days', 7))],
                );
            }
        }
        ksort($resolved);

        return array_values($resolved);
    }

    private function compactSeparatedTerms(string $text): string
    {
        $words = preg_split('/\s+/u', trim((string) preg_replace('/[-_]+/u', ' ', $text))) ?: [];
        $result = [];
        for ($index = 0; $index < count($words); $index++) {
            $current = $words[$index];
            $next = $words[$index + 1] ?? null;
            if ($next !== null && $this->shouldCompactPair($current, $next)
                && preg_match('/^[\p{L}\p{N}]+$/u', $current.$next)) {
                $result[] = $current.$next;
                $index++;
            } else {
                $result[] = $current;
            }
        }

        return trim(implode(' ', $result));
    }

    /** @return array<int,string> */
    private function terms(string $text): array
    {
        $normalized = mb_strtolower(strip_tags($text));
        preg_match_all('/[\p{L}\p{N}]{2,}/u', $normalized, $matches);
        $terms = $matches[0];
        $words = preg_split('/\s+/u', trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $normalized))) ?: [];
        for ($index = 0; $index < count($words) - 1; $index++) {
            if ($this->shouldCompactPair($words[$index], $words[$index + 1])) {
                $terms[] = $words[$index].$words[$index + 1];
            }
        }
        $stop = array_flip(['the', 'and', 'for', 'with', 'this', 'that', 'from', 'your', 'you', 'are', 'was', 'were', 'what', 'when', 'where', 'which', 'how', 'can', 'could', 'would', 'about', 'into', 'have', 'want', 'need', 'please', 'to', 'of', 'a', 'an', 'is', 'it']);

        return array_values(array_unique(array_filter($terms, fn (string $term): bool => ! isset($stop[$term]))));
    }

    private function shouldCompactPair(string $left, string $right): bool
    {
        $leftLength = mb_strlen($left);
        $rightLength = mb_strlen($right);

        return ($leftLength === 1 && $rightLength <= 3)
            || ($leftLength <= 2 && $rightLength <= 2);
    }

    /**
     * @param  array<int,string>  $queryTerms
     * @param  array<int,string>  $candidateTerms
     */
    private function fuzzyScore(array $queryTerms, array $candidateTerms): float
    {
        $scores = [];
        foreach ($queryTerms as $query) {
            if (mb_strlen($query) < 4) {
                continue;
            }
            $best = 0.0;
            foreach (array_slice($candidateTerms, 0, 250) as $candidate) {
                if (abs(mb_strlen($query) - mb_strlen($candidate)) > 3) {
                    continue;
                }
                $best = max($best, $this->dice($query, $candidate));
            }
            $scores[] = $best;
        }

        // Coverage across the customer's meaningful words is useful; one common
        // word must not give every passage a perfect fuzzy score.
        return $scores === [] ? 0.0 : array_sum($scores) / count($scores);
    }

    private function dice(string $left, string $right): float
    {
        $left = $this->bigrams($left);
        $right = $this->bigrams($right);
        if ($left === [] || $right === []) {
            return 0.0;
        }
        $intersection = count(array_intersect($left, $right));

        return (2 * $intersection) / (count($left) + count($right));
    }

    /** @return array<int,string> */
    private function bigrams(string $value): array
    {
        $characters = preg_split('//u', mb_strtolower($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $grams = [];
        for ($index = 0; $index < count($characters) - 1; $index++) {
            $grams[] = $characters[$index].$characters[$index + 1];
        }

        return array_values(array_unique($grams));
    }

    /** @param array<int,string> $queryTerms */
    private function focusedPassage(string $content, array $queryTerms): string
    {
        $parts = preg_split('/(?=^#\s*CONVERSATION\s+\d+)|(?=^Customer\s*:)|(?:\R\s*){2,}/imu', trim($content)) ?: [];
        if (count($parts) <= 1) {
            return mb_substr(trim($content), 0, 2200);
        }
        $ranked = collect($parts)->map(function (string $part) use ($queryTerms): array {
            $terms = $this->terms($part);
            $lexical = $queryTerms === [] ? 0.0 : count(array_intersect($queryTerms, $terms)) / count($queryTerms);

            return ['text' => trim($part), 'score' => max($lexical, $this->fuzzyScore($queryTerms, $terms))];
        })->filter(fn (array $part): bool => $part['text'] !== '')->sortByDesc('score')->take(2)->pluck('text')->implode("\n\n");

        return mb_substr(trim($ranked), 0, 2200);
    }

    private function normalize(string $value): string
    {
        return trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($value)));
    }
}
