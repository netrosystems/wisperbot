<?php

namespace Tests\Feature\Social;

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
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class SocialTokenRenewalTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_hourly_token_with_a_refresh_token_is_not_shown_as_expired(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();
        $youtube = $this->account($workspace->id, 'youtube', ['token_expires_at' => now()->subHours(5)]);
        $noRefresh = $this->account($workspace->id, 'linkedin', ['token_expires_at' => now()->subDay(), 'refresh_token' => null]);

        $this->actingAs($user)->get(route('client.social.automation.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('accounts', fn ($accounts) => collect($accounts)->firstWhere('id', $youtube->id)['token_expired'] === false
                    && collect($accounts)->firstWhere('id', $noRefresh->id)['token_expired'] === true));

        $this->actingAs($user)->get(route('client.social.automation.schedule'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('accounts', fn ($accounts) => collect($accounts)->pluck('id')->all() === [$youtube->id]));
    }

    public function test_publishing_renews_an_expired_access_token_first(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $this->linkedInCredentials();
        $profile = $this->account($workspace->id, 'linkedin', ['account_id' => 'member-1', 'token_expires_at' => now()->subMinute()]);
        $post = $this->socialPost($workspace->id, $profile->id);
        Http::preventStrayRequests();
        Http::fake([
            'www.linkedin.com/oauth/v2/accessToken' => Http::response(['access_token' => 'fresh-access', 'refresh_token' => 'fresh-refresh', 'expires_in' => 5184000]),
            'api.linkedin.com/v2/ugcPosts' => Http::response([], 201, ['X-RestLi-Id' => 'urn:li:share:1']),
        ]);

        app(SocialPublisher::class)->publish($post);

        $this->assertSame('published', SocialPostAccount::where('post_id', $post->id)->firstOrFail()->status);
        $this->assertSame('fresh-refresh', $profile->fresh()->refresh_token);
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/ugcPosts') && $request->hasHeader('Authorization', 'Bearer fresh-access'));
    }

    public function test_a_temporary_renewal_failure_keeps_the_connection(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $this->linkedInCredentials();
        $profile = $this->account($workspace->id, 'linkedin', ['token_expires_at' => now()->subMinute()]);
        $post = $this->socialPost($workspace->id, $profile->id);
        Http::preventStrayRequests();
        Http::fake(['www.linkedin.com/oauth/v2/accessToken' => Http::response('Service Unavailable', 503)]);

        $this->publishIgnoringRetrySignal($post);

        $link = SocialPostAccount::where('post_id', $post->id)->firstOrFail();
        $this->assertSame('LinkedIn could not be reached to renew the connection. Publishing will be retried.', $link->error);
        $this->assertNull($link->provider_attempted_at);
        $this->assertTrue($profile->fresh()->active);
    }

    public function test_a_revoked_refresh_token_asks_the_client_to_reconnect(): void
    {
        Notification::fake();
        ['user' => $owner, 'workspace' => $workspace] = $this->createWorkspaceContext();
        $workspace->update(['owner_id' => $owner->id]);
        $this->googleCredentials();
        $youtube = $this->account($workspace->id, 'youtube', ['token_expires_at' => now()->addMinutes(20)]);
        Http::preventStrayRequests();
        Http::fake(['oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant', 'error_description' => 'Token has been expired or revoked.'], 400)]);

        app(RefreshSocialTokensJob::class)->handle(app(SocialTokenRefresher::class));

        $youtube->refresh();
        $this->assertFalse($youtube->active);
        $this->assertTrue($youtube->meta['reconnect_required']);
        Notification::assertSentTo($owner, SocialConnectionAttentionNotification::class,
            fn ($notification) => str_contains($notification->toArray($owner)['message'], 'YouTube disconnected'));

        $this->actingAs($owner)->get(route('client.social.automation.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('accounts.0.reconnect_required', true));
    }

    public function test_only_a_token_rejection_disconnects_an_account(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $this->googleCredentials();
        $youtube = $this->account($workspace->id, 'youtube', ['token_expires_at' => now()->addMinutes(20)]);
        Http::preventStrayRequests();
        Http::fake(['oauth2.googleapis.com/token' => Http::sequence()
            // A wrong platform client secret is an admin problem, not the client's.
            ->push(['error' => 'invalid_client'], 401)
            ->push('Bad Gateway', 502)
            ->push(['access_token' => 'fresh-access', 'expires_in' => 3599])]);

        $job = app(RefreshSocialTokensJob::class);
        $job->handle(app(SocialTokenRefresher::class));
        $job->handle(app(SocialTokenRefresher::class));
        $this->assertTrue($youtube->fresh()->active);

        $job->handle(app(SocialTokenRefresher::class));
        $this->assertSame('fresh-access', $youtube->fresh()->access_token);
        $this->assertTrue($youtube->fresh()->token_expires_at->isFuture());
    }

    public function test_the_hourly_job_renews_only_tokens_about_to_expire(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $this->googleCredentials();
        $soon = $this->account($workspace->id, 'youtube', ['token_expires_at' => now()->addMinutes(30)]);
        $later = $this->account($workspace->id, 'youtube', ['token_expires_at' => now()->addHours(5), 'refresh_token' => 'other-refresh']);
        Http::preventStrayRequests();
        Http::fake(['oauth2.googleapis.com/token' => Http::response(['access_token' => 'fresh-access', 'expires_in' => 3599])]);

        app(RefreshSocialTokensJob::class)->handle(app(SocialTokenRefresher::class));

        Http::assertSentCount(1);
        $this->assertSame('fresh-access', $soon->fresh()->access_token);
        $this->assertSame('old-access', $later->fresh()->access_token);
    }

    public function test_a_connection_that_cannot_be_renewed_is_flagged_a_week_ahead_once(): void
    {
        Notification::fake();
        ['user' => $owner, 'workspace' => $workspace] = $this->createWorkspaceContext();
        $workspace->update(['owner_id' => $owner->id]);
        $profile = $this->account($workspace->id, 'linkedin', ['token_expires_at' => now()->addDays(5)->addHour(), 'refresh_token' => null]);
        $healthy = $this->account($workspace->id, 'linkedin', ['token_expires_at' => now()->addDays(40), 'refresh_token' => null]);
        Http::preventStrayRequests();

        $job = app(RefreshSocialTokensJob::class);
        $job->handle(app(SocialTokenRefresher::class));
        $job->handle(app(SocialTokenRefresher::class));

        Notification::assertSentToTimes($owner, SocialConnectionAttentionNotification::class, 1);
        Notification::assertSentTo($owner, SocialConnectionAttentionNotification::class,
            fn ($notification) => str_contains($notification->toArray($owner)['message'], 'expires in 6 days'));

        $this->actingAs($owner)->get(route('client.social.automation.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('accounts', fn ($accounts) => collect($accounts)->firstWhere('id', $profile->id)['reconnect_in_days'] === 6
                && collect($accounts)->firstWhere('id', $healthy->id)['reconnect_in_days'] === null));
    }

    private function account(int $workspaceId, string $network, array $overrides = []): SocialAccount
    {
        return SocialAccount::create(array_merge([
            'workspace_id' => $workspaceId,
            'network' => $network,
            'account_id' => fake()->uuid(),
            'name' => ucfirst($network).' account',
            'access_token' => 'old-access',
            'refresh_token' => 'old-refresh',
            'meta' => ['actor_type' => 'member'],
            'active' => true,
        ], $overrides));
    }

    private function socialPost(int $workspaceId, int $accountId): SocialPost
    {
        return SocialPost::create([
            'workspace_id' => $workspaceId, 'body' => 'We are open late tonight.', 'media_urls' => [],
            'target_accounts' => [$accountId], 'status' => 'publishing',
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

    private function googleCredentials(): void
    {
        IntegrationConfig::updateOrCreate(['provider' => 'oauth_youtube'], [
            'label' => 'YouTube OAuth', 'credentials' => ['client_id' => 'google-id', 'client_secret' => 'google-secret'], 'enabled' => true,
        ]);
    }

    private function linkedInCredentials(): void
    {
        IntegrationConfig::updateOrCreate(['provider' => 'oauth_linkedin'], [
            'label' => 'LinkedIn OAuth', 'credentials' => ['client_id' => 'li-id', 'client_secret' => 'li-secret'], 'enabled' => true,
        ]);
    }
}
