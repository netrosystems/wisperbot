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
                    'choices' => [['message' => ['content' => json_encode([
                        'reply' => 'Our refund policy is 30 days.',
                        'quick_replies' => [],
                        'grounded' => true,
                    ])]]],
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

    public function test_knowledge_only_bot_bypasses_an_unrelated_question_without_calling_chat(): void
    {
        $data = $this->createWorkspaceContext();
        $workspace = $data['workspace'];
        [$chatbot, $message] = $this->botWithKnowledge(
            $workspace->id,
            'clarify_then_handoff',
            'Barack Obama was the best president ever.',
        );

        Http::fake([
            'api.openai.com/v1/embeddings' => Http::response([
                'data' => [['embedding' => [0.0, 1.0, 0.0]]],
            ]),
            'api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'A political opinion from general knowledge.']]],
            ]),
        ]);

        $result = app(ChatbotRunner::class)->run($chatbot, $message);

        $this->assertSame(0, $result['tokens_used']);
        $this->assertStringContainsString('this business', $result['reply']);
        $this->assertStringNotContainsString('president', strtolower($result['reply']));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/chat/completions'));
    }

    public function test_general_answer_setting_allows_safe_questions_outside_the_knowledge_base(): void
    {
        $data = $this->createWorkspaceContext();
        $workspace = $data['workspace'];
        [$chatbot, $message] = $this->botWithKnowledge(
            $workspace->id,
            'general',
            'What is the capital of France?',
        );

        Http::fake([
            'api.openai.com/v1/embeddings' => Http::response([
                'data' => [['embedding' => [0.0, 1.0, 0.0]]],
            ]),
            'api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'Paris is the capital of France.']]],
                'usage' => ['prompt_tokens' => 20, 'completion_tokens' => 7],
                'model' => 'gpt-4o-mini',
            ]),
        ]);

        $result = app(ChatbotRunner::class)->run($chatbot, $message);

        $this->assertStringContainsString('Paris', $result['reply']);
        $this->assertSame(27, $result['tokens_used']);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/chat/completions'));
    }

    public function test_knowledge_only_bot_rejects_a_provider_answer_not_marked_as_grounded(): void
    {
        $data = $this->createWorkspaceContext();
        $workspace = $data['workspace'];
        [$chatbot, $message] = $this->botWithKnowledge(
            $workspace->id,
            'handoff',
            'Tell me whether this unrelated opinion is correct.',
        );

        Http::fake([
            'api.openai.com/v1/embeddings' => Http::response([
                // Simulate an overly broad semantic match so the second guard is exercised.
                'data' => [['embedding' => [1.0, 0.0, 0.0]]],
            ]),
            'api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => json_encode([
                    'reply' => '',
                    'quick_replies' => [],
                    'grounded' => false,
                ])]]],
                'usage' => ['prompt_tokens' => 30, 'completion_tokens' => 5],
                'model' => 'gpt-4o-mini',
            ]),
        ]);

        $result = app(ChatbotRunner::class)->run($chatbot, $message);

        $this->assertSame(0, $result['tokens_used']);
        $this->assertStringContainsString('verified answer', $result['reply']);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/chat/completions'));
    }

    /** @return array{AiChatbot, Message} */
    private function botWithKnowledge(int $workspaceId, string $unsupportedAction, string $question): array
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
        ]);
        $chunk = AiKbChunk::create([
            'kb_id' => $kb->id,
            'document_id' => $document->id,
            'ord' => 0,
            'content' => 'Customers may return unopened products within 30 days of delivery.',
            'tokens' => 10,
        ]);
        app(EmbeddingStore::class)->storeEmbedding($chunk, [1.0, 0.0, 0.0]);

        $chatbot = AiChatbot::create([
            'workspace_id' => $workspaceId,
            'name' => 'Support Bot',
            'ai_kb_id' => $kb->id,
            'retrieval_match_threshold' => 0.60,
            'unsupported_answer_action' => $unsupportedAction,
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

        return [$chatbot, $message];
    }
}
