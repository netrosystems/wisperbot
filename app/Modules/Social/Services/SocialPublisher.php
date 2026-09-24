<?php

namespace App\Modules\Social\Services;

use App\Modules\Broadcasting\Models\UsageMeter;
use App\Modules\Social\Models\SocialAccount;
use App\Modules\Social\Models\SocialPost;
use App\Modules\Social\Exceptions\ClientSafePublishException;
use App\Modules\Social\Exceptions\PublishOutcomeUnknownException;
use App\Modules\Social\Exceptions\XPublishException;
use App\Modules\Social\Models\SocialPostAccount;
use App\Modules\Social\Services\Drivers\FacebookDriver;
use App\Modules\Social\Services\Drivers\InstagramSocialDriver;
use App\Modules\Social\Services\Drivers\LinkedInDriver;
use App\Modules\Social\Services\Drivers\SocialNetworkInterface;
use App\Modules\Social\Services\Drivers\TikTokDriver;
use App\Modules\Social\Services\Drivers\XDriver;
use App\Modules\Social\Services\Drivers\YoutubeDriver;
use App\Modules\Social\Services\OAuth\OAuthManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class SocialPublisher
{
    private const LABELS = ['facebook' => 'Facebook', 'instagram' => 'Instagram', 'linkedin' => 'LinkedIn', 'youtube' => 'YouTube', 'tiktok' => 'TikTok'];

    /** @var array<string, SocialNetworkInterface> */
    private array $drivers;

    public function __construct()
    {
        $this->drivers = [
            'facebook' => new FacebookDriver,
            'instagram' => new InstagramSocialDriver,
            'linkedin' => new LinkedInDriver,
            'youtube' => new YoutubeDriver,
            'tiktok' => new TikTokDriver,
            'twitter' => new XDriver,
        ];
    }

    public function publish(SocialPost $post): void
    {
        $post->update(['status' => 'publishing']);

        // Scope accounts to the post's own workspace to prevent cross-workspace publishing.
        $accounts = SocialAccount::where('workspace_id', $post->workspace_id)
            ->whereIn('id', $post->target_accounts ?? [])
            ->get();

        $results = [];

        foreach ($accounts as $account) {
            $link = SocialPostAccount::firstOrCreate(
                ['post_id' => $post->id, 'social_account_id' => $account->id],
                ['status' => 'pending']
            );

            // On job retry, skip accounts already successfully published.
            if ($link->status === 'published') {
                $results[$account->id] = ['status' => 'published', 'post_id' => $link->platform_post_id];
                continue;
            }

            $driver = $this->drivers[$account->network] ?? null;
            if (! $driver) {
                $link->update(['status' => 'failed', 'error' => "No driver for network {$account->network}."]);
                $results[$account->id] = ['status' => 'failed'];

                continue;
            }

            if ($account->network === 'twitter' && $driver instanceof XDriver) {
                $results[$account->id] = $this->publishToX($post, $account, $link, $driver);

                continue;
            }

            $results[$account->id] = $this->publishOnce($post, $account, $link, $driver);
        }

        $succeededCount = collect($results)->filter(fn ($r) => $r['status'] === 'published')->count();
        $failedCount = collect($results)->filter(fn ($r) => $r['status'] === 'failed')->count();
        $allFailed = $succeededCount === 0;

        // Keep the post retryable whenever one account failed. Published account
        // links are skipped on the next attempt, while failed links are retried.
        $finalStatus = $failedCount > 0 ? 'failed' : 'published';

        $post->update([
            'status' => $finalStatus,
            'published_at' => $failedCount === 0 && ! $allFailed ? now() : null,
            'publish_results' => $results,
        ]);

        if (! $allFailed && $failedCount === 0) {
            UsageMeter::track($post->workspace_id, 'social_posts');
        }

        // The queue job must retry failed accounts. Leaving the exception
        // swallowed here makes a temporary provider outage look permanent and
        // prevents Laravel from applying its retry/backoff policy. Published
        // account links are skipped on the next attempt, so this is safe for
        // partial success.
        if ($failedCount > 0) {
            throw new \RuntimeException("{$failedCount} social account publish attempt(s) failed.");
        }
    }

    /**
     * No provider offers an idempotency key, so an attempt whose outcome is
     * unknown (timeout, 5xx, or a worker stopped mid-request) must never be
     * sent again automatically: it may already be live. The link is marked
     * before the request; only a definite answer clears the mark. Media the
     * driver prepared is kept on the link so a retry does not upload it again.
     *
     * @return array{status: string, post_id?: string}
     */
    private function publishOnce(SocialPost $post, SocialAccount $account, SocialPostAccount $link, SocialNetworkInterface $driver): array
    {
        $label = self::LABELS[$account->network] ?? ucfirst($account->network);
        $unconfirmed = "{$label} did not confirm this post. Check {$label} before publishing it again.";

        if ($link->provider_attempted_at !== null) {
            $link->update(['status' => 'failed', 'error' => $unconfirmed]);

            return ['status' => 'failed'];
        }

        $link->update(['provider_attempted_at' => now()]);

        try {
            $content = $post->contentFor($account->network);
            $platformId = $driver->publish($account, array_merge($post->toArray(), $content, [
                'media_cache' => new ProviderMediaCache($link),
            ]));
            $link->update(['status' => 'published', 'platform_post_id' => $platformId, 'published_at' => now(), 'provider_attempted_at' => null, 'provider_media' => null, 'error' => null]);

            return ['status' => 'published', 'post_id' => $platformId];
        } catch (PublishOutcomeUnknownException $e) {
            Log::error('Social publish outcome unknown', ['post_id' => $post->id, 'account_id' => $account->id, 'network' => $account->network, 'error' => $e->getPrevious()?->getMessage()]);
            $link->update(['status' => 'failed', 'error' => $unconfirmed]);

            return ['status' => 'failed'];
        } catch (\Throwable $e) {
            // Store a sanitized message; full details go to the log.
            Log::error('Social publish failed', [
                'post_id' => $post->id,
                'account_id' => $account->id,
                'network' => $account->network,
                'error' => $e->getMessage(),
            ]);
            $link->update([
                'status' => 'failed',
                'error' => $e instanceof ClientSafePublishException ? $e->getMessage() : 'Publish failed. See application logs for details.',
                'provider_attempted_at' => null,
            ]);

            return ['status' => 'failed'];
        }
    }

    /**
     * Every X create costs credits and X has no idempotency key, so an attempt
     * whose outcome is unknown must never be sent again automatically. The
     * link is marked before the request; only a definite answer from X clears
     * the mark. A client re-publishing after checking X clears it deliberately.
     *
     * @return array{status: string, post_id?: string}
     */
    private function publishToX(SocialPost $post, SocialAccount $account, SocialPostAccount $link, XDriver $driver): array
    {
        if ($link->provider_attempted_at !== null) {
            $link->update(['status' => 'failed', 'error' => 'X did not confirm this post. Check X before publishing it again.']);

            return ['status' => 'failed'];
        }

        try {
            $account = $this->freshXAccount($account);
        } catch (\Throwable $e) {
            Log::warning('X token refresh failed before publishing', ['post_id' => $post->id, 'account_id' => $account->id, 'error' => $e->getMessage()]);
            $account->update(['active' => false]);
            $link->update(['status' => 'failed', 'error' => 'Reconnect your X account, then publish again.']);

            return ['status' => 'failed'];
        }

        // The client's X version when they customised one, else the shared post.
        $content = $post->contentFor('twitter');

        // Media first: uploads never create a post, so their failures are
        // definite and a retry reuses whatever was already uploaded.
        try {
            $mediaIds = app(XMediaUploader::class)->prepare($account, $link, $content['media_urls'], $driver);
        } catch (XPublishException $e) {
            Log::warning('X media preparation failed', ['post_id' => $post->id, 'account_id' => $account->id, 'category' => $e->category]);
            $link->update(['status' => 'failed', 'error' => $e->getMessage()]);
            if ($e->category === 'reconnect') {
                $account->update(['active' => false]);
            }

            return ['status' => 'failed'];
        } catch (\Throwable $e) {
            Log::error('X media preparation failed unexpectedly', ['post_id' => $post->id, 'account_id' => $account->id, 'error' => $e->getMessage()]);
            $link->update(['status' => 'failed', 'error' => 'The images or video for this post could not be prepared for X. Try again.']);

            return ['status' => 'failed'];
        }

        $link->update(['provider_attempted_at' => now()]);

        try {
            $platformId = $driver->publish($account, array_merge($post->toArray(), $content, ['x_media_ids' => $mediaIds]));
            $link->update(['status' => 'published', 'platform_post_id' => $platformId, 'published_at' => now(), 'provider_attempted_at' => null, 'provider_media' => null, 'error' => null]);

            return ['status' => 'published', 'post_id' => $platformId];
        } catch (XPublishException $e) {
            Log::error('X publish failed', ['post_id' => $post->id, 'account_id' => $account->id, 'category' => $e->category, 'definite' => $e->definite]);
            $link->update([
                'status' => 'failed',
                // Messages are written for clients and never carry provider text.
                'error' => $e->getMessage(),
                'provider_attempted_at' => $e->definite ? null : $link->provider_attempted_at,
            ]);
            if ($e->category === 'reconnect') {
                $account->update(['active' => false]);
            }

            return ['status' => 'failed'];
        } catch (\Throwable $e) {
            // Anything unexpected after the request started: treat as unknown.
            Log::error('X publish failed unexpectedly', ['post_id' => $post->id, 'account_id' => $account->id, 'error' => $e->getMessage()]);
            $link->update(['status' => 'failed', 'error' => 'X did not confirm this post. Check X before publishing it again.']);

            return ['status' => 'failed'];
        }
    }

    /**
     * X access tokens last about two hours, so refresh right before use. X
     * rotates the refresh token on every use; the lock stops two workers from
     * spending the same refresh token and locking the account out.
     */
    private function freshXAccount(SocialAccount $account): SocialAccount
    {
        if ($account->token_expires_at && $account->token_expires_at->isAfter(now()->addMinutes(5))) {
            return $account;
        }

        return Cache::lock('social-access-token:'.$account->id, 30)->block(10, function () use ($account): SocialAccount {
            $current = $account->fresh();
            if ($current->token_expires_at && $current->token_expires_at->isAfter(now()->addMinutes(5))) {
                return $current;
            }
            if (! $current->refresh_token) {
                throw new \RuntimeException('X account has no refresh token.');
            }

            $tokens = app(OAuthManager::class)->refresh('twitter', $current->refresh_token);
            $current->update([
                'access_token' => $tokens['access_token'],
                'refresh_token' => $tokens['refresh_token'] ?? $current->refresh_token,
                'token_expires_at' => now()->addSeconds(max(60, (int) ($tokens['expires_in'] ?? 7200))),
                'active' => true,
            ]);

            return $current;
        });
    }
}
