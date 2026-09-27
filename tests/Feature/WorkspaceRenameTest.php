<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkspaceRenameTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_owner_can_rename_a_workspace_and_it_is_audited(): void
    {
        ['user' => $owner, 'workspace' => $workspace] = $this->createWorkspaceContext();
        $workspace->update(['owner_id' => $owner->id]);
        $previous = $workspace->name;

        $this->actingAs($owner)
            ->put(route('client.workspaces.update', $workspace), ['name' => '  Downtown Branch  '])
            ->assertRedirect()
            ->assertSessionHas('success', 'Workspace renamed.');

        $this->assertSame('Downtown Branch', $workspace->fresh()->name);
        $log = AuditLog::where('action', 'workspace.renamed')->firstOrFail();
        $this->assertSame(['name' => $previous], $log->old_values);
        $this->assertSame(['name' => 'Downtown Branch'], $log->new_values);
    }

    public function test_only_the_owner_can_rename(): void
    {
        ['user' => $owner, 'workspace' => $workspace, 'client' => $client] = $this->createWorkspaceContext();
        $workspace->update(['owner_id' => $owner->id]);

        // A member of the same workspace who is not its owner.
        ['user' => $member] = $this->createWorkspaceContext();
        $member->update(['client_id' => $client->id]);
        $workspace->members()->attach($member->id, ['role' => 'member']);

        // Someone from another company.
        ['user' => $stranger] = $this->createWorkspaceContext();

        $this->actingAs($member)->put(route('client.workspaces.update', $workspace), ['name' => 'Taken over'])->assertForbidden();
        $this->actingAs($stranger)->put(route('client.workspaces.update', $workspace), ['name' => 'Taken over'])->assertForbidden();

        $this->assertNotSame('Taken over', $workspace->fresh()->name);
    }

    public function test_a_workspace_needs_a_name(): void
    {
        ['user' => $owner, 'workspace' => $workspace] = $this->createWorkspaceContext();
        $workspace->update(['owner_id' => $owner->id]);
        $name = $workspace->name;

        $this->actingAs($owner)
            ->put(route('client.workspaces.update', $workspace), ['name' => '   '])
            ->assertSessionHasErrors('name');

        $this->assertSame($name, $workspace->fresh()->name);
        $this->assertSame(0, AuditLog::where('action', 'workspace.renamed')->count());
    }

    public function test_the_workspace_list_marks_which_ones_the_user_owns(): void
    {
        ['user' => $owner, 'workspace' => $workspace] = $this->createWorkspaceContext();
        $workspace->update(['owner_id' => $owner->id]);
        $shared = Workspace::factory()->create();
        $shared->members()->attach($owner->id, ['role' => 'member']);

        $this->actingAs($owner)->get(route('client.workspaces.index'))
            ->assertInertia(fn ($page) => $page
                ->component('client/Workspaces/Index')
                ->where('workspaces', fn ($list) => collect($list)->firstWhere('id', $workspace->id)['is_owner'] === true
                    && collect($list)->firstWhere('id', $shared->id)['is_owner'] === false));
    }
}
