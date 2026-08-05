<?php

namespace Tests\Feature\Inbox;

use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class InboxStatusFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_view_includes_open_resolved_and_snoozed_conversations(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();
        $account = ChannelAccount::create([
            'workspace_id' => $workspace->id,
            'channel' => 'webchat',
            'display_name' => 'Website chat',
            'status' => 'active',
        ]);

        foreach (['open', 'resolved', 'snoozed'] as $status) {
            $contact = Contact::create([
                'workspace_id' => $workspace->id,
                'source' => 'webchat',
                'first_name' => ucfirst($status),
            ]);
            $conversation = Conversation::create([
                'workspace_id' => $workspace->id,
                'channel_account_id' => $account->id,
                'contact_id' => $contact->id,
                'status' => $status,
                'external_thread_id' => 'visitor-'.$status,
                'last_message_at' => now(),
            ]);
            Message::create([
                'conversation_id' => $conversation->id,
                'direction' => 'in',
                'channel' => 'webchat',
                'type' => 'text',
                'body' => 'Hello',
                'status' => 'delivered',
                'sent_at' => now(),
            ]);
        }

        $this->actingAs($user)->get(route('client.inbox.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Inbox/Index')
                ->where('conversations.total', 3)
            );

        $this->actingAs($user)->get(route('client.inbox.index', ['folder' => 'resolved']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('conversations.total', 1));

        $this->actingAs($user)->get(route('client.inbox.index', ['folder' => 'snoozed']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('conversations.total', 1));
    }

    public function test_live_users_view_includes_online_presence_without_polluting_all_inbox(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();
        $account = ChannelAccount::create([
            'workspace_id' => $workspace->id,
            'channel' => 'webchat',
            'display_name' => 'Website chat',
            'status' => 'active',
        ]);
        $contact = Contact::create([
            'workspace_id' => $workspace->id,
            'source' => 'webchat',
            'first_name' => 'Customer 1',
            'last_seen_at' => now(),
            'custom_fields' => ['webchat_last_ip' => '203.0.113.8'],
        ]);
        Conversation::create([
            'workspace_id' => $workspace->id,
            'channel_account_id' => $account->id,
            'contact_id' => $contact->id,
            'status' => 'open',
            'external_thread_id' => 'live-customer',
        ]);

        $this->actingAs($user)->get(route('client.inbox.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('conversations.total', 0)
                ->where('liveUsersCount', 1));

        $this->actingAs($user)->get(route('client.inbox.index', ['folder' => 'live']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('conversations.total', 1)
                ->where('conversations.data.0.contact.custom_fields.webchat_last_ip', '203.0.113.8'));
    }
}
