<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Workspace;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use App\Notifications\NewMessageNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{user: User, first: Workspace, second: Workspace} */
    private function userWithTwoWorkspaces(): array
    {
        $user = User::factory()->create([
            'role' => 'client',
            'email_verified_at' => now(),
        ]);
        $first = Workspace::factory()->create(['owner_id' => $user->id]);
        $second = Workspace::factory()->create();
        $second->members()->attach($user->id, ['role' => 'member']);
        $user->update(['workspace_id' => $first->id]);

        return compact('user', 'first', 'second');
    }

    private function createNotification(User $user, Workspace $workspace, string $message): string
    {
        $id = (string) Str::uuid();
        $user->notifications()->create([
            'id' => $id,
            'type' => 'App\\Notifications\\TestNotification',
            'workspace_id' => $workspace->id,
            'data' => ['message' => $message],
        ]);

        return $id;
    }

    public function test_web_notification_reads_and_counts_are_scoped_to_current_workspace(): void
    {
        ['user' => $user, 'first' => $first, 'second' => $second] = $this->userWithTwoWorkspaces();
        $this->createNotification($user, $first, 'First workspace');
        $this->createNotification($user, $second, 'Second workspace');

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $first->id])
            ->get(route('client.notifications.recent'))
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.workspace_id', $first->id)
            ->assertJsonPath('0.data.message', 'First workspace');

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $first->id])
            ->get(route('client.notifications.unread-count'))
            ->assertOk()
            ->assertJson(['count' => 1]);

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $second->id])
            ->get(route('client.notifications.recent'))
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.workspace_id', $second->id)
            ->assertJsonPath('0.data.message', 'Second workspace');
    }

    public function test_web_mutations_cannot_change_another_workspaces_notifications(): void
    {
        ['user' => $user, 'first' => $first, 'second' => $second] = $this->userWithTwoWorkspaces();
        $firstId = $this->createNotification($user, $first, 'First workspace');
        $secondId = $this->createNotification($user, $second, 'Second workspace');

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $first->id])
            ->postJson(route('client.notifications.read', $secondId))
            ->assertNotFound();

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $first->id])
            ->deleteJson(route('client.notifications.destroy', $secondId))
            ->assertNotFound();

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $first->id])
            ->post(route('client.notifications.read-all'))
            ->assertRedirect();

        $this->assertNotNull($user->notifications()->findOrFail($firstId)->read_at);
        $this->assertNull($user->notifications()->findOrFail($secondId)->read_at);

    }

    public function test_mobile_notification_api_is_scoped_to_users_active_workspace(): void
    {
        ['user' => $user, 'first' => $first, 'second' => $second] = $this->userWithTwoWorkspaces();
        $firstId = $this->createNotification($user, $first, 'First workspace');
        $secondId = $this->createNotification($user, $second, 'Second workspace');
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $firstId)
            ->assertJsonPath('data.0.workspace_id', $first->id);

        $this->getJson('/api/v1/notifications/unread-count')
            ->assertOk()
            ->assertJson(['count' => 1]);

        $this->postJson("/api/v1/notifications/{$secondId}/read")->assertNotFound();
        $this->deleteJson("/api/v1/notifications/{$secondId}")->assertNotFound();
        $this->postJson('/api/v1/notifications/read-all')->assertOk()->assertJson(['updated' => 1]);

        $this->assertNotNull($user->notifications()->findOrFail($firstId)->read_at);
        $this->assertNull($user->notifications()->findOrFail($secondId)->read_at);

        $user->update(['workspace_id' => $second->id]);
        $this->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $secondId)
            ->assertJsonPath('data.0.workspace_id', $second->id);
    }

    public function test_mobile_notification_api_enriches_legacy_conversation_notifications_with_uuid(): void
    {
        ['user' => $user, 'first' => $workspace] = $this->userWithTwoWorkspaces();
        $contact = Contact::factory()->create(['workspace_id' => $workspace->id]);
        $conversation = Conversation::create([
            'workspace_id' => $workspace->id,
            'contact_id' => $contact->id,
            'status' => 'open',
        ]);
        $user->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => NewMessageNotification::class,
            'workspace_id' => $workspace->id,
            'data' => ['conversation_id' => $conversation->id],
        ]);
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonPath('data.0.data.conversation_uuid', $conversation->uuid);
    }

    public function test_legacy_notification_uuid_fallback_is_workspace_scoped(): void
    {
        ['user' => $user, 'first' => $first, 'second' => $second] = $this->userWithTwoWorkspaces();
        $contact = Contact::factory()->create(['workspace_id' => $second->id]);
        $conversation = Conversation::create([
            'workspace_id' => $second->id,
            'contact_id' => $contact->id,
            'status' => 'open',
        ]);
        $user->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => NewMessageNotification::class,
            'workspace_id' => $first->id,
            'data' => ['conversation_id' => $conversation->id],
        ]);
        Sanctum::actingAs($user);

        $data = $this->getJson('/api/v1/notifications')->assertOk()->json('data.0.data');

        $this->assertArrayNotHasKey('conversation_uuid', $data);
    }

    public function test_notification_serialization_preserves_existing_conversation_uuid(): void
    {
        ['user' => $user, 'first' => $workspace] = $this->userWithTwoWorkspaces();
        $user->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => NewMessageNotification::class,
            'workspace_id' => $workspace->id,
            'data' => [
                'conversation_id' => 999999,
                'conversation_uuid' => 'existing-uuid',
            ],
        ]);
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonPath('data.0.data.conversation_uuid', 'existing-uuid');
    }

    public function test_database_channel_uses_source_workspace_instead_of_users_active_workspace(): void
    {
        ['user' => $user, 'first' => $first, 'second' => $second] = $this->userWithTwoWorkspaces();
        $contact = Contact::factory()->create(['workspace_id' => $second->id]);
        $conversation = Conversation::create([
            'workspace_id' => $second->id,
            'contact_id' => $contact->id,
            'status' => 'open',
        ]);
        $message = Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'in',
            'channel' => 'whatsapp',
            'body' => 'Workspace-scoped message',
            'status' => 'sent',
            'sent_at' => now(),
        ]);

        $user->notify(new NewMessageNotification($message, $conversation));

        $stored = $user->notifications()->sole();
        $this->assertSame($second->id, (int) $stored->workspace_id);
        $this->assertSame($second->id, (int) $stored->data['workspace_id']);
        $this->assertSame($conversation->uuid, $stored->data['conversation_uuid']);
        $this->assertNotSame($first->id, (int) $stored->workspace_id);
    }

    public function test_email_inbox_notifications_default_on_and_are_exposed_by_mobile_profile(): void
    {
        ['user' => $user] = $this->userWithTwoWorkspaces();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('email_inbox_notifications_enabled', true);
    }

    public function test_user_can_update_only_their_email_inbox_notification_preference(): void
    {
        ['user' => $user] = $this->userWithTwoWorkspaces();
        $other = User::factory()->create(['email_inbox_notifications_enabled' => true]);
        Sanctum::actingAs($user);

        $this->putJson('/api/v1/notifications/preferences/email-inbox', ['enabled' => false])
            ->assertOk()
            ->assertExactJson(['email_inbox_notifications_enabled' => false]);

        $this->assertFalse($user->fresh()->email_inbox_notifications_enabled);
        $this->assertTrue($other->fresh()->email_inbox_notifications_enabled);

        $this->putJson('/api/v1/notifications/preferences/email-inbox', ['enabled' => 'invalid'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('enabled');
    }

    public function test_web_user_can_update_email_inbox_notification_preference(): void
    {
        ['user' => $user, 'first' => $workspace] = $this->userWithTwoWorkspaces();

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->put(route('client.notification-preferences.email-inbox.update'), ['enabled' => false])
            ->assertRedirect();

        $this->assertFalse($user->fresh()->email_inbox_notifications_enabled);
    }

    public function test_email_inbox_preference_suppresses_only_email_message_notifications(): void
    {
        $user = User::factory()->create(['email_inbox_notifications_enabled' => false]);
        $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
        $contact = Contact::factory()->create(['workspace_id' => $workspace->id]);
        $conversation = Conversation::create([
            'workspace_id' => $workspace->id,
            'contact_id' => $contact->id,
            'status' => 'open',
        ]);
        $email = Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'in',
            'channel' => 'email',
            'body' => 'Email message',
            'status' => 'sent',
            'sent_at' => now(),
        ]);
        $whatsapp = Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'in',
            'channel' => 'whatsapp',
            'body' => 'WhatsApp message',
            'status' => 'sent',
            'sent_at' => now(),
        ]);

        $this->assertSame([], (new NewMessageNotification($email, $conversation))->via($user));
        $this->assertNotSame([], (new NewMessageNotification($whatsapp, $conversation))->via($user));

        $user->update(['email_inbox_notifications_enabled' => true]);
        $this->assertNotSame([], (new NewMessageNotification($email, $conversation))->via($user->fresh()));
    }

    public function test_guest_cannot_access_notifications(): void
    {
        $this->get(route('client.notifications.index'))
            ->assertRedirect(route('login'));

        $this->putJson('/api/v1/notifications/preferences/email-inbox', ['enabled' => false])
            ->assertUnauthorized();
    }
}
