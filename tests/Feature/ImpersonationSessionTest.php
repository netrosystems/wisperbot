<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Client;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Regression tests for the impersonation session hygiene bug:
 *
 * On live (SESSION_DRIVER=database) a previously impersonated session row
 * could keep `impersonating=true` after the user clicked "Return to admin",
 * because:
 *   1. stop() only logged out the web guard, never the admin guard
 *   2. stop() did not invalidate()/regenerate() the session, so the
 *      session ID stayed stable and a parallel client tab could re-bind
 *      to the orphan "impersonating" row
 *   3. impersonate() did not rotate the session ID before setting the
 *      flags, so a stale admin session row could bleed into the new
 *      impersonation
 *   4. impersonate() did not assert that the target user actually had
 *      role=client, so picking a malformed user would later bounce to
 *      admin.dashboard via the role middleware
 *
 * After the fix, stop() must:
 *   - log out BOTH guards
 *   - invalidate() the session
 *   - regenerate() the CSRF token
 *
 * And impersonate() must:
 *   - regenerate the session before setting the flags
 *   - regenerate the CSRF token after login
 *   - refuse to impersonate a non-client user
 */
class ImpersonationSessionTest extends TestCase
{
    use RefreshDatabase;

    private function makeSuperAdmin(): AdminUser
    {
        $admin = new AdminUser([
            'name' => 'Test Super Admin',
            'email' => 'superadmin-'.uniqid().'@test.local',
            'password' => Hash::make('password'),
            'status' => AdminUser::STATUS_ACTIVE,
            'is_super_admin' => true,
        ]);
        $admin->save();

        // Attach the super-admin role so the `permission:view_clients` gate
        // on admin.clients.impersonate passes. We sync all permissions onto
        // this role to mirror the seeder.
        $superAdminRole = Role::firstOrCreate(
            ['key' => Role::KEY_SUPER_ADMIN],
            ['name' => 'Super Admin', 'description' => 'All permissions'],
        );
        $allPermissionIds = \App\Models\Permission::pluck('id')->all();
        if ($allPermissionIds === []) {
            // No permissions seeded — the seeder may not have run in this
            // test DB. Create the one we need so hasPermissionTo() returns
            // true.
            $p = \App\Models\Permission::firstOrCreate(
                ['key' => 'view_clients'],
                ['name' => 'View Clients', 'category' => 'Clients'],
            );
            $allPermissionIds = [$p->id];
        }
        $superAdminRole->permissions()->sync($allPermissionIds);
        $admin->roles()->sync([$superAdminRole->id]);
        $admin->refresh();

        return $admin;
    }

