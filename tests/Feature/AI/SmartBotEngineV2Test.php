<?php

namespace Tests\Feature\AI;

use App\Modules\AI\Models\AiChatbot;
use App\Modules\AI\Models\AiCreditLedger;
use App\Modules\AI\Models\AiKbChunk;
use App\Modules\AI\Models\AiKbDocument;
use App\Modules\AI\Models\AiKbRetrievalDiagnostic;
use App\Modules\AI\Models\AiKnowledgeBase;
use App\Modules\AI\Models\AiProviderConfig;
use App\Modules\AI\Services\ChatbotRunner;
use App\Modules\AI\Services\EmbeddingStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Smart Bot 2.0 engine v2 (Phase 1.1): the answer ladder, the checks before a
 * reply is sent, and one charge per answer.
 */
class SmartBotEngineV2Test extends TestCase
{
    use RefreshDatabase;

    private const PASSAGE = 'To install an eSIM, open Settings, choose Mobile Data and scan the QR code. The Japan plan costs 12 USD.';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('chatbot.engine_v2_enabled', true);
        // These tests cover the search path; full-context mode has its own tests.
        config()->set('chatbot.v2_full_context_max_tokens', 0);
    }

    public function test_a_quoted_answer_is_checked_sent_and_charged_once(): void
    {
        [$bot, $workspaceId] = $this->bot();
        $this->fake([1.0, 0.0, 0.0], [
            $this->reply('Open Settings, choose Mobile Data and scan the QR code.', 'answer', [1], ['choose Mobile Data and scan the QR code']),
            ['supported' => true, 'unsupported' => ''],
        ]);

        $result = app(ChatbotRunner::class)->runForApi($bot, 'How do I install the eSIM?', $workspaceId, [], 'api:one');

        $this->assertSame('Open Settings, choose Mobile Data and scan the QR code.', $result['reply']);
        $this->assertSame('knowledge_base', $result['answer_origin']);
        $this->assertSame('answer', $result['response_mode']);
        $this->assertSame('succeeded', AiCreditLedger::sole()->status);
        $row = AiKbRetrievalDiagnostic::sole();
        $this->assertSame('v2', $row->engine);
        $this->assertSame('answered', $row->reason_code);
        $this->assertSame('answer', $row->trace['answer_kind']);
        $this->assertSame([['supported' => true]], $row->trace['support_check']);
        $this->assertCount(2, $this->chatRequests());
    }

    public function test_the_same_key_replays_the_stored_answer_without_calling_the_model(): void
    {
        [$bot, $workspaceId] = $this->bot();
        $this->fake([1.0, 0.0, 0.0], [
            $this->reply('Open Settings, choose Mobile Data and scan the QR code.', 'answer', [1], ['choose Mobile Data and scan the QR code']),
            ['supported' => true, 'unsupported' => ''],
        ]);
        $runner = app(ChatbotRunner::class);

        $first = $runner->runForApi($bot, 'How do I install the eSIM?', $workspaceId, [], 'api:same');
        $second = $runner->runForApi($bot, 'How do I install the eSIM?', $workspaceId, [], 'api:same');

        $this->assertSame($first['reply'], $second['reply']);
        $this->assertCount(2, $this->chatRequests());
        $this->assertSame(1, AiCreditLedger::count());
    }

    public function test_a_full_answer_without_a_real_quote_is_written_again(): void
    {
        [$bot, $workspaceId] = $this->bot();
        $this->fake([1.0, 0.0, 0.0], [
            $this->reply('Yes, it works on every phone.', 'answer', [1], ['works on every phone']),
            $this->reply('I can confirm how to install it: open Settings, choose Mobile Data and scan the QR code. I cannot confirm which phones are supported.', 'partial', [1], ['scan the QR code']),
            ['supported' => true, 'unsupported' => ''],
        ]);

        $result = app(ChatbotRunner::class)->runForApi($bot, 'Does the eSIM work on my phone?', $workspaceId);

        $this->assertStringContainsString('cannot confirm which phones', $result['reply']);
        $row = AiKbRetrievalDiagnostic::sole();
        $this->assertSame('answered_regenerated', $row->reason_code);
        $this->assertStringContainsString('evidence quotes', $row->trace['regenerated_because']);
        $regenerate = $this->chatRequests()[1]['messages'][0]['content'];
        $this->assertStringContainsString('Your previous reply was rejected', $regenerate);
    }

    public function test_a_reply_the_knowledge_does_not_state_ends_as_a_free_offer_of_a_person(): void
    {
        [$bot, $workspaceId] = $this->bot();
        $this->fake([1.0, 0.0, 0.0], [
            $this->reply('Yes, we deliver the QR code by post.', 'partial', [1], ['scan the QR code']),
            ['supported' => false, 'unsupported' => 'delivery by post'],
            $this->reply('I do not have that detail. Would you like a team member to help?', 'handoff', [], []),
        ]);

        $result = app(ChatbotRunner::class)->runForApi($bot, 'Can you post me the QR code?', $workspaceId);

        $this->assertSame('fallback', $result['answer_origin']);
        $this->assertStringContainsString('team member', $result['reply']);
        $this->assertSame('refunded', AiCreditLedger::sole()->status);
        $this->assertSame('model_handoff', AiKbRetrievalDiagnostic::sole()->reason_code);
    }

    public function test_a_question_back_is_sent_but_not_charged_and_never_asked_twice(): void
    {
        [$bot, $workspaceId] = $this->bot();
        $question = $this->reply('Which country are you travelling to?', 'clarification', [], [], ['Japan', 'Italy']);
        // The answer to a question back is always planned with that question.
        $plan = ['standalone_question' => 'I need an eSIM', 'search_queries' => ['eSIM']];
        $this->fake([1.0, 0.0, 0.0], [$question, $plan, $question]);
        $runner = app(ChatbotRunner::class);

        $first = $runner->runForApi($bot, 'I need an eSIM', $workspaceId);
        $second = $runner->runForApi($bot, 'I need an eSIM please', $workspaceId, [
            ['role' => 'user', 'content' => 'I need an eSIM'],
            ['role' => 'assistant', 'content' => 'Which country are you travelling to?', 'response_mode' => 'clarification'],
        ]);

        $this->assertSame('clarification', $first['response_mode']);
        $this->assertSame(['Japan', 'Italy'], array_column($first['quick_replies'], 'label'));
        $this->assertSame('fallback', $second['response_mode']);
        $this->assertSame(['refunded', 'refunded'], AiCreditLedger::orderBy('id')->pluck('status')->all());
        $this->assertSame(['model_clarification', 'clarified_twice'], AiKbRetrievalDiagnostic::orderBy('id')->pluck('reason_code')->all());
    }

    public function test_a_short_follow_up_is_planned_before_searching(): void
    {
        [$bot, $workspaceId] = $this->bot();
        $this->fake([1.0, 0.0, 0.0], [
            ['standalone_question' => 'How much is the Japan eSIM plan?', 'search_queries' => ['Japan eSIM plan price']],
            $this->reply('The Japan plan costs 12 USD.', 'answer', [1], ['The Japan plan costs 12 USD']),
            ['supported' => true, 'unsupported' => ''],
        ]);

        $result = app(ChatbotRunner::class)->runForApi($bot, 'and Japan?', $workspaceId, [
            ['role' => 'user', 'content' => 'How much is an eSIM?'],
            ['role' => 'assistant', 'content' => 'Which country?'],
        ]);

        $this->assertSame('The Japan plan costs 12 USD.', $result['reply']);
        $this->assertSame(['How much is the Japan eSIM plan?', 'Japan eSIM plan price'], AiKbRetrievalDiagnostic::sole()->trace['planner']['queries']);
        $this->assertStringContainsString('standalone', $this->chatRequests()[0]['messages'][0]['content']);
    }

    public function test_an_invented_price_is_never_sent(): void
    {
        [$bot, $workspaceId] = $this->bot();
        $invented = $this->reply('The Japan plan costs 15 USD.', 'answer', [1], ['The Japan plan costs']);
        $this->fake([1.0, 0.0, 0.0], [$invented, $invented]);

        $result = app(ChatbotRunner::class)->runForApi($bot, 'How much is Japan?', $workspaceId);

        $this->assertSame('fallback', $result['answer_origin']);
        $this->assertStringNotContainsString('15', $result['reply']);
        $this->assertSame('rejected_reply', AiKbRetrievalDiagnostic::sole()->reason_code);
        $this->assertSame('refunded', AiCreditLedger::sole()->status);
    }

    public function test_balanced_gives_guidance_but_strict_with_nothing_close_offers_the_fallback_free(): void
    {
        [$bot, $workspaceId] = $this->bot();
        $this->fake([0.0, 1.0, 0.0], [
            $this->reply('For travel, an eSIM avoids roaming charges. Tell me your destination and I can point you to the right option.', 'guidance', [], []),
        ]);

        $balanced = app(ChatbotRunner::class)->runForApi($bot, 'Why use an eSIM when travelling?', $workspaceId);
        $this->assertSame('business_guidance', $balanced['answer_origin']);
        $this->assertStringContainsString('Ladder: Answer from the knowledge excerpts', 'Ladder: '.$this->ladderIn($this->chatRequests()[0]));

        $bot->update(['answer_scope' => 'verified_only']);
        $strict = app(ChatbotRunner::class)->runForApi($bot->fresh(), 'Why use an eSIM when travelling?', $workspaceId);

        $this->assertSame('fallback', $strict['answer_origin']);
        $this->assertCount(1, $this->chatRequests());
        $this->assertSame('no_context', AiKbRetrievalDiagnostic::latest('id')->first()->reason_code);
    }

    public function test_bots_stay_on_engine_v1_until_the_switch_and_the_bot_both_say_v2(): void
    {
        [$bot, $workspaceId] = $this->bot();
        config()->set('chatbot.engine_v2_enabled', false);
        $this->fake([1.0, 0.0, 0.0], [[
            'reply' => 'Open Settings and scan the QR code.', 'quick_replies' => [], 'grounded' => true, 'response_type' => 'answer', 'show_video' => false,
        ]]);

        app(ChatbotRunner::class)->runForApi($bot, 'How do I install the eSIM?', $workspaceId);

        $this->assertSame('v1', AiKbRetrievalDiagnostic::sole()->engine);
        $this->assertStringNotContainsString('answer_kind', $this->chatRequests()[0]['messages'][0]['content']);
    }

    public function test_a_small_knowledge_base_is_read_whole_without_planning_or_refusing(): void
    {
        config()->set('chatbot.v2_full_context_max_tokens', 20000);
        [$bot, $workspaceId] = $this->bot();
        $bot->update(['answer_scope' => 'verified_only']);
        // The search finds nothing close, but the knowledge is small enough to read whole.
        $this->fake([0.0, 1.0, 0.0], [
            $this->reply('The Japan plan costs 12 USD.', 'answer', [1], ['The Japan plan costs 12 USD']),
            ['supported' => true, 'unsupported' => ''],
        ]);

        $result = app(ChatbotRunner::class)->runForApi($bot->fresh(), 'and Japan?', $workspaceId, [
            ['role' => 'user', 'content' => 'How much is an eSIM?'],
            ['role' => 'assistant', 'content' => 'Which country?'],
        ]);

        $this->assertSame('The Japan plan costs 12 USD.', $result['reply']);
        $row = AiKbRetrievalDiagnostic::sole();
        $this->assertSame(['passages' => 1, 'tokens' => 26], $row->trace['full_context']);
        $this->assertArrayNotHasKey('planner', $row->trace);
        $requests = $this->chatRequests();
        $this->assertCount(2, $requests);
        $this->assertStringContainsString('everything this business has written down', $requests[0]['messages'][0]['content']);
        $this->assertStringContainsString('No knowledge excerpt stood out', $requests[0]['messages'][3]['content']);
    }

    public function test_a_knowledge_base_over_the_limit_is_searched(): void
    {
        config()->set('chatbot.v2_full_context_max_tokens', 10);
        [$bot, $workspaceId] = $this->bot();
        $this->fake([1.0, 0.0, 0.0], [
            $this->reply('Open Settings, choose Mobile Data and scan the QR code.', 'answer', [1], ['choose Mobile Data and scan the QR code']),
            ['supported' => true, 'unsupported' => ''],
        ]);

        app(ChatbotRunner::class)->runForApi($bot, 'How do I install the eSIM?', $workspaceId);

        $this->assertArrayNotHasKey('full_context', AiKbRetrievalDiagnostic::sole()->trace);
        $this->assertStringContainsString('found by searching', $this->chatRequests()[0]['messages'][1]['content']);
    }

    public function test_the_engine_command_moves_a_bot(): void
    {
        [$bot] = $this->bot();
        $bot->update(['engine' => 'v1']);

        $this->assertSame(0, Artisan::call('ai:engine', ['bot' => $bot->id, 'engine' => 'v2']));
        $this->assertSame('v2', $bot->fresh()->engine);
        $this->assertSame(1, Artisan::call('ai:engine', ['bot' => $bot->id, 'engine' => 'v3']));
    }

    /** @return array{0:AiChatbot,1:int} */
    private function bot(): array
    {
        $workspaceId = $this->createWorkspaceContext()['workspace']->id;
        $kb = AiKnowledgeBase::create([
            'workspace_id' => $workspaceId, 'name' => 'Travel eSIM knowledge', 'brand' => 'Telzen',
            'purpose' => 'Sells travel eSIM data plans for international trips', 'audience' => 'Travellers',
            'embedding_model' => 'text-embedding-3-small', 'dimensions' => 3, 'status' => 'active',
        ]);
        $document = AiKbDocument::create([
            'kb_id' => $kb->id, 'title' => 'Installing an eSIM', 'source_type' => 'file', 'source_ref' => 'install.md',
            'status' => 'indexed', 'review_status' => 'approved', 'publication_status' => 'published',
        ]);
        $chunk = AiKbChunk::create([
            'kb_id' => $kb->id, 'document_id' => $document->id, 'ord' => 0, 'content' => self::PASSAGE,
            'tokens' => 24, 'index_generation' => 'legacy', 'embedding_status' => 'ready',
        ]);
        app(EmbeddingStore::class)->storeEmbedding($chunk, [1.0, 0.0, 0.0]);
        AiProviderConfig::create([
            'workspace_id' => $workspaceId, 'provider' => 'openai', 'credentials' => ['api_key' => 'sk-test'],
            'default_model_chat' => 'gpt-4o-mini', 'default_model_embed' => 'text-embedding-3-small',
            'enabled' => true, 'last_tested_at' => now(), 'last_test_succeeded_at' => now(),
        ]);
        $bot = AiChatbot::create([
            'workspace_id' => $workspaceId, 'name' => 'Support Bot', 'ai_kb_id' => $kb->id,
            'answer_scope' => 'business_only', 'engine' => 'v2', 'enabled' => true,
        ]);

        return [$bot, $workspaceId];
    }

    /**
     * @param  list<int>  $sources
     * @param  list<string>  $evidence
     * @param  list<string>  $choices
     * @return array<string,mixed>
     */
    private function reply(string $text, string $kind, array $sources, array $evidence, array $choices = []): array
    {
        return [
            'reply' => $text, 'answer_kind' => $kind, 'used_sources' => $sources, 'evidence' => $evidence,
            'quick_replies' => $choices, 'grounded' => $kind !== 'guidance', 'show_video' => false, 'language' => 'en',
        ];
    }

    /**
     * @param  array<int,float>  $embedding
     * @param  list<array<string,mixed>>  $chats
     */
    private function fake(array $embedding, array $chats): void
    {
        $sequence = Http::sequence();
        foreach ($chats as $chat) {
            $sequence->push([
                'choices' => [['message' => ['content' => json_encode($chat)], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 50, 'completion_tokens' => 20],
                'model' => 'gpt-4o-mini',
            ]);
        }
        Http::fake([
            'api.openai.com/v1/embeddings' => Http::response(['data' => [['embedding' => $embedding]]]),
            'api.openai.com/v1/chat/completions' => $sequence,
        ]);
    }

    /** @return list<array<string,mixed>> */
    private function chatRequests(): array
    {
        return collect(Http::recorded())->map(fn ($pair) => $pair[0])
            ->filter(fn (Request $request) => str_contains($request->url(), '/chat/completions'))
            ->map(fn (Request $request) => $request->data())->values()->all();
    }

    /** @param array<string,mixed> $request */
    private function ladderIn(array $request): string
    {
        preg_match('/- (Answer from the knowledge excerpts[^\n]*)/', (string) $request['messages'][0]['content'], $match);

        return $match[1] ?? '';
    }
}
