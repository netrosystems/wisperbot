<?php

namespace Tests\Feature\Api\V1;

use App\Models\User;
use App\Models\Workspace;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MobileConversationTest extends TestCase
{
    use RefreshDatabase;

    public function test_mobile_conversations_index_includes_assigned_fields(): void
    {
        $workspace = Workspace::factory()->create();
        $user = User::factory()->create(['workspace_id' => $workspace->id]);
        $contact = Contact::create([
            'workspace_id' => $workspace->id,
            'first_name' => 'Alice',
        ]);

        $assignedAgent = User::factory()->create([
            'workspace_id' => $workspace->id,
            'name' => 'John Agent',
        ]);

        $conversation = Conversation::create([
            'workspace_id' => $workspace->id,
            'contact_id' => $contact->id,
            'assigned_user_id' => $assignedAgent->id,
            'assigned_to' => 'human',
            'status' => 'open',
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/mobile/conversations');

        $response->assertOk()
            ->assertJsonPath('data.0.id', $conversation->id)
            ->assertJsonPath('data.0.assigned_user_id', $assignedAgent->id)
            ->assertJsonPath('data.0.assigned_to', 'human')
            ->assertJsonPath('data.0.assigned_user.id', $assignedAgent->id)
            ->assertJsonPath('data.0.assigned_user.name', 'John Agent');
    }

    public function test_mobile_conversation_show_returns_all_messages(): void
    {
        $workspace = Workspace::factory()->create();
        $user = User::factory()->create(['workspace_id' => $workspace->id]);
        $contact = Contact::create([
            'workspace_id' => $workspace->id,
            'first_name' => 'Bob',
        ]);

        $conversation = Conversation::create([
            'workspace_id' => $workspace->id,
            'contact_id' => $contact->id,
            'status' => 'open',
        ]);

        for ($i = 1; $i <= 55; $i++) {
            $conversation->messages()->create([
                'direction' => 'in',
                'channel' => 'webchat',
                'type' => 'text',
                'body' => "Message {$i}",
                'status' => 'delivered',
                'sent_by' => 'human',
                'sent_at' => now()->addSeconds($i),
            ]);
        }

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/v1/mobile/conversations/{$conversation->uuid}");

        $response->assertOk();
        $this->assertCount(55, $response->json('messages'));
        $this->assertEquals('Message 55', $response->json('messages.54.body'));
    }

    public function test_mobile_can_update_contact_by_id(): void
    {
        $workspace = Workspace::factory()->create();
        $user = User::factory()->create(['workspace_id' => $workspace->id]);
        $contact = Contact::create([
            'workspace_id' => $workspace->id,
            'first_name' => 'Old',
            'last_name' => 'Name',
            'email' => 'old@example.com',
        ]);

        Sanctum::actingAs($user);

        $response = $this->patchJson("/api/v1/mobile/contacts/{$contact->id}", [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'phone' => '+1234567890',
        ]);

        $response->assertOk()
            ->assertJsonPath('name', 'John Doe')
            ->assertJsonPath('first_name', 'John')
            ->assertJsonPath('last_name', 'Doe')
            ->assertJsonPath('email', 'john@example.com');

        $contact->refresh();
        $this->assertSame('John', $contact->first_name);
        $this->assertSame('Doe', $contact->last_name);
        $this->assertSame('john@example.com', $contact->email);
        $this->assertSame('+1234567890', $contact->phone_e164);
    }

    public function test_mobile_can_update_contact_by_conversation_uuid(): void
    {
        $workspace = Workspace::factory()->create();
        $user = User::factory()->create(['workspace_id' => $workspace->id]);
        $contact = Contact::create([
            'workspace_id' => $workspace->id,
            'first_name' => 'Customer',
            'last_name' => '100',
        ]);

        $conversation = Conversation::create([
            'workspace_id' => $workspace->id,
            'contact_id' => $contact->id,
            'status' => 'open',
        ]);

        Sanctum::actingAs($user);

        $response = $this->patchJson("/api/v1/mobile/conversations/{$conversation->uuid}/contact", [
            'name' => 'Jane Smith',
            'email' => 'jane@example.com',
        ]);

        $response->assertOk()
            ->assertJsonPath('name', 'Jane Smith')
            ->assertJsonPath('email', 'jane@example.com');

        $contact->refresh();
        $this->assertSame('Jane', $contact->first_name);
        $this->assertSame('Smith', $contact->last_name);
        $this->assertSame('jane@example.com', $contact->email);
    }
}
