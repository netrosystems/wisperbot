<?php

namespace App\Modules\Social\Services;

use App\Modules\Social\Exceptions\TokenRefreshRejectedException;
use App\Modules\Social\Models\SocialAccount;
use App\Modules\Social\Services\OAuth\OAuthManager;
use Illuminate\Support\Facades\Cache;

/**
 * Keeps short-lived access tokens usable. YouTube tokens last an hour, X two
 * hours and TikTok a day, but each comes with a refresh token, so a stale
 * access token is renewed on demand instead of making the client reconnect.
 *
 * Renewal runs under a per-account lock: X and TikTok replace the refresh
 * token on every use, so two workers renewing at once would lock one out.
 */
class SocialTokenRefresher
{
    /** Networks whose access tokens are renewed with a refresh token. */
    public const NETWORKS = ['youtube', 'tiktok', 'linkedin', 'twitter'];

    public function __construct(private readonly OAuthManager $oauth) {}

    public static function renews(SocialAccount $account): bool
    {
        return in_array($account->network, self::NETWORKS, true) && filled($account->refresh_token);
    }

    /**
     * Renew the access token when it expires within `$withinSeconds`.
     *
     * @throws TokenRefreshRejectedException when the account must be reconnected
     * @throws \Throwable on a temporary failure; the account stays connected
     */
    public function ensureFresh(SocialAccount $account, int $withinSeconds = 300): SocialAccount
    {
        if (! self::renews($account) || ! $this->expiresWithin($account, $withinSeconds)) {
            return $account;
        }

        return Cache::lock('social-access-token:'.$account->id, 30)->block(10, function () use ($account, $withinSeconds): SocialAccount {
            // Another worker may have renewed it while this one waited.
            $current = $account->fresh() ?? $account;
            if (! self::renews($current) || ! $this->expiresWithin($current, $withinSeconds)) {
                return $current;
            }

            $previousRefreshToken = $current->refresh_token;
            $tokens = $this->oauth->refresh($current->network, $previousRefreshToken, [
                // Company Page tokens belong to the separate LinkedIn app.
                'variant' => ($current->meta['actor_type'] ?? null) === 'organization' ? 'pages' : null,
            ]);

            $values = [
                'access_token' => $tokens['access_token'],
                'refresh_token' => $tokens['refresh_token'] ?? $previousRefreshToken,
                'token_expires_at' => isset($tokens['expires_in']) ? now()->addSeconds(max(60, (int) $tokens['expires_in'])) : null,
                'active' => true,
                'meta' => array_diff_key((array) $current->meta, ['reconnect_required' => true]),
            ];
            $current->update($values);
            $this->shareWithSiblings($current, $previousRefreshToken, $values);

            return $current;
        });
    }

    private function expiresWithin(SocialAccount $account, int $seconds): bool
    {
        // No recorded expiry: nothing to renew ahead of time.
        return $account->token_expires_at !== null && $account->token_expires_at->lte(now()->addSeconds($seconds));
    }

    /**
     * One LinkedIn authorization can back several rows: the member's profile
     * and each Company Page they connected. When the refresh token rotates,
     * the new pair must reach the siblings or their next renewal would fail.
     *
     * @param  array<string, mixed>  $values
     */
    private function shareWithSiblings(SocialAccount $account, ?string $previousRefreshToken, array $values): void
    {
        if ($previousRefreshToken === null || $previousRefreshToken === '') {
            return;
        }

        SocialAccount::where('workspace_id', $account->workspace_id)
            ->where('network', $account->network)
            ->whereKeyNot($account->getKey())
            ->get()
            // refresh_token is encrypted, so the match cannot be done in SQL.
            ->filter(fn (SocialAccount $sibling): bool => $sibling->refresh_token === $previousRefreshToken)
            ->each(fn (SocialAccount $sibling) => $sibling->update(array_diff_key($values, ['meta' => true])));
    }
}
