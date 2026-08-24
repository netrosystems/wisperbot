<?php

namespace Tests\Feature\Social;

use App\Models\User;
use App\Models\Workspace;
use App\Modules\Integrations\Models\IntegrationConfig;
use App\Modules\Social\Models\SocialAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SelectedMetaAccountConnectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_facebook_callback_connects_only_the_page_selected_in_meta_oauth(): void
    {
        $this->withoutMiddleware();
        config(['app.url' => 'https://wisperbot.test']);

        IntegrationConfig::create([
            'provider' => 'meta_app',
            'label' => 'Meta App',
            'mode' => 'live',
            'enabled' => true,
            'credentials' => [
                'app_id' => 'meta-app-id',
                'app_secret' => 'meta-app-secret',
            ],
        ]);

        $user = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
        $user->forceFill(['workspace_id' => $workspace->id])->save();

        Http::fake(function (Request $request) {
            $url = $request->url();

            if (str_contains($url, '/oauth/access_token')) {
                return ($request['grant_type'] ?? null) === 'fb_exchange_token'
                    ? Http::response(['access_token' => 'long-token', 'expires_in' => 5_184_000])
                    : Http::response(['access_token' => 'short-token', 'expires_in' => 3600]);
            }

            if (str_contains($url, '/debug_token')) {
                return Http::response(['data' => [
                    'is_valid' => true,
                    'granular_scopes' => [
                        // Meta may retain every previously granted Page on a
                        // broad read scope even though this authorization chose
                        // only PAGE_MINI_PC for publishing.
                        ['scope' => 'pages_show_list', 'target_ids' => ['PAGE_MINI_PC', 'PAGE_NETRO']],
                        ['scope' => 'pages_read_engagement', 'target_ids' => ['PAGE_MINI_PC', 'PAGE_NETRO']],
                        ['scope' => 'pages_manage_posts', 'target_ids' => ['PAGE_MINI_PC']],
                        ['scope' => 'business_management', 'target_ids' => ['BUSINESS_NETRO']],
                    ],
                ]]);
            }

            return match (true) {
                str_contains($url, '/me/accounts') => Http::response(['data' => [
                    ['id' => 'PAGE_MINI_PC', 'name' => 'Mini PC Bangladesh', 'access_token' => 'mini-page-token'],
                ]]),
                str_contains($url, '/me/businesses') => Http::response(['data' => [
                    ['id' => 'BUSINESS_NETRO', 'name' => 'Netro Systems Official'],
                ]]),
                str_contains($url, '/BUSINESS_NETRO/owned_pages') => Http::response(['data' => [
                    ['id' => 'PAGE_MINI_PC', 'name' => 'Mini PC Bangladesh', 'access_token' => 'mini-page-token'],
                    ['id' => 'PAGE_NETRO', 'name' => 'Netro Systems', 'access_token' => 'netro-page-token'],
                ]]),
                str_contains($url, '/BUSINESS_NETRO/client_pages') => Http::response(['data' => []]),
                default => Http::response(['error' => ['message' => 'Unexpected URL']], 500),
            };
        });

        $response = $this->actingAs($user)
            ->withSession([
                'social_oauth_workspace' => $workspace->id,
                'social_oauth_state' => ['state' => 'verified-state', 'network' => 'facebook'],
            ])
            ->get('/app/social/accounts/callback/facebook?code=auth-code&state=verified-state');

        $response->assertRedirect(route('client.social.accounts.index'));
        $response->assertSessionHas('success', '1 Facebook account(s) connected.');

        $this->assertDatabaseHas('social_media_accounts', [
            'workspace_id' => $workspace->id,
            'network' => 'facebook',
            'account_id' => 'PAGE_MINI_PC',
            'name' => 'Mini PC Bangladesh',
        ]);
        $this->assertDatabaseMissing('social_media_accounts', [
            'workspace_id' => $workspace->id,
            'network' => 'facebook',
            'account_id' => 'PAGE_NETRO',
        ]);
        $this->assertSame(1, SocialAccount::where('workspace_id', $workspace->id)->count());
    }
}
