<?php

namespace App\Modules\Social\Services;

use App\Modules\Broadcasting\Models\UsageMeter;
use App\Modules\Social\Exceptions\ClientSafePublishException;
use App\Modules\Social\Exceptions\MetaAccessRevokedException;
use App\Modules\Social\Exceptions\PublishOutcomeUnknownException;
use App\Modules\Social\Exceptions\TokenRefreshRejectedException;
use App\Modules\Social\Exceptions\XPublishException;
use App\Modules\Social\Models\SocialAccount;
use App\Modules\Social\Models\SocialPost;
use App\Modules\Social\Models\SocialPostAccount;
use App\Modules\Social\Services\Drivers\FacebookDriver;
use App\Modules\Social\Services\Drivers\InstagramSocialDriver;
use App\Modules\Social\Services\Drivers\LinkedInDriver;
use App\Modules\Social\Services\Drivers\SocialNetworkInterface;
use App\Modules\Social\Services\Drivers\TikTokDriver;
use App\Modules\Social\Services\Drivers\XDriver;
use App\Modules\Social\Services\Drivers\YoutubeDriver;
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

        $results = $this->disconnectedTargets($post, $accounts->modelKeys());

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

        // Show clients why a network failed; link errors are already client-safe.
        foreach ($results as $accountId => $result) {
            if ($result['status'] === 'failed' && ! isset($result['error'])) {
                $error = SocialPostAccount::where('post_id', $post->id)->where('social_account_id', $accountId)->value('error');
                if (is_string($error) && $error !== '') {
                    $results[$accountId]['error'] = $error;
                }
            }
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
     * A target that was disconnected used to be skipped without a word, so a
     * post "published" while silently leaving out a network. It now fails for
     * that network with the reason; reconnecting the same account brings the
     * old connection back and a retry publishes there.
     *
     * @param  list<int>  $foundIds
     * @return array<int, array{status: string, error: string}>
     */
    private function disconnectedTargets(SocialPost $post, array $foundIds): array
    {
        $missing = array_values(array_diff(array_map('intval', $post->target_accounts ?? []), $foundIds));
        if ($missing === []) {
            return [];
        }

        $trashed = SocialAccount::onlyTrashed()->where('workspace_id', $post->workspace_id)->whereIn('id', $missing)->get()->keyBy('id');
        $results = [];
        foreach ($missing as $id) {
            $account = $trashed->get($id);
            $label = $account ? (self::LABELS[$account->network] ?? ($account->network === 'twitter' ? 'X' : ucfirst($account->network))) : null;
            $error = $account
                ? "{$label} account {$account->name} was disconnected. Reconnect it in Social Media Automation, then publish again."
                : 'This account was removed from the workspace. Edit the post to choose another account.';
            if ($account) {
                SocialPostAccount::updateOrCreate(['post_id' => $post->id, 'social_account_id' => $id], ['status' => 'failed', 'error' => $error]);
            }
            $results[$id] = ['status' => 'failed', 'error' => $error];
        }

        return $results;
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

        $account = $this->renewBeforePublish($post, $account, $link, $label);
        if ($account === null) {
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
        } catch (MetaAccessRevokedException $e) {
            Log::warning('Social publish refused: Meta access removed', ['post_id' => $post->id, 'account_id' => $account->id, 'network' => $account->network, 'error' => $e->getMessage()]);
            app(MetaConnectionHealth::class)->markBroken($account);
            $link->update([
                'status' => 'failed',
                'error' => "{$label} no longer lets WisperBot post to {$account->name}. Reconnect {$label} in Social Media Automation, keep this account selected in Meta, then publish again.",
                'provider_attempted_at' => null,
            ]);

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

        $account = $this->renewBeforePublish($post, $account, $link, 'X');
        if ($account === null) {
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
     * Renew a short-lived access token right before the provider call. Only a
     * refresh token the network rejected disconnects the account; any other
     * failure fails this attempt, which the queue retries.
     */
    private function renewBeforePublish(SocialPost $post, SocialAccount $account, SocialPostAccount $link, string $label): ?SocialAccount
    {
        try {
            return app(SocialTokenRefresher::class)->ensureFresh($account);
        } catch (TokenRefreshRejectedException $e) {
            Log::warning('Social connection revoked before publishing', ['post_id' => $post->id, 'account_id' => $account->id, 'error' => $e->getMessage()]);
            $account->update(['active' => false, 'meta' => array_merge((array) $account->meta, ['reconnect_required' => true])]);
            $link->update(['status' => 'failed', 'error' => "Reconnect your {$label} account, then publish again."]);
        } catch (\Throwable $e) {
            Log::warning('Social token refresh failed before publishing', ['post_id' => $post->id, 'account_id' => $account->id, 'error' => $e->getMessage()]);
            $link->update(['status' => 'failed', 'error' => "{$label} could not be reached to renew the connection. Publishing will be retried."]);
        }

        return null;
    }
}
