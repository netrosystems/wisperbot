<?php

namespace App\Modules\Whatsapp\Jobs;

use App\Modules\Whatsapp\Models\WhatsappConnectionHealth;
use App\Modules\Whatsapp\Models\WhatsappConnectionOperation;
use App\Modules\Whatsapp\Services\WhatsappConnectionHealthService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class CheckWhatsappConnectionJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public string $operationId, public int $workspaceId)
    {
        $this->onQueue('channel-health');
    }

    public function handle(WhatsappConnectionHealthService $service): void
    {
        $service->execute($this->operationId, $this->workspaceId);
    }

    public function failed(?\Throwable $exception): void
    {
        WhatsappConnectionOperation::where('workspace_id', $this->workspaceId)->whereKey($this->operationId)
            ->whereNull('finished_at')->update(['state' => 'failed', 'finished_at' => now(), 'results' => json_encode(['code' => 'check_interrupted'])]);
        WhatsappConnectionHealth::where('workspace_id', $this->workspaceId)->where('operation_id', $this->operationId)
            ->update(['operation_id' => null, 'state' => 'check_delayed', 'next_check_at' => now()->addMinutes(5)]);
    }
}
