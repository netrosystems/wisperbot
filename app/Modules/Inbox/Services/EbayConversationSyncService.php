<?php

namespace App\Modules\Inbox\Services;

use App\Events\MessageReceived;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class EbayConversationSyncService
{
    public function sync(ChannelAccount $account): int
    {
        if ($account->channel !== 'ebay') {
            throw new \InvalidArgumentException('Only eBay channel accounts can be synchronized by this service.');
        }

        $client = new EbayApiClient($account);
        $rows = [];
        $offset = 0;
        do {
            $summary = $client->conversations(10, $offset);
            $page = $summary['conversations'] ?? $summary['conversation'] ?? $summary['members'] ?? [];
            $page = is_array($page) ? $page : [];
            $rows = array_merge($rows, $page);
            $offset += count($page);
            $total = (int) ($summary['total'] ?? $summary['totalCount'] ?? $offset);
        } while (count($page) === 10 && $offset < $total && $offset < 100);

        $sellerId = (string) (($account->meta_json ?? [])['seller_username']
            ?? ($account->meta_json ?? [])['seller_user_id']
            ?? '');
        $created = 0;

        foreach (is_array($rows) ? $rows : [] as $row) {
            $conversationId = (string) ($row['conversationId'] ?? $row['conversation_id'] ?? $row['id'] ?? '');
            if ($conversationId === '') {
                continue;
            }

            $detail = $client->conversation($conversationId);
            $messages = $detail['messages'] ?? $detail['message'] ?? [];
            $otherUsername = $this->otherUsername($detail, $messages, $sellerId) ?: 'eBay buyer';

            DB::transaction(function () use ($account, $conversationId, $messages, $otherUsername, $sellerId, &$created): void {
                $contact = Contact::query()
                    ->where('workspace_id', $account->workspace_id)
                    ->where('source', 'ebay')
                    ->where('custom_fields->ebay_username', $otherUsername)
                    ->first();

                if (! $contact) {
                    $contact = Contact::create([
                        'workspace_id' => $account->workspace_id,
                        'first_name' => mb_substr($otherUsername, 0, 100),
                        'source' => 'ebay',
                        'custom_fields' => ['ebay_username' => $otherUsername],
                        'last_seen_at' => now(),
                    ]);
                }

                $conversation = Conversation::firstOrCreate(
                    [
                        'workspace_id' => $account->workspace_id,
                        'channel_account_id' => $account->id,
                        'external_thread_id' => $conversationId,
                    ],
                    ['contact_id' => $contact->id, 'status' => 'open', 'unread_count' => 0]
                );

                foreach (is_array($messages) ? $messages : [] as $remoteMessage) {
                    $providerId = (string) ($remoteMessage['messageId'] ?? $remoteMessage['message_id'] ?? $remoteMessage['id'] ?? '');
                    if ($providerId === '' || Message::where('channel', 'ebay')->where('provider_message_id', $providerId)->exists()) {
                        continue;
                    }

                    $sender = (string) ($remoteMessage['senderUserName'] ?? $remoteMessage['senderUsername'] ?? $remoteMessage['sender'] ?? '');
                    $direction = $sellerId !== '' && strcasecmp($sender, $sellerId) === 0 ? 'out' : 'in';
                    $sentAt = $remoteMessage['createdDate'] ?? $remoteMessage['creationDate'] ?? $remoteMessage['sentDate'] ?? now();

                    $message = Message::create([
                        'conversation_id' => $conversation->id,
                        'direction' => $direction,
                        'channel' => 'ebay',
                        'type' => 'text',
                        'payload' => $remoteMessage,
                        'body' => (string) ($remoteMessage['messageText'] ?? $remoteMessage['messageBody'] ?? $remoteMessage['body'] ?? ''),
                        'status' => 'delivered',
                        'provider_message_id' => $providerId,
                        'sent_by' => $direction === 'in' ? 'contact' : 'human',
                        'sent_at' => Carbon::parse($sentAt),
                    ]);

                    $updates = ['last_message_at' => $message->sent_at];
                    if ($direction === 'in') {
                        $updates['status'] = 'open';
                        $updates['last_inbound_at'] = $message->sent_at;
                        $updates['unread_count'] = $conversation->unread_count + 1;
                        MessageReceived::dispatch($message);
                    }
                    $conversation->update($updates);
                    $conversation->refresh();
                    $created++;
                }
            });
        }

        $account->update([
            'status' => 'active',
            'meta_json' => array_merge($account->meta_json ?? [], [
                'last_sync_at' => now()->toIso8601String(),
                'last_sync_message_count' => $created,
            ]),
        ]);

        return $created;
    }

    private function otherUsername(array $detail, array $messages, string $sellerId): ?string
    {
        $candidates = [
            Arr::get($detail, 'otherParty.username'),
            $detail['senderUserName'] ?? null,
            $detail['recipientUserName'] ?? null,
        ];

        foreach ($messages as $message) {
            $candidates[] = $message['senderUserName'] ?? $message['senderUsername'] ?? null;
            $candidates[] = $message['recipientUserName'] ?? $message['recipientUsername'] ?? null;
        }

        return collect($candidates)
            ->filter(fn ($value) => is_string($value) && $value !== '' && strcasecmp($value, $sellerId) !== 0)
            ->first();
    }
}
