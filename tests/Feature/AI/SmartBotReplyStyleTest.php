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
use Tests\TestCase;

/**
 * Facts stay grounded while wording, follow-up questions and video
 * presentation behave like a support agent rather than a copy of the script.
 */
class SmartBotReplyStyleTest extends TestCase
{
    use RefreshDatabase;

    private const SCRIPT = 'Customer: How do I buy a plan? AI: Which country are you travelling to? [Shows country CTAs] AI: Plans start at 5 USD. Open Plans > Search country > Select package > Pay. Here is a video tutorial: https://youtu.be/dQw4w9WgXcQ ***';

    private ?string $systemPrompt = null;

    private ?float $temperature = null;

    private int $chatCalls = 0;

    private ?array $responseFormat = null;

    public function test_follow_up_question_marked_ungrounded_is_sent_instead_of_a_handoff(): void
    {
        $result = $this->answer(['reply' => 'Which country are you travelling to?', 'quick_replies' => ['Japan', 'Thailand'], 'grounded' => false]);

        $this->assertSame('Which country are you travelling to?', $result['display_body']);
        $this->assertSame(['Japan', 'Thailand'], array_column($result['quick_replies'], 'label'));
        $this->assertSame('knowledge_base', $result['answer_origin']);
    }

    public function test_ungrounded_statement_is_still_rejected(): void
    {
        $result = $this->answer(['reply' => 'Northwind is the cheapest provider in the world.', 'quick_replies' => [], 'grounded' => false]);

        $this->assertStringNotContainsString('cheapest', (string) $result['reply']);
        $this->assertNotSame('knowledge_base', $result['answer_origin']);
    }

    public function test_clarification_question_is_accepted_even_when_marked_ungrounded(): void
    {
        $validate = new \ReflectionMethod(ChatbotRunner::class, 'validChatResponse');
        $runner = app(ChatbotRunner::class);

        $this->assertTrue($validate->invoke($runner, '{"reply":"Which country are you travelling to?","quick_replies":["Japan","Thailand"],"grounded":false}', true, 'clarification'));
        $this->assertFalse($validate->invoke($runner, '{"reply":"Plans start at 5 USD. Which country?","quick_replies":[],"grounded":false}', true, 'clarification'));
        $this->assertFalse($validate->invoke($runner, '{"reply":"","quick_replies":[],"grounded":false}', true, 'answer'));
    }

    public function test_clarification_mode_may_answer_when_the_passages_clearly_answer(): void
    {
        $validate = new \ReflectionMethod(ChatbotRunner::class, 'validChatResponse');
        $runner = app(ChatbotRunner::class);

        $this->assertTrue($validate->invoke($runner, '{"reply":"Plans start at 5 USD. Would you like help buying one?","quick_replies":["Yes","No"],"grounded":true,"response_type":"answer"}', true, 'clarification'));
        $this->assertTrue($validate->invoke($runner, '{"reply":"Plans start at 5 USD and you buy them in the app.","quick_replies":[],"grounded":true}', true, 'clarification'));
        $this->assertFalse($validate->invoke($runner, '{"reply":"Plans start at 5 USD.","quick_replies":[],"response_type":"answer"}', true, 'clarification'));
        $this->assertTrue($validate->invoke($runner, '{"reply":"If data is off, check the eSIM first. Is it installed?","quick_replies":["Yes","No"],"grounded":true,"response_type":"clarification"}', true, 'clarification'));
        $this->assertFalse($validate->invoke($runner, '{"reply":"If data is off, check the eSIM first. Is it installed?","quick_replies":["Yes","No"],"grounded":false,"response_type":"clarification"}', true, 'clarification'));
        $this->assertFalse($validate->invoke($runner, '{"reply":"Plans start at 5 USD.","quick_replies":[],"grounded":false,"response_type":"answer"}', true, 'clarification'));
    }

