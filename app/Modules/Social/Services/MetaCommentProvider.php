<?php

namespace App\Modules\Social\Services;

use App\Modules\Integrations\Services\CredentialResolver;
use App\Modules\Social\Exceptions\CommentProviderException;
use App\Modules\Social\Models\SocialAccount;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/** Only customer account tokens and fixed Graph origins may reach comment operations. */
class MetaCommentProvider
{
    public function fingerprint(SocialAccount $account): string
    {
        return hash_hmac('sha256', (string) $account->access_token, (string) config('app.key'));
    }

    public function request(SocialAccount $account, string $method, string $path, array $data = []): array
    {
        if (! SocialCommentCapabilities::implemented($account->network)) {
            throw new CommentProviderException('unsupported_platform');
        }
        if (! $account->active || $account->isTokenExpired() || ! $account->access_token) {
            throw new CommentProviderException('reconnect_required');
        }
        if (! preg_match('~^[a-zA-Z0-9_/-]+$~D', $path)) {
            throw new CommentProviderException('invalid_provider_identifier');
        }
        try {
            $response = Http::withToken($account->access_token)->acceptJson()->connectTimeout(5)->timeout(20)
                ->withoutRedirecting()->send($method, 'https://graph.facebook.com/'.config('social_comments.graph_version').'/'.$path,
                    [$method === 'GET' ? 'query' : 'form_params' => $data]);
        } catch (ConnectionException) {
            throw new CommentProviderException($method === 'GET' ? 'temporarily_unavailable' : 'delivery_unknown');
        }
        if ($response->successful() && is_array($response->json())) {
            return $response->json();
        }
        $code = (int) $response->json('error.code', 0);
        $reason = match (true) {
            $code === 190 => 'reconnect_required',
            in_array($code, [10, 200, 294], true) => 'permission_required',
            $response->status() === 429, in_array($code, [4, 17, 32, 613], true) => 'rate_limited',
            $response->serverError() => $method === 'GET' ? 'temporarily_unavailable' : 'delivery_unknown',
            default => 'provider_rejected',
        };
        throw new CommentProviderException($reason, max(30, min(3600, (int) ($response->header('Retry-After') ?: '60'))));
    }

