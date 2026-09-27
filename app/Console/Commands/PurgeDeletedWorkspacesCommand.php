<?php

namespace App\Console\Commands;

use App\Models\Workspace;
use App\Services\WorkspacePurger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class PurgeDeletedWorkspacesCommand extends Command
{
    protected $signature = 'workspaces:purge-deleted {--dry-run : List what would be erased without erasing it}';

    protected $description = 'Permanently erase workspaces deleted more than 30 days ago';

    public function handle(WorkspacePurger $purger): int
    {
        $due = Workspace::onlyTrashed()
            ->where('deleted_at', '<=', now()->subDays(Workspace::RESTORE_DAYS))
            ->orderBy('deleted_at')
            ->get();

        if ($due->isEmpty()) {
            $this->info('No deleted workspaces are due for erasure.');

            return self::SUCCESS;
        }

        $failed = 0;
        foreach ($due as $workspace) {
            if ($this->option('dry-run')) {
                $this->line("Would erase workspace {$workspace->id} ({$workspace->name}), deleted {$workspace->deleted_at}.");

                continue;
            }

            try {
                $purger->purge($workspace);
                $this->info("Erased workspace {$workspace->id}.");
            } catch (\Throwable $e) {
                // Left in place; the next run tries again.
                $failed++;
                Log::error('Workspace erase failed', ['workspace_id' => $workspace->id, 'error' => $e->getMessage()]);
                $this->error("Could not erase workspace {$workspace->id}: {$e->getMessage()}");
            }
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
