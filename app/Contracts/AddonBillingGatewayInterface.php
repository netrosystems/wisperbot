<?php

namespace App\Contracts;

use App\Models\ClientAddonSubscription;
use App\Models\User;

interface AddonBillingGatewayInterface
{
    /** @param array<string, mixed> $addon */
    public function createAddonCheckout(User $user, array $addon): array;

    public function fulfillAddonCheckout(string $sessionId): array;

    public function cancelAddon(ClientAddonSubscription $subscription): bool;
}
