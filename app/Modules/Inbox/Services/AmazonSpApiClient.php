<?php

namespace App\Modules\Inbox\Services;

use App\Modules\Integrations\Models\IntegrationConfig;
use App\Modules\Shared\Models\ChannelAccount;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

class AmazonSpApiClient
{
    public function __construct(private readonly ChannelAccount $account) {}

    public static function systemConfig(): IntegrationConfig
    {
        $config = IntegrationConfig::forProvider('oauth_amazon_spapi');

        if (! $config || ! $config->enabled || ! $config->isConfigured()) {
            throw new \RuntimeException('Amazon Seller Messaging is not configured or enabled by the Super Admin.');
        }

        return $config;
    }

    public static function environment(IntegrationConfig $config): string
    {
        return strtolower((string) (($config->credentials ?? [])['environment'] ?? 'sandbox')) === 'production'
            ? 'production'
            : 'sandbox';
    }

    public static function authorizationUrl(IntegrationConfig $config, string $state): string
    {
        $credentials = $config->credentials ?? [];
        $base = rtrim((string) $credentials['seller_central_url'], '/');
        $query = [
            'application_id' => (string) $credentials['application_id'],
            'state' => $state,
        ];

        if (static::environment($config) !== 'production') {
            $query['version'] = 'beta';
        }

        return $base.'/apps/authorize/consent?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /** @return array<string, mixed> */
    public static function exchangeAuthorizationCode(
        IntegrationConfig $config,
        string $code,
        string $redirectUri
    ): array {
        $credentials = $config->credentials ?? [];
        $response = Http::timeout(20)->asForm()->post('https://api.amazon.com/auth/o2/token', [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'client_id' => (string) $credentials['lwa_client_id'],
            'client_secret' => (string) $credentials['lwa_client_secret'],
        ]);

        if (! $response->successful() || ! $response->json('refresh_token')) {
            throw new \RuntimeException(
                $response->json('error_description')
                ?? $response->json('error')
                ?? 'Amazon could not exchange the authorization code.'
            );
        }

        return $response->json();
    }

    /** @return array<string, mixed> */
    public function marketplaceParticipations(): array
    {
        $response = $this->request()->get($this->apiBase().'/sellers/v1/marketplaceParticipations');
        $this->ensureSuccessful($response, 'Unable to load the Amazon seller marketplaces.');

        return $response->json('payload') ?? [];
    }

    /** @return array<string, mixed> */
    public function messagingActions(string $amazonOrderId, ?string $marketplaceId = null): array
    {
        $marketplace = $marketplaceId ?: (string) (($this->account->meta_json ?? [])['marketplace_id'] ?? '');
        if ($marketplace === '') {
            throw new \RuntimeException('An Amazon marketplace ID is required.');
        }

        $response = $this->request()->get(
            $this->apiBase().'/messaging/v1/orders/'.rawurlencode($amazonOrderId),
            ['marketplaceIds' => $marketplace]
        );
        $this->ensureSuccessful($response, 'Unable to load the Amazon messaging actions for this order.');

        return $response->json();
    }

    /**
     * Send one of the order-specific actions returned by messagingActions().
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function sendMessagingAction(string $actionPath, array $payload): array
    {
        if (! str_starts_with($actionPath, '/messaging/v1/orders/')) {
            throw new \InvalidArgumentException('Amazon returned an invalid messaging action path.');
        }

        $response = $this->request()->post($this->apiBase().$actionPath, $payload);
        $this->ensureSuccessful($response, 'Amazon rejected the order message.');

        return $response->json() ?: [];
    }

    private function request(): PendingRequest
    {
        return Http::timeout(20)
            ->acceptJson()
            ->withHeaders(['x-amz-access-token' => $this->accessToken()]);
    }

    private function apiBase(): string
    {
        $meta = $this->account->meta_json ?? [];
        $region = in_array(($meta['selling_region'] ?? 'eu'), ['na', 'eu', 'fe'], true)
            ? $meta['selling_region']
            : 'eu';
        $sandbox = ($meta['environment'] ?? 'sandbox') !== 'production' ? 'sandbox.' : '';

        return "https://{$sandbox}sellingpartnerapi-{$region}.amazon.com";
    }

    private function accessToken(): string
    {
        $credentials = $this->account->credentials ?? [];
        $expiresAt = isset($credentials['expires_at']) ? Carbon::parse($credentials['expires_at']) : null;

        if (! empty($credentials['access_token']) && (! $expiresAt || $expiresAt->isAfter(now()->addMinutes(5)))) {
            return (string) $credentials['access_token'];
        }

        $refreshToken = (string) ($credentials['refresh_token'] ?? '');
        if ($refreshToken === '') {
            throw new \RuntimeException('The Amazon seller authorization has expired. Reconnect the account.');
        }

        $systemCredentials = static::systemConfig()->credentials ?? [];
        $response = Http::timeout(20)->asForm()->post('https://api.amazon.com/auth/o2/token', [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id' => (string) $systemCredentials['lwa_client_id'],
            'client_secret' => (string) $systemCredentials['lwa_client_secret'],
        ]);

        if (! $response->successful() || ! $response->json('access_token')) {
            $this->account->update(['status' => 'error']);
            throw new \RuntimeException($response->json('error_description') ?? 'Amazon could not refresh the seller access token.');
        }

        $credentials['access_token'] = $response->json('access_token');
        $credentials['expires_at'] = now()->addSeconds((int) $response->json('expires_in', 3600))->toIso8601String();
        $this->account->update(['credentials' => $credentials, 'status' => 'active']);

        return (string) $credentials['access_token'];
    }

    private function ensureSuccessful(Response $response, string $fallback): void
    {
        if ($response->successful()) {
            return;
        }

        $message = $response->json('errors.0.message')
            ?? $response->json('error_description')
            ?? $response->json('message')
            ?? $fallback;

        throw new \RuntimeException($message.' (HTTP '.$response->status().')');
    }
}
