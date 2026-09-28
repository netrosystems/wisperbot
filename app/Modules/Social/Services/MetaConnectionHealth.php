<?php

namespace App\Modules\Social\Services;

use App\Modules\Integrations\Services\CredentialResolver;
use App\Modules\Social\Models\SocialAccount;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Facebook and Instagram Page tokens never expire, but Meta invalidates them
 * when the person who connected them later signs in to WisperBot again and
 * leaves that Page unselected, or removes WisperBot's access. Nothing tells
 * WisperBot, so the card kept saying "Active" until a scheduled post failed.
 * This asks Meta directly and flags the account as "Reconnect needed", or
 * clears the flag once Meta reports the token usable again.
 */
class MetaConnectionHealth
{
    public const NETWORKS = ['facebook', 'instagram'];

    /** The permission each network needs to publish. */
    public const REQUIRED_SCOPES = [
        'facebook' => 'pages_manage_posts',
        'instagram' => 'instagram_content_publish',
    ];

    /** Marks a flag this class set, so it only ever clears its own. */
    public const REASON = 'meta_access';

    public function __construct(
        private readonly CredentialResolver $credentials,
        private readonly SocialConnectionAlerts $alerts,
    ) {}

    /**
     * @return 'ok'|'broken'|'unknown' `unknown` when Meta could not be asked;
     *                                 the account is left as it is.
     */
    public function check(SocialAccount $account): string
    {
        if (! in_array($account->network, self::NETWORKS, true)) {
            return 'unknown';
        }

        $data = $this->inspect((string) $account->access_token);
        if ($data === null) {
            return 'unknown';
        }

        $usable = ($data['is_valid'] ?? false) === true
            && in_array(self::REQUIRED_SCOPES[$account->network], (array) ($data['scopes'] ?? []), true);

        if (! $usable) {
            $this->markBroken($account);

            return 'broken';
        }

        $meta = (array) $account->meta;
        $restored = ! $account->active && ($meta['reconnect_reason'] ?? null) === self::REASON;
        $userId = isset($data['user_id']) ? (string) $data['user_id'] : null;
        if ($restored || ($userId !== null && ($meta['meta_user_id'] ?? null) !== $userId)) {
            if ($restored) {
                unset($meta['reconnect_required'], $meta['reconnect_reason']);
            }
            $account->update([
                'active' => $restored ? true : $account->active,
                'meta' => array_merge($meta, array_filter(['meta_user_id' => $userId])),
            ]);
        }

        return 'ok';
    }

    /**
     * Flags the account and notifies its workspace once.
     *
     * @return bool true when the account was not flagged before
     */
    public function markBroken(SocialAccount $account): bool
    {
        $meta = (array) $account->meta;
        if (! $account->active && ($meta['reconnect_required'] ?? false)) {
            return false;
        }

        $account->update([
            'active' => false,
            'meta' => array_merge($meta, ['reconnect_required' => true, 'reconnect_reason' => self::REASON]),
        ]);
        Log::warning('Meta connection lost access', ['account_id' => $account->id, 'workspace_id' => $account->workspace_id, 'network' => $account->network]);
        $this->alerts->notify($account, null);

        return true;
    }

    /** @return array<string, mixed>|null Meta's debug_token data, or null when Meta could not be reached. */
    private function inspect(string $token): ?array
    {
        if ($token === '') {
            return ['is_valid' => false];
        }
        $meta = $this->credentials->meta();
        if ($meta === null || ! $meta->appId() || ! $meta->appSecret()) {
            return null;
        }

        try {
            $response = Http::timeout(15)->get('https://graph.facebook.com/v25.0/debug_token', [
                'input_token' => $token,
                'access_token' => $meta->appId().'|'.$meta->appSecret(),
            ]);
        } catch (\Throwable) {
            return null;
        }

        $data = $response->json('data');

        return $response->successful() && is_array($data) ? $data : null;
    }
}
