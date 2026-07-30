<?php

namespace App\Modules\Inbox\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inbox\Services\EbayApiClient;
use App\Modules\Inbox\Services\EbayConversationSyncService;
use App\Modules\Shared\Models\ChannelAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class EbaySetupController extends Controller
{
    public function connect(Request $request): RedirectResponse
    {
        try {
            $config = EbayApiClient::systemConfig();
        } catch (\Throwable $e) {
            return redirect()->route('client.inbox.setup')->with('error', $e->getMessage());
        }

        $workspaceId = $this->workspaceId($request);
        $state = Str::random(64);
        $request->session()->put('ebay_oauth', [
            'state' => $state,
            'workspace_id' => $workspaceId,
            'created_at' => now()->timestamp,
        ]);

        $credentials = $config->credentials ?? [];
        $query = http_build_query([
            'client_id' => $credentials['client_id'],
            'redirect_uri' => $credentials['ru_name'],
            'response_type' => 'code',
            'scope' => implode(' ', EbayApiClient::SCOPES),
            'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);

        return redirect()->away(EbayApiClient::authBase($config).'/oauth2/authorize?'.$query);
    }

    public function callback(Request $request, EbayConversationSyncService $sync): RedirectResponse
    {
        $pending = $request->session()->pull('ebay_oauth');
        if (! is_array($pending)
            || ! hash_equals((string) ($pending['state'] ?? ''), (string) $request->query('state', ''))
            || (int) ($pending['workspace_id'] ?? 0) !== $this->workspaceId($request)
            || (int) ($pending['created_at'] ?? 0) < now()->subMinutes(15)->timestamp) {
            return redirect()->route('client.inbox.setup')->with('error', 'The eBay connection expired or failed the security check. Please try again.');
        }

        if ($request->filled('error')) {
            return redirect()->route('client.inbox.setup')->with(
                'error',
                (string) ($request->query('error_description') ?: 'eBay authorization was cancelled.')
            );
        }

        $code = (string) $request->query('code', '');
        if ($code === '') {
            return redirect()->route('client.inbox.setup')->with('error', 'eBay did not return an authorization code.');
        }

        try {
            $config = EbayApiClient::systemConfig();
            $tokens = EbayApiClient::exchangeAuthorizationCode($config, $code);
            $temporary = new ChannelAccount([
                'channel' => 'ebay',
                'credentials' => [
                    'access_token' => $tokens['access_token'],
                    'refresh_token' => $tokens['refresh_token'] ?? null,
                    'expires_at' => now()->addSeconds((int) ($tokens['expires_in'] ?? 7200))->toIso8601String(),
                ],
                'meta_json' => ['environment' => EbayApiClient::environment($config)],
            ]);
            $identity = (new EbayApiClient($temporary))->identity();
            $sellerId = (string) ($identity['userId'] ?? $identity['username'] ?? $identity['userName'] ?? '');
            $sellerUsername = (string) ($identity['username'] ?? $identity['userName'] ?? $sellerId);

            if ($sellerId === '') {
                throw new \RuntimeException('eBay authorized the account but did not return a seller identity.');
            }

            $workspaceId = $this->workspaceId($request);
            $existing = ChannelAccount::where('channel', 'ebay')
                ->whereJsonContains('meta_json->seller_user_id', $sellerId)
                ->first();

            if ($existing && (int) $existing->workspace_id !== $workspaceId) {
                return redirect()->route('client.inbox.setup')->with(
                    'error',
                    'This eBay seller account is already connected to another workspace. Disconnect it there before reconnecting.'
                );
            }

            $systemCredentials = $config->credentials ?? [];
            $account = $existing ?: new ChannelAccount;
            $account->fill([
                'workspace_id' => $workspaceId,
                'channel' => 'ebay',
                'provider' => 'ebay',
                'display_name' => mb_substr((string) ($identity['businessAccount']['name'] ?? $sellerUsername), 0, 128),
                'credentials' => [
                    'access_token' => $tokens['access_token'],
                    'refresh_token' => $tokens['refresh_token'] ?? null,
                    'expires_at' => now()->addSeconds((int) ($tokens['expires_in'] ?? 7200))->toIso8601String(),
                    'refresh_token_expires_at' => isset($tokens['refresh_token_expires_in'])
                        ? now()->addSeconds((int) $tokens['refresh_token_expires_in'])->toIso8601String()
                        : null,
                ],
                'status' => 'active',
                'meta_json' => array_merge($existing?->meta_json ?? [], [
                    'seller_user_id' => $sellerId,
                    'seller_username' => $sellerUsername,
                    'environment' => EbayApiClient::environment($config),
                    'marketplace_id' => $systemCredentials['marketplace_id'] ?? 'EBAY_GB',
                    'scope' => $tokens['scope'] ?? implode(' ', EbayApiClient::SCOPES),
                ]),
            ])->save();

            try {
                $count = $sync->sync($account);
            } catch (\Throwable $e) {
                Log::warning('Initial eBay conversation sync failed', [
                    'channel_account_id' => $account->id,
                    'error' => $e->getMessage(),
                ]);

                return redirect()->route('client.inbox.setup')->with(
                    'warning',
                    'eBay account connected, but the first message sync could not finish: '.$e->getMessage()
                );
            }

            return redirect()->route('client.inbox.setup')->with(
                'success',
                "eBay seller account connected. {$count} new message(s) imported."
            );
        } catch (\Throwable $e) {
            Log::warning('eBay OAuth callback failed', ['error' => $e->getMessage()]);

            return redirect()->route('client.inbox.setup')->with('error', $e->getMessage());
        }
    }

    public function sync(Request $request, ChannelAccount $channelAccount, EbayConversationSyncService $sync): RedirectResponse
    {
        abort_unless(
            $channelAccount->channel === 'ebay'
            && (int) $channelAccount->workspace_id === $this->workspaceId($request),
            404
        );

        try {
            $count = $sync->sync($channelAccount);

            return back()->with('success', "eBay sync finished. {$count} new message(s) imported.");
        } catch (\Throwable $e) {
            Log::warning('Manual eBay sync failed', [
                'channel_account_id' => $channelAccount->id,
                'error' => $e->getMessage(),
            ]);

            return back()->with('error', $e->getMessage());
        }
    }

    private function workspaceId(Request $request): int
    {
        return (int) ($request->user()->current_workspace_id ?? $request->user()->workspace_id);
    }
}
