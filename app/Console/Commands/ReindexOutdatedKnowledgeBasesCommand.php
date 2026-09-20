<?php

namespace App\Console\Commands;

use App\Modules\AI\Jobs\IndexDocumentJob;
use App\Modules\AI\Models\AiKbDocument;
use Illuminate\Console\Command;

class ReindexOutdatedKnowledgeBasesCommand extends Command
{
    protected $signature = 'ai:kb-reindex-outdated
        {--active-only : Queue only Knowledge Bases attached to an enabled Smart Bot}
        {--limit=100 : Maximum number of documents to queue}';

    protected $description = 'Queue safe generation-based reindexing for Knowledge Base sources using an older index version';

    public function handle(): int
    {
        $targetVersion = (int) config('knowledge_base.current_index_version', 2);
        $limit = max(1, min(1000, (int) $this->option('limit')));
        $documents = AiKbDocument::query()
            ->where('index_version', '<', $targetVersion)
            ->whereNull('pending_index_generation')
            ->whereHas('knowledgeBase', function ($knowledgeBases): void {
                if ($this->option('active-only')) {
                    $knowledgeBases->whereHas('chatbots', fn ($chatbots) => $chatbots->where('enabled', true));
                }
            })
            ->orderByRaw("CASE WHEN status = 'indexed' THEN 0 ELSE 1 END")
            ->orderBy('id')
            ->limit($limit)
            ->get(['id']);

        foreach ($documents as $document) {
            IndexDocumentJob::dispatch($document->id)->onQueue('ai');
        }

        $scope = $this->option('active-only') ? 'active Smart Bot sources' : 'sources';
        $this->info("Queued {$documents->count()} {$scope} for index version {$targetVersion}.");

        return self::SUCCESS;
    }
}
