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

class SmartBotVideoTurnTest extends TestCase
{
    use RefreshDatabase;

    private ?string $systemPrompt = null;

    public function test_follow_up_question_does_not_show_the_video_even_if_the_model_asks_for_it(): void
    {
        $result = $this->answer(['reply' => 'Do you have an eSIM supported phone?', 'quick_replies' => ['Yes', 'No'], 'grounded' => true, 'show_video' => true]);

        $this->assertSame([], $result['resources']);
        $this->assertStringContainsString('Always include the key "show_video"', (string) $this->systemPrompt);
    }

    public function test_qualifying_question_with_conditional_steps_does_not_show_the_video(): void
    {
        $result = $this->answer(['reply' => 'Is your phone eSIM-supported? If yes, open My eSIM and tap Install eSIM.', 'quick_replies' => ['Yes', 'No'], 'grounded' => true, 'show_video' => true]);

        $this->assertSame([], $result['resources']);
        $this->assertStringContainsString('ask only that question in this reply', (string) $this->systemPrompt);
    }

    public function test_solution_reply_shows_the_video_when_the_model_confirms_it(): void
    {
        $result = $this->answer(['reply' => 'Open the app, go to My eSIM, then tap Install eSIM. Need anything else?', 'quick_replies' => ['Yes', 'No'], 'grounded' => true, 'show_video' => true]);

        $this->assertCount(1, $result['resources']);
        $this->assertSame('dQw4w9WgXcQ', $result['resources'][0]['video_id']);
    }

    public function test_reply_on_a_different_topic_hides_the_video_when_the_model_declines_it(): void
    {
        $result = $this->answer(['reply' => 'Our support team is available every day.', 'quick_replies' => [], 'grounded' => true, 'show_video' => false]);

        $this->assertSame([], $result['resources']);
    }

    public function test_bengali_steps_ending_with_a_question_are_treated_as_a_solution(): void
    {
        $result = $this->answer(['reply' => 'অ্যাপে My eSIM এ যান এবং Install eSIM চাপুন। আর কিছু জানতে চান?', 'quick_replies' => [], 'grounded' => true, 'show_video' => true]);

        $this->assertCount(1, $result['resources']);
    }

    public function test_discovered_video_does_not_use_the_source_document_name_as_its_title(): void
    {
        $result = $this->answer(['reply' => 'Open the app, go to My eSIM, then tap Install eSIM.', 'quick_replies' => [], 'grounded' => true, 'show_video' => true]);

        $this->assertCount(1, $result['resources']);
        $this->assertArrayNotHasKey('title', $result['resources'][0]);
    }

    public function test_reply_that_mentions_the_video_shows_it_without_an_explicit_flag(): void
    {
        $result = $this->answer(['reply' => 'Open the app, go to My eSIM, then tap Install eSIM. A video tutorial is available if you need it.', 'quick_replies' => [], 'grounded' => true]);

        $this->assertCount(1, $result['resources']);
    }

    public function test_reply_that_mentions_the_video_in_bengali_shows_it(): void
    {
        $result = $this->answer(['reply' => 'অ্যাপে My eSIM এ গিয়ে Install eSIM চাপুন। নিচের ভিডিওটি দেখুন।', 'quick_replies' => [], 'grounded' => true]);

        $this->assertCount(1, $result['resources']);
    }

    public function test_missing_flag_shows_the_video_when_its_passage_leads_the_evidence(): void
    {
        $result = $this->answer(['reply' => 'Open the app, go to My eSIM, then tap Install eSIM.', 'quick_replies' => [], 'grounded' => true]);

        $this->assertCount(1, $result['resources']);
        $this->assertStringContainsString('Always include the key "show_video"', (string) $this->systemPrompt);
    }

    public function test_missing_flag_hides_the_video_when_its_passage_only_supports_the_answer(): void
    {
        config()->set('knowledge_base.hybrid_retrieval_enabled', true);

        $result = $this->answer(
            ['reply' => 'Open the app, go to My eSIM, then tap Install eSIM.', 'quick_replies' => [], 'grounded' => true],
            videoEmbedding: [0.3, 0.2, 0.1],
            strongerPassages: 2,
        );

        $this->assertStringContainsString('Video guide', (string) $this->systemPrompt, 'The video passage was given to the model.');
        $this->assertSame([], $result['resources']);
    }

    public function test_prompt_names_the_video_and_the_steps_it_accompanies(): void
    {
        $this->answer(['reply' => 'Open the app, go to My eSIM, then tap Install eSIM.', 'quick_replies' => [], 'grounded' => true, 'show_video' => true]);

        $this->assertStringContainsString('it accompanies: "How to install eSIM: ask whether the phone supports eSIM, then open My eSIM and tap Install eSIM. Video tutorial:"', (string) $this->systemPrompt);
        $this->assertStringContainsString('send them to the app or website to browse', (string) $this->systemPrompt);
    }

