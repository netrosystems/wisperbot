<?php

namespace App\Console\Commands;

use App\Modules\Social\Models\SocialAccount;
use App\Modules\Social\Models\SocialPost;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Before disconnecting kept the account, a disconnected account was deleted
 * and reconnecting the same Page created a new one, leaving scheduled posts
 * on an id that no longer exists (they silently skipped that network). This
 * moves the unpublished posts of one workspace from the old id to the new one.
 */
class ReattachSocialPostsCommand extends Command
{
    protected $signature = 'social:reattach-posts
        {from : Id of the removed social account the posts still point at}
        {to : Id of the connected account that replaces it}
        {--apply : Change the posts; without it only a preview is shown}';

    protected $description = 'Move unpublished social posts from a removed account to its reconnected replacement';

    public function handle(): int
    {
        $from = (int) $this->argument('from');
        $to = SocialAccount::find((int) $this->argument('to'));

        if (! $to || $to->id === $from) {
            $this->error('The "to" account must be a connected account different from "from".');

            return self::FAILURE;
        }

        $old = SocialAccount::withTrashed()->find($from);
        if ($old && ! $old->trashed()) {
            $this->error("Account {$from} is still connected; nothing to reattach.");

            return self::FAILURE;
        }
        if ($old && ($old->workspace_id !== $to->workspace_id || $old->network !== $to->network)) {
            $this->error("Account {$from} belongs to another workspace or network.");

            return self::FAILURE;
        }

        $posts = SocialPost::where('workspace_id', $to->workspace_id)
            ->whereIn('status', ['scheduled', 'draft', 'failed'])
            ->where(fn ($query) => $query->whereJsonContains('target_accounts', $from)
                ->orWhereJsonContains('target_accounts', (string) $from))
            ->get();

        $this->line(sprintf(
            '%d unpublished post(s) in workspace %d point at account %d; they would use %s account %d (%s).',
            $posts->count(), $to->workspace_id, $from, $to->network, $to->id, $to->name,
        ));

        if (! $this->option('apply') || $posts->isEmpty()) {
            $this->line($this->option('apply') ? 'Nothing to change.' : 'Preview only. Run again with --apply to change them.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($posts, $from, $to): void {
            foreach ($posts as $post) {
                $targets = collect($post->target_accounts ?? [])
                    ->map(fn ($id) => (int) $id === $from ? $to->id : (int) $id)
                    ->unique()->values()->all();
                $results = $post->publish_results;
                if (is_array($results)) {
                    // The old account's failure no longer applies.
                    unset($results[$from], $results[(string) $from]);
                }
                $post->update(['target_accounts' => $targets, 'publish_results' => $results ?: null]);
            }
        });

        $this->info("Moved {$posts->count()} post(s) to account {$to->id}.");

        return self::SUCCESS;
    }
}
