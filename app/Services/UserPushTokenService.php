<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserPushToken;

class UserPushTokenService
{
    public function register(User $user, ?string $token, ?string $deviceName = null): void
    {
        $token = trim((string) $token);

        if ($token === '') {
            return;
        }

        UserPushToken::updateOrCreate(
            [
                'provider' => UserPushToken::PROVIDER_ONESIGNAL,
                'token' => $token,
            ],
            [
                'user_id' => $user->id,
                'device_name' => $deviceName,
                'last_seen_at' => now(),
                'revoked_at' => null,
            ],
        );
    }

    /**
     * @return array<int, string>
     */
    public function activeTokensFor(User $user): array
    {
        return UserPushToken::query()
            ->where('user_id', $user->id)
            ->where('provider', UserPushToken::PROVIDER_ONESIGNAL)
            ->whereNull('revoked_at')
            ->pluck('token')
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
