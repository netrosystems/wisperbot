<?php

namespace App\Models\Concerns;

use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;

/**
 * For records that make a workspace act on its own: channels, widgets,
 * automations, campaigns, scheduled posts, store connections. Lookups skip
 * records of deleted workspaces, so webhooks, widgets and schedulers stop for
 * a deleted workspace and resume untouched when it is restored.
 *
 * Use `withoutGlobalScope('active_workspace')` only to erase a workspace.
 */
trait ExcludesDeletedWorkspaces
{
    public static function bootExcludesDeletedWorkspaces(): void
    {
        static::addGlobalScope('active_workspace', function (Builder $query): void {
            $deleted = Workspace::deletedIds();
            if ($deleted !== []) {
                $query->whereNotIn($query->getModel()->qualifyColumn('workspace_id'), $deleted);
            }
        });
    }
}
