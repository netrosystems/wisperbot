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
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * "Is the bot getting better?" needs every turn to say why it ended, and a
 * report that reads those records back.
 */
class SmartBotMeasurementTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_answered_turn_records_its_reason_model_and_finish_reason(): void
    {
        $workspaceId = $this->createWorkspaceContext()['workspace']->id;
        [$bot, $message] = $this->bot($workspaceId, 'Can I return a product?');
        $this->fakeOpenAi([1.0, 0.0, 0.0], $this->reply('Unopened products can be returned within 30 days.'));

        app(ChatbotRunner::class)->run($bot, $message);

        $row = AiKbRetrievalDiagnostic::sole();
        $this->assertSame('answer', $row->decision);
        $this->assertSame('answered', $row->reason_code);
        $this->assertSame('playground', $row->channel);
        $this->assertSame('gpt-4o-mini', $row->model);
        $this->assertSame('stop', $row->finish_reason);
        $this->assertSame('charged_once', $row->credit_result);
        $this->assertSame(1, $row->passages_used);
    }

    public function test_a_fallback_before_the_model_is_recorded_with_its_best_score(): void
    {
        $workspaceId = $this->createWorkspaceContext()['workspace']->id;
        [$bot, $message] = $this->bot($workspaceId, 'Barack Obama was the best president ever.');
        $this->fakeOpenAi([0.65, 0.76, 0.0], $this->reply('unused'));

        app(ChatbotRunner::class)->run($bot, $message);

        $row = AiKbRetrievalDiagnostic::sole();
        $this->assertSame('handoff', $row->decision);
        $this->assertSame('no_context', $row->reason_code);
        $this->assertSame('not_charged', $row->credit_result);
        $this->assertEqualsWithDelta(0.507, $row->best_score, 0.01);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/chat/completions'));
    }

    public function test_an_empty_cut_off_reply_is_recorded_as_refunded(): void
    {
        $workspaceId = $this->createWorkspaceContext()['workspace']->id;
        [$bot] = $this->bot($workspaceId, 'unused');
        $this->fakeOpenAi([1.0, 0.0, 0.0], ['content' => '', 'finish_reason' => 'length']);

        $result = app(ChatbotRunner::class)->runForApi($bot, 'Can I return a product?', $workspaceId);

        $this->assertSame('fallback', $result['answer_origin']);
        $row = AiKbRetrievalDiagnostic::sole();
        $this->assertSame('fallback', $row->decision);
        $this->assertSame('empty_reply', $row->reason_code);
        $this->assertSame('length', $row->finish_reason);
        $this->assertSame('refunded', $row->credit_result);
        $this->assertSame('api', $row->channel);
    }

    public function test_a_bot_without_a_knowledge_base_is_measured_too(): void
    {
        $workspaceId = $this->createWorkspaceContext()['workspace']->id;
        [$bot] = $this->bot($workspaceId, 'unused');
        $bot->update(['ai_kb_id' => null]);
        $this->fakeOpenAi([1.0, 0.0, 0.0], $this->reply('Hello! How can I help?'));

        app(ChatbotRunner::class)->runForApi($bot->fresh(), 'What can you do for me?', $workspaceId);

        $row = AiKbRetrievalDiagnostic::sole();
        $this->assertNull($row->kb_id);
        $this->assertSame('answered', $row->reason_code);
    }

    public function test_the_report_reads_the_turns_back(): void
    {
        $workspaceId = $this->createWorkspaceContext()['workspace']->id;
        [$bot, $message] = $this->bot($workspaceId, 'Barack Obama was the best president ever.');
        $this->fakeOpenAi([0.65, 0.76, 0.0], $this->reply('unused'));
        app(ChatbotRunner::class)->run($bot, $message);
        AiKbRetrievalDiagnostic::create([
            'workspace_id' => $workspaceId, 'kb_id' => $bot->ai_kb_id, 'chatbot_id' => $bot->id,
            'decision' => 'answer', 'reason_code' => 'answered', 'channel' => 'webchat',
            'model' => 'gpt-4o-mini', 'finish_reason' => 'stop', 'latency_ms' => 900,
        ]);

        Artisan::call('ai:smart-bot-report', ['--json' => true, '--workspace' => $workspaceId]);
        $report = json_decode(Artisan::output(), true);

        $this->assertSame(2, $report['turns']['total']);
        $this->assertSame(1, $report['turns']['answered']);
        $this->assertEquals(50, $report['turns']['fallback_rate']);
        $this->assertSame(1, $report['reasons']['no_context']);
        $this->assertSame(1, $report['reasons']['answered']);
        $this->assertSame(1, $report['no_context_best_scores']['between_0.45_and_threshold']);
        $this->assertSame(900, $report['latency_ms']['p50']);
        $this->assertFalse($report['settings']['hybrid_retrieval']);
    }

    /** @return array{0:AiChatbot,1:Message} */
    private function bot(int $workspaceId, string $question): array
    {
        $kb = AiKnowledgeBase::create([
            'workspace_id' => $workspaceId,
            'name' => 'Business knowledge',
            'embedding_model' => 'text-embedding-3-small',
            'dimensions' => 3,
            'status' => 'active',
        ]);
        $document = AiKbDocument::create([
            'kb_id' => $kb->id,
            'title' => 'Returns guide',
            'source_type' => 'file',
            'source_ref' => 'returns.md',
            'status' => 'indexed',
            'review_status' => 'approved',
            'publication_status' => 'published',
        ]);
        $chunk = AiKbChunk::create([
            'kb_id' => $kb->id,
            'document_id' => $document->id,
            'ord' => 0,
            'content' => 'Customers may return unopened products within 30 days of delivery.',
            'tokens' => 10,
            'index_generation' => 'legacy',
            'embedding_status' => 'ready',
        ]);
        app(EmbeddingStore::class)->storeEmbedding($chunk, [1.0, 0.0, 0.0]);

        $bot = AiChatbot::create([
            'workspace_id' => $workspaceId,
            'name' => 'Support Bot',
            'ai_kb_id' => $kb->id,
            'unsupported_answer_action' => 'clarify_then_handoff',
            'enabled' => true,
        ]);
        AiProviderConfig::create([
            'workspace_id' => $workspaceId,
            'provider' => 'openai',
            'credentials' => ['api_key' => 'sk-test'],
            'default_model_chat' => 'gpt-4o-mini',
            'default_model_embed' => 'text-embedding-3-small',
            'enabled' => true,
            'last_tested_at' => now(),
            'last_test_succeeded_at' => now(),
        ]);

        $contact = Contact::factory()->create(['workspace_id' => $workspaceId]);
        $conversation = Conversation::create([
            'workspace_id' => $workspaceId,
            'contact_id' => $contact->id,
            'status' => 'open',
        ]);
        $message = new Message;
        $message->body = $question;
        $message->direction = 'in';
        $message->channel = 'playground';
        $message->setRelation('conversation', $conversation);

        return [$bot, $message];
    }

    /** @return array{content:string,finish_reason:string} */
    private function reply(string $text): array
    {
        return ['content' => (string) json_encode([
            'reply' => $text,
            'quick_replies' => [],
            'grounded' => true,
            'response_type' => 'answer',
            'show_video' => false,
        ]), 'finish_reason' => 'stop'];
    }

    /**
     * @param  array<int,float>  $embedding
     * @param  array{content:string,finish_reason:string}  $chat
     */
    private function fakeOpenAi(array $embedding, array $chat): void
    {
        Http::fake([
            'api.openai.com/v1/embeddings' => Http::response(['data' => [['embedding' => $embedding]]]),
            'api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => $chat['content']], 'finish_reason' => $chat['finish_reason']]],
                'usage' => ['prompt_tokens' => 50, 'completion_tokens' => 20],
                'model' => 'gpt-4o-mini',
            ]),
        ]);
    }
}
