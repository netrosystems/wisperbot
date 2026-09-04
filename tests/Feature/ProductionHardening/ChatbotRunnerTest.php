<?php

namespace Tests\Feature\ProductionHardening;

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
use Tests\TestCase;

/**
 * ChatbotRunner end-to-end test with Http::fake for OpenAI.
 * Verifies that context chunks from the KB are included in the prompt.
 */
class ChatbotRunnerTest extends TestCase
{
    use RefreshDatabase;

    public function test_chatbot_runner_includes_kb_context_in_prompt(): void
    {
        $data = $this->createWorkspaceContext();
        $workspace = $data['workspace'];

        // Seed: KB + document + chunk with a known embedding
        $kb = AiKnowledgeBase::create([
            'workspace_id' => $workspace->id,
            'name' => 'Test KB',
            'embedding_model' => 'text-embedding-3-small',
            'dimensions' => 3,
            'status' => 'active',
        ]);
        $doc = AiKbDocument::create([
            'kb_id' => $kb->id,
            'title' => 'Refund walkthrough',
            'source_type' => 'file',
            'source_ref' => 'kb-docs/refund-guide.md',
            'resource_json' => [
                'version' => 1,
                'kind' => 'video_collection',
                'videos' => [[
                    'version' => 1,
                    'kind' => 'video',
                    'provider' => 'youtube',
                    'video_id' => 'dQw4w9WgXcQ',
                    'title' => 'Refund walkthrough',
                    'canonical_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
                    'playback_url' => 'https://www.youtube.com/embed/dQw4w9WgXcQ',
                ]],
            ],
            'status' => 'indexed',
        ]);
        $chunk = AiKbChunk::create([
            'kb_id' => $kb->id,
            'document_id' => $doc->id,
            'ord' => 0,
            'content' => 'Our refund policy is 30 days. Watch https://youtu.be/dQw4w9WgXcQ for the walkthrough.',
            'tokens' => 8,
            'embedding' => null,
        ]);

        // Manually store a small embedding that matches anything
        $embeddingStore = app(EmbeddingStore::class);
        $embeddingStore->storeEmbedding($chunk, [0.1, 0.2, 0.3]);

        $chatbot = AiChatbot::create([
            'workspace_id' => $workspace->id,
            'name' => 'Support Bot',
            'ai_kb_id' => $kb->id,
            'system_prompt' => 'You are a helpful assistant.',
            'max_context_chunks' => 3,
            'enabled' => true,
            'channels' => ['whatsapp'],
        ]);

        $contact = Contact::factory()->create(['workspace_id' => $workspace->id]);
        $conv = Conversation::create([
            'workspace_id' => $workspace->id,
            'contact_id' => $contact->id,
            'status' => 'open',
        ]);
        $message = new Message;
        $message->body = 'What is your refund policy?';
        $message->direction = 'in';
        $message->channel = 'playground';
        $message->setRelation('conversation', $conv);

        $capturedSystemPrompt = null;
        $capturedMaxTokens = null;

        // Fake both embedding and chat OpenAI calls using URL-keyed closures
        Http::fake([
            'api.openai.com/v1/embeddings' => Http::response([
                'data' => [['embedding' => [0.1, 0.2, 0.3]]],
            ], 200),
            'api.openai.com/v1/chat/completions' => function ($request) use (&$capturedSystemPrompt, &$capturedMaxTokens) {
                $body = json_decode($request->body(), true);
                $capturedSystemPrompt = collect($body['messages'] ?? [])->firstWhere('role', 'system')['content'] ?? '';
                $capturedMaxTokens = $body['max_tokens'] ?? null;

                return Http::response([
                    'choices' => [['message' => ['content' => 'Our refund policy is 30 days.']]],
                    'usage' => ['prompt_tokens' => 50, 'completion_tokens' => 20],
                    'model' => 'gpt-4o-mini',
                ], 200);
            },
        ]);

        // Add LLM workspace credential via AiProviderConfig
        AiProviderConfig::create([
            'workspace_id' => $workspace->id,
            'provider' => 'openai',
            'credentials' => ['api_key' => 'sk-test'],
            'default_model_chat' => 'gpt-4o-mini',
            'default_model_embed' => 'text-embedding-3-small',
            'enabled' => true,
            'last_tested_at' => now(),
            'last_test_succeeded_at' => now(),
        ]);

        $runner = app(ChatbotRunner::class);
        $result = $runner->run($chatbot, $message);
        $reply = $result['reply'];

        $this->assertNotNull($reply, 'ChatbotRunner should return a reply');
        $this->assertStringContainsString('refund', strtolower($reply));
        $this->assertCount(1, $result['resources']);
        $this->assertSame('youtube', $result['resources'][0]['provider']);
        // Assert that context chunks were included in the system prompt sent to OpenAI
        $this->assertNotNull($capturedSystemPrompt, 'System prompt should have been captured');
        $this->assertStringContainsString('refund policy is 30 days', $capturedSystemPrompt);
        $this->assertStringContainsString('at most 60 words', $capturedSystemPrompt);
        $this->assertStringContainsString('Reply in the customer\'s language', $capturedSystemPrompt);
        $this->assertStringContainsString('Never substitute general knowledge for business facts', $capturedSystemPrompt);
        $this->assertStringContainsString('Markdown link', $capturedSystemPrompt);
        $this->assertSame(160, $capturedMaxTokens);
    }
}
