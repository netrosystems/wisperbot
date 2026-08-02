<?php

namespace App\Modules\Inbox\Jobs;

use App\Modules\Inbox\Services\TelegramBusinessClient;
use App\Modules\Inbox\Services\TelegramBusinessWebhookProcessor;
use App\Services\StorageManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;

class ProcessTelegramBusinessUpdateJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public int $backoff = 30;

    public function __construct(public readonly array $update)
    {
        $this->onQueue('social');
    }

    public function handle(StorageManager $storage): void
    {
        $client = TelegramBusinessClient::configured();
        if (! $client) {
            throw new RuntimeException('Telegram webhook job cannot run because the integration is disabled or incomplete.');
        }

        (new TelegramBusinessWebhookProcessor($client, $storage))->process($this->update);
    }
}
