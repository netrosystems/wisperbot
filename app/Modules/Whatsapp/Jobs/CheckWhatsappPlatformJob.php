<?php

namespace App\Modules\Whatsapp\Jobs;

use App\Modules\Whatsapp\Models\WhatsappBusinessAccount;
use App\Modules\Whatsapp\Services\WhatsappHealthProbe;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

class CheckWhatsappPlatformJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 240;

    public int $timeout = 30;

    public int $tries = 1;

    public function __construct()
    {
        $this->onQueue('channel-health');
    }

    public function handle(WhatsappHealthProbe $probe): void
    {
        Cache::put('wa-health:worker', now()->toIso8601String(), 86400);
        $result = $probe->platform();
        if (! WhatsappBusinessAccount::where('status', 'active')->where('meta_json->connected_via', 'coexistence')->exists()) {
            unset($result['coexistence']);
        }
        Cache::put('wa-health:last-platform', $result, 86400);
    }
}
