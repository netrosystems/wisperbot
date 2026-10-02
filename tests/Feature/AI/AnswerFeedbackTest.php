<?php

namespace Tests\Feature\AI;

use App\Modules\AI\Models\AiAnswerFeedback;
use App\Modules\AI\Models\AiChatbot;
use App\Modules\AI\Models\AiKbChunk;
use App\Modules\AI\Models\AiKbDocument;
use App\Modules\AI\Models\AiKnowledgeBase;
use App\Modules\AI\Models\AiProviderConfig;
use App\Modules\AI\Services\EmbeddingStore;
use App\Modules\AI\Services\UnansweredQuestionService;
use App\Modules\Inbox\Jobs\ProcessWebchatAiReplyJob;
use App\Modules\Inbox\Models\ChatWidget;
use App\Modules\Inbox\Services\WidgetPayloadBuilder;
use App\Modules\Shared\Contracts\ChannelDriverInterface;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use App\Modules\Shared\Services\ChannelManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

/**
 * "Why this answer", 👍/👎 and "Improve" on Smart Bot replies (Smart Bot 2.0,
 * Phase 1.5): staff-only, workspace-scoped, shared by the inbox and the
 * mobile app.
 */
class AnswerFeedbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_bot_reply_keeps_why_it_answered_for_the_team_but_never_for_the_visitor(): void
    {
        [$bot, $kb, , $conversation] = $this->setUpBot();
        $inbound = Message::create(['conversation_id' => $conversation->id, 'direction' => 'in', 'channel' => 'webchat', 'type' => 'text', 'body' => 'How do I install the eSIM?', 'status' => 'delivered', 'sent_at' => now()]);
        Http::fake([
            'api.openai.com/v1/embeddings' => Http::response(['data' => [['embedding' => [1.0, 0.0, 0.0]]]]),
            'api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => '{"reply":"Scan the QR code in Settings.","quick_replies":[],"grounded":true,"response_type":"answer","show_video":false}'], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5], 'model' => 'gpt-4o-mini',
            ]),
        ]);
        $this->fakeDriver();

        app()->call([new ProcessWebchatAiReplyJob($inbound->id, $bot->id), 'handle']);

        $reply = Message::where('direction', 'out')->sole();
        $review = $reply->payload['ai_review'];
        $this->assertSame('answered', $review['reason_code']);
        $this->assertSame('knowledge_base', $review['answer_origin']);
        $this->assertSame(['Installing an eSIM'], $review['sources']);
        $this->assertSame($inbound->id, $review['question_message_id']);
        $this->assertSame($kb->id, $review['kb_id']);

        $widget = ChatWidget::create(['workspace_id' => $conversation->workspace_id, 'widget_key' => 'wk_feedback_test_key_001', 'enabled' => true]);
        $public = app(WidgetPayloadBuilder::class)->message($reply->load('conversation'), $widget);
        $this->assertArrayNotHasKey('ai_review', $public);
        $this->assertStringNotContainsString('Installing an eSIM', json_encode($public));
    }

    public function test_the_team_rates_a_reply_and_the_same_rating_again_clears_it(): void
    {
        [, , $user, $conversation] = $this->setUpBot();
        $reply = $this->botReply($conversation);
        $url = route('client.inbox.messages.ai-feedback', ['conversation' => $conversation->uuid, 'message' => $reply->id]);

        $this->actingAs($user)->postJson($url, ['rating' => 'up'])->assertOk()->assertJsonPath('ai_feedback.rating', 'up');
        $this->assertSame('up', AiAnswerFeedback::sole()->rating);
        $this->assertSame('up', $reply->fresh()->payload['ai_feedback']['rating']);

        $this->actingAs($user)->postJson($url, ['rating' => 'up'])->assertJsonPath('ai_feedback.rating', null);
        $this->actingAs($user)->postJson($url, ['rating' => 'sideways'])->assertUnprocessable();
    }

    public function test_improve_writes_the_answer_into_the_knowledge_base_and_marks_the_reply(): void
    {
        [, $kb, $user, $conversation] = $this->setUpBot();
        Queue::fake();
        $reply = $this->botReply($conversation);
        $params = ['conversation' => $conversation->uuid, 'message' => $reply->id];

        $this->actingAs($user)->getJson(route('client.inbox.messages.ai-question', $params))->assertJsonPath('question', 'Do you sell eSIMs for Japan?');
        $this->actingAs($user)->postJson(route('client.inbox.messages.ai-improve', $params), [
            'question' => 'Do you sell eSIMs for Japan?', 'answer' => 'Yes, the Japan plan covers all of Japan for 7 days.',
        ])->assertOk()->assertJsonPath('ai_feedback.improved', true)->assertJsonPath('ai_feedback.rating', 'down');

        $source = AiKbDocument::where('kb_id', $kb->id)->where('original_source_ref', UnansweredQuestionService::ANSWERS_SOURCE)->sole();
        $this->assertSame('Yes, the Japan plan covers all of Japan for 7 days.', json_decode($source->source_ref, true)[0]['answer']);
    }

    public function test_other_workspaces_and_non_bot_messages_are_refused(): void
    {
        [, , $user, $conversation] = $this->setUpBot();
        $reply = $this->botReply($conversation);
        $human = Message::create(['conversation_id' => $conversation->id, 'direction' => 'out', 'channel' => 'webchat', 'type' => 'text', 'body' => 'Hi from Sam', 'sent_by' => 'human', 'status' => 'sent', 'sent_at' => now()]);
        $stranger = $this->createWorkspaceContext()['user'];

        $this->actingAs($stranger)->postJson(route('client.inbox.messages.ai-feedback', ['conversation' => $conversation->uuid, 'message' => $reply->id]), ['rating' => 'up'])->assertForbidden();
        $this->actingAs($user)->postJson(route('client.inbox.messages.ai-feedback', ['conversation' => $conversation->uuid, 'message' => $human->id]), ['rating' => 'up'])->assertNotFound();
        $this->assertSame(0, AiAnswerFeedback::count());
    }

    public function test_the_mobile_app_rates_and_improves_through_its_own_api(): void
    {
        [, , $user, $conversation] = $this->setUpBot();
        Queue::fake();
        $reply = $this->botReply($conversation);
        Sanctum::actingAs($user);
        $base = "/api/v1/mobile/conversations/{$conversation->uuid}/messages/{$reply->id}";

        $this->postJson("{$base}/ai-feedback", ['rating' => 'down'])->assertOk()->assertJsonPath('data.ai_feedback.rating', 'down');
        $this->getJson("{$base}/ai-question")->assertJsonPath('data.question', 'Do you sell eSIMs for Japan?');
        $this->postJson("{$base}/ai-improve", ['question' => 'Do you sell eSIMs for Japan?', 'answer' => 'Yes.'])->assertJsonPath('data.ai_feedback.improved', true);
    }

    /** @return array{0:AiChatbot,1:AiKnowledgeBase,2:mixed,3:Conversation} */
    private function setUpBot(): array
    {
        $context = $this->createWorkspaceContext();
        $workspaceId = $context['workspace']->id;
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
            'kb_id' => $kb->id, 'document_id' => $document->id, 'ord' => 0, 'content' => 'Open Settings and scan the QR code in Settings to install the eSIM.',
            'tokens' => 14, 'index_generation' => 'legacy', 'embedding_status' => 'ready',
        ]);
        app(EmbeddingStore::class)->storeEmbedding($chunk, [1.0, 0.0, 0.0]);
        AiProviderConfig::create([
            'workspace_id' => $workspaceId, 'provider' => 'openai', 'credentials' => ['api_key' => 'sk-test'],
            'default_model_chat' => 'gpt-4o-mini', 'default_model_embed' => 'text-embedding-3-small',
            'enabled' => true, 'last_tested_at' => now(), 'last_test_succeeded_at' => now(),
        ]);
        $bot = AiChatbot::create(['workspace_id' => $workspaceId, 'name' => 'Support Bot', 'ai_kb_id' => $kb->id, 'enabled' => true]);
        $conversation = Conversation::create([
            'workspace_id' => $workspaceId,
            'contact_id' => Contact::factory()->create(['workspace_id' => $workspaceId])->id,
            'status' => 'open',
        ]);

        return [$bot, $kb, $context['user'], $conversation];
    }

    private function botReply(Conversation $conversation): Message
    {
        $bot = AiChatbot::where('workspace_id', $conversation->workspace_id)->sole();
        $question = Message::create(['conversation_id' => $conversation->id, 'direction' => 'in', 'channel' => 'webchat', 'type' => 'text', 'body' => 'Do you sell eSIMs for Japan?', 'status' => 'delivered', 'sent_at' => now()]);

        return Message::create([
            'conversation_id' => $conversation->id, 'direction' => 'out', 'channel' => 'webchat', 'type' => 'text',
            'body' => 'I could not find that.', 'sent_by' => 'bot', 'status' => 'sent', 'sent_at' => now(),
            'payload' => ['answer_origin' => 'fallback', 'ai_review' => [
                'chatbot_id' => $bot->id, 'kb_id' => $bot->ai_kb_id, 'question_message_id' => $question->id, 'reason_code' => 'no_context',
            ]],
        ]);
    }

    private function fakeDriver(): void
    {
        $driver = new class implements ChannelDriverInterface
        {
            public function send(Message $message): string
            {
                return 'provider-1';
            }

            public function receiveWebhook(Request $request): array
            {
                return [];
            }

            public function verifyCreds(): bool
            {
                return true;
            }
        };
        $manager = Mockery::mock(ChannelManager::class);
        $manager->shouldReceive('driver')->andReturn($driver);
        $this->app->instance(ChannelManager::class, $manager);
    }
}
