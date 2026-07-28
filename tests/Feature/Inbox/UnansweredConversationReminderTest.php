<?php

namespace Tests\Feature\Inbox;

use App\Mail\UnansweredConversationReminderMail;
use App\Models\User;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class UnansweredConversationReminderTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_queues_one_hour_reminder_for_owner_and_assigned_teammate_once(): void
    {
        Mail::fake();
        ['user' => $owner, 'workspace' => $workspace, 'client' => $client] = $this->createWorkspaceContext();
        $teammate = User::factory()->create([
            'role' => User::ROLE_CLIENT,
            'client_id' => $client->id,
            'workspace_id' => $workspace->id,
            'status' => User::STATUS_ACTIVE,
        ]);
        $account = ChannelAccount::create([
            'workspace_id' => $workspace->id,
            'channel' => 'webchat',
            'display_name' => 'Website',
            'status' => 'active',
        ]);
        $contact = Contact::create([
            'workspace_id' => $workspace->id,
            'first_name' => 'Waiting',
            'last_name' => 'Customer',
        ]);
        $inboundAt = now()->subMinutes(70);
        $conversation = Conversation::create([
            'workspace_id' => $workspace->id,
            'channel_account_id' => $account->id,
            'contact_id' => $contact->id,
            'status' => 'open',
            'assigned_user_id' => $teammate->id,
            'last_message_at' => $inboundAt,
            'last_inbound_at' => $inboundAt,
        ]);
        Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'in',
            'channel' => 'webchat',
            'type' => 'text',
            'body' => 'Can someone help me?',
            'status' => 'delivered',
            'sent_by' => 'human',
            'sent_at' => $inboundAt,
        ]);

        $this->artisan('inbox:send-unanswered-reminders')->assertSuccessful();

        Mail::assertQueued(UnansweredConversationReminderMail::class, 2);
        Mail::assertQueued(
            UnansweredConversationReminderMail::class,
            fn ($mail) => $mail->hasTo($owner->email)
                && $mail->customerName === 'Waiting Customer'
                && $mail->messagePreview === 'Can someone help me?',
        );
        Mail::assertQueued(
            UnansweredConversationReminderMail::class,
            fn ($mail) => $mail->hasTo($teammate->email),
        );
        $this->assertNotNull($conversation->fresh()->unanswered_reminder_sent_at);

        $this->artisan('inbox:send-unanswered-reminders')->assertSuccessful();
        Mail::assertQueued(UnansweredConversationReminderMail::class, 2);
    }

    public function test_it_does_not_remind_after_a_successful_reply(): void
    {
        Mail::fake();
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $account = ChannelAccount::create([
            'workspace_id' => $workspace->id,
            'channel' => 'webchat',
            'display_name' => 'Website',
            'status' => 'active',
        ]);
        $contact = Contact::create([
            'workspace_id' => $workspace->id,
            'first_name' => 'Answered Customer',
        ]);
        $inboundAt = now()->subMinutes(70);
        $conversation = Conversation::create([
            'workspace_id' => $workspace->id,
            'channel_account_id' => $account->id,
            'contact_id' => $contact->id,
            'status' => 'open',
            'last_message_at' => $inboundAt,
            'last_inbound_at' => $inboundAt,
        ]);
        Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'in',
            'channel' => 'webchat',
            'type' => 'text',
            'body' => 'Question',
            'status' => 'delivered',
            'sent_by' => 'human',
            'sent_at' => $inboundAt,
        ]);
        Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'out',
            'channel' => 'webchat',
            'type' => 'text',
            'body' => 'Answered',
            'status' => 'sent',
            'sent_by' => 'human',
            'sent_at' => $inboundAt->copy()->addMinutes(5),
        ]);

        $this->artisan('inbox:send-unanswered-reminders')->assertSuccessful();

        Mail::assertNothingQueued();
        $this->assertNull($conversation->fresh()->unanswered_reminder_sent_at);
    }
}
