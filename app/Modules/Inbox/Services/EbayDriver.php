<?php

namespace App\Modules\Inbox\Services;

use App\Modules\Shared\Contracts\ChannelDriverInterface;
use App\Modules\Shared\Models\Message;
use Illuminate\Http\Request;

class EbayDriver implements ChannelDriverInterface
{
    public function send(Message $message): string
    {
        $conversation = $message->conversation;
        $account = $conversation?->channelAccount;
        if (! $account || $account->channel !== 'ebay') {
            throw new \RuntimeException('The eBay channel account is missing.');
        }

        if ($message->type !== 'text') {
            throw new \RuntimeException('The eBay Message API currently supports text replies only in this inbox.');
        }

        $result = (new EbayApiClient($account))->sendMessage(
            (string) $conversation->external_thread_id,
            (string) $message->body
        );

        return (string) ($result['messageId'] ?? $result['message_id'] ?? '');
    }

    public function receiveWebhook(Request $request): array
    {
        return [];
    }

    public function verifyCreds(): bool
    {
        return true;
    }
}
