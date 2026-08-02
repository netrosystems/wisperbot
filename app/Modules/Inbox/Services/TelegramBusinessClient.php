<?php

namespace App\Modules\Inbox\Services;

use App\Modules\Integrations\Models\IntegrationConfig;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class TelegramBusinessClient
{
    public function __construct(private readonly ?IntegrationConfig $config = null) {}

    public static function configured(): ?self
    {
        $config = IntegrationConfig::forProvider('telegram_business');

        return $config?->enabled && $config->isConfigured() ? new self($config) : null;
    }

    public function credentials(): array
    {
        return $this->config?->credentials ?? [];
    }

    public function botUsername(): string
    {
        return ltrim(trim((string) ($this->credentials()['bot_username'] ?? '')), '@');
    }

    public function webhookSecret(): string
    {
        return trim((string) ($this->credentials()['webhook_secret'] ?? ''));
    }

    public function call(string $method, array $payload = []): array
    {
        $response = Http::asJson()->timeout(20)->post($this->apiUrl($method), $payload);
        $this->throwOnFailure($response, $method);

        return (array) $response->json('result', []);
    }

    public function registerWebhook(): void
    {
        $url = route('webhooks.telegram.receive');
        if (app()->environment('production') && ! str_starts_with($url, 'https://')) {
            throw new \RuntimeException('Telegram requires a public HTTPS webhook URL. Check APP_URL and proxy HTTPS settings.');
        }

        $this->call('setWebhook', [
            'url' => $url,
            'secret_token' => $this->webhookSecret(),
            'allowed_updates' => [
                'message',
                'business_connection',
                'business_message',
                'edited_business_message',
                'deleted_business_messages',
            ],
            'drop_pending_updates' => false,
        ]);
    }

    public function sendText(int|string $chatId, string $text, ?string $businessConnectionId = null): string
    {
        $payload = ['chat_id' => $chatId, 'text' => $text, 'link_preview_options' => ['is_disabled' => false]];
        if ($businessConnectionId) {
            $payload['business_connection_id'] = $businessConnectionId;
        }

        $result = $this->call('sendMessage', $payload);

        return (string) ($result['message_id'] ?? '');
    }

    public function sendMedia(string $method, int|string $chatId, string $field, string $media, ?string $caption, string $businessConnectionId): string
    {
        $payload = [
            'business_connection_id' => $businessConnectionId,
            'chat_id' => $chatId,
            $field => $media,
        ];
        if (filled($caption)) {
            $payload['caption'] = mb_substr((string) $caption, 0, 1024);
        }

        $result = $this->call($method, $payload);

        return (string) ($result['message_id'] ?? '');
    }

    /**
     * @return array{contents:string,path:string}|null
     */
    public function downloadFile(string $fileId): ?array
    {
        try {
            $file = $this->call('getFile', ['file_id' => $fileId]);
            $path = (string) ($file['file_path'] ?? '');
            if ($path === '') {
                return null;
            }

            $response = Http::timeout(30)->get($this->fileUrl($path));
            if (! $response->successful()) {
                return null;
            }

            return ['contents' => $response->body(), 'path' => $path];
        } catch (\Throwable) {
            return null;
        }
    }

    private function apiUrl(string $method): string
    {
        $token = trim((string) ($this->credentials()['bot_token'] ?? ''));
        if ($token === '') {
            throw new \RuntimeException('Telegram Business bot token is not configured.');
        }

        return "https://api.telegram.org/bot{$token}/{$method}";
    }

    private function fileUrl(string $path): string
    {
        $token = trim((string) ($this->credentials()['bot_token'] ?? ''));

        return 'https://api.telegram.org/file/bot'.$token.'/'.ltrim($path, '/');
    }

    private function throwOnFailure(Response $response, string $method): void
    {
        if ($response->successful() && $response->json('ok') === true) {
            return;
        }

        $description = (string) ($response->json('description') ?? 'Unknown Telegram error.');
        throw new \RuntimeException("Telegram {$method} failed: {$description}");
    }
}
