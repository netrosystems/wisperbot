<?php

namespace Tests\Feature\Inbox;

use App\Events\ConversationActivityCreated;
use App\Events\ConversationOwnershipChanged;
use App\Events\MessageReceived;
use App\Events\MessageSent;
use App\Models\User;
use App\Modules\Inbox\Models\WorkspaceMemberAvailability;
use App\Modules\Inbox\Services\ConversationOwnershipService;
use App\Modules\Inbox\Services\WebchatDriver;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class ConversationOwnershipTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_agent_to_join_owns_the_chat_and_other_join_is_rejected(): void
    {
        [$conversation, $admin, $staff] = $this->conversationContext();
        $conversation->update(['unread_count' => 4, 'last_message_at' => now()->subHour()]);
        $lastMessageAt = $conversation->fresh()->last_message_at;

        $this->actingAs($admin)->postJson(route('client.inbox.join', $conversation))->assertOk();
        $this->actingAs($admin)->postJson(route('client.inbox.join', $conversation))->assertOk();
        $this->actingAs($staff)->postJson(route('client.inbox.join', $conversation))
            ->assertStatus(409)
            ->assertJsonPath('joined_user.id', $admin->id);

        $conversation->refresh();
        $this->assertSame($admin->id, $conversation->joined_user_id);
        $this->assertSame($admin->id, $conversation->assigned_user_id);
        $this->assertSame('human', $conversation->assigned_to);
        $this->assertSame(4, $conversation->unread_count);
        $this->assertEquals($lastMessageAt, $conversation->last_message_at);

        $activity = $conversation->messages()->where('direction', 'system')->sole();
        $this->assertSame('event', $activity->type);
        $this->assertSame('system', $activity->sent_by);
        $this->assertNull($activity->provider_message_id);
        $this->assertSame("{$admin->name} joined the chat", $activity->body);
        $this->assertSame('conversation.joined', $activity->payload['activity']['type']);
        $this->assertSame($admin->id, $activity->payload['activity']['actor']['id']);
        $this->assertSame($admin->name, $activity->payload['activity']['actor']['name']);
    }

    public function test_human_reply_requires_the_joined_owner(): void
    {
        [$conversation, $admin, $staff] = $this->conversationContext();
        $this->actingAs($admin)->postJson(route('client.inbox.join', $conversation))->assertOk();

        $this->actingAs($staff)->postJson(route('client.inbox.reply', $conversation), ['body' => 'Hello'])
            ->assertStatus(409)
            ->assertJsonPath('error', 'Join this chat before replying.');
    }

    public function test_resolution_clears_ownership_and_preserves_messages(): void
    {
        [$conversation, $admin] = $this->conversationContext();
        $this->actingAs($admin)->postJson(route('client.inbox.join', $conversation))->assertOk();
        $conversation->messages()->create([
            'direction' => 'in', 'channel' => 'webchat', 'type' => 'text', 'body' => 'Keep me',
            'status' => 'delivered', 'sent_by' => 'human', 'sent_at' => now(),
        ]);
        $conversation->update(['unread_count' => 3]);

        $this->actingAs($admin)->post(route('client.inbox.status', $conversation), ['status' => 'resolved'])->assertRedirect();

        $conversation->refresh();
        $this->assertSame('resolved', $conversation->status);
        $this->assertSame(0, $conversation->unread_count);
        $this->assertNull($conversation->assigned_user_id);
        $this->assertNull($conversation->joined_user_id);
        $this->assertNull($conversation->joined_at);
        $this->assertNull($conversation->handover_at);
        $this->assertSame(1, $conversation->contentMessages()->count());
        $this->assertSame(2, $conversation->messages()->where('direction', 'system')->count());
        $resolved = $conversation->messages()->where('direction', 'system')->latest('id')->firstOrFail();
        $this->assertSame("Resolved by {$admin->name}", $resolved->body);
        $this->assertSame('conversation.resolved', $resolved->payload['activity']['type']);
        $this->assertSame('Keep me', $conversation->fresh()->lastMessage?->body);

        $this->actingAs($admin)->post(route('client.inbox.status', $conversation), ['status' => 'resolved'])->assertRedirect();
        $this->assertSame(2, $conversation->messages()->where('direction', 'system')->count());
    }

    public function test_inbound_reopens_the_same_resolved_conversation_and_resets_stale_control_state(): void
    {
        Event::fake([ConversationOwnershipChanged::class]);
        [$conversation, $admin] = $this->conversationContext();
        $conversation->messages()->create([
            'direction' => 'in', 'channel' => 'webchat', 'type' => 'text', 'body' => 'Existing history',
            'status' => 'delivered', 'sent_by' => 'human', 'sent_at' => now()->subHour(),
        ]);
        $stale = $conversation->fresh();
        $conversation->update([
            'status' => 'resolved',
            'resolved_at' => now()->subMinute(),
            'assigned_user_id' => $admin->id,
            'joined_user_id' => $admin->id,
            'joined_at' => now()->subMinutes(5),
            'handover_at' => now()->subMinutes(4),
            'ai_paused_at' => now()->subMinutes(3),
            'ai_pause_reason' => 'joined',
            'first_response_at' => now()->subMinutes(2),
            'unanswered_reminder_sent_at' => now()->subMinute(),
        ]);

        $receivedAt = now();
        $reopened = app(ConversationOwnershipService::class)->prepareInbound(
            $stale,
            $receivedAt,
            1,
        );

        $conversation->refresh();
        $this->assertTrue($reopened);
        $this->assertSame($stale->id, $conversation->id);
        $this->assertSame('open', $conversation->status);
        $this->assertNull($conversation->resolved_at);
        $this->assertNull($conversation->assigned_user_id);
        $this->assertNull($conversation->joined_user_id);
        $this->assertNull($conversation->joined_at);
        $this->assertNull($conversation->handover_at);
        $this->assertNull($conversation->ai_paused_at);
        $this->assertNull($conversation->ai_pause_reason);
        $this->assertNull($conversation->first_response_at);
        $this->assertNull($conversation->unanswered_reminder_sent_at);
        $this->assertSame(1, $conversation->unread_count);
        $this->assertEquals($receivedAt->toDateTimeString(), $conversation->last_inbound_at->toDateTimeString());
        $this->assertSame(2, $conversation->messages()->count());
        $reopenedActivity = $conversation->messages()->where('direction', 'system')->sole();
        $this->assertSame('conversation.reopened', $reopenedActivity->payload['activity']['type']);
        $this->assertSame('Chat reopened after a new customer message', $reopenedActivity->body);
        $this->assertArrayNotHasKey('actor', $reopenedActivity->payload['activity']);
        Event::assertDispatchedTimes(ConversationOwnershipChanged::class, 1);

        $this->assertFalse(app(ConversationOwnershipService::class)->prepareInbound($conversation));
        Event::assertDispatchedTimes(ConversationOwnershipChanged::class, 1);
        $this->assertSame(1, $conversation->messages()->where('direction', 'system')->count());
    }

    public function test_webchat_message_reuses_and_reopens_the_resolved_thread_before_dispatch(): void
    {
        Event::fake([ConversationOwnershipChanged::class, MessageReceived::class]);
        [$conversation] = $this->conversationContext();
        $conversation->update([
            'status' => 'resolved',
            'resolved_at' => now()->subMinute(),
        ]);

        $message = app(WebchatDriver::class)->recordInboundMessage(
            $conversation->fresh(),
            'visitor-1',
            'I need help again',
        );

        $conversation->refresh();
        $this->assertSame('open', $conversation->status);
        $this->assertNull($conversation->resolved_at);
        $this->assertSame($conversation->id, $message->conversation_id);
        $this->assertSame(1, Conversation::where('workspace_id', $conversation->workspace_id)->count());
        Event::assertDispatched(MessageReceived::class, function (MessageReceived $event) use ($conversation): bool {
            return $event->reopened
                && $event->message->conversation_id === $conversation->id
                && $event->message->conversation->status === 'open';
        });
    }

    public function test_available_agent_can_take_over_from_off_shift_owner(): void
    {
        [$conversation, $admin, $staff] = $this->conversationContext();
        $this->actingAs($admin)->postJson(route('client.inbox.join', $conversation))->assertOk();
        WorkspaceMemberAvailability::create([
            'workspace_id' => $conversation->workspace_id,
            'user_id' => $admin->id,
            'enabled' => true,
            'timezone' => 'UTC',
            'schedule_json' => [],
        ]);

        $this->actingAs($staff)->postJson(route('client.inbox.takeover', $conversation))->assertOk();
        $this->assertSame($staff->id, $conversation->fresh()->joined_user_id);
        $activities = $conversation->messages()->where('direction', 'system')->orderBy('id')->get();
        $this->assertCount(2, $activities);
        $this->assertSame("{$staff->name} took over the chat from {$admin->name}", $activities->last()->body);
        $this->assertSame('conversation.transferred', $activities->last()->payload['activity']['type']);
        $this->assertSame($admin->id, $activities->last()->payload['activity']['previous_actor']['id']);
    }

    public function test_leave_assignment_and_status_changes_create_staff_activity_only_on_change(): void
    {
        [$conversation, $admin, $staff] = $this->conversationContext();
        $service = app(ConversationOwnershipService::class);

        $service->join($conversation, $admin);
        $service->leave($conversation->fresh(), $admin);
        $service->leave($conversation->fresh(), $admin);
        $service->assign($conversation->fresh(), $staff, $admin);
        $service->assign($conversation->fresh(), $staff, $admin);
        $service->assign($conversation->fresh(), null, $admin);
        $service->assign($conversation->fresh(), null, $admin);
        $service->changeStatus($conversation->fresh(), 'pending', $admin);
        $service->changeStatus($conversation->fresh(), 'pending', $admin);
        $service->changeStatus($conversation->fresh(), 'snoozed', $admin);
        $service->changeStatus($conversation->fresh(), 'open', $admin);

        $activities = $conversation->messages()->where('direction', 'system')->orderBy('id')->get();
        $this->assertSame([
            'conversation.joined',
            'conversation.left',
            'conversation.assigned',
            'conversation.unassigned',
            'conversation.pending',
            'conversation.snoozed',
            'conversation.reopened',
        ], $activities->pluck('payload.activity.type')->all());
        $this->assertSame($staff->id, $activities[2]->payload['activity']['subject']['id']);
        $this->assertSame('snoozed', $activities[6]->payload['activity']['previous_status']);
    }

    public function test_releasing_an_inactive_or_removed_agent_records_who_removed_them(): void
    {
        [$conversation, $admin, $staff] = $this->conversationContext();
        $service = app(ConversationOwnershipService::class);
        $service->join($conversation, $staff);

        $service->releaseUser($staff->id, actor: $admin);

        $conversation->refresh();
        $this->assertNull($conversation->joined_user_id);
        $activity = $conversation->messages()->where('direction', 'system')->latest('id')->firstOrFail();
        $this->assertSame('conversation.left', $activity->payload['activity']['type']);
        $this->assertSame($admin->id, $activity->payload['activity']['actor']['id']);
        $this->assertSame($staff->id, $activity->payload['activity']['subject']['id']);
        $this->assertSame("{$admin->name} removed {$staff->name} from the chat", $activity->body);
    }

    public function test_off_shift_agent_cannot_take_over_and_empty_leave_preserves_preassignment(): void
    {
        [$conversation, $admin, $staff] = $this->conversationContext();
        $conversation->update(['assigned_user_id' => $admin->id]);

        $this->actingAs($staff)->postJson(route('client.inbox.leave', $conversation))->assertOk();
        $this->assertSame($admin->id, $conversation->fresh()->assigned_user_id);

        $this->actingAs($admin)->postJson(route('client.inbox.join', $conversation))->assertOk();
        WorkspaceMemberAvailability::create([
            'workspace_id' => $conversation->workspace_id,
            'user_id' => $admin->id,
            'enabled' => true,
            'timezone' => 'UTC',
            'schedule_json' => [],
        ]);
        WorkspaceMemberAvailability::create([
            'workspace_id' => $conversation->workspace_id,
            'user_id' => $staff->id,
            'enabled' => true,
            'timezone' => 'UTC',
            'schedule_json' => [],
        ]);

        $this->actingAs($staff)->postJson(route('client.inbox.takeover', $conversation))
            ->assertForbidden()
            ->assertJsonPath('error', 'You must be currently available to take over this chat.');
        $this->assertSame($admin->id, $conversation->fresh()->joined_user_id);
        $this->assertSame(1, $conversation->messages()->where('direction', 'system')->count());
    }

    public function test_activity_record_and_broadcast_contract_exist_only_after_a_successful_state_change(): void
    {
        Event::fake([MessageReceived::class, MessageSent::class]);
        [$conversation, $admin, $staff] = $this->conversationContext();

        $this->actingAs($admin)->postJson(route('client.inbox.join', $conversation))->assertOk();
        $this->actingAs($admin)->postJson(route('client.inbox.join', $conversation))->assertOk();
        $this->actingAs($staff)->postJson(route('client.inbox.join', $conversation))->assertStatus(409);

        $this->assertSame(1, $conversation->messages()->where('direction', 'system')->count());
        $activity = $conversation->messages()->where('direction', 'system')->sole();
        $payload = (new ConversationActivityCreated($activity))->broadcastWith();
        $this->assertSame('system', $payload['direction']);
        $this->assertSame('event', $payload['type']);
        $this->assertSame('conversation.joined', $payload['payload']['activity']['type']);
        Event::assertNotDispatched(MessageReceived::class);
        Event::assertNotDispatched(MessageSent::class);
    }

    /** @return array{Conversation, User, User} */
    private function conversationContext(): array
    {
        ['workspace' => $workspace, 'user' => $admin, 'client' => $client] = $this->createWorkspaceContext();
        $staff = User::factory()->create([
            'client_id' => $client->id,
            'workspace_id' => $workspace->id,
            'client_role' => User::CLIENT_ROLE_STAFF,
            'role' => User::ROLE_CLIENT,
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);
        $account = ChannelAccount::create([
            'workspace_id' => $workspace->id, 'channel' => 'webchat', 'display_name' => 'Web', 'status' => 'active',
        ]);
        $contact = Contact::factory()->create(['workspace_id' => $workspace->id]);
        $conversation = Conversation::create([
            'workspace_id' => $workspace->id, 'channel_account_id' => $account->id,
            'contact_id' => $contact->id, 'status' => 'open', 'assigned_to' => 'human',
        ]);

        return [$conversation, $admin, $staff];
    }
}
