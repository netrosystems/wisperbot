<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Services\AddonEntitlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    public function __construct(private AddonEntitlementService $entitlements) {}

    public function search(Request $request): JsonResponse
    {
        $query = trim($request->get('q', ''));

        if (strlen($query) < 2) {
            return response()->json(['results' => []]);
        }

        $user = $request->user();
        $results = [];

        // Search navigation items
        $navItems = [
            ['label' => 'Dashboard', 'href' => route('client.dashboard'), 'icon' => 'LayoutDashboard'],
            ['label' => 'Subscription', 'href' => route('client.subscription.show'), 'icon' => 'CreditCard'],
            ['label' => 'Billing History', 'href' => route('client.billing.index'), 'icon' => 'CreditCard'],
            ['label' => 'Pricing / Plans', 'href' => route('client.pricing'), 'icon' => 'Package'],
            ['label' => 'Team', 'href' => route('client.team.index'), 'icon' => 'Users'],
            ['label' => 'Workspaces', 'href' => route('client.workspaces.index'), 'icon' => 'Layers'],
            ['label' => 'Settings', 'href' => route('client.settings.index'), 'icon' => 'Settings'],
            ['label' => 'Profile', 'href' => route('client.profile.edit'), 'icon' => 'User'],
            ['label' => 'Two-Factor Auth', 'href' => route('client.profile.2fa'), 'icon' => 'Shield'],
            ['label' => 'Sessions', 'href' => route('client.profile.sessions'), 'icon' => 'Monitor'],
            ['label' => 'Notifications', 'href' => route('client.notifications.index'), 'icon' => 'Bell'],
            ['label' => 'Media Library', 'href' => route('client.media.index'), 'icon' => 'Image'],
            ['label' => 'Add-ons', 'href' => route('client.addons.index'), 'icon' => 'Package'],
            ['label' => 'Audit Log', 'href' => route('client.audit-log.index'), 'icon' => 'FileText'],
        ];

        if ($this->entitlements->enabledFor($user, AddonEntitlementService::DEVELOPER_TOOLS)) {
            $navItems[] = ['label' => 'Webhooks', 'href' => route('client.webhooks.index'), 'icon' => 'Webhook'];
            $navItems[] = ['label' => 'API Tokens', 'href' => route('client.api-tokens.index'), 'icon' => 'Key'];
            $navItems[] = ['label' => 'API Documentation', 'href' => route('client.api-docs'), 'icon' => 'BookOpen'];
        }

        $lower = strtolower($query);
        foreach ($navItems as $item) {
            if (str_contains(strtolower($item['label']), $lower)) {
                $results[] = array_merge($item, ['type' => 'page']);
            }
        }

        return response()->json(['results' => array_slice($results, 0, 10)]);
    }
}
