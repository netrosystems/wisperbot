<?php

namespace App\Modules\Inbox\Services;

use App\Modules\Integrations\Models\IntegrationConfig;
use App\Modules\Shared\Models\ChannelAccount;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Carbon;

class EbayApiClient
{
    public const SCOPES = [
        'https://api.ebay.com/oauth/api_scope',
        'https://api.ebay.com/oauth/api_scope/commerce.message',
    ];

    public function __construct(private readonly ChannelAccount $account) {}

    public static function systemConfig(): IntegrationConfig
    {
        $config = IntegrationConfig::forProvider('oauth_ebay');

        if (! $config || ! $config->enabled || ! $config->isConfigured()) {
            throw new \RuntimeException('eBay is not configured or enabled by the Super Admin.');
        }

        return $config;
    }

    public static function environment(IntegrationConfig $config): string
    {
        $environment = strtolower(trim((string) (($config->credentials ?? [])['environment'] ?? 'sandbox')));

        return $environment === 'production' ? 'production' : 'sandbox';
    }

    public static function authBase(IntegrationConfig $config): string
    {
        return static::environment($config) === 'production'
            ? 'https://auth.ebay.com'
            : 'https://auth.sandbox.ebay.com';
    }

    public static function apiBase(IntegrationConfig $config): string
    {
        return static::environment($config) === 'production'
            ? 'https://api.ebay.com'
            : 'https://api.sandbox.ebay.com';
    }

    /** @return array<string, mixed> */
    public static function exchangeAuthorizationCode(IntegrationConfig $config, string $code): array
    {
        $credentials = $config->credentials ?? [];
        $response = Http::timeout(20)
            ->withBasicAuth((string) $credentials['client_id'], (string) $credentials['client_secret'])
            ->asForm()
            ->post(static::apiBase($config).'/identity/v1/oauth2/token', [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => (string) $credentials['ru_name'],
            ]);

        if (! $response->successful() || ! $response->json('access_token')) {
            throw new \RuntimeException(
                $response->json('error_description')
                ?? $response->json('error')
                ?? 'eBay authorization code exchange failed.'
            );
        }

        return $response->json();
    }

    /** @return array<string, mixed> */
    public function identity(): array
    {
        $response = $this->request()->get($this->apiBaseForAccount().'/commerce/identity/v1/user/');
        $this->ensureSuccessful($response, 'Unable to read the connected eBay seller identity.');

        return $response->json();
    }

    /** @return array<string, mixed> */
    public function conversations(int $limit = 10, int $offset = 0): array
    {
        $response = $this->request()->get($this->apiBaseForAccount().'/commerce/message/v1/conversation', [
            'conversation_type' => 'FROM_MEMBERS',
            'limit' => min(max($limit, 1), 10),
            'offset' => max($offset, 0),
        ]);
        $this->ensureSuccessful($response, 'Unable to load eBay conversations.');

        return $response->json();
    }

    /** @return array<string, mixed> */
    public function conversation(string $conversationId): array
    {
        $response = $this->request()->get(
            $this->apiBaseForAccount().'/commerce/message/v1/conversation/'.rawurlencode($conversationId),
            ['conversation_type' => 'FROM_MEMBERS']
        );
        $this->ensureSuccessful($response, 'Unable to load the eBay conversation.');

        return $response->json();
    }

    /** @return array<string, mixed> */
    public function sendMessage(string $conversationId, string $body): array
    {
        $response = $this->request()->post($this->apiBaseForAccount().'/commerce/message/v1/send_message', [
            'conversationId' => $conversationId,
            'messageText' => mb_substr($body, 0, 2000),
            'emailCopyToSender' => false,
        ]);
        $this->ensureSuccessful($response, 'eBay message send failed.');

        return $response->json() ?: [];
    }

    private function request(): PendingRequest
    {
        return Http::timeout(20)
            ->acceptJson()
            ->withToken($this->accessToken())
            ->withHeaders([
                'X-EBAY-C-MARKETPLACE-ID' => (string) (($this->account->meta_json ?? [])['marketplace_id'] ?? 'EBAY_GB'),
            ]);
    }

    private function apiBaseForAccount(): string
    {
        $environment = ($this->account->meta_json ?? [])['environment'] ?? 'sandbox';

        return $environment === 'production' ? 'https://api.ebay.com' : 'https://api.sandbox.ebay.com';
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
            throw new \RuntimeException('The eBay connection has expired. Reconnect the seller account.');
        }

        $config = static::systemConfig();
        $systemCredentials = $config->credentials ?? [];
        $response = Http::timeout(20)
            ->withBasicAuth((string) $systemCredentials['client_id'], (string) $systemCredentials['client_secret'])
            ->asForm()
            ->post(static::apiBase($config).'/identity/v1/oauth2/token', [
                'grant_type' => 'refresh_token',
                'refresh_token' => $refreshToken,
                'scope' => implode(' ', self::SCOPES),
            ]);

        if (! $response->successful() || ! $response->json('access_token')) {
            $this->account->update(['status' => 'error']);
            throw new \RuntimeException($response->json('error_description') ?? 'The eBay access token could not be refreshed.');
        }

        $credentials['access_token'] = $response->json('access_token');
        $credentials['expires_at'] = now()->addSeconds((int) $response->json('expires_in', 7200))->toIso8601String();
        if ($response->json('refresh_token')) {
            $credentials['refresh_token'] = $response->json('refresh_token');
        }
        $this->account->update(['credentials' => $credentials, 'status' => 'active']);

        return (string) $credentials['access_token'];
    }

    private function ensureSuccessful(\Illuminate\Http\Client\Response $response, string $fallback): void
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
