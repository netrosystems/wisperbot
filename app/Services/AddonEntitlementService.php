<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientAddonSubscription;
use App\Models\User;
use Carbon\CarbonInterface;

class AddonEntitlementService
{
    public const DEVELOPER_TOOLS = 'developer_tools';

    public function enabledFor(User|Client|null $subject, string $addonKey): bool
    {
        $clientId = $subject instanceof User ? $subject->client_id : $subject?->id;
        if (! $clientId) {
            return false;
        }

        try {
            $subscription = ClientAddonSubscription::where('client_id', $clientId)
                ->where('addon_key', $addonKey)
                ->first();

            return $subscription?->grantsAccess() ?? false;
        } catch (\Throwable) {
            // During a rolling deployment the migration can briefly lag behind
            // application code. Add-ons fail closed until the table is ready.
            return false;
        }
    }

    public function subscriptionFor(User|Client|null $subject, string $addonKey): ?ClientAddonSubscription
    {
        $clientId = $subject instanceof User ? $subject->client_id : $subject?->id;
        if (! $clientId) {
            return null;
        }

        return ClientAddonSubscription::where('client_id', $clientId)
            ->where('addon_key', $addonKey)
            ->first();
    }

    public function markPending(User $user, string $addonKey, string $gateway, array $metadata = []): ClientAddonSubscription
    {
        return ClientAddonSubscription::updateOrCreate(
            ['client_id' => $user->client_id, 'addon_key' => $addonKey],
            [
                'purchased_by_user_id' => $user->id,
                'status' => ClientAddonSubscription::STATUS_PENDING,
                'gateway' => $gateway,
                'gateway_subscription_id' => $metadata['gateway_subscription_id'] ?? null,
                'starts_at' => null,
                'renews_at' => null,
                'ends_at' => null,
                'cancel_at_period_end' => false,
                'gateway_metadata' => $metadata,
            ]
        );
    }

    public function activate(
        int $clientId,
        string $addonKey,
        int $purchasedByUserId,
        string $gateway,
        string $gatewaySubscriptionId,
        ?CarbonInterface $renewsAt = null,
        array $metadata = []
    ): ClientAddonSubscription {
        $subscription = ClientAddonSubscription::firstOrNew([
            'client_id' => $clientId,
            'addon_key' => $addonKey,
        ]);
        $subscription->fill([
            'purchased_by_user_id' => $purchasedByUserId ?: null,
            'status' => ClientAddonSubscription::STATUS_ACTIVE,
            'gateway' => $gateway,
            'gateway_subscription_id' => $gatewaySubscriptionId,
            'starts_at' => $subscription->starts_at ?? now(),
            'renews_at' => $renewsAt,
            'ends_at' => null,
            'cancel_at_period_end' => false,
            'gateway_metadata' => $metadata,
        ]);
        $subscription->save();

        return $subscription;
    }

    public function syncGatewayStatus(
        string $gateway,
        string $gatewaySubscriptionId,
        string $status,
        ?CarbonInterface $renewsAt = null,
        ?CarbonInterface $endsAt = null
    ): bool {
        $subscription = ClientAddonSubscription::where('gateway', $gateway)
            ->where('gateway_subscription_id', $gatewaySubscriptionId)
            ->first();
        if (! $subscription) {
            return false;
        }

        $subscription->update([
            'status' => $this->canonicalStatus($status),
            'renews_at' => $renewsAt ?? $subscription->renews_at,
            'ends_at' => $endsAt,
        ]);

        return true;
    }

    private function canonicalStatus(string $status): string
    {
        return match (strtolower($status)) {
            'active', 'trialing' => ClientAddonSubscription::STATUS_ACTIVE,
            'past_due', 'unpaid', 'paused', 'suspended' => ClientAddonSubscription::STATUS_PAST_DUE,
            'canceled', 'cancelled', 'expired' => ClientAddonSubscription::STATUS_CANCELLED,
            default => ClientAddonSubscription::STATUS_PENDING,
        };
    }
}
