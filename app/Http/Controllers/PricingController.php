<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ClientSetting;
use App\Models\Currency;
use App\Models\Plan;
use App\Services\Billing\BillingGatewayRegistry;
use App\Services\CurrencyService;
use App\Services\PlanSelectionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PricingController extends Controller
{
    public function __construct(
        private CurrencyService $currency,
        private BillingGatewayRegistry $gateways,
        private PlanSelectionService $planSelection,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $subscription = $user?->effectiveSubscription();
        $effectivePlan = $subscription?->plan;
        if ($effectivePlan === null && $user?->client instanceof Client) {
            $effectivePlan = $user->client->effectivePlan();
        }
        $requiresPlan = $user?->client_id
            && ClientSetting::get((int) $user->client_id, 'plan_selection_required', '0') === '1'
            && $effectivePlan === null;
        $displayCurrency = $user?->display_currency
            ?? $user?->workspace?->currency_code
            ?? $request->session()->get('display_currency')
            ?? Currency::defaultCode();

        $plans = Plan::where('enabled', true)
            ->orderBy('sort_order')
            ->get()
            ->map(function (Plan $plan) use ($displayCurrency) {
                $monthlyCents = $plan->priceCentsForCycle('month');
                $yearlyCents = $plan->priceCentsForCycle('year');

                return [
                    'id' => $plan->id,
                    'name' => $plan->name,
                    'slug' => $plan->slug,
                    'description' => $plan->description,
                    'monthly_price_display' => $monthlyCents !== null
                        ? $this->currency->formatConverted($monthlyCents, $plan->currency_code, $displayCurrency)
                        : null,
                    'yearly_price_display' => $yearlyCents !== null
                        ? $this->currency->formatConverted($yearlyCents, $plan->currency_code, $displayCurrency)
                        : null,
                    'monthly_price_cents' => $monthlyCents,
                    'yearly_price_cents' => $yearlyCents,
                    'features' => is_array($plan->features) ? $plan->features : [],
                    'limits' => is_array($plan->limits) ? $plan->limits : [],
                    'white_label_enabled' => (bool) $plan->white_label_enabled,
                    'popular' => (bool) $plan->popular,
                    'featured' => (bool) $plan->featured,
                    'is_free' => $plan->isFree(),
                    'trial_days' => $plan->trial_days,
                ];
            });

        return Inertia::render('client/Pricing', [
            'plans' => $plans,
            'gateways' => $this->gateways->listForFrontend(),
            'is_authenticated' => (bool) $user,
            'register_url' => route('register'),
            'checkout_url' => route('client.checkout.store'),
            'select_free_url' => route('client.pricing.select-free'),
            'requires_plan' => (bool) $requiresPlan,
            'current_plan_id' => $effectivePlan?->id,
            'selected_plan_id' => (int) ($request->session()->get('plan_id') ?? $request->query('plan_id', 0)),
            'selected_cycle' => $this->normalizeCycle((string) ($request->session()->get('cycle') ?? $request->query('cycle', 'month'))),
            'flash' => [
                'error' => $request->session()->get('error'),
                'success' => $request->session()->get('success'),
                'plan_required' => (bool) $request->session()->get('plan_required'),
            ],
        ]);
    }

    public function selectFree(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->isClientAdministrator(), 403);

        $validated = $request->validate([
            'plan_id' => [
                'required',
                'integer',
                Rule::exists('plans', 'id')->where(fn ($query) => $query->where('enabled', true)),
            ],
        ]);

        $plan = Plan::where('enabled', true)->findOrFail($validated['plan_id']);

        if (! $plan->isFree()) {
            return back()->with('error', __('Paid plans must be activated through secure checkout.'));
        }

        try {
            $this->planSelection->activateFreePlan($request->user(), $plan);
        } catch (\DomainException $exception) {
            return back()->with('error', __($exception->getMessage()));
        }

        return redirect()->route('client.dashboard')->with('success', __('Your Free plan is active.'));
    }

    private function normalizeCycle(string $cycle): string
    {
        return in_array($cycle, ['year', 'yearly'], true) ? 'year' : 'month';
    }
}