    #[DataProvider('retrievalModes')]
    public function test_video_from_a_passage_the_model_did_not_receive_is_not_attached(bool $hybrid): void
    {
        config()->set('knowledge_base.hybrid_retrieval_enabled', $hybrid);

        $result = $this->answer(
            ['reply' => 'Open the app, go to My eSIM, then tap Install eSIM.', 'quick_replies' => [], 'grounded' => true, 'show_video' => true],
            videoEmbedding: [0.3, 0.2, 0.1],
            strongerPassages: 5,
        );

        $this->assertSame([], $result['resources']);
        $this->assertStringNotContainsString('Video guide', (string) $this->systemPrompt);
    }

    public function test_video_is_offered_at_the_answer_bar_and_shown_when_the_model_confirms(): void
    {
        config()->set('knowledge_base.hybrid_retrieval_enabled', true);

        // Cosine 0.53 plus the wording bonus ranks between the answer (0.60) and
        // automatic-video (0.72) thresholds.
        $result = $this->answer(['reply' => 'Open the app, go to My eSIM, then tap Install eSIM.', 'quick_replies' => [], 'grounded' => true, 'show_video' => true], videoEmbedding: [0.900, -0.095, 0.425]);

        $this->assertStringContainsString('Video guide', (string) $this->systemPrompt);
        $this->assertCount(1, $result['resources']);
        $this->assertGreaterThanOrEqual(0.6, $result['resources'][0]['match_score']);
        $this->assertLessThan(0.72, $result['resources'][0]['match_score']);
    }

    public function test_clarification_turn_that_answers_with_the_steps_shows_the_video(): void
    {
        config()->set('knowledge_base.hybrid_retrieval_enabled', true);

        // Cosine 0.45: medium evidence, so retrieval runs in clarification mode.
        $result = $this->answer(['reply' => 'Open the app, go to My eSIM, then tap Install eSIM.', 'quick_replies' => [], 'grounded' => true, 'response_type' => 'answer', 'show_video' => true], videoEmbedding: [0.918, -0.158, 0.361], question: 'esim setup');

        $this->assertStringContainsString('Grounded clarification mode', (string) $this->systemPrompt);
        $this->assertStringContainsString('Video guide', (string) $this->systemPrompt);
        $this->assertSame('answer', $result['response_mode']);
        $this->assertCount(1, $result['resources']);
    }

    public function test_missing_flag_default_still_requires_the_automatic_video_threshold(): void
    {
        config()->set('knowledge_base.hybrid_retrieval_enabled', true);

        $result = $this->answer(['reply' => 'Open the app, go to My eSIM, then tap Install eSIM.', 'quick_replies' => [], 'grounded' => true], videoEmbedding: [0.900, -0.095, 0.425]);

        $this->assertSame([], $result['resources']);
    }

    public function test_model_sees_every_set_of_steps_the_same_video_accompanies(): void
    {
        config()->set('knowledge_base.hybrid_retrieval_enabled', true);

        $this->answer(['reply' => 'Turn on Data Roaming for the eSIM.', 'quick_replies' => [], 'grounded' => true, 'show_video' => true], secondVideoPassage: true);

        $this->assertStringContainsString('; and also: ', (string) $this->systemPrompt);
        $this->assertStringContainsString('turn on Data Roaming', (string) $this->systemPrompt);
    }

    public static function retrievalModes(): array
    {
        return ['hybrid' => [true], 'legacy' => [false]];
    }

    public function test_provider_failure_fallback_does_not_attach_the_video(): void
    {
        $result = $this->answer(null);

        $this->assertSame([], $result['resources']);
    }

