<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Workspace;
use App\Modules\Shared\Models\Contact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkspaceSwitchTest extends TestCase
{
    use RefreshDatabase;

    public function test_web_workspace_switch_is_session_scoped_and_does_not_change_mobile_active_workspace(): void
    {
        $user = User::factory()->create([
            'role' => 'client',
            'email_verified_at' => now(),
        ]);
        $home = Workspace::factory()->create(['owner_id' => $user->id]);
        $shared = Workspace::factory()->create();
        $shared->members()->attach($user->id, ['role' => 'member']);
        $user->update(['workspace_id' => $home->id]);

        $this->actingAs($user)
            ->post(route('client.workspaces.switch'), ['workspace_id' => $shared->id])
            ->assertRedirect(route('client.dashboard'))
            ->assertSessionHas('current_workspace_id', $shared->id);

        $this->assertSame($home->id, (int) $user->fresh()->workspace_id);
    }

    public function test_web_requests_scope_queries_to_the_session_workspace_without_persisting_it(): void
    {
        $user = User::factory()->create([
            'role' => 'client',
            'email_verified_at' => now(),
        ]);
        $home = Workspace::factory()->create(['owner_id' => $user->id]);
        $shared = Workspace::factory()->create();
        $shared->members()->attach($user->id, ['role' => 'member']);
        $user->update(['workspace_id' => $home->id]);

        Contact::factory()->create(['workspace_id' => $shared->id]);

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $shared->id])
            ->get(route('client.dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('client/Dashboard')
                ->where('stats.contacts_total', 1)
            );

        $this->assertSame($home->id, (int) $user->fresh()->workspace_id);
    }

    public function test_web_workspace_creation_selects_the_new_workspace_without_changing_mobile_active_workspace(): void
    {
        $user = User::factory()->create([
            'role' => 'client',
            'email_verified_at' => now(),
        ]);
        $home = Workspace::factory()->create(['owner_id' => $user->id]);
        $user->update(['workspace_id' => $home->id]);

        $this->actingAs($user)
            ->post(route('client.workspaces.store'), ['name' => 'New campaign workspace'])
            ->assertRedirect(route('client.dashboard'))
            ->assertSessionHas('current_workspace_id');

        $created = Workspace::where('name', 'New campaign workspace')->sole();

        $this->assertSame($created->id, (int) session('current_workspace_id'));
        $this->assertSame($home->id, (int) $user->fresh()->workspace_id);
    }
}
