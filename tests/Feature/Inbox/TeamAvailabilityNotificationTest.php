<?php

namespace Tests\Feature\Inbox;

use App\Events\MessageReceived;
use App\Listeners\SendNewMessageNotification;
use App\Models\User;
use App\Modules\Inbox\Models\WorkspaceMemberAvailability;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use App\Notifications\NewMessageNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class TeamAvailabilityNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_off_shift_joined_owner_escalates_new_message_to_available_teammate(): void
    {
        ['workspace' => $workspace, 'user' => $owner, 'client' => $client] = $this->createWorkspaceContext();
        $teammate = User::factory()->create([
            'client_id' => $client->id, 'workspace_id' => $workspace->id,
            'role' => User::ROLE_CLIENT, 'client_role' => User::CLIENT_ROLE_STAFF,
            'status' => User::STATUS_ACTIVE,
        ]);
        WorkspaceMemberAvailability::create([
            'workspace_id' => $workspace->id,
            'user_id' => $owner->id,
            'enabled' => true,
            'timezone' => 'UTC',
            'schedule_json' => [],
        ]);
        $contact = Contact::factory()->create(['workspace_id' => $workspace->id]);
        $conversation = Conversation::create([
            'workspace_id' => $workspace->id, 'contact_id' => $contact->id,
            'status' => 'open', 'assigned_to' => 'human',
            'assigned_user_id' => $owner->id, 'joined_user_id' => $owner->id, 'joined_at' => now(),
        ]);
        $message = Message::create([
            'conversation_id' => $conversation->id, 'direction' => 'in', 'channel' => 'webchat',
            'type' => 'text', 'body' => 'Anyone there?', 'status' => 'delivered', 'sent_by' => 'human', 'sent_at' => now(),
        ]);
        $message->setRelation('conversation', $conversation);
        Notification::fake();

        app(SendNewMessageNotification::class)->handle(new MessageReceived($message));

        Notification::assertNotSentTo($owner, NewMessageNotification::class);
        Notification::assertSentTo($teammate, NewMessageNotification::class);
    }
}
