<?php

namespace Tests\Feature\AI;

use App\Modules\AI\Models\AiChatbot;
use App\Modules\AI\Models\AiKbChunk;
use App\Modules\AI\Models\AiKbDocument;
use App\Modules\AI\Models\AiKnowledgeBase;
use App\Modules\AI\Models\AiProviderConfig;
use App\Modules\AI\Services\ChatbotRunner;
use App\Modules\AI\Services\EmbeddingStore;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * A question in another language (including romanized text) must still find
 * an English Knowledge Base, while English questions pay for no extra call.
 */
class CrossLanguageRetrievalTest extends TestCase
{
    use RefreshDatabase;

    private int $translationCalls = 0;

    private ?string $answerPrompt = null;

    #[DataProvider('retrievalModes')]
    public function test_romanized_question_finds_the_english_knowledge_base(bool $hybrid): void
    {
        $result = $this->ask('install kivabe korbo esim', $hybrid);

        $this->assertSame(1, $this->translationCalls);
        $this->assertSame('knowledge_base', $result['answer_origin']);
        $this->assertStringContainsString('open My eSIM and tap Install eSIM', (string) $this->answerPrompt);
    }

    #[DataProvider('retrievalModes')]
    public function test_english_question_needs_no_translation(bool $hybrid): void
    {
        $result = $this->ask('how to install esim', $hybrid);

        $this->assertSame(0, $this->translationCalls);
        $this->assertSame('knowledge_base', $result['answer_origin']);
    }

    public static function retrievalModes(): array
    {
        return ['hybrid' => [true], 'legacy' => [false]];
    }

    private function ask(string $question, bool $hybrid): array
    {
        config()->set('knowledge_base.hybrid_retrieval_enabled', $hybrid);
        $workspaceId = $this->createWorkspaceContext()['workspace']->id;
        $kb = AiKnowledgeBase::create([
            'workspace_id' => $workspaceId,
            'name' => 'Support KB',
            'brand' => 'Northwind',
            'embedding_model' => 'text-embedding-3-small',
            'dimensions' => 3,
            'status' => 'active',
        ]);
        $document = AiKbDocument::create([
            'kb_id' => $kb->id,
            'title' => 'Install guide',
            'source_type' => 'file',
            'source_ref' => 'kb-docs/install.md',
            'status' => 'indexed',
            'review_status' => 'approved',
            'publication_status' => 'published',
        ]);
        $chunk = AiKbChunk::create([
            'kb_id' => $kb->id,
            'document_id' => $document->id,
            'ord' => 0,
            'content' => 'How to install eSIM: open My eSIM and tap Install eSIM.',
            'tokens' => 12,
            'index_generation' => 'legacy',
            'embedding_status' => 'ready',
        ]);
        app(EmbeddingStore::class)->storeEmbedding($chunk, [1.0, 0.0, 0.0]);
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
        $bot = AiChatbot::create(['workspace_id' => $workspaceId, 'name' => 'Support Bot', 'ai_kb_id' => $kb->id, 'enabled' => true, 'channels' => ['webchat']]);

        Http::fake([
            // English text sits next to the passage; romanized text does not.
            'api.openai.com/v1/embeddings' => function ($request) {
                $inputs = (array) (json_decode($request->body(), true)['input'] ?? []);

                return Http::response(['data' => array_map(fn ($text): array => [
                    'embedding' => preg_match('/\b(how|install)\b/i', (string) $text) && ! preg_match('/kivabe/i', (string) $text) ? [1.0, 0.0, 0.0] : [0.0, 1.0, 0.0],
                ], $inputs)], 200);
            },
            'api.openai.com/v1/chat/completions' => function ($request) {
                $system = collect(json_decode($request->body(), true)['messages'] ?? [])->firstWhere('role', 'system')['content'] ?? '';
                if (str_starts_with($system, 'Translate the customer message')) {
                    $this->translationCalls++;
                    $content = json_encode(['query' => 'how to install esim']);
                } else {
                    $this->answerPrompt = $system;
                    $content = json_encode(['reply' => 'Open My eSIM and tap Install eSIM.', 'quick_replies' => [], 'grounded' => true]);
                }

                return Http::response([
                    'choices' => [['message' => ['content' => $content]]],
                    'usage' => ['prompt_tokens' => 20, 'completion_tokens' => 10],
                    'model' => 'gpt-4o-mini',
                ], 200);
            },
        ]);

        $contact = Contact::factory()->create(['workspace_id' => $workspaceId]);
        $message = new Message;
        $message->body = $question;
        $message->direction = 'in';
        $message->channel = 'playground';
        $message->setRelation('conversation', Conversation::create(['workspace_id' => $workspaceId, 'contact_id' => $contact->id, 'status' => 'open']));

        return app(ChatbotRunner::class)->run($bot, $message);
    }
}
