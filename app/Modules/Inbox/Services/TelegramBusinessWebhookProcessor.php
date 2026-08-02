<?php

namespace App\Modules\Inbox\Services;

use App\Events\ContactCreated;
use App\Events\MessageReceived;
use App\Events\MessageSent;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use App\Services\StorageManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class TelegramBusinessWebhookProcessor
{
    public function __construct(
        private readonly TelegramBusinessClient $client,
        private readonly StorageManager $storage,
    ) {}

    public function process(array $update): array
    {
        if (isset($update['message'])) {
            $this->processPairingMessage((array) $update['message']);

            return [];
        }

        if (isset($update['business_connection'])) {
            $this->processBusinessConnection((array) $update['business_connection']);

            return [];
        }

        if (isset($update['business_message'])) {
            $message = $this->processBusinessMessage((array) $update['business_message']);

            return $message ? [$message] : [];
        }

        if (isset($update['edited_business_message'])) {
            $this->processEditedMessage((array) $update['edited_business_message']);

            return [];
        }

        if (isset($update['deleted_business_messages'])) {
            $this->processDeletedMessages((array) $update['deleted_business_messages']);
        }

        return [];
    }

    private function processPairingMessage(array $message): void
    {
        $text = trim((string) ($message['text'] ?? ''));
        if (! preg_match('/^\/start(?:@\w+)?\s+wb_([A-Za-z0-9]{32})$/', $text, $matches)) {
            return;
        }

        $chatId = $message['chat']['id'] ?? null;
        $user = (array) ($message['from'] ?? []);
        $telegramUserId = (string) ($user['id'] ?? '');
        if (! $chatId || $telegramUserId === '') {
            return;
        }

        $hash = hash('sha256', $matches[1]);
        $pending = ChannelAccount::where('channel', 'telegram')
            ->where('status', 'inactive')
            ->get()
            ->first(fn (ChannelAccount $account) => hash_equals(
                (string) ($account->meta_json['pairing_code_hash'] ?? ''),
                $hash,
            ));

        $expiresAt = $pending?->meta_json['pairing_expires_at'] ?? null;
        if (! $pending || ! $expiresAt || now()->greaterThan(Carbon::parse($expiresAt))) {
            $this->client->sendText($chatId, 'This WisperBot pairing link is invalid or expired. Return to Inbox Channel Setup and create a new link.');

            return;
        }

        $conflict = ChannelAccount::where('channel', 'telegram')
            ->where('status', 'active')
            ->where('business_account_id', $telegramUserId)
            ->where('workspace_id', '!=', $pending->workspace_id)
            ->exists();
        if ($conflict) {
            $this->client->sendText($chatId, 'This Telegram Business account is already connected to another WisperBot workspace. Disconnect it there before pairing again.');

            return;
        }

        $meta = $pending->meta_json ?? [];
        unset($meta['pairing_code_hash']);
        $meta['pairing_verified_at'] = now()->toIso8601String();
        $meta['telegram_user'] = $user;
        $pending->update([
            'business_account_id' => $telegramUserId,
            'display_name' => $this->displayName($user),
            'meta_json' => $meta,
        ]);

        $cachedConnection = Cache::pull('telegram:business-connection:user:'.$telegramUserId);
        if (is_array($cachedConnection)) {
            $this->activateConnection($pending, $cachedConnection);
            $pending->refresh();
            $this->client->sendText(
                $chatId,
                $pending->status === 'active'
                    ? 'Telegram Business is now connected to WisperBot. New customer chats will appear in your Omni Channel Inbox.'
                    : 'Pairing was confirmed, but Telegram has not enabled the business connection. Reconnect the bot in Telegram Business settings and allow message access.'
            );

            return;
        }

        $this->client->sendText(
            $chatId,
            'Pairing confirmed. Now open Telegram Settings → Telegram Business → Chatbots, connect this bot, and allow it to manage messages. WisperBot will activate the inbox automatically.'
        );
    }

    private function processBusinessConnection(array $connection): void
    {
        $connectionId = (string) ($connection['id'] ?? '');
        $user = (array) ($connection['user'] ?? []);
        $telegramUserId = (string) ($user['id'] ?? '');
        if ($connectionId === '' || $telegramUserId === '') {
            return;
        }

        $existing = ChannelAccount::where('channel', 'telegram')
            ->where('phone_number_id', $connectionId)
            ->first();

        if ($existing) {
            $meta = array_merge($existing->meta_json ?? [], [
                'business_connection' => $connection,
                'connection_updated_at' => now()->toIso8601String(),
            ]);
            $existing->update([
                'status' => ($connection['is_enabled'] ?? false) ? 'active' : 'inactive',
                'meta_json' => $meta,
            ]);

            return;
        }

        $pending = ChannelAccount::where('channel', 'telegram')
            ->where('status', 'inactive')
            ->where('business_account_id', $telegramUserId)
            ->latest('id')
            ->first();

        if (! $pending) {
            Cache::put('telegram:business-connection:user:'.$telegramUserId, $connection, now()->addMinutes(30));
            Log::notice('Telegram Business connection arrived before workspace pairing', ['telegram_user_id' => $telegramUserId]);

            return;
        }

        $this->activateConnection($pending, $connection);
    }

    private function activateConnection(ChannelAccount $pending, array $connection): void
    {
        $telegramUserId = (string) ($connection['user']['id'] ?? $pending->business_account_id ?? '');
        DB::transaction(function () use ($pending, $connection, $telegramUserId): void {
            // Serialize competing callbacks so one Telegram identity can never
            // be routed into two workspaces during a near-simultaneous connect.
            $matching = ChannelAccount::where('channel', 'telegram')
                ->where(function ($query) use ($connection, $telegramUserId): void {
                    $query->where('phone_number_id', (string) ($connection['id'] ?? ''))
                        ->orWhere('business_account_id', $telegramUserId);
                })
                ->lockForUpdate()
                ->get();

            if ($matching->contains(fn (ChannelAccount $account) => $account->status === 'active'
                && (int) $account->workspace_id !== (int) $pending->workspace_id)) {
                $pending->update([
                    'status' => 'error',
                    'meta_json' => array_merge($pending->meta_json ?? [], [
                        'connection_error' => 'This Telegram Business account is already connected to another workspace.',
                    ]),
                ]);

                return;
            }

            // A reconnect inside the same workspace updates the existing account
            // instead of creating two routes for the same Telegram identity.
            $target = ChannelAccount::where('channel', 'telegram')
                ->where('workspace_id', $pending->workspace_id)
                ->where('business_account_id', $telegramUserId)
                ->where('status', 'active')
                ->whereKeyNot($pending->id)
                ->first() ?? $pending;

            $meta = array_merge($target->meta_json ?? [], [
                'business_connection' => $connection,
                'connected_at' => now()->toIso8601String(),
            ]);
            unset($meta['pairing_code_hash'], $meta['pairing_expires_at']);
            unset($meta['connection_error']);

            $target->update([
                'business_account_id' => $telegramUserId,
                'phone_number_id' => (string) $connection['id'],
                'display_name' => $this->displayName((array) ($connection['user'] ?? [])),
                'status' => ($connection['is_enabled'] ?? false) ? 'active' : 'inactive',
                'meta_json' => $meta,
            ]);

            if (! $target->is($pending)) {
                $pending->delete();
            }
        });
    }

    private function processBusinessMessage(array $telegramMessage): ?Message
    {
        $connectionId = (string) ($telegramMessage['business_connection_id'] ?? '');
        $messageId = (string) ($telegramMessage['message_id'] ?? '');
        $chatId = (string) ($telegramMessage['chat']['id'] ?? '');
        if ($connectionId === '' || $messageId === '' || $chatId === '') {
            return null;
        }

        $account = ChannelAccount::where('channel', 'telegram')
            ->where('phone_number_id', $connectionId)
            ->where('status', 'active')
            ->first();
        if (! $account) {
            Log::warning('Telegram Business message has no active workspace route', ['business_connection_id' => $connectionId]);

            return null;
        }

        $providerId = $this->providerMessageId($connectionId, $chatId, $messageId);
        if (Message::where('provider_message_id', $providerId)->exists()) {
            return null;
        }

        $from = (array) ($telegramMessage['from'] ?? $telegramMessage['chat'] ?? []);
        $direction = ((string) ($from['id'] ?? '') === (string) $account->business_account_id
            || ! empty($telegramMessage['sender_business_bot'])) ? 'out' : 'in';
        $contactIdentity = $direction === 'in' ? $from : (array) ($telegramMessage['chat'] ?? []);
        $contact = $this->resolveContact($account, $chatId, $contactIdentity);
        $conversation = Conversation::firstOrCreate(
            [
                'workspace_id' => $account->workspace_id,
                'channel_account_id' => $account->id,
                'contact_id' => $contact->id,
                'external_thread_id' => $chatId,
            ],
            ['status' => 'open', 'assigned_to' => 'bot'],
        );

        [$type, $body, $payload] = $this->normalizeMessage($telegramMessage);
        $sentAt = isset($telegramMessage['date']) ? now()->setTimestamp((int) $telegramMessage['date']) : now();

        $stored = Message::create([
            'conversation_id' => $conversation->id,
            'direction' => $direction,
            'channel' => 'telegram',
            'type' => $type,
            'body' => $body,
            'payload' => $payload,
            'status' => $direction === 'in' ? 'delivered' : 'sent',
            'provider_message_id' => $providerId,
            'sent_by' => 'human',
            'sent_at' => $sentAt,
        ]);

        $changes = ['last_message_at' => $sentAt];
        if ($direction === 'in') {
            $changes += [
                'status' => 'open',
                'last_inbound_at' => $sentAt,
                'unread_count' => (int) $conversation->unread_count + 1,
            ];
        }
        $conversation->update($changes);

        $direction === 'in' ? MessageReceived::dispatch($stored) : MessageSent::dispatch($stored);

        return $stored;
    }

    private function processEditedMessage(array $telegramMessage): void
    {
        $connectionId = (string) ($telegramMessage['business_connection_id'] ?? '');
        $chatId = (string) ($telegramMessage['chat']['id'] ?? '');
        $messageId = (string) ($telegramMessage['message_id'] ?? '');
        [$type, $body, $payload] = $this->normalizeMessage($telegramMessage);

        Message::where('provider_message_id', $this->providerMessageId($connectionId, $chatId, $messageId))
            ->update(['type' => $type, 'body' => $body, 'payload' => array_merge($payload, ['edited' => true])]);
    }

    private function processDeletedMessages(array $deletion): void
    {
        $connectionId = (string) ($deletion['business_connection_id'] ?? '');
        $chatId = (string) ($deletion['chat']['id'] ?? '');
        foreach ($deletion['message_ids'] ?? [] as $messageId) {
            $message = Message::where('provider_message_id', $this->providerMessageId($connectionId, $chatId, (string) $messageId))->first();
            if (! $message) {
                continue;
            }
            $message->update([
                'body' => 'Message deleted in Telegram',
                'payload' => array_merge($message->payload ?? [], ['deleted' => true]),
            ]);
        }
    }

    private function resolveContact(ChannelAccount $account, string $chatId, array $identity): Contact
    {
        $contact = Contact::where('workspace_id', $account->workspace_id)
            ->where('source', 'telegram')
            ->where('custom_fields->telegram_chat_id', $chatId)
            ->first();

        if ($contact) {
            $contact->update(array_filter([
                'first_name' => $identity['first_name'] ?? $identity['title'] ?? null,
                'last_name' => $identity['last_name'] ?? null,
                'last_seen_at' => now(),
                'custom_fields' => array_merge($contact->custom_fields ?? [], array_filter([
                    'telegram_user_id' => isset($identity['id']) ? (string) $identity['id'] : null,
                    'telegram_username' => $identity['username'] ?? null,
                ])),
            ], fn ($value) => $value !== null));

            return $contact;
        }

        $contact = Contact::create([
            'workspace_id' => $account->workspace_id,
            'source' => 'telegram',
            'first_name' => $identity['first_name'] ?? $identity['title'] ?? 'Telegram customer',
            'last_name' => $identity['last_name'] ?? null,
            'custom_fields' => array_filter([
                'telegram_chat_id' => $chatId,
                'telegram_user_id' => isset($identity['id']) ? (string) $identity['id'] : null,
                'telegram_username' => $identity['username'] ?? null,
            ]),
            'last_seen_at' => now(),
        ]);
        ContactCreated::dispatch($contact);

        return $contact;
    }

    /** @return array{0:string,1:?string,2:array} */
    private function normalizeMessage(array $message): array
    {
        $payload = ['telegram' => $message];
        $body = $message['text'] ?? $message['caption'] ?? null;
        $type = 'text';
        $fileId = null;
        $fileName = null;
        $mimeType = null;

        if (! empty($message['photo'])) {
            $type = 'image';
            $fileId = (string) (collect($message['photo'])->last()['file_id'] ?? '');
            $mimeType = 'image/jpeg';
        } elseif (! empty($message['video'])) {
            $type = 'video';
            $fileId = (string) ($message['video']['file_id'] ?? '');
            $fileName = $message['video']['file_name'] ?? null;
            $mimeType = $message['video']['mime_type'] ?? 'video/mp4';
        } elseif (! empty($message['voice']) || ! empty($message['audio'])) {
            $type = 'audio';
            $item = (array) ($message['voice'] ?? $message['audio']);
            $fileId = (string) ($item['file_id'] ?? '');
            $fileName = $item['file_name'] ?? null;
            $mimeType = $item['mime_type'] ?? 'audio/ogg';
        } elseif (! empty($message['document'])) {
            $type = 'document';
            $fileId = (string) ($message['document']['file_id'] ?? '');
            $fileName = $message['document']['file_name'] ?? null;
            $mimeType = $message['document']['mime_type'] ?? 'application/octet-stream';
        } elseif (! empty($message['sticker'])) {
            $type = 'sticker';
            $fileId = (string) ($message['sticker']['file_id'] ?? '');
            $mimeType = $message['sticker']['is_animated'] ?? false ? 'application/x-tgsticker' : 'image/webp';
        } elseif (! empty($message['location'])) {
            return ['location', $body, array_merge($payload, ['location' => $message['location']])];
        } elseif (! empty($message['contact'])) {
            return ['contacts', $body, array_merge($payload, ['contact' => $message['contact']])];
        } elseif ($body === null) {
            return ['unsupported', 'Unsupported Telegram message', $payload];
        }

        if ($fileId) {
            $payload += ['telegram_file_id' => $fileId, 'mime_type' => $mimeType, 'filename' => $fileName];
            $download = $this->client->downloadFile($fileId);
            if ($download) {
                $extension = pathinfo($download['path'], PATHINFO_EXTENSION) ?: $this->extensionForMime((string) $mimeType);
                $path = $this->storage->prefixedPath('message-media/telegram/'.Str::uuid().'.'.$extension);
                $this->storage->disk()->put($path, $download['contents'], ['visibility' => 'public']);
                $payload['preview_url'] = $this->storage->disk()->url($path);
                $payload['storage_path'] = $path;
            }
        }

        return [$type, $body, $payload];
    }

    private function extensionForMime(string $mime): string
    {
        return match ($mime) {
            'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp',
            'video/mp4' => 'mp4', 'audio/ogg' => 'ogg', 'audio/mpeg' => 'mp3',
            default => 'bin',
        };
    }

    private function displayName(array $user): string
    {
        $name = trim(implode(' ', array_filter([$user['first_name'] ?? null, $user['last_name'] ?? null])));

        return $name !== '' ? $name : (! empty($user['username']) ? '@'.$user['username'] : 'Telegram Business');
    }

    private function providerMessageId(string $connectionId, string $chatId, string $messageId): string
    {
        return 'tg:'.$connectionId.':'.$chatId.':'.$messageId;
    }
}
