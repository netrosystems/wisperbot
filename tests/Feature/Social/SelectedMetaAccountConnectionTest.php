<?php

namespace Tests\Feature\Social;

use App\Models\User;
use App\Models\Workspace;
use App\Modules\Inbox\Http\Controllers\InboxSetupController;
use App\Modules\Integrations\Models\IntegrationConfig;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Social\Models\SocialAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SelectedMetaAccountConnectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_resumed_messenger_selection_without_page_token_does_not_use_an_undefined_user_token(): void
    {
        $this->withoutMiddleware();
        IntegrationConfig::create([
            'provider' => 'meta_app', 'label' => 'Meta App', 'mode' => 'live', 'enabled' => true,
            'credentials' => ['app_id' => 'test-app', 'app_secret' => 'test-secret'],
        ]);
        /** @var User $user */
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
        $user->forceFill(['workspace_id' => $workspace->id])->save();
        Http::fake();

        $request = \Illuminate\Http\Request::create('/app/inbox/setup/embedded-signup/messenger', 'POST', [
            'selection_token' => 'pending', 'selected_facebook_page_id' => 'selected-page',
        ]);
        $request->setUserResolver(fn () => $user);
        $request->setLaravelSession(app('session.store'));
        $request->session()->put('messenger_connect_selection.pending', [
            'workspace_id' => $workspace->id,
            'pages' => [['id' => 'selected-page', 'name' => 'Test Page']],
        ]);
        $response = app(InboxSetupController::class)->embeddedSignupMessenger($request);
        $this->assertSame(422, $response->getStatusCode());

        Http::assertNothingSent();
        $this->assertDatabaseMissing('channel_accounts', ['workspace_id' => $workspace->id, 'channel' => 'messenger']);
    }

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

        /** @var User $user */
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

        $response->assertRedirect(route('client.social.automation.index'));
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

    public function test_instagram_connection_repair_registers_webhook_and_resubscribes_page(): void
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
                'verify_token' => 'verify-token',
            ],
        ]);

        /** @var User $user */
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
        $user->forceFill(['workspace_id' => $workspace->id])->save();
        $account = ChannelAccount::create([
            'workspace_id' => $workspace->id,
            'channel' => 'instagram',
            'provider' => 'meta',
            'display_name' => 'netrosystems',
            'credentials' => ['access_token' => 'page-token', 'instagram_account_id' => 'ig-1'],
            'meta_json' => [
                'instagram_page_id' => 'ig-1',
                'instagram_account_id' => 'ig-1',
                'facebook_page_id' => 'page-1',
            ],
            'status' => 'active',
        ]);

        Http::fake([
            'https://graph.facebook.com/v25.0/meta-app-id/subscriptions*' => Http::response(['success' => true]),
            'https://graph.facebook.com/v25.0/page-1/subscribed_apps*' => Http::response(['success' => true]),
        ]);

        $this->actingAs($user)
            ->postJson(route('client.inbox.setup.repair', ['channelAccount' => $account->id]))
            ->assertOk()
            ->assertJsonPath('success', true);

        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request->url() === 'https://graph.facebook.com/v25.0/meta-app-id/subscriptions'
            && $request['object'] === 'instagram'
            && $request['callback_url'] === 'https://wisperbot.test/webhooks/meta/verify-token'
            && str_contains((string) $request['fields'], 'messages'));

        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request->url() === 'https://graph.facebook.com/v25.0/page-1/subscribed_apps'
            && $request->hasHeader('Authorization', 'Bearer page-token')
            && str_contains((string) $request['subscribed_fields'], 'messages'));

        $this->assertNotNull($account->fresh()->meta_json['webhook_repaired_at'] ?? null);
    }

    public function test_disconnect_does_not_unsubscribe_shared_meta_page_used_by_messenger(): void
    {
        $this->withoutMiddleware();

        /** @var User $user */
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
        $user->forceFill(['workspace_id' => $workspace->id])->save();
        $instagram = ChannelAccount::create([
            'workspace_id' => $workspace->id,
            'channel' => 'instagram',
            'provider' => 'meta',
            'display_name' => 'Instagram',
            'credentials' => ['access_token' => 'instagram-page-token', 'instagram_account_id' => 'ig-1'],
            'meta_json' => [
                'instagram_page_id' => 'ig-1',
                'instagram_account_id' => 'ig-1',
                'facebook_page_id' => 'page-1',
            ],
            'status' => 'active',
        ]);
        ChannelAccount::create([
            'workspace_id' => $workspace->id,
            'channel' => 'messenger',
            'provider' => 'meta',
            'display_name' => 'Messenger',
            'credentials' => ['page_access_token' => 'messenger-page-token'],
            'meta_json' => ['page_id' => 'page-1'],
            'status' => 'active',
        ]);

        Http::fake();

        $this->actingAs($user)
            ->delete(route('client.inbox.setup.destroy', ['channelAccount' => $instagram->id]))
            ->assertRedirect();

        Http::assertNothingSent();
        $this->assertDatabaseMissing('channel_accounts', ['id' => $instagram->id]);
        $this->assertDatabaseHas('channel_accounts', ['workspace_id' => $workspace->id, 'channel' => 'messenger']);
    }
}
