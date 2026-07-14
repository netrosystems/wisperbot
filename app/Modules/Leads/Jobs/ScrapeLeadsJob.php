<?php

namespace App\Modules\Leads\Jobs;

use App\Modules\Leads\Models\LeadScrapeJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ScrapeLeadsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 300;

    public function __construct(public readonly int $scrapeJobId) {}

    public function handle(): void
    {
        $job = LeadScrapeJob::find($this->scrapeJobId);
        if (! $job || in_array($job->status, ['done', 'failed'], true)) {
            return;
        }

        // Jobs queued before the feature was retired must not make a Places API call.
        $job->update([
            'status' => 'failed',
            'error' => 'Lead scraper has been retired.',
            'completed_at' => now(),
        ]);
    }
}
