<?php

namespace App\Modules\AI\Jobs;

use App\Modules\AI\Models\AiKbDocument;
use App\Modules\AI\Services\LiveProductCatalogService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RefreshLiveProductDocumentJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    public int $uniqueFor = 300;

    /** @var array<int,int> */
    public array $backoff = [30, 120, 300];

    public function __construct(public readonly int $documentId) {}

    public function uniqueId(): string
    {
        return (string) $this->documentId;
    }

    public function handle(LiveProductCatalogService $catalog): void
    {
        $document = AiKbDocument::with('knowledgeBase')->find($this->documentId);
        if (! $document) {
            return;
        }
        try {
            $catalog->refreshDocument($document);
        } catch (\Throwable $exception) {
            $document->update([
                'product_detection_status' => 'error',
                'product_detection_message' => 'Live product verification failed. WisperBot will retry without exposing an unverified price.',
            ]);
            throw $exception;
        }
    }
}
