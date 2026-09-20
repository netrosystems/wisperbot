<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

class PlanSelectionService
{
    public function defaultFreePlan(): ?Plan
    {
        return Plan::query()
            ->where('enabled', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->first(fn (Plan $plan) => $plan->isFree());
    }

    public function activateDefaultFreePlan(User $user): Subscription
    {
        $plan = $this->defaultFreePlan();

        if (! $plan) {
            throw new DomainException('The initial Free plan is not available.');
        }

        return $this->activateFreePlan($user, $plan);
    }

    public function activateFreePlan(User $user, Plan $plan): Subscription
    {
        if (! $plan->enabled || ! $plan->isFree()) {
            throw new DomainException('Only an enabled free plan can be activated without checkout.');
        }

        return DB::transaction(function () use ($user, $plan) {
            /** @var User $lockedUser */
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);
            $existing = $lockedUser->effectiveSubscription();

            if ($existing instanceof Subscription && (int) $existing->plan_id === (int) $plan->id) {
                return $existing;
            }

            $clientHasPlan = $lockedUser->client instanceof Client
                && $lockedUser->client->effectivePlan() !== null;

            if ($existing !== null || $clientHasPlan) {
                throw new DomainException('This account already has an active plan.');
            }

            return Subscription::create([
                'user_id' => $lockedUser->id,
                'plan_id' => $plan->id,
                'status' => 'active',
                'billing_cycle' => 'month',
                'starts_at' => now(),
                'gateway' => 'free',
                'gateway_subscription_id' => null,
                'renews_at' => null,
                'ends_at' => null,
            ]);
        });
    }
}
