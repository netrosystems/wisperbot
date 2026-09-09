<?php

namespace App\Services;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Collection;

class WorkspaceNotificationRecipients
{
    /** @return Collection<int, User> */
    public function for(int $workspaceId): Collection
    {
        $workspace = Workspace::find($workspaceId);
        if (! $workspace) {
            return new Collection;
        }

        $userIds = $workspace->members()->where('users.status', User::STATUS_ACTIVE)->pluck('users.id')
            ->merge($workspace->users()->where('status', User::STATUS_ACTIVE)->pluck('users.id'))
            ->when($workspace->owner?->isActive(), fn ($ids) => $ids->push($workspace->owner_id))
            ->unique()
            ->values();

        return User::query()->whereIn('id', $userIds)->where('status', User::STATUS_ACTIVE)->get();
    }
}