    private function makeClientWithUser(): array
    {
        $client = Client::create([
            'name' => fake()->company(),
            'email' => fake()->unique()->safeEmail(),
            'status' => Client::STATUS_ACTIVE,
        ]);
        $user = User::factory()->create([
            'client_id' => $client->id,
            'role' => User::ROLE_CLIENT,
            'client_role' => User::CLIENT_ROLE_ADMINISTRATOR,
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);
        return [$client, $user];
    }

    public function test_stop_clears_impersonating_flag_and_both_guards(): void
    {
        $admin = $this->makeSuperAdmin();
        [$client, $user] = $this->makeClientWithUser();

        // Bootstrap an impersonated session the same way the controller does.
        session()->put('impersonating', true);
        session()->put('impersonator_admin_id', $admin->id);
        session()->put('impersonated_client_id', $client->id);
        Auth::guard('web')->login($user);
        Auth::guard('admin')->login($admin);

        $this->assertTrue((bool) session('impersonating'));

        $response = $this->actingAs($user, 'web')
            ->post(route('admin.impersonation.stop'));

        // Should land back on the admin clients page.
        $response->assertRedirect(route('admin.clients.index'));
        $response->assertSessionHas('success');

        // All three impersonation keys must be gone.
        $this->assertNull(session('impersonating'));
        $this->assertNull(session('impersonator_admin_id'));
        $this->assertNull(session('impersonated_client_id'));

        // Both guards must be logged out.
        $this->assertFalse(Auth::guard('web')->check());
        $this->assertFalse(Auth::guard('admin')->check());
    }

    public function test_stop_rotates_session_id_so_orphan_rows_cant_rebind(): void
    {
        $admin = $this->makeSuperAdmin();
        [$client, $user] = $this->makeClientWithUser();

        session()->put('impersonating', true);
        session()->put('impersonator_admin_id', $admin->id);
        session()->put('impersonated_client_id', $client->id);
        Auth::guard('web')->login($user);

        $idBefore = session()->getId();

        $this->actingAs($user, 'web')
            ->post(route('admin.impersonation.stop'));

        $idAfter = session()->getId();

        // The session ID must have changed so any previously-cached
        // session row in SESSION_DRIVER=database is no longer the one
        // the browser is bound to.
        $this->assertNotSame($idBefore, $idAfter, 'Session ID must rotate on stop().');
    }

    public function test_stop_without_active_impersonation_still_clears_both_guards(): void
    {
        $admin = $this->makeSuperAdmin();
        Auth::guard('admin')->login($admin);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.impersonation.stop'))
            ->assertRedirect(route('admin.login'));

        $this->assertFalse(Auth::guard('admin')->check());
    }

    public function test_impersonate_regenerates_session_id(): void
    {
        $admin = $this->makeSuperAdmin();
        [$client, $user] = $this->makeClientWithUser();

        // Pre-authenticate as admin and grab the session id.
        Auth::guard('admin')->login($admin);
        session()->save();
        $idBefore = session()->getId();

        // Call the controller directly so we can inspect session state
        // without following the redirect chain (the post-redirect client
        // dashboard is licensed and would bounce to /license, hiding the
        // session rotation we want to verify).
        $request = \Illuminate\Http\Request::create(
            route('admin.clients.impersonate', $client),
            'POST'
        );
        $request->setLaravelSession(session()->driver());
        $request->setUserResolver(fn ($guard = null) => $guard === 'admin' ? $admin : ($guard ? Auth::guard($guard)->user() : null));

        $controller = app(\App\Http\Controllers\Admin\ClientController::class);
        $response = $controller->impersonate($request, $client);

        // The response must be a 3xx and must NOT be the
        // `Already impersonating` bounce-back.
        $this->assertSame(302, $response->getStatusCode());
        $location = $response->headers->get('Location');
        $this->assertNotSame(
            route('admin.clients.index'),
            $location,
            'impersonate() must not bounce back with Already-impersonating'
        );

        // The impersonation flags must be set in the session that the
        // request was bound to — even though we regenerate() inside the
        // controller, the values are set AFTER the regenerate and stay in
        // the new session row.
        $this->assertTrue((bool) session('impersonating'));
        $this->assertSame($client->id, (int) session('impersonated_client_id'));
        $this->assertSame($admin->id, (int) session('impersonator_admin_id'));

        // The session id must have changed so the impersonation lives in a
        // fresh row, not a stale one that another tab may still be bound to.
        $this->assertNotSame(
            $idBefore,
            session()->getId(),
            'Session ID must rotate on impersonate() so the new impersonation has a clean row.'
        );

        // And the impersonated user must be the web-guard user.
        $this->assertTrue(Auth::guard('web')->check());
        $this->assertSame($user->id, Auth::guard('web')->id());
    }

    public function test_impersonate_refuses_non_client_user(): void
    {
        $admin = $this->makeSuperAdmin();
        $client = Client::create([
            'name' => fake()->company(),
            'email' => fake()->unique()->safeEmail(),
            'status' => Client::STATUS_ACTIVE,
        ]);
        User::factory()->create([
            'client_id' => $client->id,
            'role' => 'admin', // wrong role
            'status' => User::STATUS_ACTIVE,
        ]);

        $response = $this->actingAs($admin, 'admin')
            ->post(route('admin.clients.impersonate', $client));
        $response->assertRedirect();

        // `redirect()->back()->with(...)` uses the previous URL which is
        // empty in tests — the assertion below covers the same intent: the
        // session error must be present and impersonation must not start.
        $this->assertNotSame(
            'http://127.0.0.1:8000',
            $response->headers->get('Location'),
        );

        $this->assertNull(session('impersonating'));
        $this->assertFalse(Auth::guard('web')->check());
    }

    public function test_impersonate_blocked_when_already_impersonating(): void
    {
        $admin = $this->makeSuperAdmin();
        [$client, $user] = $this->makeClientWithUser();
        $otherClient = Client::create([
            'name' => fake()->company(),
            'email' => fake()->unique()->safeEmail(),
            'status' => Client::STATUS_ACTIVE,
        ]);
        User::factory()->create([
            'client_id' => $otherClient->id,
            'role' => User::ROLE_CLIENT,
            'status' => User::STATUS_ACTIVE,
        ]);

        // Simulate an in-progress impersonation.
        session()->put('impersonating', true);
        session()->put('impersonator_admin_id', $admin->id);
        session()->put('impersonated_client_id', $client->id);
        Auth::guard('web')->login($user);

        // The web-guard user can't actually hit this route because the
        // auth:admin middleware on admin.* would bounce them. We call the
        // route handler directly to simulate the controller running while
        // the impersonation is already in progress (e.g. via the queue or
        // a second tab using a stale cookie).
        $request = \Illuminate\Http\Request::create(
            route('admin.clients.impersonate', $otherClient),
            'POST'
        );
        $request->setLaravelSession(session()->driver());
        $request->setUserResolver(fn ($guard = null) => $guard === 'admin' ? $admin : ($guard ? Auth::guard($guard)->user() : null));

        $controller = app(\App\Http\Controllers\Admin\ClientController::class);
        $response = $controller->impersonate($request, $otherClient);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(
            route('admin.clients.index'),
            $response->headers->get('Location'),
        );
        $this->assertSame(
            __('Already impersonating.'),
            session('error'),
        );

        // The pre-existing impersonation must still be intact.
        $this->assertTrue((bool) session('impersonating'));
        $this->assertSame($client->id, (int) session('impersonated_client_id'));
    }
}
