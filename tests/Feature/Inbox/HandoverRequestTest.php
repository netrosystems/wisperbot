<?php

namespace Tests\Feature\Inbox;

use App\Events\MessageReceived;
use App\Listeners\AutoReplyListener;
use App\Modules\AI\Models\AiChatbot;
use App\Modules\Inbox\Models\WorkspaceAiAnsweringPolicy;
use App\Modules\Shared\Contracts\ChannelDriverInterface;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use App\Modules\Shared\Services\ChannelManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

/**
 * Asking for a person, in any language, or saying yes to the bot's offer of
 * one hands the conversation over, and a channel customer is told so.
 */
class HandoverRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_yes_to_the_bots_offer_hands_over_and_tells_the_customer(): void
    {
        $conversation = $this->conversation();
        $this->bot($conversation, "I do not have a verified answer for that yet. Would you like me to connect you with a person?\n\n1. Yes\n2. No");
        $sent = $this->fakeDriver();

        $this->receive($conversation, 'Yes');

        $conversation->refresh();
        $this->assertSame('human', $conversation->assigned_to);
        $this->assertNotNull($conversation->handover_at);
        $this->assertCount(1, $sent);
        $this->assertSame('Of course. A member of our team will reply here shortly.', $sent[0]->body);
        Queue::assertNothingPushed();
    }

    public function test_a_request_in_bangla_is_answered_in_bangla(): void
    {
        $conversation = $this->conversation();
        $sent = $this->fakeDriver();

        $this->receive($conversation, 'আমি মানুষের সাথে কথা বলতে চাই');

        $this->assertSame('human', $conversation->fresh()->assigned_to);
        $this->assertStringContainsString('আমাদের টিমের', $sent[0]->body);
    }

    public function test_yes_to_another_question_is_left_to_the_bot(): void
    {
        $conversation = $this->conversation();
        $this->bot($conversation, 'Would you like the Japan plan?');
        $sent = $this->fakeDriver();

        $this->receive($conversation, 'Yes please');

        $this->assertSame('bot', $conversation->fresh()->assigned_to);
        $this->assertCount(0, $sent);
    }

    private function conversation(): Conversation
    {
        Notification::fake();
        Queue::fake();
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

    private function bot(Conversation $conversation, string $body): void
    {
        Message::create([
            'conversation_id' => $conversation->id, 'direction' => 'out', 'channel' => 'messenger', 'type' => 'text',
            'body' => $body, 'sent_by' => 'bot', 'status' => 'sent', 'sent_at' => now()->subMinute(),
        ]);
    }

    private function receive(Conversation $conversation, string $body): void
    {
        $message = Message::create([
            'conversation_id' => $conversation->id, 'direction' => 'in', 'channel' => 'messenger',
            'type' => 'text', 'body' => $body, 'status' => 'delivered', 'sent_at' => now(),
        ]);
        app(AutoReplyListener::class)->handle(new MessageReceived($message->load('conversation.channelAccount')));
    }

    /** @return \ArrayObject<int,Message> */
    private function fakeDriver(): \ArrayObject
    {
        $sent = new \ArrayObject;
        $driver = new class($sent) implements ChannelDriverInterface
        {
            /** @param \ArrayObject<int,Message> $sent */
            public function __construct(private \ArrayObject $sent) {}

            public function send(Message $message): string
            {
                $this->sent->append($message);

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

        return $sent;
    }
}
