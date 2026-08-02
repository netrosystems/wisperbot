<?php

namespace App\Modules\Inbox\Services;

use App\Modules\Shared\Contracts\ChannelDriverInterface;
use App\Modules\Shared\Models\Message;
use App\Services\StorageManager;
use Illuminate\Http\Request;

class TelegramBusinessDriver implements ChannelDriverInterface
{
    public function __construct(private readonly StorageManager $storage) {}

    public function send(Message $message): string
    {
        $conversation = $message->conversation()->with('channelAccount')->first();
        $account = $conversation?->channelAccount;
        if (! $account || $account->channel !== 'telegram' || $account->status !== 'active') {
            throw new \RuntimeException('The Telegram Business account is disconnected or inactive.');
        }

        $connectionId = (string) $account->phone_number_id;
        $chatId = (string) $conversation->external_thread_id;
        if ($connectionId === '' || $chatId === '') {
            throw new \RuntimeException('The Telegram Business conversation route is incomplete. Reconnect the account.');
        }
        if (data_get($account->meta_json, 'business_connection.rights.can_reply') === false) {
            throw new \RuntimeException('Telegram has not granted this bot permission to reply. Enable Reply to messages in Telegram Business → Chatbots.');
        }

        $client = TelegramBusinessClient::configured();
        if (! $client) {
            throw new \RuntimeException('Telegram Business is not configured by the Super Admin.');
        }

        try {
            if ($message->type === 'text') {
                $messageId = $client->sendText($chatId, (string) $message->body, $connectionId);

                return $this->providerMessageId($connectionId, $chatId, $messageId);
            }

            $payload = $message->payload ?? [];
            $media = $payload['link'] ?? $payload['preview_url'] ?? null;
            if (! is_string($media) || $media === '') {
                throw new \RuntimeException('Telegram requires a public media URL for this attachment.');
            }
            if (! str_starts_with($media, 'http://') && ! str_starts_with($media, 'https://')) {
                $media = url($media);
            }

            [$method, $field] = match ($message->type) {
                'image' => ['sendPhoto', 'photo'],
                'video' => ['sendVideo', 'video'],
                'audio' => ['sendAudio', 'audio'],
                'document' => ['sendDocument', 'document'],
                default => throw new \RuntimeException('Telegram supports text, image, video, audio, and document replies in this inbox.'),
            };

            $messageId = $client->sendMedia($method, $chatId, $field, $media, $message->body, $connectionId);

            return $this->providerMessageId($connectionId, $chatId, $messageId);
        } catch (\Throwable $e) {
            if (str_contains($e->getMessage(), '403') || str_contains(strtolower($e->getMessage()), 'forbidden')) {
                $account->update(['status' => 'error']);
            }

            throw $e;
        }
    }

    public function receiveWebhook(Request $request): array
    {
        $client = TelegramBusinessClient::configured();
        if (! $client) {
            return [];
        }

        return (new TelegramBusinessWebhookProcessor($client, $this->storage))->process($request->all());
    }

    public function verifyCreds(): bool
    {
        $client = TelegramBusinessClient::configured();
        if (! $client) {
            return false;
        }

        try {
            return ! empty($client->call('getMe')['id']);
        } catch (\Throwable) {
            return false;
        }
    }

    private function providerMessageId(string $connectionId, string $chatId, string $messageId): string
    {
        return 'tg:'.$connectionId.':'.$chatId.':'.$messageId;
    }
}
