<?php

namespace Tests\Feature\Inbox;

use App\Modules\Inbox\Services\GenericMailboxClient;
use App\Modules\Shared\Contracts\ChannelDriverInterface;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use App\Modules\Shared\Services\ChannelManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
use Tests\TestCase;

class EmailMasterBoxTest extends TestCase
{
    use RefreshDatabase;

    public function test_email_mastebox_only_returns_email_conversations_and_mailboxes(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();
        $email = ChannelAccount::create([
            'workspace_id' => $workspace->id,
            'channel' => 'email',
            'provider' => 'gmail',
            'business_account_id' => 'team@example.com',
            'display_name' => 'Team Gmail',
            'status' => 'active',
            'meta_json' => ['email' => 'team@example.com'],
        ]);
        $webchat = ChannelAccount::create([
            'workspace_id' => $workspace->id,
            'channel' => 'webchat',
            'display_name' => 'Website',
            'status' => 'active',
        ]);
        foreach ([$email, $webchat] as $account) {
            $contact = Contact::create(['workspace_id' => $workspace->id, 'source' => $account->channel, 'first_name' => $account->channel]);
            Conversation::create(['workspace_id' => $workspace->id, 'channel_account_id' => $account->id, 'contact_id' => $contact->id, 'status' => 'open', 'last_message_at' => now()]);
        }

        $this->actingAs($user)->get(route('client.inbox.email-inbox'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Inbox/EmailMasterBox')
                ->where('conversations.total', 1)
                ->has('accounts', 1)
                ->where('accounts.0.email', 'team@example.com'));
    }

    public function test_client_can_compose_from_a_connected_generic_mailbox(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();
        $account = ChannelAccount::create([
            'workspace_id' => $workspace->id,
            'channel' => 'email',
            'provider' => 'imap_smtp',
            'business_account_id' => 'team@example.com',
            'display_name' => 'Team Gmail',
            'status' => 'active',
            'meta_json' => ['email' => 'team@example.com'],
        ]);
        $client = Mockery::mock(GenericMailboxClient::class);
        $client->shouldReceive('send')
            ->once()
            ->withArgs(fn ($mailbox, $to, $subject, $body, $replyTo, $cc, $bcc) => $mailbox->is($account)
                && $to === 'buyer@example.net'
                && $subject === 'Welcome'
                && $body === 'Hello from our team.'
                && $replyTo === null
                && $cc === ['manager@example.com']
                && $bcc === []
            )
            ->andReturn('smtp:test-message');
        $this->app->instance(GenericMailboxClient::class, $client);

        $response = $this->actingAs($user)->post(route('client.inbox.email.compose'), [
            'channel_account_id' => $account->id,
            'to' => 'buyer@example.net',
            'cc' => 'manager@example.com',
            'subject' => 'Welcome',
            'body' => 'Hello from our team.',
        ]);

        $conversation = Conversation::latest('id')->firstOrFail();
        $response->assertRedirect(route('client.inbox.email-inbox', ['conversation' => $conversation->uuid]))
            ->assertSessionHas('success');
        $this->assertDatabaseHas('contacts', ['workspace_id' => $workspace->id, 'email' => 'buyer@example.net']);
        $this->assertDatabaseHas('messages', [
            'channel' => 'email',
            'direction' => 'out',
            'status' => 'sent',
            'provider_message_id' => 'smtp:test-message',
        ]);
        $this->assertSame('Welcome', Message::latest('id')->firstOrFail()->payload['subject']);
    }

    public function test_replying_from_masterbox_requires_the_agent_to_join_first(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();
        $account = ChannelAccount::create([
            'workspace_id' => $workspace->id,
            'channel' => 'email',
            'provider' => 'imap_smtp',
            'display_name' => 'Support Mailbox',
            'status' => 'active',
        ]);
        $contact = Contact::create([
            'workspace_id' => $workspace->id,
            'source' => 'email',
            'email' => 'customer@example.com',
        ]);
        $conversation = Conversation::create([
            'workspace_id' => $workspace->id,
            'channel_account_id' => $account->id,
            'contact_id' => $contact->id,
            'status' => 'open',
            'assigned_to' => 'human',
        ]);

        $driver = Mockery::mock(ChannelDriverInterface::class);
        $driver->shouldReceive('send')->once()->andReturn('smtp:reply-message');
        $manager = Mockery::mock(ChannelManager::class);
        $manager->shouldReceive('driver')->with('email')->andReturn($driver);
        $this->app->instance(ChannelManager::class, $manager);

        $this->actingAs($user)
            ->postJson(route('client.inbox.reply', $conversation), ['body' => 'Thanks for your email.'])
            ->assertConflict()
            ->assertJsonPath('error', 'Join this chat before replying.');

        $this->assertNull($conversation->fresh()->joined_user_id);

        $this->actingAs($user)
            ->postJson(route('client.inbox.join', $conversation))
            ->assertOk()
            ->assertJsonPath('conversation.joined_user.id', $user->id);

        $this->actingAs($user)
            ->postJson(route('client.inbox.reply', $conversation), ['body' => 'Thanks for your email.'])
            ->assertOk()
            ->assertJsonPath('message.status', 'sent');
    }
}
