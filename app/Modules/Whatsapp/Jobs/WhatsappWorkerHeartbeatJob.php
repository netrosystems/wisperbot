<?php

namespace App\Modules\Whatsapp\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

class WhatsappWorkerHeartbeatJob implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->onQueue('whatsapp');
    }

    public function handle(): void
    {
        Cache::put('wa-health:whatsapp-worker', now()->toIso8601String(), 86400);
    }
}
