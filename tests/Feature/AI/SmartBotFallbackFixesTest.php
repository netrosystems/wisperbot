<?php

namespace Tests\Feature\AI;

use App\Modules\AI\Models\AiChatbot;
use App\Modules\AI\Models\AiKbChunk;
use App\Modules\AI\Models\AiKbDocument;
use App\Modules\AI\Models\AiKbRetrievalDiagnostic;
use App\Modules\AI\Models\AiKnowledgeBase;
use App\Modules\AI\Models\AiProviderConfig;
use App\Modules\AI\Services\ChatbotRunner;
use App\Modules\AI\Services\EmbeddingStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Phase 0 fixes for replies that fell back although a useful, safe answer
 * was possible.
 */
class SmartBotFallbackFixesTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_declined_business_question_gets_one_guidance_reply_on_a_balanced_bot(): void
    {
        $workspaceId = $this->createWorkspaceContext()['workspace']->id;
        $bot = $this->bot($workspaceId, 'business_only');
        $this->fakeChat([
            ['reply' => '', 'quick_replies' => [], 'grounded' => false, 'response_type' => 'answer', 'show_video' => false],
            ['reply' => 'Tell me which country you are travelling to and I can point you to the right eSIM option.', 'quick_replies' => [], 'grounded' => false, 'response_type' => 'answer', 'show_video' => false],
        ]);

        $result = app(ChatbotRunner::class)->runForApi($bot, 'Which eSIM should I buy?', $workspaceId);

        $this->assertSame('business_guidance', $result['answer_origin']);
        $this->assertStringContainsString('which country', $result['reply']);
        $this->assertSame('answered_guidance', AiKbRetrievalDiagnostic::sole()->reason_code);
        $guidancePrompt = collect(Http::recorded())->map(fn ($pair) => $pair[0])
            ->filter(fn (Request $request) => str_contains($request->url(), '/chat/completions'))
            ->last()['messages'][0]['content'];
        $this->assertStringContainsString('Business guidance scope', $guidancePrompt);
    }

    public function test_a_guidance_reply_with_an_invented_figure_still_falls_back(): void
    {
        $workspaceId = $this->createWorkspaceContext()['workspace']->id;
        $bot = $this->bot($workspaceId, 'business_only');
        $this->fakeChat([
            ['reply' => '', 'quick_replies' => [], 'grounded' => false, 'response_type' => 'answer', 'show_video' => false],
            ['reply' => 'Our 20GB plan costs $15.', 'quick_replies' => [], 'grounded' => false, 'response_type' => 'answer', 'show_video' => false],
        ]);

        $result = app(ChatbotRunner::class)->runForApi($bot, 'Which eSIM should I buy?', $workspaceId);

        $this->assertSame('fallback', $result['answer_origin']);
        $this->assertSame('declined_empty', AiKbRetrievalDiagnostic::sole()->reason_code);
    }

    public function test_a_strict_bot_never_gets_a_guidance_reply(): void
    {
        $workspaceId = $this->createWorkspaceContext()['workspace']->id;
        $bot = $this->bot($workspaceId, 'verified_only');
        $this->fakeChat([
            ['reply' => '', 'quick_replies' => [], 'grounded' => false, 'response_type' => 'answer', 'show_video' => false],
        ]);

        $result = app(ChatbotRunner::class)->runForApi($bot, 'Which eSIM should I buy?', $workspaceId);

        $this->assertSame('fallback', $result['answer_origin']);
        Http::assertSentCount(2); // the query embedding and one chat call
    }

    /** @return array<string,array{0:string,1:string}> */
    public static function equivalentFigures(): array
    {
        return [
            '24/7 against hours' => ['Support is available 24/7.', 'Our team answers 24 hours a day, every day.'],
            'Arabic-Indic digits' => ['التوصيل ١٥٠ تاكا', 'Delivery costs 150 taka.'],
            'South Asian grouping' => ['দাম ১,৫০,০০০ টাকা', 'Price: 150000 BDT'],
            'percent in words' => ['You get 10% off.', 'Members get 10 percent off.'],
            'hrs abbreviation' => ['Refunds arrive within 48 hrs.', 'Refunds arrive within 48 hours.'],
        ];
    }

    #[DataProvider('equivalentFigures')]
    public function test_the_figure_check_accepts_the_same_value_written_differently(string $reply, string $evidence): void
    {
        $this->assertFalse($this->runnerMethod('hasUnsupportedFigures', $reply, $evidence));
    }

    public function test_the_figure_check_still_rejects_an_invented_figure(): void
    {
        $this->assertTrue($this->runnerMethod('hasUnsupportedFigures', 'Support is available 24/7.', 'Our team answers from 9am to 6pm.'));
        $this->assertTrue($this->runnerMethod('hasUnsupportedFigures', 'It costs ৳২০০.', 'Delivery costs 150 taka.'));
    }

    public function test_a_follow_up_search_leaves_out_the_bots_fallback_reply(): void
    {
        $bot = new AiChatbot(['fallback_reply' => 'Sorry, I could not find that. A person will help you.']);
        $history = [
            ['role' => 'user', 'content' => 'Do you sell eSIMs for Japan?'],
            ['role' => 'assistant', 'content' => 'Sorry, I could not find that. A person will help you.'],
            ['role' => 'user', 'content' => 'and Korea?'],
            ['role' => 'assistant', 'content' => 'I can help with questions about this business.', 'answer_origin' => 'fallback'],
        ];

        $query = $this->runnerMethod('retrievalQuestion', $bot, 'price?', $history);

        $this->assertStringContainsString('Japan', $query);
        $this->assertStringContainsString('Korea', $query);
        $this->assertStringNotContainsString('Sorry', $query);
        $this->assertStringNotContainsString('I can help', $query);
    }

    private function runnerMethod(string $method, mixed ...$arguments): mixed
    {
        return (fn () => $this->{$method}(...$arguments))->call(app(ChatbotRunner::class));
    }

    private function bot(int $workspaceId, string $scope): AiChatbot
    {
        $kb = AiKnowledgeBase::create([
            'workspace_id' => $workspaceId,
            'name' => 'Travel eSIM knowledge',
            'brand' => 'Telzen',
            'purpose' => 'Sells travel eSIM data plans for international trips',
            'audience' => 'Travellers',
            'embedding_model' => 'text-embedding-3-small',
            'dimensions' => 3,
            'status' => 'active',
        ]);
        $document = AiKbDocument::create([
            'kb_id' => $kb->id, 'title' => 'Installing an eSIM', 'source_type' => 'file', 'source_ref' => 'install.md',
            'status' => 'indexed', 'review_status' => 'approved', 'publication_status' => 'published',
        ]);
        $chunk = AiKbChunk::create([
            'kb_id' => $kb->id, 'document_id' => $document->id, 'ord' => 0,
            'content' => 'To install an eSIM, open Settings, choose Mobile Data and scan the QR code.',
            'tokens' => 16, 'index_generation' => 'legacy', 'embedding_status' => 'ready',
        ]);
        app(EmbeddingStore::class)->storeEmbedding($chunk, [1.0, 0.0, 0.0]);
        AiProviderConfig::create([
            'workspace_id' => $workspaceId, 'provider' => 'openai', 'credentials' => ['api_key' => 'sk-test'],
            'default_model_chat' => 'gpt-4o-mini', 'default_model_embed' => 'text-embedding-3-small',
            'enabled' => true, 'last_tested_at' => now(), 'last_test_succeeded_at' => now(),
        ]);

        return AiChatbot::create([
            'workspace_id' => $workspaceId, 'name' => 'Support Bot', 'ai_kb_id' => $kb->id,
            'answer_scope' => $scope, 'unsupported_answer_action' => 'clarify_then_handoff', 'enabled' => true,
        ]);
    }

    /** @param list<array<string,mixed>> $replies */
    private function fakeChat(array $replies): void
    {
        $sequence = Http::sequence();
        foreach ($replies as $reply) {
            $sequence->push([
                'choices' => [['message' => ['content' => json_encode($reply)], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 50, 'completion_tokens' => 20],
                'model' => 'gpt-4o-mini',
            ]);
        }
        Http::fake([
            'api.openai.com/v1/embeddings' => Http::response(['data' => [['embedding' => [1.0, 0.0, 0.0]]]]),
            'api.openai.com/v1/chat/completions' => $sequence,
        ]);
    }
}
