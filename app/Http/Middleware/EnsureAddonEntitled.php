<?php

namespace App\Http\Middleware;

use App\Services\AddonEntitlementService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAddonEntitled
{
    public function __construct(private AddonEntitlementService $entitlements) {}

    public function handle(Request $request, Closure $next, string $addonKey): Response
    {
        if ($this->entitlements->enabledFor($request->user(), $addonKey)) {
            return $next($request);
        }

        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json([
                'message' => 'The Developer Tools add-on is required to use this endpoint.',
                'addon' => $addonKey,
            ], 403);
        }

        return redirect()->route('client.addons.index')
            ->with('error', __('The Developer Tools add-on is required to access that feature.'));
    }
}
