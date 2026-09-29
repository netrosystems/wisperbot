<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Client;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Client impersonation was removed on 2026-09-29: admins cannot sign in as a
 * client, and sessions left mid-impersonation by the old feature are signed out.
 */
class ClientImpersonationRemovedTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_cannot_start_impersonating_a_client(): void
    {
        $admin = $this->superAdmin();
        [$client, $user] = $this->clientWithUser();

        $this->actingAs($admin, 'admin')
            ->post('/admin/clients/'.$client->id.'/impersonate')
            ->assertNotFound();

        $this->assertGuest('web');
        $this->assertFalse(app('router')->has('admin.clients.impersonate'));
        $this->assertFalse(app('router')->has('admin.impersonation.stop'));
    }

    public function test_session_left_mid_impersonation_is_signed_out_of_both_guards(): void
    {
        $admin = $this->superAdmin();
        [$client, $user] = $this->clientWithUser();

        $this->actingAs($user, 'web')
            ->actingAs($admin, 'admin')
            ->withSession([
                'impersonating' => true,
                'impersonator_admin_id' => $admin->id,
                'impersonated_client_id' => $client->id,
            ])
            ->get(route('client.dashboard'))
            ->assertRedirect(route('admin.login'));

        $this->assertGuest('web');
        $this->assertGuest('admin');
        $this->assertNull(session('impersonating'));
    }

    public function test_ordinary_client_session_is_untouched(): void
    {
        [, $user] = $this->clientWithUser();

        $this->actingAs($user, 'web')
            ->get(route('client.inbox.chat-widgets.settings'))
            ->assertOk();

        $this->assertAuthenticatedAs($user, 'web');
    }

    private function superAdmin(): AdminUser
    {
        $admin = AdminUser::create([
            'name' => 'Test Super Admin',
            'email' => 'superadmin-'.uniqid().'@test.local',
            'password' => bcrypt('password'),
            'status' => AdminUser::STATUS_ACTIVE,
        ]);
        $role = Role::firstOrCreate(
            ['key' => Role::KEY_SUPER_ADMIN],
            ['name' => 'Super Admin', 'description' => 'All permissions'],
        );
        $viewClients = Permission::firstOrCreate(
            ['key' => 'view_clients'],
            ['name' => 'View Clients', 'category' => 'Clients'],
        );
        $role->permissions()->sync([$viewClients->id]);
        $admin->roles()->sync([$role->id]);

        return $admin;
    }

    /**
     * @return array{0: Client, 1: User}
     */
    private function clientWithUser(): array
    {
        $client = Client::create([
            'name' => 'Test Co',
            'email' => 'client-'.uniqid().'@test.local',
            'status' => Client::STATUS_ACTIVE,
        ]);
        $workspace = Workspace::create(['client_id' => $client->id, 'name' => 'Default']);
        $user = User::factory()->create([
            'client_id' => $client->id,
            'workspace_id' => $workspace->id,
            'role' => User::ROLE_CLIENT,
            'client_role' => User::CLIENT_ROLE_ADMINISTRATOR,
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);

        return [$client, $user];
    }
}