    /**
     * @param  array<string,mixed>|null  $reply
     * @param  array<int,float>  $videoEmbedding
     */
    private function answer(?array $reply, array $videoEmbedding = [0.1, 0.2, 0.3], int $strongerPassages = 0, bool $secondVideoPassage = false, string $question = 'How to install eSIM?'): array
    {
        $workspace = $this->createWorkspaceContext()['workspace'];
        $kb = AiKnowledgeBase::create([
            'workspace_id' => $workspace->id,
            'name' => 'Support KB',
            'embedding_model' => 'text-embedding-3-small',
            'dimensions' => 3,
            'status' => 'active',
        ]);
        $doc = AiKbDocument::create([
            'kb_id' => $kb->id,
            'title' => 'Company Knowledgebase.docx',
            'source_type' => 'file',
            'source_ref' => 'kb-docs/company.docx',
            'resource_json' => [
                'version' => 1,
                'kind' => 'video_collection',
                'videos' => [[
                    'version' => 1,
                    'kind' => 'video',
                    'provider' => 'youtube',
                    'video_id' => 'dQw4w9WgXcQ',
                    'title' => 'Company Knowledgebase.docx',
                    'canonical_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
                    'playback_url' => 'https://www.youtube.com/embed/dQw4w9WgXcQ',
                ]],
            ],
            'status' => 'indexed',
            'review_status' => 'approved',
            'publication_status' => 'published',
        ]);
        $chunk = AiKbChunk::create([
            'kb_id' => $kb->id,
            'document_id' => $doc->id,
            'ord' => 0,
            'content' => 'How to install eSIM: ask whether the phone supports eSIM, then open My eSIM and tap Install eSIM. Video tutorial: https://youtu.be/dQw4w9WgXcQ',
            'tokens' => 30,
            'index_generation' => 'legacy',
            'embedding_status' => 'ready',
        ]);
        app(EmbeddingStore::class)->storeEmbedding($chunk, $videoEmbedding);
        if ($secondVideoPassage) {
            $roaming = AiKbChunk::create([
                'kb_id' => $kb->id,
                'document_id' => $doc->id,
                'ord' => 1,
                'content' => 'If data is not working after you install eSIM, open Settings > Cellular and turn on Data Roaming. Video tutorial: https://youtu.be/dQw4w9WgXcQ',
                'tokens' => 30,
                'index_generation' => 'legacy',
                'embedding_status' => 'ready',
            ]);
            app(EmbeddingStore::class)->storeEmbedding($roaming, [0.1, 0.2, 0.3]);
        }
        for ($i = 1; $i <= $strongerPassages; $i++) {
            $guide = AiKbDocument::create([
                'kb_id' => $kb->id,
                'title' => 'Install guide '.$i,
                'source_type' => 'file',
                'source_ref' => 'kb-docs/install-'.$i.'.md',
                'status' => 'indexed',
                'review_status' => 'approved',
                'publication_status' => 'published',
            ]);
            $stronger = AiKbChunk::create([
                'kb_id' => $kb->id,
                'document_id' => $guide->id,
                'ord' => 0,
                'content' => [
                    1 => 'Restart the phone before you install the eSIM so the network list refreshes.',
                    2 => 'Keep Wi-Fi connected while the eSIM profile downloads to your device.',
                    3 => 'Scan the QR code from your confirmation email if the app cannot install the eSIM.',
                    4 => 'Label the new line as Travel in Settings after you install the eSIM.',
                    5 => 'Turn off your home line abroad to avoid roaming charges after installing the eSIM.',
                    6 => 'Contact support with your order number if the eSIM install keeps failing.',
                ][$i],
                'tokens' => 15,
                'index_generation' => 'legacy',
                'embedding_status' => 'ready',
            ]);
            app(EmbeddingStore::class)->storeEmbedding($stronger, [0.1, 0.2, 0.3]);
        }

        $bot = AiChatbot::create([
            'workspace_id' => $workspace->id,
            'name' => 'Support Bot',
            'ai_kb_id' => $kb->id,
            'enabled' => true,
            'channels' => ['webchat'],
        ]);
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

        $contact = Contact::factory()->create(['workspace_id' => $workspace->id]);
        $conversation = Conversation::create(['workspace_id' => $workspace->id, 'contact_id' => $contact->id, 'status' => 'open']);
        $message = new Message;
        $message->body = $question;
        $message->direction = 'in';
        $message->channel = 'playground';
        $message->setRelation('conversation', $conversation);

        Http::fake([
            'api.openai.com/v1/embeddings' => Http::response(['data' => [['embedding' => [0.1, 0.2, 0.3]]]], 200),
            'api.openai.com/v1/chat/completions' => function ($request) use ($reply) {
                $this->systemPrompt = collect(json_decode($request->body(), true)['messages'] ?? [])->firstWhere('role', 'system')['content'] ?? '';

                return $reply === null
                    ? Http::response(['error' => ['message' => 'unavailable']], 500)
                    : Http::response([
                        'choices' => [['message' => ['content' => json_encode($reply, JSON_UNESCAPED_UNICODE)]]],
                        'usage' => ['prompt_tokens' => 50, 'completion_tokens' => 20],
                        'model' => 'gpt-4o-mini',
                    ], 200);
            },
        ]);

        return app(ChatbotRunner::class)->run($bot, $message);
    }
}
