<?php

namespace Tests\Feature\Inbox;

use App\Modules\AI\Models\AiChatbot;
use App\Modules\AI\Services\ChatbotRunner;
use App\Modules\Inbox\Jobs\ProcessChannelAiReplyJob;
use App\Modules\Inbox\Jobs\ProcessWebchatAiReplyJob;
use App\Modules\Inbox\Models\WorkspaceAiAnsweringPolicy;
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
 * A turn the Smart Bot cannot answer must never leave the customer waiting
 * in silence: they get a holding reply and a person takes over.
 */
class AiHoldingReplyTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_provider_failure_on_a_channel_sends_a_holding_reply_and_hands_over(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $bot = AiChatbot::factory()->create(['workspace_id' => $workspace->id]);
        WorkspaceAiAnsweringPolicy::create([
            'workspace_id' => $workspace->id, 'segment' => 'omni', 'mode' => 'always_on',
            'chatbot_id' => $bot->id, 'enabled_at' => now()->subMinute(),
        ]);
        $account = ChannelAccount::create([
            'workspace_id' => $workspace->id, 'channel' => 'messenger', 'provider' => 'test',
            'display_name' => 'Messenger account', 'status' => 'active', 'ai_eligible_from_at' => now()->subMinute(),
        ]);
        $conversation = Conversation::create([
            'workspace_id' => $workspace->id, 'channel_account_id' => $account->id,
            'contact_id' => Contact::factory()->create(['workspace_id' => $workspace->id])->id,
            'status' => 'open', 'assigned_to' => 'bot',
        ]);
        Message::create([
            'conversation_id' => $conversation->id, 'direction' => 'in', 'channel' => 'messenger',
            'type' => 'text', 'body' => 'Do you deliver to Sylhet?', 'status' => 'delivered', 'sent_at' => now(),
        ]);
        $runner = $this->mock(ChatbotRunner::class);
        $runner->shouldReceive('run')->andThrow(new \RuntimeException('provider down'));
        $runner->shouldReceive('lastTurnContext')->andReturn([]);
        $sent = $this->fakeDriver();

        app()->call([new ProcessChannelAiReplyJob($conversation->id), 'handle']);

        $reply = Message::where('direction', 'out')->sole();
        $this->assertSame('Thanks for your message. A member of our team will reply here shortly.', $reply->body);
        $this->assertSame('handoff', $reply->payload['response_mode']);
        $this->assertSame('sent', $reply->status);
        $this->assertCount(1, $sent);
        $this->assertSame('human', $conversation->fresh()->assigned_to);
        $this->assertNotNull($conversation->fresh()->ai_paused_at);
    }

    public function test_an_empty_webchat_reply_becomes_a_holding_reply_in_the_customers_script(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $bot = AiChatbot::factory()->create(['workspace_id' => $workspace->id]);
        $conversation = Conversation::create([
            'workspace_id' => $workspace->id,
            'contact_id' => Contact::factory()->create(['workspace_id' => $workspace->id])->id,
            'status' => 'open', 'assigned_to' => 'bot',
        ]);
        $inbound = Message::create([
            'conversation_id' => $conversation->id, 'direction' => 'in', 'channel' => 'webchat',
            'type' => 'text', 'body' => 'ডেলিভারি চার্জ কত?', 'status' => 'delivered', 'sent_at' => now(),
        ]);
        $runner = $this->mock(ChatbotRunner::class);
        $runner->shouldReceive('run')->andReturn(['reply' => null, 'tokens_used' => 0, 'resources' => []]);
        $runner->shouldReceive('lastTurnContext')->andReturn([]);
        $this->fakeDriver();

        app()->call([new ProcessWebchatAiReplyJob($inbound->id, $bot->id), 'handle']);

        $reply = Message::where('direction', 'out')->sole();
        $this->assertStringContainsString('ধন্যবাদ', $reply->body);
        $this->assertSame('human', $conversation->fresh()->assigned_to);
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
