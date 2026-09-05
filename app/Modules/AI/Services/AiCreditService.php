<?php

namespace App\Modules\AI\Services;

use App\Models\ClientSubscription;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Modules\AI\Exceptions\AiCreditsException;
use App\Modules\AI\Models\AiCreditLedger;
use App\Modules\AI\Models\AiCreditPeriod;
use App\Modules\AI\Models\AiWorkspaceSetting;
use App\Modules\AI\Services\Llm\LlmResponse;
use App\Notifications\AiCreditsThresholdNotification;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class AiCreditService
{
    public function creditsFor(string $feature): int
    {
        $action = config('ai_credits.actions.'.$feature);
        if (! is_array($action) || ! array_key_exists('credits', $action)) {
            throw new AiCreditsException('This AI action is not configured for managed credits.', 'ai_credit_rate_missing', 500);
        }

        return max(0, (int) $action['credits']);
    }

    /** @return array<int, array{key:string,label:string,credits:int}> */
    public function actionCatalog(): array
    {
        return collect(config('ai_credits.actions', []))
            ->reject(fn (mixed $action, string $key): bool => $key === 'rag_reply' || ! is_array($action))
            ->map(fn (array $action, string $key): array => [
                'key' => $key,
                'label' => (string) ($action['label'] ?? Str::headline($key)),
                'credits' => max(0, (int) ($action['credits'] ?? 0)),
            ])->values()->all();
    }

    public function usage(int $workspaceId): array
    {
        $workspace = Workspace::with(['owner', 'client'])->findOrFail($workspaceId);
        $identity = $this->accountIdentity($workspace);
        $subscription = $identity['subscription'];
        $mode = AiWorkspaceSetting::modeFor($workspaceId);
        if (! $subscription || ! $this->subscriptionProvidesAccess($subscription)) {
            return $this->emptyUsage('no_active_subscription', $mode);
        }

        $planLimit = $this->configuredAllowance($subscription);
        if ($planLimit === null) {
            return $this->emptyUsage('allowance_not_configured', $mode);
        }

        $period = $this->currentPeriod($workspace);
        $allowance = $period?->allowance ?? 0;
        $adjustments = $period?->adjustment_credits ?? 0;
        $used = $period?->used_credits ?? 0;
        $reserved = $period?->reserved_credits ?? 0;
        $total = max(0, $allowance + $adjustments);
        $remaining = max(0, $total - $used - $reserved);

        $status = match (true) {
            $total === 0 => 'not_included',
            $remaining === 0 => 'exhausted',
            (($used + $reserved) / $total) >= 0.8 => 'warning',
            default => 'available',
        };

        return [
            'limit' => $total,
            'plan_limit' => $planLimit,
            'allowance' => $total,
            'used' => $used,
            'reserved' => $reserved,
            'remaining' => $remaining,
            'percent_used' => $total > 0 ? min(100, (int) round((($used + $reserved) / $total) * 100)) : 0,
            'resets_at' => $period?->period_end?->toIso8601String(),
            'mode' => $mode,
            'status' => $status,
            'exhausted' => $total === 0 || $remaining === 0,
            'warning' => $total > 0 && (($used + $reserved) / $total) >= 0.8,
        ];
    }

    public function usageDetails(int $workspaceId): array
    {
        $workspace = Workspace::with(['owner', 'client'])->findOrFail($workspaceId);
        $period = $this->currentPeriod($workspace);
        if (! $period) {
            return ['by_feature' => [], 'by_action' => [], 'rates' => $this->actionCatalog(), 'recent' => []];
        }

        $byFeature = AiCreditLedger::where('period_id', $period->id)
            ->where('status', 'succeeded')
            ->where('provider_source', 'managed')
            ->selectRaw('feature, SUM(credits) AS credits, COUNT(*) AS actions')
            ->groupBy('feature')->orderByDesc('credits')->get();
        $recent = AiCreditLedger::where('period_id', $period->id)
            ->whereIn('status', ['succeeded', 'refunded', 'granted', 'revoked'])
            ->latest('id')->limit(25)
            ->get(['id', 'feature', 'provider_source', 'credits', 'adjustment_delta', 'status', 'created_at']);
        $byAction = $byFeature
            ->groupBy(fn ($row): string => $row->feature === 'rag_reply' ? 'chatbot_reply' : $row->feature)
            ->map(function ($rows, string $key): array {
                $actions = (int) $rows->sum('actions');
                $credits = (int) $rows->sum('credits');

                return [
                    'key' => $key,
                    'label' => $this->actionLabel($key),
                    'credits_per_action' => (int) (config('ai_credits.actions.'.$key.'.credits')
                        ?? ($actions > 0 ? round($credits / $actions) : 0)),
                    'actions' => $actions,
                    'credits_used' => $credits,
                ];
            })->sortByDesc('credits_used')->values()->all();

        return [
            'by_feature' => $byFeature->toArray(),
            'by_action' => $byAction,
            'rates' => $this->actionCatalog(),
            'recent' => $recent->map(fn (AiCreditLedger $row): array => [
                'id' => $row->id,
                'key' => $row->feature,
                'label' => $this->actionLabel($row->feature),
                'provider_source' => $row->provider_source,
                'credits' => $row->credits,
                'adjustment_delta' => $row->adjustment_delta,
                'status' => $row->status,
                'created_at' => $row->created_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }

    public function reserve(int $workspaceId, string $feature, string $idempotencyKey, ?int $actorId = null): AiCreditReservation
    {
        $credits = $this->creditsFor($feature);
        $workspace = Workspace::with(['owner', 'client'])->findOrFail($workspaceId);
        $identity = $this->accountIdentity($workspace);
        $stableKey = hash('sha256', $identity['type'].':'.$identity['id'].':'.$idempotencyKey);

        $existing = AiCreditLedger::where('idempotency_key', $stableKey)->first();
        if ($existing) {
            return $this->replayOrReject($existing);
        }

        try {
            return DB::transaction(function () use ($workspace, $identity, $workspaceId, $actorId, $feature, $stableKey, $credits) {
                if (! $identity['subscription'] || ! $this->subscriptionProvidesAccess($identity['subscription'])) {
                    throw new AiCreditsException('No active subscription includes WisperBot AI credits.', 'ai_credits_unavailable');
                }
                if ($this->configuredAllowance($identity['subscription']) === null) {
                    throw new AiCreditsException("Your plan's WisperBot AI credit allowance is not configured.", 'ai_credit_allowance_not_configured');
                }
                $period = $this->currentPeriod($workspace, lock: true);
                if (! $period) {
                    throw new AiCreditsException('No active subscription includes WisperBot AI credits.', 'ai_credits_unavailable');
                }

                if ($this->isFreeSubscription($identity['subscription']) && ! $this->accountHasVerifiedEmail($workspace)) {
                    throw new AiCreditsException('Verify the workspace owner email to activate managed AI credits.', 'ai_email_verification_required', 403);
                }

                $concurrent = AiCreditLedger::where('period_id', $period->id)->where('status', 'reserved')->count();
                if ($concurrent >= (int) config('ai_credits.max_concurrent_managed_requests', 3)) {
                    throw new AiCreditsException('Too many AI actions are already running. Try again shortly.', 'ai_concurrency_limited', 429);
                }
                $rateKey = 'managed-ai:'.$identity['type'].':'.$identity['id'];
                $perMinute = $period->allowance <= 100
                    ? (int) config('ai_credits.free_managed_requests_per_minute', 10)
                    : (int) config('ai_credits.managed_requests_per_minute', 30);
                if (RateLimiter::tooManyAttempts($rateKey, $perMinute)) {
                    throw new AiCreditsException('Too many AI requests. Try again in a minute.', 'ai_rate_limited', 429);
                }
                RateLimiter::hit($rateKey, 60);

                $available = max(0, $period->allowance + $period->adjustment_credits - $period->used_credits - $period->reserved_credits);
                if ($credits > $available && config('ai_credits.enforce', false)) {
                    throw new AiCreditsException('Your WisperBot AI credits are exhausted. Add your API provider or upgrade your plan.', 'ai_credits_exhausted');
                }

                // Shadow mode can observe over-limit demand without producing a negative displayed balance.
                $reserved = config('ai_credits.enforce', false) ? $credits : min($credits, $available);
                $period->increment('reserved_credits', $reserved);

                $ledger = AiCreditLedger::create([
                    'period_id' => $period->id,
                    'workspace_id' => $workspaceId,
                    'actor_id' => $actorId,
                    'feature' => $feature,
                    'rate_version' => (int) config('ai_credits.rate_version', 1),
                    'idempotency_key' => $stableKey,
                    'request_fingerprint' => $this->requestFingerprint(),
                    'provider_source' => 'managed',
                    'credits' => $reserved,
                    'status' => 'reserved',
                    'reserved_at' => now(),
                ]);

                return new AiCreditReservation($ledger);
            }, 3);
        } catch (QueryException $e) {
            // A concurrent request may have won the unique idempotency-key race.
            $existing = AiCreditLedger::where('idempotency_key', $stableKey)->first();
            if ($existing) {
                return $this->replayOrReject($existing);
            }
            throw $e;
        }
    }

    public function recordByok(int $workspaceId, string $feature, string $idempotencyKey, ?int $actorId = null): AiCreditReservation
    {
        $this->creditsFor($feature);
        $workspace = Workspace::findOrFail($workspaceId);
        $identity = $this->accountIdentity($workspace);
        $stableKey = hash('sha256', $identity['type'].':'.$identity['id'].':'.$idempotencyKey);
        $existing = AiCreditLedger::where('idempotency_key', $stableKey)->first();
        if ($existing) {
            return $this->replayOrReject($existing);
        }

        $ledger = AiCreditLedger::create([
            'workspace_id' => $workspaceId,
            'actor_id' => $actorId,
            'feature' => $feature,
            'rate_version' => (int) config('ai_credits.rate_version', 1),
            'idempotency_key' => $stableKey,
            'request_fingerprint' => $this->requestFingerprint(),
            'provider_source' => 'byok',
            'credits' => 0,
            'status' => 'reserved',
            'reserved_at' => now(),
        ]);

        return new AiCreditReservation($ledger);
    }

    public function succeed(AiCreditLedger $ledger, LlmResponse $response, string $provider): void
    {
        $warnings = [];
        $warningPeriod = null;
        DB::transaction(function () use ($ledger, $response, $provider, &$warnings, &$warningPeriod) {
            $locked = AiCreditLedger::lockForUpdate()->findOrFail($ledger->id);
            if ($locked->status !== 'reserved') {
                return;
            }
            if ($locked->period_id && $locked->credits > 0) {
                $period = AiCreditPeriod::lockForUpdate()->findOrFail($locked->period_id);
                $period->reserved_credits = max(0, $period->reserved_credits - $locked->credits);
                $period->used_credits += $locked->credits;
                $total = max(0, $period->allowance + $period->adjustment_credits);
                $percent = $total > 0 ? ($period->used_credits / $total) * 100 : 0;
                if ($percent >= 80 && ! $period->warned_80_at) {
                    $period->warned_80_at = now();
                    $warnings[] = 80;
                }
                if ($percent >= 100 && ! $period->warned_100_at) {
                    $period->warned_100_at = now();
                    $warnings[] = 100;
                }
                $period->save();
                $warningPeriod = $period;
            }
            $locked->update([
                'provider' => $provider,
                'model' => $response->model,
                'prompt_tokens' => $response->promptTokens,
                'completion_tokens' => $response->completionTokens,
                'cost_microusd' => $this->estimateCost($response),
                'status' => 'succeeded',
                'result_json' => [
                    'content' => $response->content,
                    'prompt_tokens' => $response->promptTokens,
                    'completion_tokens' => $response->completionTokens,
                    'model' => $response->model,
                    'latency_ms' => $response->latencyMs,
                ],
                'finalized_at' => now(),
            ]);
        }, 3);

        if ($warningPeriod && $warnings !== []) {
            try {
                $this->sendThresholdWarnings($warningPeriod, $warnings);
            } catch (\Throwable $exception) {
                // Credit finalization is authoritative. A notification transport outage
                // must never turn a completed AI response into a customer-visible error.
                Log::warning('AI credit threshold notification could not be dispatched.', [
                    'period_id' => $warningPeriod->id,
                    'exception' => $exception::class,
                ]);
            }
        }
    }

    public function refund(AiCreditLedger $ledger, string $errorCode = 'provider_failed'): void
    {
        DB::transaction(function () use ($ledger, $errorCode) {
            $locked = AiCreditLedger::lockForUpdate()->find($ledger->id);
            if (! $locked || $locked->status !== 'reserved') {
                return;
            }
            if ($locked->period_id && $locked->credits > 0) {
                $period = AiCreditPeriod::lockForUpdate()->find($locked->period_id);
                if ($period) {
                    $period->reserved_credits = max(0, $period->reserved_credits - $locked->credits);
                    $period->save();
                }
            }
            $locked->update(['status' => 'refunded', 'error_code' => Str::limit($errorCode, 64, ''), 'finalized_at' => now()]);
        }, 3);
    }

    public function reconcileStaleReservations(): int
    {
        $cutoff = now()->subMinutes((int) config('ai_credits.reservation_ttl_minutes', 10));
        $count = 0;
        AiCreditLedger::where('status', 'reserved')->where('reserved_at', '<=', $cutoff)
            ->chunkById(100, function ($rows) use (&$count) {
                foreach ($rows as $row) {
                    $this->refund($row, 'reservation_expired');
                    $count++;
                }
            });

        return $count;
    }

    private function currentPeriod(Workspace $workspace, bool $lock = false): ?AiCreditPeriod
    {
        $identity = $this->accountIdentity($workspace);
        $subscription = $identity['subscription'];
        if (! $subscription || ! $this->subscriptionProvidesAccess($subscription)) {
            return null;
        }
        $allowance = $this->configuredAllowance($subscription);
        if ($allowance === null) {
            return null;
        }

        // Replacing a subscription during an upgrade must not mint a fresh period.
        // Reuse the account's still-open monthly bucket and only raise its ceiling.
        $openQuery = AiCreditPeriod::where('account_type', $identity['type'])
            ->where('account_id', $identity['id'])
            ->where('period_start', '<=', now())
            ->where('period_end', '>', now())
            ->latest('period_start');
        $open = $lock ? $openQuery->lockForUpdate()->first() : $openQuery->first();
        if ($open) {
            if ($allowance > $open->allowance) {
                $open->update(['allowance' => $allowance, 'subscription_id' => $subscription->id]);
            }

            return $open;
        }

        [$start, $end] = $this->periodBounds($subscription);

        $query = AiCreditPeriod::where('account_type', $identity['type'])
            ->where('account_id', $identity['id'])
            ->where('period_start', $start);
        $period = $lock ? $query->lockForUpdate()->first() : $query->first();
        if (! $period) {
            $period = AiCreditPeriod::firstOrCreate([
                'account_type' => $identity['type'],
                'account_id' => $identity['id'],
                'period_start' => $start,
            ], [
                'account_type' => $identity['type'],
                'account_id' => $identity['id'],
                'subscription_type' => $subscription instanceof ClientSubscription ? 'client_subscription' : 'subscription',
                'subscription_id' => $subscription->id,
                'period_start' => $start,
                'period_end' => $end,
                'allowance' => $allowance,
                'status' => 'active',
            ]);
            if ($lock) {
                $period = AiCreditPeriod::lockForUpdate()->findOrFail($period->id);
            }
        } elseif ($allowance > $period->allowance) {
            // Mid-period upgrades raise the ceiling immediately. Downgrades wait for next period.
            $period->update(['allowance' => $allowance, 'subscription_id' => $subscription->id]);
        }

        return $period;
    }

    private function accountIdentity(Workspace $workspace): array
    {
        $workspace->loadMissing(['owner.client', 'client']);
        $client = $workspace->client ?? $workspace->owner?->client;
        if ($client) {
            $subscription = ClientSubscription::with('plan')
                ->where('client_id', $client->id)
                ->where(function ($query) {
                    $query->where('status', ClientSubscription::STATUS_ACTIVE)
                        ->orWhere(function ($cancelled) {
                            $cancelled->where('status', ClientSubscription::STATUS_CANCELLED)
                                ->where('ends_at', '>', now());
                        });
                })
                ->latest('id')
                ->first();

            // Self-service billing belongs to a User even when that user operates
            // inside a Client organization. Mirror effectiveSubscription/effectivePlan
            // and keep the resulting credits pooled at the Client identity.
            if (! $subscription) {
                $subscription = Subscription::with('plan')
                    ->whereIn('user_id', $client->users()->select('id'))
                    ->where(function ($query) {
                        $query->whereIn('status', ['active', 'trialing'])
                            ->orWhere(function ($cancelled) {
                                $cancelled->where('status', 'canceled')->where('ends_at', '>', now());
                            });
                    })
                    ->latest('id')
                    ->first();
            }

            return ['type' => 'client', 'id' => (int) $client->id, 'subscription' => $subscription];
        }

        if (! $workspace->owner) {
            throw new AiCreditsException('The workspace has no billing owner.', 'ai_billing_owner_missing', 422);
        }

        $subscription = Subscription::with('plan')
            ->where('user_id', $workspace->owner_id)
            ->where(function ($query) {
                $query->whereIn('status', ['active', 'trialing'])
                    ->orWhere(function ($cancelled) {
                        $cancelled->where('status', 'canceled')->where('ends_at', '>', now());
                    });
            })
            ->latest('id')
            ->first();

        return ['type' => 'user', 'id' => (int) $workspace->owner_id, 'subscription' => $subscription];
    }

    private function configuredAllowance(Subscription|ClientSubscription $subscription): ?int
    {
        $limits = $subscription->plan?->limits;
        if (! is_array($limits) || ! array_key_exists('ai_credits_per_month', $limits) || ! is_numeric($limits['ai_credits_per_month'])) {
            return null;
        }

        return max(0, (int) $limits['ai_credits_per_month']);
    }

    private function emptyUsage(string $status, string $mode): array
    {
        return [
            'limit' => 0,
            'plan_limit' => null,
            'allowance' => 0,
            'used' => 0,
            'reserved' => 0,
            'remaining' => 0,
            'percent_used' => 0,
            'resets_at' => null,
            'mode' => $mode,
            'status' => $status,
            'exhausted' => true,
            'warning' => false,
        ];
    }

    private function actionLabel(string $feature): string
    {
        return (string) config('ai_credits.actions.'.$feature.'.label', Str::headline($feature));
    }

    private function subscriptionProvidesAccess(Subscription|ClientSubscription $subscription): bool
    {
        $starts = $subscription->starts_at;
        if ($starts && $starts->isFuture()) {
            return false;
        }

        $activeStatuses = $subscription instanceof Subscription
            ? ['active', 'trialing']
            : [ClientSubscription::STATUS_ACTIVE];
        if (in_array($subscription->status, $activeStatuses, true)) {
            // Billing status is authoritative for renewable subscriptions. Some
            // gateways and legacy admin assignments retain the previous cycle's
            // end date while the subscription remains active.
            return true;
        }

        $cancelledStatus = $subscription instanceof Subscription
            ? 'canceled'
            : ClientSubscription::STATUS_CANCELLED;

        return $subscription->status === $cancelledStatus
            && $subscription->ends_at
            && $subscription->ends_at->isFuture();
    }

    private function isFreeSubscription(Subscription|ClientSubscription $subscription): bool
    {
        $plan = $subscription->plan;
        if (! $plan) {
            return false;
        }

        return (int) ($plan->monthly_price_cents ?? $plan->price_cents ?? 0) === 0;
    }

    private function accountHasVerifiedEmail(Workspace $workspace): bool
    {
        $client = $workspace->client ?? $workspace->owner?->client;
        if ($client) {
            return $client->users()
                ->whereNotNull('email_verified_at')
                ->where(function ($query) {
                    $query->where('client_role', User::CLIENT_ROLE_ADMINISTRATOR)
                        ->orWhereNull('client_role');
                })
                ->exists();
        }

        return (bool) $workspace->owner?->hasVerifiedEmail();
    }

    /** @param array<int, int> $thresholds */
    private function sendThresholdWarnings(AiCreditPeriod $period, array $thresholds): void
    {
        $recipients = $period->account_type === 'client'
            ? User::where('client_id', $period->account_id)
                ->where(function ($query) {
                    $query->where('client_role', User::CLIENT_ROLE_ADMINISTRATOR)->orWhereNull('client_role');
                })->get()
            : User::whereKey($period->account_id)->get();

        foreach ($thresholds as $threshold) {
            Notification::send($recipients, new AiCreditsThresholdNotification(
                $threshold,
                $period->used_credits,
                max(0, $period->allowance + $period->adjustment_credits),
                $period->period_end->toIso8601String(),
            ));
        }
    }

    private function periodBounds(Subscription|ClientSubscription $subscription): array
    {
        $anchor = CarbonImmutable::instance(($subscription->starts_at ?? $subscription->created_at ?? now())->startOfDay());
        $now = CarbonImmutable::now($anchor->timezone);
        $start = $anchor;
        while ($start->addMonthNoOverflow()->lessThanOrEqualTo($now)) {
            $start = $start->addMonthNoOverflow();
        }
        $end = $start->addMonthNoOverflow();
        $cancelledStatus = $subscription instanceof Subscription
            ? 'canceled'
            : ClientSubscription::STATUS_CANCELLED;
        if ($subscription->status === $cancelledStatus
            && $subscription->ends_at
            && CarbonImmutable::instance($subscription->ends_at)->lessThan($end)) {
            $end = CarbonImmutable::instance($subscription->ends_at);
        }

        return [$start, $end];
    }

    private function replayOrReject(AiCreditLedger $ledger): AiCreditReservation
    {
        if ($ledger->status === 'succeeded' && is_array($ledger->result_json)) {
            $r = $ledger->result_json;

            return new AiCreditReservation($ledger, new LlmResponse(
                (string) ($r['content'] ?? ''),
                (int) ($r['prompt_tokens'] ?? 0),
                (int) ($r['completion_tokens'] ?? 0),
                (string) ($r['model'] ?? ''),
                (int) ($r['latency_ms'] ?? 0),
            ));
        }
        if ($ledger->status === 'reserved') {
            throw new AiCreditsException('This AI request is already processing.', 'ai_request_in_progress', 409);
        }

        throw new AiCreditsException('This AI request already failed. Start a new request to retry.', 'ai_request_already_failed', 409);
    }

    private function estimateCost(LlmResponse $response): int
    {
        // Model IDs may contain dots (for example qwen3.7-flash), which Laravel
        // would otherwise interpret as nested configuration keys.
        $rate = config('ai_credits.costs', [])[$response->model] ?? null;
        if (! is_array($rate)) {
            return 0;
        }

        return (int) round((($response->promptTokens * ($rate['input'] ?? 0)) + ($response->completionTokens * ($rate['output'] ?? 0))) / 1_000_000);
    }

    private function requestFingerprint(): ?string
    {
        if (! app()->bound('request')) {
            return null;
        }
        $material = (string) request()->ip().'|'.(string) request()->userAgent();
        if ($material === '|') {
            return null;
        }

        return hash_hmac('sha256', $material, (string) config('app.key'));
    }
}
