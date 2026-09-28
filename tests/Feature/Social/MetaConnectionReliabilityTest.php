<?php

namespace Tests\Feature\Social;

use App\Models\User;
use App\Models\Workspace;
use App\Modules\Integrations\Models\IntegrationConfig;
use App\Modules\Social\Jobs\RefreshSocialTokensJob;
use App\Modules\Social\Models\SocialAccount;
use App\Modules\Social\Models\SocialPost;
use App\Modules\Social\Models\SocialPostAccount;
use App\Modules\Social\Services\SocialPublisher;
use App\Modules\Social\Services\SocialTokenRefresher;
use App\Notifications\SocialConnectionAttentionNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class MetaConnectionReliabilityTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, array<string, mixed>> debug_token data by input token */
    private array $metaTokens = [];

    private bool $metaFaked = false;

    // ── 1. Business tokens ────────────────────────────────────────────────

    public function test_connect_uses_the_login_for_business_configuration_when_one_is_set(): void
    {
        ['user' => $user] = $this->createWorkspaceContext();
        $this->metaApp();

        $personal = $this->actingAs($user)->get(route('client.social.accounts.connect', 'facebook'))->headers->get('Location');
        $this->assertStringContainsString('scope=pages_manage_posts', $personal);
        $this->assertStringNotContainsString('config_id', $personal);

        $this->metaApp(['config_id_publishing' => 'CONFIG_PUB']);
        $business = $this->actingAs($user)->get(route('client.social.accounts.connect', 'instagram'))->headers->get('Location');
        parse_str((string) parse_url($business, PHP_URL_QUERY), $query);
        $this->assertSame('CONFIG_PUB', $query['config_id']);
        $this->assertSame('true', $query['override_default_response_type']);
        $this->assertArrayNotHasKey('scope', $query);
        $this->assertSame('business', session('social_oauth_state')['meta_login']);
    }

    public function test_a_business_login_connects_its_pages_even_without_granular_targets(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();
        $this->metaApp(['config_id_publishing' => 'CONFIG_PUB']);
        $this->fakeMeta(tokens: ['long-token' => ['is_valid' => true, 'user_id' => 'SYSTEM_USER']]);

        $this->oauthCallback($user, $workspace->id, 'facebook', ['meta_login' => 'business'])
            ->assertSessionHas('success', '1 Facebook account(s) connected.');

        $account = SocialAccount::where('workspace_id', $workspace->id)->sole();
        $this->assertSame('PAGE_A', $account->account_id);
        $this->assertSame('business', $account->meta['meta_login']);
    }

    // ── 2. Hourly health check ───────────────────────────────────────────

    public function test_the_hourly_job_flags_a_meta_connection_that_lost_access_and_notifies_once(): void
    {
        Notification::fake();
        ['user' => $owner, 'workspace' => $workspace] = $this->createWorkspaceContext();
        $workspace->update(['owner_id' => $owner->id]);
        $this->metaApp();
        $page = $this->account($workspace->id, 'facebook', 'PAGE_A', 'page-token-a');
        $this->fakeMeta(tokens: ['page-token-a' => ['is_valid' => false, 'error' => ['code' => 190]]]);

        $job = app(RefreshSocialTokensJob::class);
        $job->handle(app(SocialTokenRefresher::class));
        $job->handle(app(SocialTokenRefresher::class));

        $page->refresh();
        $this->assertFalse($page->active);
        $this->assertTrue($page->meta['reconnect_required']);
        Notification::assertSentToTimes($owner, SocialConnectionAttentionNotification::class, 1);
        $this->actingAs($owner)->get(route('client.social.automation.index'))
            ->assertInertia(fn (AssertableInertia $inertia) => $inertia->where('accounts.0.reconnect_required', true));
    }

    public function test_a_token_without_the_publish_permission_counts_as_lost_and_recovers_by_itself(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $this->metaApp();
        $instagram = $this->account($workspace->id, 'instagram', 'IG_A', 'ig-token');
        $this->fakeMeta(tokens: ['ig-token' => ['is_valid' => true, 'scopes' => ['instagram_basic']]]);

        app(RefreshSocialTokensJob::class)->handle(app(SocialTokenRefresher::class));
        $this->assertFalse($instagram->fresh()->active);

        // The person selected the account again in Meta: the same token works.
        $this->fakeMeta(tokens: ['ig-token' => ['is_valid' => true, 'user_id' => 'U1', 'scopes' => ['instagram_basic', 'instagram_content_publish']]]);
        app(RefreshSocialTokensJob::class)->handle(app(SocialTokenRefresher::class));

        $instagram->refresh();
        $this->assertTrue($instagram->active);
        $this->assertArrayNotHasKey('reconnect_required', $instagram->meta);
        $this->assertSame('U1', $instagram->meta['meta_user_id']);
    }

    public function test_meta_refusing_the_token_while_publishing_explains_it_and_flags_the_account(): void
    {
        Notification::fake();
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $page = $this->account($workspace->id, 'facebook', 'PAGE_A', 'page-token-a');
        $post = $this->socialPost($workspace->id, [$page->id], ['https://cdn.test/clip.mp4']);
        Http::preventStrayRequests();
        Http::fake(['graph-video.facebook.com/*' => Http::response(['error' => [
            'message' => 'Any of the pages_read_engagement ... permission(s) must be granted before impersonating a user\'s page.',
            'type' => 'OAuthException', 'code' => 190,
        ]], 400)]);

        $this->publishIgnoringRetrySignal($post);

        $error = SocialPostAccount::where('post_id', $post->id)->value('error');
        $this->assertStringContainsString('no longer lets WisperBot post to Acme Page', $error);
        $this->assertSame($error, $post->fresh()->publish_results[$page->id]['error']);
        $this->assertFalse($page->fresh()->active);
        $this->assertTrue($page->fresh()->meta['reconnect_required']);
    }

    // ── 3. Warning when a connect cuts off another Page ─────────────────

    public function test_connecting_a_page_names_other_connections_that_just_lost_access(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();
        $other = Workspace::factory()->create(['owner_id' => $user->id, 'name' => 'Second Brand']);
        $this->metaApp();
        $cutOff = $this->account($other->id, 'facebook', 'PAGE_B', 'page-token-b', 'Brand B Page');
        $this->fakeMeta(tokens: [
            'long-token' => ['is_valid' => true, 'granular_scopes' => [['scope' => 'pages_manage_posts', 'target_ids' => ['PAGE_A']]]],
            'page-token-b' => ['is_valid' => false, 'error' => ['code' => 190]],
            'page-token-a' => ['is_valid' => true, 'scopes' => ['pages_manage_posts']],
        ]);

        $this->oauthCallback($user, $workspace->id, 'facebook')
            ->assertSessionHas('success', '1 Facebook account(s) connected.')
            ->assertSessionHas('warning', fn (string $warning): bool => str_contains($warning, 'Brand B Page (Facebook, Second Brand)'));

        $this->assertTrue($cutOff->fresh()->meta['reconnect_required']);
    }

    // ── 4. Scheduled posts stay attached ─────────────────────────────────

    public function test_disconnecting_keeps_the_account_and_reconnecting_brings_back_the_same_one(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();
        $this->metaApp();
        $page = $this->account($workspace->id, 'facebook', 'PAGE_A', 'old-token');
        $this->socialPost($workspace->id, [$page->id], [], 'scheduled');

        $this->actingAs($user)->delete(route('client.social.accounts.disconnect', $page))
            ->assertSessionHas('success', fn (string $message): bool => str_contains($message, '1 post still includes it'));
        $this->assertSoftDeleted($page);
        $this->assertSame('', SocialAccount::withTrashed()->find($page->id)->access_token);

        $this->fakeMeta(tokens: ['long-token' => ['is_valid' => true, 'granular_scopes' => [['scope' => 'pages_manage_posts', 'target_ids' => ['PAGE_A']]]]]);
        $this->oauthCallback($user, $workspace->id, 'facebook')->assertSessionHas('success');

        $restored = SocialAccount::where('workspace_id', $workspace->id)->sole();
        $this->assertSame($page->id, $restored->id);
        $this->assertTrue($restored->active);
        $this->assertSame('page-token-a', $restored->access_token);
    }

    public function test_a_post_for_a_disconnected_account_fails_with_the_reason_instead_of_skipping_it(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $page = $this->account($workspace->id, 'facebook', 'PAGE_A', 'token');
        $post = $this->socialPost($workspace->id, [$page->id, 9999]);
        $page->delete();
        Http::preventStrayRequests();

        $this->publishIgnoringRetrySignal($post);

        $post->refresh();
        $this->assertSame('failed', $post->status);
        $this->assertStringContainsString('Acme Page was disconnected', $post->publish_results[$page->id]['error']);
        $this->assertStringContainsString('removed from the workspace', $post->publish_results[9999]['error']);
    }

    public function test_reattach_command_moves_unpublished_posts_to_the_reconnected_account(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $new = $this->account($workspace->id, 'facebook', 'PAGE_A', 'token');
        $x = $this->account($workspace->id, 'twitter', 'X_1', 'x-token');
        $scheduled = $this->socialPost($workspace->id, [34, $x->id], [], 'scheduled');
        $failed = $this->socialPost($workspace->id, [34], [], 'failed');
        $failed->update(['publish_results' => ['34' => ['status' => 'failed']]]);
        $published = $this->socialPost($workspace->id, [34], [], 'published');

        $this->artisan('social:reattach-posts', ['from' => 34, 'to' => $new->id])->assertSuccessful();
        $this->assertSame([34, $x->id], $scheduled->fresh()->target_accounts);

        $this->artisan('social:reattach-posts', ['from' => 34, 'to' => $new->id, '--apply' => true])
            ->expectsOutputToContain('Moved 2 post(s)')->assertSuccessful();
        $this->assertSame([$new->id, $x->id], $scheduled->fresh()->target_accounts);
        $this->assertSame([$new->id], $failed->fresh()->target_accounts);
        $this->assertNull($failed->fresh()->publish_results);
        $this->assertSame([34], $published->fresh()->target_accounts);

        $this->artisan('social:reattach-posts', ['from' => $x->id, 'to' => $new->id])->assertFailed();
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /** @param  array<string, string>  $extra */
    private function metaApp(array $extra = []): void
    {
        IntegrationConfig::updateOrCreate(['provider' => 'meta_app'], [
            'label' => 'Meta App', 'mode' => 'live', 'enabled' => true,
            'credentials' => ['app_id' => 'meta-app-id', 'app_secret' => 'meta-app-secret'] + $extra,
        ]);
    }

    /** @param  array<string, array<string, mixed>>  $tokens  debug_token data by input token */
    private function fakeMeta(array $tokens): void
    {
        // A second Http::fake() would not replace the first, so the fake reads
        // the current map instead.
        $this->metaTokens = $tokens;
        if ($this->metaFaked) {
            return;
        }
        $this->metaFaked = true;

        Http::fake(function (Request $request) {
            $url = $request->url();
            if (str_contains($url, '/oauth/access_token')) {
                return Http::response(['access_token' => ($request['grant_type'] ?? null) === 'fb_exchange_token' ? 'long-token' : 'short-token']);
            }
            if (str_contains($url, '/debug_token')) {
                return Http::response(['data' => $this->metaTokens[$request['input_token']] ?? ['is_valid' => true, 'scopes' => ['pages_manage_posts', 'instagram_content_publish']]]);
            }

            return match (true) {
                str_contains($url, '/me/accounts') => Http::response(['data' => [['id' => 'PAGE_A', 'name' => 'Acme Page', 'access_token' => 'page-token-a']]]),
                str_contains($url, '/me/businesses') => Http::response(['data' => []]),
                default => Http::response(['error' => ['message' => 'Unexpected '.$url]], 500),
            };
        });
    }

    /** @param  array<string, string>  $state */
    private function oauthCallback(User $user, int $workspaceId, string $network, array $state = []): TestResponse
    {
        return $this->actingAs($user)
            ->withSession([
                'social_oauth_workspace' => $workspaceId,
                'social_oauth_state' => ['state' => 'verified-state', 'network' => $network] + $state,
            ])
            ->get("/app/social/accounts/callback/{$network}?code=auth-code&state=verified-state");
    }

    private function account(int $workspaceId, string $network, string $accountId, string $token, string $name = 'Acme Page'): SocialAccount
    {
        return SocialAccount::create([
            'workspace_id' => $workspaceId, 'network' => $network, 'account_id' => $accountId, 'name' => $name,
            'access_token' => $token, 'meta' => ['page_id' => $accountId], 'active' => true,
        ]);
    }

    /** @param  list<int>  $accountIds */
    private function socialPost(int $workspaceId, array $accountIds, array $media = [], string $status = 'publishing'): SocialPost
    {
        return SocialPost::create([
            'workspace_id' => $workspaceId, 'body' => 'Open late tonight', 'media_urls' => $media,
            'target_accounts' => $accountIds, 'status' => $status, 'scheduled_at' => now()->addHour(),
        ]);
    }

    private function publishIgnoringRetrySignal(SocialPost $post): void
    {
        try {
            app(SocialPublisher::class)->publish($post);
        } catch (\RuntimeException) {
            // The publisher throws so the queue retries failed links.
        }
    }
}
