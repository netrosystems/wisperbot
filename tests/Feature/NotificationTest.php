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
        $this->assertNotSame($first->id, (int) $stored->workspace_id);
    }

    public function test_guest_cannot_access_notifications(): void
    {
        $this->get(route('client.notifications.index'))
            ->assertRedirect(route('login'));
    }
}
