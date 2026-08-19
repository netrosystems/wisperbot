<?php

namespace Tests\Feature\Api\V1;

use App\Models\User;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Workspace;
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

        $assignedAgent = User::factory()->create([
            'workspace_id' => $workspace->id,
            'name' => 'John Agent',
        ]);

        $conversation = Conversation::factory()->create([
            'workspace_id' => $workspace->id,
            'assigned_user_id' => $assignedAgent->id,
            'assigned_to' => 'human',
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
}
