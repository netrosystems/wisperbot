<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Client;
use App\Models\Role;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AppVersionManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * Regression tests for the "Appearance bounces me back to admin" bug.
 *
 * The visible symptom — clicking Appearance while impersonating a client
 * "bounces" the user back to the super-admin dashboard, after which any
 * attempt to impersonate again is rejected with "Already impersonating" —
 * was traced to Inertia's asset version mismatch.
 *
 * After `app:deploy:finalize` the rebuilt JS bundle produces a different
 * `public/build/manifest.json`, which Inertia hashes for its asset version.
 * Browser tabs that were open before the deploy keep sending the OLD
 * `X-Inertia-Version` header on every SPA navigation. The server detects
 * the mismatch and answers with 409 + `X-Inertia-Location: <same-url>`,
 * forcing a hard reload that wipes the impersonation banner and
 * impersonation banner re-renders after a brief flicker.
 *
 * The fix pins the Inertia version to APP_VERSION (the .env value that
 * already drives the deploy pipeline), so:
 *   - The version only changes on intentional deploys.
 *   - Stale tabs in the wild keep working until the user navigates to a
 *     fresh page, at which point the boot HTML carries the new version.
 *
 * These tests assert that the Inertia version returned by the server is
 * stable across impersonation navigations and matches APP_VERSION.
 */
class InertiaVersionStabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_appearance_route_responds_200_for_inertia_request_during_impersonation(): void
    {
        [$admin, $user, $client] = $this->bootstrapImpersonation();

        // Pull the version the server actually advertises on the boot HTML.
        $boot = $this->actingAs($user, 'web')
            ->get(route('client.inbox.chat-widgets.settings'));
        $boot->assertOk();

        $advertised = $this->extractInertiaVersion((string) $boot->getContent());
        $this->assertNotEmpty($advertised, 'Server boot HTML should advertise an Inertia version.');

        // Now perform the same SPA-style navigation (X-Inertia: true) with the
        // version the server told us to send. This is exactly what the SPA does
        // after a fresh page load.
        $spaNav = $this->actingAs($user, 'web')
            ->withHeader('X-Inertia', 'true')
            ->withHeader('X-Inertia-Version', $advertised)
            ->get(route('client.inbox.chat-widgets.settings'));

        $spaNav->assertOk();
        $this->assertNull(
            $spaNav->headers->get('X-Inertia-Location'),
            'A matched Inertia version must not trigger the 409 hard-reload.'
        );
    }

    public function test_inertia_version_is_pinned_to_app_version_not_the_build_manifest(): void
    {
        config(['app.version' => '1.2.3']);

        [$admin, $user, $client] = $this->bootstrapImpersonation();

        $boot = $this->actingAs($user, 'web')
            ->get(route('client.inbox.chat-widgets.settings'));
        $boot->assertOk();

        $advertised = $this->extractInertiaVersion((string) $boot->getContent());

        // Hashing matches the implementation in HandleInertiaRequests::version()
        $expected = hash('xxh128', 'wisperbot:'.config('app.version'));

        $this->assertSame(
            $expected,
            $advertised,
            'Inertia version should be derived from APP_VERSION, not from the Vite manifest hash.'
        );
    }

    public function test_bumping_app_version_changes_inertia_version_so_fresh_pages_invalidate(): void
    {
        config(['app.version' => '1.0.0']);

        [$admin, $user, $client] = $this->bootstrapImpersonation();

        $before = $this->extractInertiaVersion((string) $this->actingAs($user, 'web')
            ->get(route('client.inbox.chat-widgets.settings'))
            ->getContent());

        config(['app.version' => '1.0.1']);

        $after = $this->extractInertiaVersion((string) $this->actingAs($user, 'web')
            ->get(route('client.inbox.chat-widgets.settings'))
            ->getContent());

        $this->assertNotSame($before, $after, 'A version bump should produce a different Inertia version.');
    }

    /**
     * @return array{0: AdminUser, 1: User, 2: Client}
     */
    private function bootstrapImpersonation(): array
    {
        $admin = AdminUser::create([
            'name' => 'Test Super Admin',
            'email' => 'superadmin-'.uniqid().'@test.local',
            'password' => bcrypt('password'),
            'status' => AdminUser::STATUS_ACTIVE,
        ]);

        $superAdminRole = Role::firstOrCreate(
            ['key' => Role::KEY_SUPER_ADMIN],
            ['name' => 'Super Admin', 'description' => 'All permissions'],
        );
        $viewClients = \App\Models\Permission::firstOrCreate(
            ['key' => 'view_clients'],
            ['name' => 'View Clients', 'category' => 'Clients'],
        );
        $superAdminRole->permissions()->sync([$viewClients->id]);
        $admin->roles()->sync([$superAdminRole->id]);

        $client = Client::create([
            'name' => 'Test Co',
            'email' => 'client-'.uniqid().'@test.local',
            'status' => Client::STATUS_ACTIVE,
        ]);
        $workspace = Workspace::create([
            'client_id' => $client->id,
            'name' => 'Default',
        ]);
        $user = User::factory()->create([
            'client_id' => $client->id,
            'workspace_id' => $workspace->id,
            'role' => User::ROLE_CLIENT,
            'client_role' => User::CLIENT_ROLE_ADMINISTRATOR,
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);

        session()->put('impersonating', true);
        session()->put('impersonator_admin_id', $admin->id);
        session()->put('impersonated_client_id', $client->id);
        Auth::guard('web')->login($user);
        Auth::guard('admin')->login($admin);

        return [$admin, $user, $client];
    }

    private function extractInertiaVersion(string $html): string
    {
        // Inertia renders the page payload into the boot HTML via either
        //   <div id="app" data-page="{...json...}">  (default)
        // or
        //   <script data-page="app" type="application/json">{...json...}</script>
        // The attribute value is HTML-escaped, so we have to decode it first.

        $pattern = '/data-page=(?:"([^"]*)"|\'([^\']*)\')/';
        if (preg_match($pattern, $html, $match) === 1) {
            $raw = $match[1] !== '' ? $match[1] : $match[2];
            $decoded = json_decode(html_entity_decode($raw), true);
            if (is_array($decoded) && isset($decoded['version'])) {
                return (string) $decoded['version'];
            }
        }

        return '';
    }
}