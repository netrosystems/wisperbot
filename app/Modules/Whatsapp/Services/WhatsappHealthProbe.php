<?php

namespace App\Modules\Whatsapp\Services;

use App\Modules\Integrations\Services\CredentialResolver;
use App\Modules\Whatsapp\Models\WhatsappBusinessAccount;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/** Read provider state; repairs only restore this WABA's app subscription. */
class WhatsappHealthProbe
{
    /** @return array{state: string, code: string, message: string, action: string} */
    public function component(string $state, string $code, string $message, string $action = 'none'): array
    {
        return compact('state', 'code', 'message', 'action');
    }

    public function operator(WhatsappBusinessAccount $waba): bool
    {
        return filled(config('channel_health.operator_business_id'))
            && in_array((string) $waba->waba_id, config('channel_health.operator_waba_ids', []), true);
    }

    public function revision(WhatsappBusinessAccount $waba): string
    {
        $meta = CredentialResolver::system()->meta();

        return hash_hmac('sha256', json_encode([
            $waba->credentials, $meta?->appId(), $meta?->appSecret(),
            $this->operator($waba) ? $meta?->systemUserToken() : null,
            config('channel_health.operator_business_id'), $this->operator($waba),
            $waba->phoneNumbers()->orderBy('id')->pluck('phone_number_id')->all(),
        ], JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    /**
     * No raw provider errors, tokens or URLs containing credentials escape this method.
     *
     * @param  array<string, mixed>  $params
     * @return array{ok: bool, data?: array<string, mixed>, reason?: string, retry_after?: int}
     */
    public function request(string $token, string $path, array $params = [], bool $post = false): array
    {
        $retryKey = 'wa-health:retry:'.hash_hmac('sha256', $token.':'.$path, (string) config('app.key'));
        $retryAt = (int) Cache::get($retryKey, 0);
        if ($retryAt > time()) {
            return ['ok' => false, 'reason' => 'temporary', 'retry_after' => $retryAt - time()];
        }
        try {
            $http = Http::connectTimeout(3)->timeout(10)->withoutRedirecting();
            $http = str_contains($token, '|') ? $http->withQueryParameters(['access_token' => $token]) : $http->withToken($token);
            $url = 'https://graph.facebook.com/v25.0/'.$path;
            $response = $post ? $http->post($url, $params) : $http->get($url, $params);
            if ($response->successful() && is_array($response->json())) {
                return ['ok' => true, 'data' => $response->json()];
            }
            $code = (int) $response->json('error.code', 0);
            if ($response->status() === 429 || $response->serverError() || in_array($code, [1, 2, 4, 17, 32, 613], true)) {
                $retry = $response->header('Retry-After');
                $seconds = is_numeric($retry) ? (int) $retry : max(0, (int) strtotime($retry) - time());

                $seconds = min(86400, max(60, $seconds));
                Cache::put($retryKey, time() + $seconds, $seconds);

                return ['ok' => false, 'reason' => 'temporary', 'retry_after' => $seconds];
            }

            return ['ok' => false, 'reason' => $code === 190 ? 'authorization_expired' : (in_array($code, [10, 200], true) ? 'access_denied' : 'unavailable')];
        } catch (ConnectionException) {
            return ['ok' => false, 'reason' => 'temporary', 'retry_after' => 60];
        }
    }

    /** @return array{components: array<string, array<string, string>>, repaired: bool, retry_after: int, metadata?: array<int, array<string, string>>} */
    public function inspect(WhatsappBusinessAccount $waba, bool $repair = false): array
    {
        $meta = CredentialResolver::system()->meta();
        $operator = $this->operator($waba);
        $token = $operator ? $meta?->systemUserToken() : $waba->accessToken();
        $components = [];
        $retryAfter = 0;
        if (! $meta?->appId() || ! $meta->appSecret() || ! $token) {
            $components['authorization'] = $this->component('failed', 'credentials_missing', 'WhatsApp authorization is missing.', $operator || ! $meta?->appId() ? 'contact_admin' : 'reconnect');

            return ['components' => $components, 'repaired' => false, 'retry_after' => 0];
        }
        $debug = $this->request($meta->appId().'|'.$meta->appSecret(), 'debug_token', ['input_token' => $token]);
        if ($debug['ok']) {
            $info = $debug['data']['data'] ?? [];
            $valid = ($info['is_valid'] ?? false) && (string) ($info['app_id'] ?? '') === $meta->appId();
            $scopes = array_diff(['whatsapp_business_management', 'whatsapp_business_messaging'], $info['scopes'] ?? []);
            if (! $valid || $scopes !== []) {
                $components['authorization'] = $this->component('failed', 'authorization_incomplete', 'Meta authorization is expired or missing required WhatsApp permissions.', $operator ? 'contact_admin' : 'reconnect');

                return ['components' => $components, 'repaired' => false, 'retry_after' => 0];
            }
            $components['permissions'] = $this->component('passed', 'permissions_verified', 'Required WhatsApp permissions verified.');
        } else {
            $components['platform:token_inspection'] = $this->failure($debug, true);
            $retryAfter = $debug['retry_after'] ?? 0;
        }
        $access = $this->request($token, $waba->waba_id, ['fields' => $operator ? 'id,owner_business_info' : 'id']);
        if (! $access['ok']) {
            $components['authorization'] = $access['reason'] === 'unavailable'
                ? $this->component('failed', 'account_access_unverified', 'Access to this WhatsApp account could not be verified.', $operator ? 'contact_admin' : 'reconnect')
                : $this->failure($access, $operator);

            return ['components' => $components, 'repaired' => false, 'retry_after' => $access['retry_after'] ?? 0];
        }
        if ((string) ($access['data']['id'] ?? '') !== (string) $waba->waba_id
            || ($operator && (string) data_get($access, 'data.owner_business_info.id') !== (string) config('channel_health.operator_business_id'))) {
            $components['authorization'] = $this->component('failed', 'ownership_unverified', 'An administrator must verify WhatsApp account ownership.', 'contact_admin');

            return ['components' => $components, 'repaired' => false, 'retry_after' => 0];
        }
        $components['authorization'] = $this->component('passed', 'access_verified', 'WhatsApp account access verified.');
        $subscription = $this->request($token, $waba->waba_id.'/subscribed_apps');
        if ($subscription['ok'] && ! is_array($subscription['data']['data'] ?? null)) {
            $subscription = ['ok' => false, 'reason' => 'unavailable'];
        }
        $repaired = false;
        if ($subscription['ok'] && ! $this->hasApp($subscription['data'], $meta->appId()) && $repair) {
            $write = $this->request($token, $waba->waba_id.'/subscribed_apps', [], true);
            $subscription = $write['ok'] ? $this->request($token, $waba->waba_id.'/subscribed_apps') : $write;
            $repaired = $subscription['ok'] && $this->hasApp($subscription['data'], $meta->appId());
        }
        $components['subscription'] = $subscription['ok']
            ? ($this->hasApp($subscription['data'], $meta->appId())
                ? $this->component('passed', 'subscription_verified', 'WisperBot is subscribed to this WhatsApp account.')
                : $this->component('failed', 'subscription_missing', 'WhatsApp message delivery needs repair.', 'repair'))
            : $this->failure($subscription, $operator);
        $retryAfter = max($retryAfter, $subscription['retry_after'] ?? 0);
        if ($subscription['ok'] && $this->hasApp($subscription['data'], $meta->appId())) {
            $app = collect($this->rows($subscription['data']['data'] ?? []))->first(fn ($item) => (string) data_get($item, 'whatsapp_business_api_data.id', $item['id'] ?? '') === $meta->appId());
            $override = data_get($app, 'override_callback_uri');
            if ($override && $override !== route('webhooks.whatsapp.global.receive')) {
                $components['subscription'] = $this->component('failed', 'callback_override', 'An administrator must review the WhatsApp callback override.', 'contact_admin');
            }
        }
        // WABA edge verifies membership; never attach newly discovered phones in a repair.
        $phones = $this->request($token, $waba->waba_id.'/phone_numbers', ['fields' => 'id,status,quality_rating,verified_name,display_phone_number', 'limit' => 100]);
        if (! $phones['ok'] && $phones['reason'] === 'unavailable') {
            $phones = $this->request($token, $waba->waba_id.'/phone_numbers', ['fields' => 'id', 'limit' => 100]);
        }
        if ($phones['ok'] && ! is_array($phones['data']['data'] ?? null)) {
            $phones = ['ok' => false, 'reason' => 'unavailable'];
        }
        $known = $waba->phoneNumbers()->get();
        if ($known->isEmpty()) {
            $components['phones'] = $this->component('failed', 'phone_missing', 'No phone number is connected. Reconnect WhatsApp.', 'reconnect');
        }
        foreach ($known as $phone) {
            $key = 'phone:'.$phone->phone_number_id;
            if (! $phones['ok']) {
                $components[$key] = $this->failure($phones, $operator);
                $retryAfter = max($retryAfter, $phones['retry_after'] ?? 0);

                continue;
            }
            if (! collect($this->rows($phones['data']['data'] ?? []))->contains(fn ($p) => (string) ($p['id'] ?? '') === (string) $phone->phone_number_id)) {
                $components[$key] = isset($phones['data']['paging']['next'])
                    ? $this->component('unknown', 'phone_membership_unknown', 'Phone membership could not be fully checked.')
                    : $this->component('failed', 'phone_not_in_waba', 'This phone is no longer available in the connected WhatsApp account.', 'reconnect');

                continue;
            }
            $detail = ['ok' => true, 'data' => collect($this->rows($phones['data']['data'] ?? []))->first(fn ($p) => (string) ($p['id'] ?? '') === (string) $phone->phone_number_id)];
            $status = strtoupper((string) ($detail['data']['status'] ?? ''));
            $components[$key] = match ($status) {
                'CONNECTED' => $this->component('passed', 'phone_verified', 'Phone connection checks passed.'),
                'DISCONNECTED', 'BANNED', 'RESTRICTED', 'DELETED' => $this->component('failed', 'phone_unavailable', 'Meta reports that this phone needs attention.', 'reconnect'),
                default => $this->component('unknown', 'phone_status_unknown', 'Phone access verified; detailed status is unavailable.'),
            };
            // Metadata is applied under the completion lock after revision validation.
            if ($repair) {
                $metadata[$phone->id] = array_filter([
                    'verified_name' => $detail['data']['verified_name'] ?? null,
                    'display_phone' => $detail['data']['display_phone_number'] ?? null,
                    'quality_rating' => $detail['data']['quality_rating'] ?? null,
                ], fn ($v) => is_string($v));
            }
        }

        return ['components' => $components, 'repaired' => $repaired, 'retry_after' => $retryAfter, 'metadata' => $metadata ?? []];
    }

    /** @param array<string, mixed> $data */
    private function hasApp(array $data, string $appId): bool
    {
        return collect($this->rows($data['data'] ?? []))->contains(fn ($a) => (string) data_get($a, 'whatsapp_business_api_data.id', $a['id'] ?? '') === $appId);
    }

    /**
     * @param  array{ok: bool, data?: array<string, mixed>, reason?: string, retry_after?: int}  $result
     * @return array<string, string>
     */
    private function failure(array $result, bool $operator): array
    {
        return match ($result['reason']) {
            'temporary' => $this->component('delayed', 'provider_temporary', 'Meta could not be reached. WisperBot will check again.'),
            'authorization_expired' => $this->component('failed', 'authorization_expired', 'Meta authorization has expired or been removed.', $operator ? 'contact_admin' : 'reconnect'),
            'access_denied' => $this->component('failed', 'access_denied', 'WhatsApp account access needs attention.', $operator ? 'contact_admin' : 'reconnect'),
            default => $this->component('unknown', 'diagnostic_unavailable', 'Meta could not confirm this connection check.', $operator ? 'contact_admin' : 'none'),
        };
    }

    /** @return array<string, array<string, string>> */
    public function platform(): array
    {
        $meta = CredentialResolver::system()->meta();
        $key = 'wa-health:platform:'.hash('sha256', ($meta?->appId() ?? '').($meta?->appSecret() ?? ''));
        if ($cached = Cache::get($key)) {
            return $cached;
        }
        $lock = Cache::lock($key.':lock', 20);
        if (! $lock->get()) {
            return ['webhook' => $this->component('delayed', 'platform_check_running', 'Shared WhatsApp checks are running.')];
        }
        try {
            return Cache::remember($key, 300, function () use ($meta) {
                if (! $meta?->appId() || ! $meta->appSecret()) {
                    return ['webhook' => $this->component('failed', 'platform_credentials_missing', 'WhatsApp service configuration needs administrator attention.', 'contact_admin')];
                }
                $res = $this->request($meta->appId().'|'.$meta->appSecret(), $meta->appId().'/subscriptions');
                if (! $res['ok']) {
                    return ['webhook' => $this->failure($res, true)];
                }
                $sub = collect($this->rows($res['data']['data'] ?? []))->firstWhere('object', 'whatsapp_business_account');
                $required = ['messages', 'message_template_status_update', 'phone_number_name_update', 'phone_number_quality_update', 'account_update'];
                $fields = [];
                foreach (is_array($sub['fields'] ?? null) ? $sub['fields'] : [] as $field) {
                    $fields[] = is_array($field) ? ($field['name'] ?? '') : $field;
                }
                $valid = $sub && ($sub['active'] ?? true) && ($sub['callback_url'] ?? '') === route('webhooks.whatsapp.global.receive') && array_diff($required, $fields) === [];

                return [
                    'webhook' => $valid ? $this->component('passed', 'webhook_verified', 'Shared webhook configuration checks passed.') : $this->component('failed', 'platform_webhook_invalid', 'WhatsApp webhook configuration needs administrator attention.', 'contact_admin'),
                    'coexistence' => array_diff(['history', 'smb_app_state_sync', 'smb_message_echoes'], $fields) === []
                        ? $this->component('passed', 'coexistence_verified', 'WhatsApp Business app event subscriptions verified.')
                        : $this->component('failed', 'coexistence_fields_missing', 'WhatsApp Business app event subscriptions need administrator attention.', 'contact_admin'),
                ];
            });
        } finally {
            $lock->release();
        }
    }

    /** @return list<array<array-key, mixed>> */
    private function rows(mixed $data): array
    {
        return array_values(array_filter(is_array($data) ? $data : [], is_array(...)));
    }
}
