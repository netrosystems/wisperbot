<?php

namespace App\Modules\Social\Jobs;

use App\Modules\Social\Models\SocialAccount;
use App\Modules\Social\Services\OAuth\OAuthManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RefreshSocialTokensJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(OAuthManager $oauthManager): void
    {
        // Networks that support programmatic refresh
        $refreshable = ['youtube', 'tiktok', 'linkedin', 'twitter'];

        SocialAccount::where('active', true)
            ->whereIn('network', $refreshable)
            ->where('token_expires_at', '<', now()->addDay())
            ->whereNotNull('refresh_token')
            ->chunkById(100, function ($accounts) use ($oauthManager) {
                foreach ($accounts as $account) {
                    try {
                        // A sibling row sharing this authorization may have
                        // rotated the token already while this chunk was held.
                        $account->refresh();
                        if (! $account->token_expires_at || $account->token_expires_at->isAfter(now()->addDay())) {
                            continue;
                        }

                        $previousRefreshToken = $account->refresh_token;
                        $refreshed = $oauthManager->refresh($account->network, $previousRefreshToken, [
                            // Company Page tokens belong to the separate LinkedIn app.
                            'variant' => ($account->meta['actor_type'] ?? null) === 'organization' ? 'pages' : null,
                        ]);

                        $tokens = [
                            'access_token' => $refreshed['access_token'],
                            'refresh_token' => $refreshed['refresh_token'] ?? $account->refresh_token,
                            'token_expires_at' => isset($refreshed['expires_in'])
                                ? now()->addSeconds((int) $refreshed['expires_in'])
                                : null,
                        ];
                        $account->update($tokens);
                        $this->shareWithSiblings($account, $previousRefreshToken, $tokens);

                        Log::info('Social token refreshed', ['network' => $account->network, 'account_id' => $account->id]);
                    } catch (\Throwable $e) {
                        // A sibling row may already have rotated this token.
                        if ($account->fresh()?->token_expires_at?->isFuture()) {
                            continue;
                        }
                        Log::warning('Social token refresh failed', [
                            'network' => $account->network,
                            'account_id' => $account->id,
                            'error' => $e->getMessage(),
                        ]);
                        $account->update(['active' => false]);
                    }
                }
            });
    }

    /**
     * One LinkedIn authorization can back several rows: the member's profile and
     * each Company Page they connected. LinkedIn rotates the refresh token, so
     * the rotated pair must reach the siblings or their next refresh would fail
     * with a stale token and deactivate a working connection.
     *
     * @param  array<string, mixed>  $tokens
     */
    private function shareWithSiblings(SocialAccount $account, ?string $previousRefreshToken, array $tokens): void
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
            ->each(fn (SocialAccount $sibling) => $sibling->update($tokens));
    }
}
