<?php

namespace App\Http\Middleware;

use App\Models\Client;
use App\Models\ClientSetting;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePlanSelected
{
    /** Routes required to select or finish activating a plan. */
    private const ALLOWED_ROUTES = [
        'client.pricing',
        'client.pricing.select-free',
        'client.checkout.store',
        'client.billing.index',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        $selectionRequired = $user?->client_id
            && ClientSetting::get((int) $user->client_id, 'plan_selection_required', '0') === '1';

        $clientPlan = $user?->client instanceof Client
            ? $user->client->effectivePlan()
            : null;
        $hasPlan = $user?->effectiveSubscription() !== null
            || $clientPlan !== null;

        if (! $user || ! $user->isClient() || ! $selectionRequired || $hasPlan) {
            return $next($request);
        }

        if ($request->routeIs(...self::ALLOWED_ROUTES)) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'code' => 'plan_required',
                'message' => 'Choose a plan before continuing.',
                'pricing_url' => route('client.pricing'),
            ], 402);
        }

        return redirect()->route('client.pricing')->with('plan_required', true);
    }
}
