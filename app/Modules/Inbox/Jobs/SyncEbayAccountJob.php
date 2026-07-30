<?php

namespace App\Modules\Inbox\Jobs;

use App\Modules\Inbox\Services\EbayConversationSyncService;
use App\Modules\Shared\Models\ChannelAccount;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncEbayAccountJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public readonly int $channelAccountId)
    {
        $this->onQueue('social');
    }

    public function handle(EbayConversationSyncService $sync): void
    {
        $account = ChannelAccount::whereKey($this->channelAccountId)
            ->where('channel', 'ebay')
            ->where('status', 'active')
            ->first();

        if ($account) {
            $sync->sync($account);
        }
    }
}