    public function test_grounded_answer_with_figures_from_the_passage_is_sent(): void
    {
        $result = $this->answer(['reply' => 'Plans start at $5.00. Open Plans, search your country, pick a package and pay.', 'quick_replies' => [], 'grounded' => true]);

        $this->assertSame('knowledge_base', $result['answer_origin']);
        $this->assertStringContainsString('$5.00', $result['display_body']);
    }

    public function test_invented_figures_are_rejected_even_when_the_model_claims_grounding(): void
    {
        $result = $this->answer(['reply' => 'You can choose the 10GB plan for Japan.', 'quick_replies' => [], 'grounded' => true]);

        $this->assertNotNull($this->systemPrompt, 'The model must have been asked.');
        $this->assertStringNotContainsString('10GB', (string) $result['reply']);
        $this->assertNotSame('knowledge_base', $result['answer_origin']);
    }

    public function test_follow_up_question_with_invented_figure_choices_is_rejected(): void
    {
        $result = $this->answer(['reply' => 'Which plan size would you like?', 'quick_replies' => ['10GB plan', '20GB plan'], 'grounded' => true]);

        $this->assertNotNull($this->systemPrompt, 'The model must have been asked.');
        $this->assertStringNotContainsString('GB', json_encode($result['quick_replies'] ?? []));
        $this->assertNotSame('knowledge_base', $result['answer_origin']);
    }

    public function test_a_rejected_genuine_attempt_is_retried_once(): void
    {
        $result = $this->answer(
            ['reply' => 'You can choose the 10GB plan.', 'quick_replies' => [], 'grounded' => true],
            retryReply: ['reply' => 'Plans start at 5 USD. Open Plans and pick your country.', 'quick_replies' => [], 'grounded' => true],
        );

        $this->assertSame(2, $this->chatCalls);
        $this->assertSame('knowledge_base', $result['answer_origin']);
        $this->assertStringContainsString('5 USD', $result['display_body']);
    }

    public function test_a_deliberate_refusal_is_not_retried(): void
    {
        $this->answer(['reply' => '', 'quick_replies' => [], 'grounded' => false]);

        $this->assertSame(1, $this->chatCalls);
    }

    public function test_natural_wording_is_the_default_style(): void
    {
        $this->answer(['reply' => 'Open Plans, search your country, pick a package and pay.', 'quick_replies' => [], 'grounded' => true]);

        $prompt = (string) $this->systemPrompt;
        $this->assertStringContainsString('Write every reply in your own words', $prompt);
        $this->assertStringContainsString('show the intended facts and flow, not text to copy', $prompt);
        $this->assertStringContainsString('Never show editing notes or script markers', $prompt);
        $this->assertStringContainsString('Never paste video links', $prompt);
        $this->assertStringContainsString('if they write their language in Latin letters', $prompt);
        $this->assertStringContainsString('A question-only reply needs no evidence', $prompt);
        $this->assertStringNotContainsString('This business requires its approved wording', $prompt);
        $this->assertSame(0.4, $this->temperature);
    }

    public function test_replies_are_requested_with_a_strict_schema_so_no_decision_key_is_omitted(): void
    {
        $this->answer(['reply' => 'Open Plans and pay.', 'quick_replies' => [], 'grounded' => true, 'response_type' => 'answer', 'show_video' => false]);

        $this->assertSame('json_schema', $this->responseFormat['type'] ?? null);
        $this->assertTrue($this->responseFormat['json_schema']['strict']);
        $this->assertSame(['reply', 'quick_replies', 'grounded', 'response_type', 'show_video'], $this->responseFormat['json_schema']['schema']['required']);
    }

    public function test_exact_wording_bot_keeps_the_approved_wording(): void
    {
        $this->answer(['reply' => 'Open Plans > Search country > Select package > Pay.', 'quick_replies' => [], 'grounded' => true], exactWording: true);

        $prompt = (string) $this->systemPrompt;
        $this->assertStringContainsString('This business requires its approved wording', $prompt);
        $this->assertStringNotContainsString('Write every reply in your own words', $prompt);
        $this->assertStringContainsString('Never show editing notes or script markers', $prompt);
        $this->assertSame(0.2, $this->temperature);
    }

