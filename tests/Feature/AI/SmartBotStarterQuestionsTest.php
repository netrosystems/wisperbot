<?php

namespace Tests\Feature\AI;

use App\Models\User;
use App\Models\Workspace;
use App\Modules\AI\Models\AiChatbot;
use App\Modules\AI\Services\ChatbotRunner;
use App\Modules\AI\Services\StarterQuestions;
use App\Modules\Inbox\Jobs\ProcessWebchatAiReplyJob;
use App\Modules\Inbox\Models\ChatWidget;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class SmartBotStarterQuestionsTest extends TestCase
{
    use RefreshDatabase;

    private const ITEMS = [
        ['id' => 'sq_install1', 'question' => 'How do I install my eSIM?', 'answer' => "Open Settings → Mobile Data → Add eSIM.\nThen scan the QR code from your email."],
        ['id' => 'sq_refund01', 'question' => 'What is your refund policy?', 'answer' => 'Unused plans can be refunded within 14 days.'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_client_saves_starter_questions_on_the_widget_and_existing_ids_stay_stable(): void
    {
        [$user, $workspace] = $this->clientWorkspace();
        [$widget] = $this->widget($workspace->id, ['starter_questions_enabled' => false, 'starter_questions' => null]);

        $this->saveWidget($user, $widget, [
            'starter_questions_enabled' => true,
            'starter_questions' => [
                ['question' => '  How do I install my eSIM? ', 'answer' => "Step one.\r\nStep two."],
            ],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $saved = $widget->fresh();
        $this->assertTrue($saved->starter_questions_enabled);
        $this->assertSame('How do I install my eSIM?', $saved->starter_questions[0]['question']);
        $this->assertSame("Step one.\nStep two.", $saved->starter_questions[0]['answer']);
        $id = $saved->starter_questions[0]['id'];
        $this->assertMatchesRegularExpression('/^sq_[a-z0-9]{8}$/', $id);

        $this->saveWidget($user, $widget, [
            'starter_questions_enabled' => true,
            'starter_questions' => [
                ['id' => 'sq_forged00', 'question' => 'Do you ship abroad?', 'answer' => 'Yes.'],
                ['id' => $id, 'question' => 'How do I install my eSIM?', 'answer' => 'Updated answer.'],
            ],
        ])->assertSessionHasNoErrors();

        $items = $widget->fresh()->starter_questions;
        $this->assertSame($id, $items[1]['id']);
        $this->assertNotSame('sq_forged00', $items[0]['id']);

        // Multipart form data omits an empty list, which means the client removed every question.
        $this->saveWidget($user, $widget, ['starter_questions_enabled' => false])->assertSessionHasNoErrors();
        $this->assertFalse($widget->fresh()->starter_questions_enabled);
        $this->assertSame([], $widget->fresh()->starter_questions);
    }

    public function test_invalid_starter_questions_are_rejected(): void
    {
        [$user, $workspace] = $this->clientWorkspace();
        [$widget] = $this->widget($workspace->id, ['starter_questions_enabled' => false, 'starter_questions' => null]);
        $save = fn (array $items) => $this->saveWidget($user, $widget, [
            'starter_questions_enabled' => true,
            'starter_questions' => $items,
        ]);

        $six = array_fill(0, 6, ['question' => 'Q', 'answer' => 'A']);
        foreach ($six as $i => $item) {
            $six[$i]['question'] = "Question {$i}";
        }
        $save($six)->assertSessionHasErrors('starter_questions');
        $save([['question' => str_repeat('a', 81), 'answer' => 'A']])->assertSessionHasErrors('starter_questions.0.question');
        $save([['question' => 'See https://example.com', 'answer' => 'A']])->assertSessionHasErrors('starter_questions.0.question');
        $save([['question' => '<b>Hi</b>', 'answer' => 'A']])->assertSessionHasErrors('starter_questions.0.question');
        $save([['question' => '🙂 ?!', 'answer' => 'A']])->assertSessionHasErrors('starter_questions.0.question');
        $save([['question' => 'Talk to human', 'answer' => 'A']])->assertSessionHasErrors('starter_questions.0.question');
        $save([['question' => 'Question', 'answer' => str_repeat('a', 1001)]])->assertSessionHasErrors('starter_questions.0.answer');
        $save([['question' => 'Question', 'answer' => "Bad\x07bell"]])->assertSessionHasErrors('starter_questions.0.answer');
        $save([
            ['question' => 'Refund policy?', 'answer' => 'A'],
            ['question' => 'refund   POLICY', 'answer' => 'B'],
        ])->assertSessionHasErrors('starter_questions.1.question');

        $this->assertNull($widget->fresh()->starter_questions);
        $this->assertFalse($widget->fresh()->starter_questions_enabled);
    }

    public function test_matching_ignores_case_and_punctuation_but_keeps_vowel_signs(): void
    {
        $starter = app(StarterQuestions::class);
        $widget = new ChatWidget([
            'starter_questions_enabled' => true,
            'starter_questions' => [['id' => 'sq_bn000001', 'question' => 'কাল কি খোলা?', 'answer' => 'হ্যাঁ']],
        ]);

        $this->assertNotNull($starter->match($widget, '  কাল কি খোলা '));
        $this->assertNull($starter->match($widget, 'কল কি খোলা?'));
        $this->assertNull($starter->match($widget, '?!'));
        $this->assertNull($starter->match($widget, ''));

        $widget->starter_questions_enabled = false;
        $this->assertNull($starter->match($widget, 'কাল কি খোলা?'));
        $this->assertSame([], $starter->publicLabels($widget));
    }

    public function test_widget_config_exposes_labels_whenever_turned_on_even_with_ai_off(): void
    {
        [, $workspace] = $this->clientWorkspace();
        [$widget] = $this->widget($workspace->id, ['ai_enabled' => false, 'ai_chatbot_id' => null]);

        $config = $widget->fresh()->publicConfig();
        $this->assertSame([
            ['id' => 'sq_install1', 'label' => 'How do I install my eSIM?'],
            ['id' => 'sq_refund01', 'label' => 'What is your refund policy?'],
        ], $config['starter_questions']);
        $this->assertStringNotContainsString('refunded within 14 days', json_encode($config));

        $widget->update(['starter_questions_enabled' => false]);
        $this->assertSame([], $widget->fresh()->publicConfig()['starter_questions']);
    }

    public function test_customer_gets_the_saved_answer_instantly_with_ai_off(): void
    {
        [, $workspace] = $this->clientWorkspace();
        [$widget] = $this->widget($workspace->id, ['ai_enabled' => false, 'ai_chatbot_id' => null]);
        Http::fake();

        $reply = $this->sendAndReadReply($widget, 'What is your refund policy?');

        $this->assertSame(self::ITEMS[1]['answer'], $reply['body']);
        $this->assertSame('starter_question', $reply['answer_origin']);
        Http::assertNothingSent();
    }

    public function test_customer_gets_the_saved_answer_instantly_while_the_bot_answers(): void
    {
        [, $workspace] = $this->clientWorkspace();
        [$widget] = $this->widget($workspace->id);

        // Typed rather than tapped: case and punctuation do not matter.
        $reply = $this->sendAndReadReply($widget, 'what is your REFUND policy');

        $this->assertSame(self::ITEMS[1]['answer'], $reply['body']);
        $this->assertSame('starter_question', $reply['answer_origin']);
    }

    public function test_other_messages_still_go_to_the_ai_queue(): void
    {
        [, $workspace] = $this->clientWorkspace();
        [$widget] = $this->widget($workspace->id);
        $session = $this->postJson(route('widget.session'), ['key' => $widget->widget_key, 'active' => true])->assertOk();

        Queue::fake();
        $this->withHeader('X-Widget-Token', $session->json('token'))
            ->postJson(route('widget.send'), ['key' => $widget->widget_key, 'message' => 'Do you sell physical SIMs?'])
            ->assertOk();

        Queue::assertPushedOn('ai', ProcessWebchatAiReplyJob::class);
    }

    public function test_the_smart_bot_no_longer_answers_starter_questions_itself(): void
    {
        Http::fake();
        [, $workspace] = $this->clientWorkspace();
        [, , $bot] = $this->widget($workspace->id);

        $result = app(ChatbotRunner::class)->runForApi($bot, 'How do I install my eSIM?', $workspace->id);

        $this->assertNotSame('starter_question', $result['answer_origin'] ?? null);
    }

    public function test_handed_over_chat_does_not_get_a_starter_answer(): void
    {
        [, $workspace] = $this->clientWorkspace();
        [$widget] = $this->widget($workspace->id, ['ai_enabled' => false, 'ai_chatbot_id' => null]);
        $session = $this->postJson(route('widget.session'), ['key' => $widget->widget_key, 'active' => true])->assertOk();
        Conversation::where('workspace_id', $workspace->id)->update(['handover_at' => now()]);

        $this->withHeader('X-Widget-Token', $session->json('token'))
            ->postJson(route('widget.send'), ['key' => $widget->widget_key, 'message' => 'What is your refund policy?'])
            ->assertOk();

        $this->assertFalse(Message::where('direction', 'out')->where('body', self::ITEMS[1]['answer'])->exists());
    }

    /** @return array<string,mixed> */
    private function sendAndReadReply(ChatWidget $widget, string $text): array
    {
        $session = $this->postJson(route('widget.session'), ['key' => $widget->widget_key, 'active' => true])
            ->assertOk()
            ->assertJsonPath('config.starter_questions.1.label', 'What is your refund policy?');

        // A real (non-sync) queue: an AI job would wait for a worker, so the
        // reply existing right after the request proves it was sent inline.
        config(['queue.default' => 'database']);
        $this->withHeader('X-Widget-Token', $session->json('token'))
            ->postJson(route('widget.send'), ['key' => $widget->widget_key, 'message' => $text])
            ->assertOk();

        $this->assertDatabaseMissing('jobs', ['queue' => 'ai']);
        $messages = $this->withHeader('X-Widget-Token', $session->json('token'))
            ->getJson(route('widget.poll', ['key' => $widget->widget_key, 'after' => 0]))
            ->assertOk()
            ->json('messages');

        return collect($messages)->firstWhere('role', 'agent');
    }

    private function saveWidget(User $user, ChatWidget $widget, array $data): TestResponse
    {
        return $this->actingAs($user)->put(route('client.inbox.chat-widgets.update', $widget), array_merge([
            'title' => 'Chat with us',
            'position' => 'bottom_right',
        ], $data));
    }

    /** @return array{ChatWidget,ChannelAccount,AiChatbot} */
    private function widget(int $workspaceId, array $overrides = []): array
    {
        $bot = AiChatbot::create(['workspace_id' => $workspaceId, 'name' => 'Assistant', 'enabled' => true]);
        $account = ChannelAccount::create([
            'workspace_id' => $workspaceId,
            'channel' => 'webchat',
            'display_name' => 'Website chat',
            'status' => 'active',
            'meta_json' => ['ai_chatbot_id' => $bot->id],
        ]);
        $widget = ChatWidget::create(array_merge([
            'workspace_id' => $workspaceId,
            'channel_account_id' => $account->id,
            'name' => 'Website chat',
            'position' => 'bottom_right',
            'ai_enabled' => true,
            'ai_chatbot_id' => $bot->id,
            'starter_questions_enabled' => true,
            'starter_questions' => self::ITEMS,
        ], $overrides));

        return [$widget, $account, $bot];
    }

    /** @return array{User,Workspace} */
    private function clientWorkspace(): array
    {
        $user = User::factory()->create(['role' => 'client', 'email_verified_at' => now()]);
        $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
        $user->update(['workspace_id' => $workspace->id]);

        return [$user, $workspace];
    }
}
