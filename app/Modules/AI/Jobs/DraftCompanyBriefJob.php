<?php

namespace App\Modules\AI\Jobs;

use App\Modules\AI\Models\AiKnowledgeBase;
use App\Modules\AI\Services\CompanyBriefService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Drafts a Knowledge Base's company brief on the `ai` queue (Smart Bot 2.0,
 * Phase 1.3). One attempt: a failure is shown on the page with a Try again
 * button, so a provider outage never charges the client twice.
 */
class DraftCompanyBriefJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public int $uniqueFor = 300;

    public function __construct(public readonly int $knowledgeBaseId, public readonly ?int $actorId) {}

    public function uniqueId(): string
    {
        return (string) $this->knowledgeBaseId;
    }

    public function handle(CompanyBriefService $briefs): void
    {
        $kb = AiKnowledgeBase::find($this->knowledgeBaseId);
        if ($kb && $kb->company_brief_status === AiKnowledgeBase::BRIEF_DRAFTING) {
            $briefs->draft($kb, $this->actorId);
        }
    }

    public function failed(\Throwable $error): void
    {
        // A lost worker must not leave the page waiting for ever.
        $kb = AiKnowledgeBase::find($this->knowledgeBaseId);
        if ($kb && $kb->company_brief_status === AiKnowledgeBase::BRIEF_DRAFTING) {
            $kb->forceFill([
                'company_brief_status' => AiKnowledgeBase::BRIEF_FAILED,
                'company_brief_error' => 'The brief could not be drafted. Try again in a moment.',
            ])->save();
        }
    }
}
