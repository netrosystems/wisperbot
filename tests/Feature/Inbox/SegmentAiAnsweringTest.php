<?php

namespace Tests\Feature\Inbox;

use App\Events\MessageReceived;
use App\Listeners\AutoReplyListener;
use App\Models\User;
use App\Modules\AI\Models\AiChatbot;
use App\Modules\Inbox\Jobs\ProcessChannelAiReplyJob;
use App\Modules\Inbox\Models\WorkspaceAiAnsweringPolicy;
use App\Modules\Inbox\Services\ConversationOwnershipService;
use App\Modules\Inbox\Services\SegmentAiPolicyService;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SegmentAiAnsweringTest extends TestCase
{
    use RefreshDatabase;

    public function test_one_omni_policy_applies_to_every_supported_account(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $bot = AiChatbot::factory()->create(['workspace_id' => $workspace->id]);
        $this->policy($workspace->id, 'omni', $bot->id);
        $service = app(SegmentAiPolicyService::class);

        foreach (['whatsapp', 'messenger', 'instagram', 'telegram', 'ebay'] as $channel) {
            $account = $this->account($workspace->id, $channel);
            $this->assertSame('eligible', $service->decision($account, now()));
            $this->assertSame($bot->id, $service->chatbotId($account));
        }
        $this->assertSame('off', $service->decision($this->account($workspace->id, 'email')));
        $this->assertSame('unsupported_channel', $service->decision($this->account($workspace->id, 'amazon')));
    }

    public function test_email_policy_is_independent_from_omni_policy(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $emailBot = AiChatbot::factory()->create(['workspace_id' => $workspace->id]);
        $this->policy($workspace->id, 'email', $emailBot->id);

        $service = app(SegmentAiPolicyService::class);
        $this->assertSame('eligible', $service->decision($this->account($workspace->id, 'email'), now()));
        $this->assertSame('off', $service->decision($this->account($workspace->id, 'whatsapp'), now()));
    }

    public function test_schedule_uses_exact_active_hours_and_new_inbound_can_cross_the_boundary(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $bot = AiChatbot::factory()->create(['workspace_id' => $workspace->id]);
        $this->policy($workspace->id, 'omni', $bot->id, 'scheduled', $this->schedule('Asia/Dhaka'));
        $account = $this->account($workspace->id, 'whatsapp');
        $service = app(SegmentAiPolicyService::class);

        $this->assertTrue($service->shouldAnswerNow($account, CarbonImmutable::parse('2026-09-14 20:30:00 UTC')));
        $this->assertFalse($service->shouldAnswerNow($account, CarbonImmutable::parse('2026-09-14 19:00:00 UTC')));
    }

    public function test_administrator_updates_a_segment_not_an_account(): void
    {
        ['workspace' => $workspace, 'user' => $user] = $this->createWorkspaceContext();
        $bot = AiChatbot::factory()->create(['workspace_id' => $workspace->id]);

        $this->actingAs($user)->patch(route('client.inbox.ai-answering.update', 'omni'), [
            'mode' => 'always_on', 'chatbot_id' => $bot->id,
        ])->assertRedirect();

        $this->assertDatabaseHas('workspace_ai_answering_policies', [
            'workspace_id' => $workspace->id, 'segment' => 'omni', 'mode' => 'always_on', 'chatbot_id' => $bot->id,
        ]);
    }

    public function test_non_administrator_cannot_update_segment_policy(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $member = User::factory()->create([
            'role' => User::ROLE_CLIENT, 'client_id' => $workspace->client_id,
            'client_role' => User::CLIENT_ROLE_STAFF, 'workspace_id' => $workspace->id,
            'email_verified_at' => now(),
        ]);
        $workspace->members()->syncWithoutDetaching([$member->id => ['role' => 'member']]);

        $this->actingAs($member)->patch(route('client.inbox.ai-answering.update', 'email'), ['mode' => 'off'])->assertForbidden();
    }

    public function test_mobile_api_returns_and_updates_segment_policies(): void
    {
        ['workspace' => $workspace, 'user' => $user] = $this->createWorkspaceContext();
        $bot = AiChatbot::factory()->create(['workspace_id' => $workspace->id]);
        Sanctum::actingAs($user);

        $this->patchJson('/api/v1/mobile/inbox/ai-answering/email', [
            'mode' => 'always_on', 'chatbot_id' => $bot->id,
        ])->assertOk()->assertJsonPath('data.segment', 'email');

        $this->getJson('/api/v1/mobile/inbox/ai-answering')
            ->assertOk()
            ->assertJsonPath('data.email.chatbot_id', $bot->id)
            ->assertJsonPath('data.omni.mode', 'off');
    }

    public function test_listener_rechecks_policy_even_when_previous_inbound_was_human_routed(): void
    {
        Queue::fake();
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $bot = AiChatbot::factory()->create(['workspace_id' => $workspace->id]);
        $this->policy($workspace->id, 'omni', $bot->id);
        $account = $this->account($workspace->id, 'whatsapp', now()->subMinute());
        $contact = Contact::factory()->create(['workspace_id' => $workspace->id]);
        $conversation = Conversation::create([
            'workspace_id' => $workspace->id, 'channel_account_id' => $account->id,
            'contact_id' => $contact->id, 'status' => 'open', 'assigned_to' => 'human',
        ]);
        $message = Message::create([
            'conversation_id' => $conversation->id, 'direction' => 'in', 'channel' => 'whatsapp',
            'type' => 'text', 'body' => 'Hello', 'status' => 'delivered', 'sent_by' => 'human', 'sent_at' => now(),
        ]);
        $message->setRelation('conversation', $conversation->load('channelAccount'));

        app(AutoReplyListener::class)->handle(new MessageReceived($message));

        Queue::assertPushed(ProcessChannelAiReplyJob::class);
        $this->assertSame('bot', $conversation->fresh()->assigned_to);
    }

    public function test_human_pause_blocks_ai_until_resolution(): void
    {
        ['workspace' => $workspace, 'user' => $user] = $this->createWorkspaceContext();
        $bot = AiChatbot::factory()->create(['workspace_id' => $workspace->id]);
        $this->policy($workspace->id, 'omni', $bot->id);
        $account = $this->account($workspace->id, 'messenger', now()->subMinute());
        $conversation = Conversation::create([
            'workspace_id' => $workspace->id, 'channel_account_id' => $account->id,
            'contact_id' => Contact::factory()->create(['workspace_id' => $workspace->id])->id,
            'status' => 'open', 'assigned_to' => 'bot',
        ]);

        app(ConversationOwnershipService::class)->join($conversation, $user);
        $this->assertNotNull($conversation->fresh()->ai_paused_at);
        app(ConversationOwnershipService::class)->leave($conversation->fresh(), $user);
        $this->assertNotNull($conversation->fresh()->ai_paused_at);
        app(ConversationOwnershipService::class)->resolve($conversation->fresh());
        $this->assertNull($conversation->fresh()->ai_paused_at);
    }

    private function account(int $workspaceId, string $channel, $eligibleFrom = null): ChannelAccount
    {
        return ChannelAccount::create([
            'workspace_id' => $workspaceId, 'channel' => $channel, 'provider' => 'test',
            'display_name' => ucfirst($channel).' account', 'status' => 'active',
            'ai_eligible_from_at' => $eligibleFrom ?: now()->subMinute(),
        ]);
    }

    private function policy(int $workspaceId, string $segment, int $botId, string $mode = 'always_on', ?array $schedule = null): void
    {
        WorkspaceAiAnsweringPolicy::create([
            'workspace_id' => $workspaceId, 'segment' => $segment, 'mode' => $mode,
            'chatbot_id' => $botId, 'schedule_json' => $schedule, 'enabled_at' => now()->subMinute(),
        ]);
    }

    private function schedule(string $timezone): array
    {
        $days = collect(['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'])
            ->mapWithKeys(fn (string $day) => [$day => ['enabled' => false, 'all_day' => false, 'windows' => []]])->all();
        $days['tue'] = ['enabled' => true, 'all_day' => false, 'windows' => [['start' => '02:00', 'end' => '07:00']]];

        return ['enabled' => true, 'mode' => 'scheduled', 'timezone' => $timezone, 'schedule' => $days];
    }
}
