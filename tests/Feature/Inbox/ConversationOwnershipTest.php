<?php

namespace Tests\Feature\Inbox;

use App\Models\User;
use App\Modules\Inbox\Models\WorkspaceMemberAvailability;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConversationOwnershipTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_agent_to_join_owns_the_chat_and_other_join_is_rejected(): void
    {
        [$conversation, $admin, $staff] = $this->conversationContext();

        $this->actingAs($admin)->postJson(route('client.inbox.join', $conversation))->assertOk();
        $this->actingAs($staff)->postJson(route('client.inbox.join', $conversation))
            ->assertStatus(409)
            ->assertJsonPath('joined_user.id', $admin->id);

        $conversation->refresh();
        $this->assertSame($admin->id, $conversation->joined_user_id);
        $this->assertSame($admin->id, $conversation->assigned_user_id);
        $this->assertSame('human', $conversation->assigned_to);
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

        $this->actingAs($admin)->post(route('client.inbox.status', $conversation), ['status' => 'resolved'])->assertRedirect();

        $conversation->refresh();
        $this->assertSame('resolved', $conversation->status);
        $this->assertNull($conversation->assigned_user_id);
        $this->assertNull($conversation->joined_user_id);
        $this->assertNull($conversation->joined_at);
        $this->assertNull($conversation->handover_at);
        $this->assertSame(1, $conversation->messages()->count());
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