    public function verifyAndSubscribe(SocialAccount $account): array
    {
        if (! SocialCommentCapabilities::implemented($account->network)) {
            throw new CommentProviderException('unsupported_platform');
        }
        $credentials = CredentialResolver::system()->meta();
        $appId = (string) ($credentials?->appId() ?? '');
        $secret = (string) ($credentials?->appSecret() ?? '');
        if ($appId === '' || $secret === '') {
            throw new CommentProviderException('platform_configuration_required');
        }
        // App token is used exclusively for supported diagnostic app-level operations.
        // Every asset request below continues to use the customer's own token.
        try {
            $client = Http::withToken($appId.'|'.$secret)->acceptJson()->connectTimeout(5)->timeout(15)->withoutRedirecting();
            $debug = $client->get('https://graph.facebook.com/'.config('social_comments.graph_version').'/debug_token', ['input_token' => $account->access_token]);
            $subscriptions = $client->get('https://graph.facebook.com/'.config('social_comments.graph_version').'/'.$appId.'/subscriptions');
        } catch (ConnectionException) {
            throw new CommentProviderException('temporarily_unavailable');
        }
        if (! $debug->successful() || ! $subscriptions->successful()) {
            throw new CommentProviderException('platform_configuration_required');
        }
        $token = $debug->json('data', []);
        if (! ($token['is_valid'] ?? false) || (string) ($token['app_id'] ?? '') !== $appId) {
            throw new CommentProviderException('reconnect_required');
        }
        $required = $account->network === 'instagram'
            ? ['instagram_basic', 'instagram_manage_comments', 'pages_read_engagement', 'pages_manage_metadata']
            : ['pages_read_engagement', 'pages_read_user_content', 'pages_manage_engagement', 'pages_manage_metadata'];
        if (array_diff($required, $token['scopes'] ?? []) !== []) {
            throw new CommentProviderException('permission_required');
        }
        $object = $account->network === 'instagram' ? 'instagram' : 'page';
        $field = $account->network === 'instagram' ? 'comments' : 'feed';
        $appSubscription = collect($subscriptions->json('data', []))->first(fn ($item) => ($item['object'] ?? '') === $object);
        $subscribedFields = array_map(fn ($item) => is_array($item) ? ($item['name'] ?? '') : $item, $appSubscription['fields'] ?? []);
        if (! ($appSubscription['active'] ?? false) || ! in_array($field, $subscribedFields, true)) {
            throw new CommentProviderException('platform_configuration_required');
        }
        // Reading the asset and comment edge proves actual access, not merely requested OAuth scopes.
        $this->request($account, 'GET', $account->account_id, ['fields' => 'id']);
        $edge = $account->network === 'instagram' ? 'media' : 'posts';
        $this->request($account, 'GET', $account->account_id.'/'.$edge, ['fields' => 'id,comments.limit(1){id}', 'limit' => 1]);
        $subscriptionId = $account->network === 'instagram'
            ? ($account->meta['page_id'] ?? null) : $account->account_id;
        if (! $subscriptionId) {
            throw new CommentProviderException('reconnect_required');
        }
        $current = $this->request($account, 'GET', $subscriptionId.'/subscribed_apps', ['fields' => 'id,subscribed_fields']);
        $own = collect($current['data'] ?? [])->first(fn ($item) => (string) ($item['id'] ?? '') === $appId);
        if (! $own && isset($current['paging']['next'])) {
            throw new CommentProviderException('subscription_unverified');
        }
        // Never replace another feature's subscription fields.
        $fields = array_values(array_unique(array_merge($own['subscribed_fields'] ?? [], ['feed'])));
        $this->request($account, 'POST', $subscriptionId.'/subscribed_apps', ['subscribed_fields' => implode(',', $fields)]);
        $verified = $this->request($account, 'GET', $subscriptionId.'/subscribed_apps', ['fields' => 'id,subscribed_fields']);
        $own = collect($verified['data'] ?? [])->first(fn ($item) => (string) ($item['id'] ?? '') === $appId);
        if (! $own || ! in_array('feed', $own['subscribed_fields'] ?? [], true)) {
            throw new CommentProviderException('subscription_unverified');
        }

        // App-level Instagram `comments` webhook selection is an operator setup requirement.
        return ['read' => true, 'reply' => true, 'hide' => true, 'delete' => true, 'ads_discovery' => false];
    }

    public function comments(SocialAccount $account, string $id, ?string $after = null, bool $replies = false): array
    {
        $ig = $account->network === 'instagram';

        return $this->request($account, 'GET', $id.'/'.($ig && $replies ? 'replies' : 'comments'), array_filter([
            'fields' => $ig ? 'id,text,from,timestamp' : 'id,message,from,created_time,parent,is_hidden',
            'limit' => 50, 'after' => $after,
        ], fn ($value) => $value !== null));
    }

    public function reply(SocialAccount $account, string $id, string $body): string
    {
        $data = $this->request($account, 'POST', $id.'/'.($account->network === 'instagram' ? 'replies' : 'comments'), ['message' => $body]);
        if (empty($data['id'])) {
            throw new CommentProviderException('delivery_unknown');
        }

        return (string) $data['id'];
    }

    public function moderate(SocialAccount $account, string $id, string $action): void
    {
        if ($action === 'delete') {
            $this->request($account, 'DELETE', $id);
        } else {
            $this->request($account, 'POST', $id, [$account->network === 'instagram' ? 'hide' : 'is_hidden' => $action === 'hide' ? 'true' : 'false']);
        }
    }
}
