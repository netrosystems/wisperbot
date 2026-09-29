<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AppVersionManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Inertia forces a hard reload (409 + X-Inertia-Location) whenever the SPA's
 * asset version differs from the server's. Hashing the Vite manifest flips on
 * every build, so stale tabs reloaded on their next navigation. The version is
 * pinned to APP_VERSION instead, so it only changes on intentional deploys.
 */
class InertiaVersionStabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // AppVersionManager reads APP_VERSION from the real .env file; point it
        // at a missing file so these tests control the version via config.
        $this->app->instance(AppVersionManager::class, new AppVersionManager(sys_get_temp_dir().'/wisperbot-no-env-'.uniqid()));
    }

    public function test_appearance_route_responds_200_for_inertia_request_with_matching_version(): void
    {
        $user = $this->signedInClientUser();

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

        $user = $this->signedInClientUser();

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

        $user = $this->signedInClientUser();

        $before = $this->extractInertiaVersion((string) $this->actingAs($user, 'web')
            ->get(route('client.inbox.chat-widgets.settings'))
            ->getContent());

        config(['app.version' => '1.0.1']);

        $after = $this->extractInertiaVersion((string) $this->actingAs($user, 'web')
            ->get(route('client.inbox.chat-widgets.settings'))
            ->getContent());

        $this->assertNotSame($before, $after, 'A version bump should produce a different Inertia version.');
    }

    private function signedInClientUser(): User
    {
        $client = Client::create([
            'name' => 'Test Co',
            'email' => 'client-'.uniqid().'@test.local',
            'status' => Client::STATUS_ACTIVE,
        ]);
        $workspace = Workspace::create([
            'client_id' => $client->id,
            'name' => 'Default',
        ]);

        return User::factory()->create([
            'client_id' => $client->id,
            'workspace_id' => $workspace->id,
            'role' => User::ROLE_CLIENT,
            'client_role' => User::CLIENT_ROLE_ADMINISTRATOR,
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);
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
