<?php

namespace Tests\Feature\Inbox;

use App\Modules\AI\Models\AiChatbot;
use App\Modules\AI\Services\ChatbotRunner;
use App\Modules\Inbox\Jobs\ProcessChannelAiReplyJob;
use App\Modules\Inbox\Models\WorkspaceAiAnsweringPolicy;
use App\Modules\Inbox\Services\MessageBurst;
use App\Modules\Shared\Contracts\ChannelDriverInterface;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use App\Modules\Shared\Services\ChannelManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Mockery;
use Tests\TestCase;

/**
 * A customer's quick run of messages is answered as one question (Smart Bot
 * 2.0, Phase 1.5): one reply, the earlier messages folded into the latest.
 */
class MessageBurstTest extends TestCase
{
    use RefreshDatabase;

    public function test_quick_consecutive_messages_are_answered_together_once(): void
    {
        $conversation = $this->conversation();
        // A greeting adds nothing to the question and is left out.
        $this->inbound($conversation, 'Hi', 40);
        $first = $this->inbound($conversation, 'Do you deliver to Sylhet?', 30);
        $latest = $this->inbound($conversation, 'And how much does it cost?', 5);
        $runner = $this->mock(ChatbotRunner::class);
        $runner->shouldReceive('answeringTogether')->once()->with([$first->id])->andReturnSelf();
        $runner->shouldReceive('run')->once()->withArgs(fn ($bot, Message $message) => $message->id === $latest->id
            && $message->body === "Do you deliver to Sylhet?\nAnd how much does it cost?")
            ->andReturn(['reply' => 'Yes, delivery to Sylhet costs 120 taka.', 'tokens_used' => 5, 'resources' => []]);
        $runner->shouldReceive('lastTurnContext')->andReturn([]);
        $this->fakeDriver();

        app()->call([new ProcessChannelAiReplyJob($conversation->id), 'handle']);

        $reply = Message::where('direction', 'out')->sole();
        $this->assertSame($latest->id, $reply->ai_source_message_id);
        $this->assertSame('And how much does it cost?', $latest->fresh()->body);
    }

    public function test_messages_already_replied_to_or_outside_the_window_are_not_folded_in(): void
    {
        $conversation = $this->conversation();
        $this->inbound($conversation, 'What are your opening hours?', 600);
        Message::create([
            'conversation_id' => $conversation->id, 'direction' => 'out', 'channel' => 'messenger', 'type' => 'text',
            'body' => 'We open at 9.', 'sent_by' => 'bot', 'status' => 'sent', 'sent_at' => now()->subSeconds(590),
        ]);
        $this->inbound($conversation, 'Do you have parking?', 300);
        $recent = $this->inbound($conversation, 'Is it free?', 20);
        $latest = $this->inbound($conversation, 'For how long?', 2);

        $earlier = app(MessageBurst::class)->earlier($conversation, $latest);

        $this->assertSame([$recent->id], array_map(fn (Message $m) => $m->id, $earlier));
    }

    public function test_a_single_message_and_email_are_answered_as_they_are(): void
    {
        $conversation = $this->conversation();
        $only = $this->inbound($conversation, 'Do you deliver to Sylhet?', 2);
        $this->assertSame([], app(MessageBurst::class)->earlier($conversation, $only));

        $this->inbound($conversation, 'Another question', 1);
        $email = $this->inbound($conversation, 'Order status?', 0);
        $email->channel = 'email';
        $this->assertSame([], app(MessageBurst::class)->earlier($conversation, $email));
    }

    public function test_the_folded_messages_leave_the_history_the_model_reads(): void
    {
        $conversation = $this->conversation();
        $first = $this->inbound($conversation, 'Do you deliver to Sylhet?', 30);
        $latest = $this->inbound($conversation, 'And how much?', 5);
        $history = fn (ChatbotRunner $runner): array => (fn () => $this->conversationHistory($conversation, $latest))->call($runner);

        $this->assertSame(['Do you deliver to Sylhet?'], array_column($history(app(ChatbotRunner::class)), 'content'));
        $this->assertSame([], $history(app(ChatbotRunner::class)->answeringTogether([$first->id])));
    }

    private function conversation(): Conversation
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $bot = AiChatbot::factory()->create(['workspace_id' => $workspace->id]);
        WorkspaceAiAnsweringPolicy::create([
            'workspace_id' => $workspace->id, 'segment' => 'omni', 'mode' => 'always_on',
            'chatbot_id' => $bot->id, 'enabled_at' => now()->subHour(),
        ]);
        $account = ChannelAccount::create([
            'workspace_id' => $workspace->id, 'channel' => 'messenger', 'provider' => 'test',
            'display_name' => 'Messenger account', 'status' => 'active', 'ai_eligible_from_at' => now()->subHour(),
        ]);

        return Conversation::create([
            'workspace_id' => $workspace->id, 'channel_account_id' => $account->id,
            'contact_id' => Contact::factory()->create(['workspace_id' => $workspace->id])->id,
            'status' => 'open', 'assigned_to' => 'bot',
        ]);
    }

    private function inbound(Conversation $conversation, string $body, int $secondsAgo): Message
    {
        return Message::create([
            'conversation_id' => $conversation->id, 'direction' => 'in', 'channel' => 'messenger',
            'type' => 'text', 'body' => $body, 'status' => 'delivered', 'sent_at' => now()->subSeconds($secondsAgo),
        ]);
    }

    private function fakeDriver(): void
    {
        $driver = new class implements ChannelDriverInterface
        {
            public function send(Message $message): string
            {
                return 'provider-message-1';
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
