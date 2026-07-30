<?php

namespace App\Modules\Inbox\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inbox\Services\AmazonSpApiClient;
use App\Modules\Shared\Models\ChannelAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AmazonSetupController extends Controller
{
    public function connect(Request $request): RedirectResponse
    {
        try {
            $config = AmazonSpApiClient::systemConfig();
        } catch (\Throwable $e) {
            return redirect()->route('client.inbox.setup')->with('error', $e->getMessage());
        }

        $state = Str::random(64);
        $request->session()->put('amazon_spapi_oauth', [
            'state' => $state,
            'workspace_id' => $this->workspaceId($request),
            'created_at' => now()->timestamp,
        ]);

        return redirect()->away(AmazonSpApiClient::authorizationUrl($config, $state));
    }

    /**
     * OAuth Login URI registered in Solution Provider Portal. Amazon visits this
     * URI midway through the website authorization workflow.
     */
    public function login(Request $request): RedirectResponse
    {
        $pending = $request->session()->get('amazon_spapi_oauth');
        if (! $this->validPendingState($pending, $request)) {
            return redirect()->route('client.inbox.setup')->with('error', 'The Amazon connection session expired. Start the connection again.');
        }

        $amazonCallback = (string) $request->query('amazon_callback_uri', '');
        $amazonState = (string) $request->query('amazon_state', '');
        $sellerId = (string) $request->query('selling_partner_id', '');
        $host = strtolower((string) parse_url($amazonCallback, PHP_URL_HOST));

        if (! str_starts_with($amazonCallback, 'https://')
            || ! preg_match('/(^|\.)amazon\.[a-z.]+$/i', $host)
            || $amazonState === ''
            || $sellerId === '') {
            return redirect()->route('client.inbox.setup')->with('error', 'Amazon returned an invalid authorization handoff.');
        }

        $pending['selling_partner_id'] = $sellerId;
        $request->session()->put('amazon_spapi_oauth', $pending);
        $query = [
            'amazon_state' => $amazonState,
            'state' => $pending['state'],
            'redirect_uri' => route('client.inbox.setup.amazon.callback'),
        ];
        if ($request->query('version') === 'beta') {
            $query['version'] = 'beta';
        }

        return redirect()->away($amazonCallback.(str_contains($amazonCallback, '?') ? '&' : '?').http_build_query($query, '', '&', PHP_QUERY_RFC3986))
            ->withHeaders(['Referrer-Policy' => 'no-referrer']);
    }

    public function callback(Request $request): RedirectResponse
    {
        $pending = $request->session()->pull('amazon_spapi_oauth');
        if (! $this->validPendingState($pending, $request)) {
            return redirect()->route('client.inbox.setup')->with('error', 'The Amazon authorization expired or failed its security check.');
        }

        if ($request->filled('error')) {
            return redirect()->route('client.inbox.setup')->with(
                'error',
                (string) ($request->query('error_description') ?: 'Amazon authorization was cancelled.')
            );
        }

        $code = (string) $request->query('spapi_oauth_code', '');
        $sellerId = (string) ($request->query('selling_partner_id') ?: ($pending['selling_partner_id'] ?? ''));
        if ($code === '' || $sellerId === '') {
            return redirect()->route('client.inbox.setup')->with('error', 'Amazon did not return the seller authorization details.');
        }

        try {
            $config = AmazonSpApiClient::systemConfig();
            $tokens = AmazonSpApiClient::exchangeAuthorizationCode(
                $config,
                $code,
                route('client.inbox.setup.amazon.callback')
            );
            $workspaceId = $this->workspaceId($request);
            $existing = ChannelAccount::where('channel', 'amazon')
                ->whereJsonContains('meta_json->selling_partner_id', $sellerId)
                ->first();

            if ($existing && (int) $existing->workspace_id !== $workspaceId) {
                return redirect()->route('client.inbox.setup')->with(
                    'error',
                    'This Amazon seller is already connected to another workspace. Disconnect it there first.'
                );
            }

            $system = $config->credentials ?? [];
            $account = $existing ?: new ChannelAccount;
            $account->fill([
                'workspace_id' => $workspaceId,
                'channel' => 'amazon',
                'provider' => 'amazon_spapi',
                'display_name' => 'Amazon Seller '.$sellerId,
                'credentials' => [
                    'access_token' => $tokens['access_token'] ?? null,
                    'refresh_token' => $tokens['refresh_token'],
                    'expires_at' => now()->addSeconds((int) ($tokens['expires_in'] ?? 3600))->toIso8601String(),
                ],
                'status' => 'active',
                'meta_json' => array_merge($existing?->meta_json ?? [], [
                    'selling_partner_id' => $sellerId,
                    'environment' => AmazonSpApiClient::environment($config),
                    'selling_region' => strtolower((string) ($system['selling_region'] ?? 'eu')),
                    'marketplace_id' => (string) ($system['marketplace_id'] ?? ''),
                    'capability' => 'order_messaging',
                ]),
            ])->save();

            try {
                $participations = (new AmazonSpApiClient($account))->marketplaceParticipations();
                $first = $participations[0] ?? [];
                $name = $first['participation']['storeName'] ?? $first['marketplace']['name'] ?? null;
                if ($name) {
                    $account->update(['display_name' => mb_substr((string) $name, 0, 128)]);
                }
            } catch (\Throwable $e) {
                Log::info('Amazon seller connected before marketplace profile became available', [
                    'channel_account_id' => $account->id,
                    'error' => $e->getMessage(),
                ]);
            }

            return redirect()->route('client.inbox.setup')->with(
                'success',
                'Amazon seller connected. Order-specific buyer messaging becomes available after Amazon approves the required SP-API role.'
            );
        } catch (\Throwable $e) {
            Log::warning('Amazon SP-API OAuth callback failed', ['error' => $e->getMessage()]);

            return redirect()->route('client.inbox.setup')->with('error', $e->getMessage());
        }
    }

    public function actions(Request $request, ChannelAccount $channelAccount): RedirectResponse
    {
        abort_unless(
            $channelAccount->channel === 'amazon'
            && (int) $channelAccount->workspace_id === $this->workspaceId($request),
            404
        );

        $validated = $request->validate([
            'amazon_order_id' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9-]+$/'],
        ]);

        try {
            $result = (new AmazonSpApiClient($channelAccount))->messagingActions($validated['amazon_order_id']);
            $actions = collect($result['_links']['actions'] ?? [])
                ->map(fn (array $action) => basename((string) ($action['href'] ?? '')))
                ->filter()
                ->unique()
                ->values();

            if ($actions->isEmpty()) {
                return back()->with('warning', 'Amazon does not currently allow any seller message actions for this order.');
            }

            return back()->with(
                'success',
                'Amazon permits '.$actions->count().' message action(s) for this order: '.$actions->implode(', ').'.'
            );
        } catch (\Throwable $e) {
            Log::warning('Amazon order messaging capability check failed', [
                'channel_account_id' => $channelAccount->id,
                'error' => $e->getMessage(),
            ]);

            return back()->with('error', $e->getMessage());
        }
    }

    private function validPendingState(mixed $pending, Request $request): bool
    {
        return is_array($pending)
            && hash_equals((string) ($pending['state'] ?? ''), (string) $request->query('state', $pending['state'] ?? ''))
            && (int) ($pending['workspace_id'] ?? 0) === $this->workspaceId($request)
            && (int) ($pending['created_at'] ?? 0) >= now()->subMinutes(10)->timestamp;
    }

    private function workspaceId(Request $request): int
    {
        return (int) ($request->user()->current_workspace_id ?? $request->user()->workspace_id);
    }
}
