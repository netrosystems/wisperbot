<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ClientSubscription;
use App\Models\Subscription;
use App\Models\Workspace;
use App\Modules\AI\Models\AiCreditLedger;
use App\Modules\AI\Models\AiCreditPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class AiCreditAdminController extends Controller
{
    public function index(Request $request): JsonResponse|Response
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);
        $from = isset($validated['from']) ? CarbonImmutable::parse($validated['from'])->startOfDay() : CarbonImmutable::now()->subDays(30)->startOfDay();
        $to = isset($validated['to']) ? CarbonImmutable::parse($validated['to'])->endOfDay() : CarbonImmutable::now()->endOfDay();
        $base = AiCreditLedger::query()->whereBetween('created_at', [$from, $to]);

        $byFeature = (clone $base)->selectRaw('feature, SUM(credits) AS credits, SUM(cost_microusd) AS cost_microusd, COUNT(*) AS actions')
            ->where('status', 'succeeded')->groupBy('feature')->orderByDesc('credits')->get();
        $byWorkspace = (clone $base)->selectRaw('workspace_id, SUM(credits) AS credits, SUM(cost_microusd) AS cost_microusd, COUNT(*) AS actions')
            ->where('status', 'succeeded')->groupBy('workspace_id')->orderByDesc('credits')->limit(100)->get();
        $byModel = (clone $base)->selectRaw('provider_source, provider, model, SUM(credits) AS credits, SUM(cost_microusd) AS cost_microusd, COUNT(*) AS actions')
            ->where('status', 'succeeded')->groupBy('provider_source', 'provider', 'model')->orderByDesc('cost_microusd')->get();
        $statuses = (clone $base)->selectRaw('status, provider_source, COUNT(*) AS actions, SUM(credits) AS credits')
            ->groupBy('status', 'provider_source')->get();
        $suspicious = (clone $base)->selectRaw('request_fingerprint, COUNT(DISTINCT workspace_id) AS workspaces, COUNT(*) AS actions')
            ->whereNotNull('request_fingerprint')->groupBy('request_fingerprint')
            ->havingRaw('COUNT(DISTINCT workspace_id) > 2')->orderByDesc('workspaces')->limit(100)->get();

        $periods = AiCreditPeriod::query()
            ->whereBetween('period_start', [$from->copy()->subMonth(), $to])
            ->orderByDesc('period_start')->limit(250)->get();
        $periodCosts = AiCreditLedger::query()->whereIn('period_id', $periods->pluck('id'))
            ->where('status', 'succeeded')->selectRaw('period_id, SUM(cost_microusd) AS cost_microusd')
            ->groupBy('period_id')->pluck('cost_microusd', 'period_id');
        $personalSubscriptions = Subscription::with('plan')->whereIn('id', $periods->where('subscription_type', 'subscription')->pluck('subscription_id'))->get()->keyBy('id');
        $clientSubscriptions = ClientSubscription::with('plan')->whereIn('id', $periods->where('subscription_type', 'client_subscription')->pluck('subscription_id'))->get()->keyBy('id');
        $byPlan = [];
        foreach ($periods as $period) {
            $subscription = $period->subscription_type === 'client_subscription'
                ? $clientSubscriptions->get($period->subscription_id)
                : $personalSubscriptions->get($period->subscription_id);
            $plan = $subscription?->plan;
            $planId = (int) ($plan?->id ?? 0);
            $monthlyRevenueMicrousd = (int) (($plan?->monthly_price_cents ?? $plan?->price_cents ?? 0) * 10_000);
            $costMicrousd = (int) ($periodCosts[$period->id] ?? 0);
            $byPlan[$planId] ??= [
                'plan_id' => $planId ?: null,
                'plan_name' => $plan?->name ?? 'Deleted or unknown plan',
                'accounts' => 0,
                'allowance' => 0,
                'used_credits' => 0,
                'estimated_revenue_microusd' => 0,
                'estimated_cost_microusd' => 0,
                'estimated_gross_margin_microusd' => 0,
            ];
            $byPlan[$planId]['accounts']++;
            $byPlan[$planId]['allowance'] += $period->allowance + $period->adjustment_credits;
            $byPlan[$planId]['used_credits'] += $period->used_credits;
            $byPlan[$planId]['estimated_revenue_microusd'] += $monthlyRevenueMicrousd;
            $byPlan[$planId]['estimated_cost_microusd'] += $costMicrousd;
            $byPlan[$planId]['estimated_gross_margin_microusd'] += $monthlyRevenueMicrousd - $costMicrousd;
        }

        $data = [
            'range' => ['from' => $from->toIso8601String(), 'to' => $to->toIso8601String()],
            'by_feature' => $byFeature,
            'by_workspace' => $byWorkspace,
            'by_model' => $byModel,
            'by_plan' => array_values($byPlan),
            'statuses' => $statuses,
            'suspicious_device_clusters' => $suspicious,
            'periods' => $periods->map->only([
                'id', 'account_type', 'account_id', 'subscription_type', 'subscription_id',
                'period_start', 'period_end', 'allowance', 'adjustment_credits',
                'used_credits', 'reserved_credits', 'status',
            ]),
        ];

        if ($request->expectsJson()) {
            return response()->json(['data' => $data]);
        }

        return Inertia::render('Admin/AiCredits/Index', [
            'report' => $data,
            'filters' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ],
        ]);
    }

    public function adjust(Request $request, AiCreditPeriod $period): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'credits' => ['required', 'integer', 'not_in:0', 'between:-1000000,1000000'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        DB::transaction(function () use ($period, $validated, $request) {
            $locked = AiCreditPeriod::lockForUpdate()->findOrFail($period->id);
            $newAdjustment = $locked->adjustment_credits + (int) $validated['credits'];
            if ($locked->allowance + $newAdjustment < $locked->used_credits + $locked->reserved_credits) {
                abort(422, 'This adjustment would reduce available credits below zero.');
            }
            $locked->update(['adjustment_credits' => $newAdjustment]);
            $workspaceId = AiCreditLedger::where('period_id', $locked->id)->where('workspace_id', '>', 0)->value('workspace_id');
            if (! $workspaceId) {
                $workspaceId = $locked->account_type === 'client'
                    ? Workspace::where('client_id', $locked->account_id)->value('id')
                    : Workspace::where('owner_id', $locked->account_id)->value('id');
            }
            abort_unless($workspaceId, 422, 'No workspace is available for this credit account.');

            AiCreditLedger::create([
                'period_id' => $locked->id,
                'workspace_id' => $workspaceId,
                'actor_id' => $request->user('admin')?->id,
                'feature' => 'admin_adjustment',
                'rate_version' => (int) config('ai_credits.rate_version', 1),
                'idempotency_key' => hash('sha256', 'admin-adjustment:'.Str::uuid()),
                'provider_source' => 'admin',
                'credits' => 0,
                'adjustment_delta' => (int) $validated['credits'],
                'adjustment_reason' => $validated['reason'],
                'status' => (int) $validated['credits'] > 0 ? 'granted' : 'revoked',
                'finalized_at' => now(),
            ]);
        }, 3);

        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'period' => $period->fresh()]);
        }

        return back()->with('success', 'AI credit balance adjusted.');
    }
}