    public function test_pasted_link_of_the_matched_video_becomes_the_player(): void
    {
        $result = $this->answer(['reply' => 'Open Plans, search your country, pick a package and pay. Here is a video tutorial: https://youtu.be/dQw4w9WgXcQ', 'quick_replies' => [], 'grounded' => true]);

        $this->assertStringNotContainsString('youtu', $result['display_body']);
        $this->assertStringNotContainsString('youtu', $result['reply']);
        $this->assertStringEndsWith('Here is a video tutorial.', $result['display_body']);
        $this->assertCount(1, $result['resources']);
        $this->assertSame('dQw4w9WgXcQ', $result['resources'][0]['video_id']);
    }

    public function test_other_pasted_video_links_are_removed_without_a_player(): void
    {
        $result = $this->answer(['reply' => 'Open Plans and pay. [Watch](https://vimeo.com/123456789) or see https://example.com/setup.mp4', 'quick_replies' => [], 'grounded' => true]);

        $this->assertStringNotContainsString('vimeo', $result['display_body']);
        $this->assertStringNotContainsString('.mp4', $result['display_body']);
        $this->assertSame([], $result['resources']);
    }

    /**
     * @param  array<string,mixed>  $reply
     * @param  array<string,mixed>|null  $retryReply  returned from the second model call onwards
     */
    private function answer(array $reply, bool $exactWording = false, ?array $retryReply = null): array
    {
        config()->set('knowledge_base.hybrid_retrieval_enabled', true);
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
            'title' => 'Support script.docx',
            'source_type' => 'file',
            'source_ref' => 'kb-docs/script.docx',
            'status' => 'indexed',
            'review_status' => 'approved',
            'publication_status' => 'published',
            'resource_json' => ['version' => 1, 'kind' => 'video_collection', 'videos' => [[
                'version' => 1, 'kind' => 'video', 'provider' => 'youtube', 'video_id' => 'dQw4w9WgXcQ', 'title' => 'Buying a plan',
                'canonical_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'playback_url' => 'https://www.youtube.com/embed/dQw4w9WgXcQ',
            ]]],
        ]);
        $chunk = AiKbChunk::create([
            'kb_id' => $kb->id,
            'document_id' => $document->id,
            'ord' => 0,
            'content' => self::SCRIPT,
            'tokens' => 40,
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
        $bot = AiChatbot::create([
            'workspace_id' => $workspaceId,
            'name' => 'Support Bot',
            'ai_kb_id' => $kb->id,
            'enabled' => true,
            'channels' => ['webchat'],
            'kb_exact_wording' => $exactWording,
        ]);
        $contact = Contact::factory()->create(['workspace_id' => $workspaceId]);
        $message = new Message;
        $message->body = 'How do I buy a plan?';
        $message->direction = 'in';
        $message->channel = 'playground';
        $message->setRelation('conversation', Conversation::create(['workspace_id' => $workspaceId, 'contact_id' => $contact->id, 'status' => 'open']));

        Http::fake([
            'api.openai.com/v1/embeddings' => Http::response(['data' => [['embedding' => [1.0, 0.0, 0.0]]]], 200),
            'api.openai.com/v1/chat/completions' => function ($request) use ($reply, $retryReply) {
                $this->chatCalls++;
                $body = json_decode($request->body(), true);
                $reply = $this->chatCalls > 1 && $retryReply !== null ? $retryReply : $reply;
                $this->systemPrompt = collect($body['messages'] ?? [])->firstWhere('role', 'system')['content'] ?? '';
                $this->temperature = isset($body['temperature']) ? (float) $body['temperature'] : null;
                $this->responseFormat = $body['response_format'] ?? null;

                return Http::response([
                    'choices' => [['message' => ['content' => json_encode($reply)]]],
                    'usage' => ['prompt_tokens' => 50, 'completion_tokens' => 20],
                    'model' => 'gpt-4o-mini',
                ], 200);
            },
        ]);

        return app(ChatbotRunner::class)->run($bot, $message);
    }
}
