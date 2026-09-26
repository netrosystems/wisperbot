<?php

namespace App\Modules\Social\Jobs;

use App\Models\User;
use App\Models\Workspace;
use App\Modules\Social\Exceptions\TokenRefreshRejectedException;
use App\Modules\Social\Models\SocialAccount;
use App\Modules\Social\Services\SocialTokenRefresher;
use App\Notifications\SocialConnectionAttentionNotification;
use App\Services\WorkspaceNotificationRecipients;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Hourly. Renews access tokens that expire within two hours (YouTube tokens
 * last one hour), so connections stay usable without the client doing
 * anything. Only a refresh token the network rejected disconnects an account;
 * timeouts and server errors are retried next hour. Connections that cannot be
 * renewed (LinkedIn without a refresh token) get one reminder a week ahead.
 */
class RefreshSocialTokensJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const RENEW_WITHIN_SECONDS = 2 * 3600;

    public const REMIND_DAYS_BEFORE = 7;

    public function handle(SocialTokenRefresher $refresher): void
    {
        SocialAccount::where('active', true)
            ->whereIn('network', SocialTokenRefresher::NETWORKS)
            ->whereNotNull('refresh_token')
            ->where('token_expires_at', '<', now()->addSeconds(self::RENEW_WITHIN_SECONDS))
            ->chunkById(100, function ($accounts) use ($refresher): void {
                foreach ($accounts as $account) {
                    try {
                        $refresher->ensureFresh($account, self::RENEW_WITHIN_SECONDS);
                    } catch (TokenRefreshRejectedException $e) {
                        Log::warning('Social connection revoked by the network', ['network' => $account->network, 'account_id' => $account->id, 'error' => $e->getMessage()]);
                        $account->update(['active' => false, 'meta' => array_merge((array) $account->meta, ['reconnect_required' => true])]);
                        $this->notify($account, null);
                    } catch (\Throwable $e) {
                        // Temporary: keep the connection and try again next hour.
                        Log::warning('Social token refresh failed; will retry', ['network' => $account->network, 'account_id' => $account->id, 'error' => $e->getMessage()]);
                    }
                }
            });

        $this->remindExpiringConnections();
    }

    private function remindExpiringConnections(): void
    {
        SocialAccount::where('active', true)
            ->whereNotNull('token_expires_at')
            ->whereBetween('token_expires_at', [now(), now()->addDays(self::REMIND_DAYS_BEFORE)])
            ->chunkById(100, function ($accounts): void {
                foreach ($accounts as $account) {
                    $expiry = $account->token_expires_at->toIso8601String();
                    $days = $account->daysUntilReconnect();
                    // Renewable connections never need a reminder; send one per expiry date.
                    if ($days === null || ($account->meta['expiry_reminder_for'] ?? null) === $expiry) {
                        continue;
                    }
                    $this->notify($account, $days);
                    $account->update(['meta' => array_merge((array) $account->meta, ['expiry_reminder_for' => $expiry])]);
                }
            });
    }

    private function notify(SocialAccount $account, ?int $daysLeft): void
    {
        $workspace = Workspace::find($account->workspace_id);
        if (! $workspace) {
            return;
        }

        $managerIds = $workspace->members()->wherePivotIn('role', ['owner', 'admin'])->pluck('users.id');
        app(WorkspaceNotificationRecipients::class)->for((int) $workspace->id)
            ->filter(fn (User $user) => (int) $workspace->owner_id === (int) $user->id
                || $user->client_role === User::CLIENT_ROLE_ADMINISTRATOR
                || $managerIds->contains($user->id))
            ->unique('id')
            ->each(fn (User $user) => $user->notify(new SocialConnectionAttentionNotification($account->network, (string) $account->name, $daysLeft, (int) $workspace->id)));
    }
}
