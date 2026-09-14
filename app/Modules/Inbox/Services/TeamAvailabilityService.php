<?php

namespace App\Modules\Inbox\Services;

use App\Models\User;
use App\Modules\Inbox\Models\WorkspaceMemberAvailability;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;

class TeamAvailabilityService
{
    public function __construct(private readonly WeeklySchedule $weekly) {}

    public function isAvailable(int $workspaceId, User|int $user, ?CarbonInterface $at = null): bool
    {
        $user = $user instanceof User ? $user : User::find($user);
        if (! $user || ! $user->isActive()) {
            return false;
        }

        $availability = WorkspaceMemberAvailability::query()
            ->where('workspace_id', $workspaceId)
            ->where('user_id', $user->id)
            ->first();

        // No record and explicitly disabled schedules preserve the historical
        // continuously-available notification behaviour.
        return $this->recordAvailable($availability, $at);
    }

    /** @param Collection<int, User> $users */
    public function available(int $workspaceId, Collection $users, ?CarbonInterface $at = null): Collection
    {
        $active = $users->filter(fn (User $user) => $user->isActive())->values();
        $records = WorkspaceMemberAvailability::query()
            ->where('workspace_id', $workspaceId)
            ->whereIn('user_id', $active->pluck('id'))
            ->get()
            ->keyBy('user_id');

        return $active->filter(fn (User $user) => $this->recordAvailable($records->get($user->id), $at))->values();
    }

    private function recordAvailable(?WorkspaceMemberAvailability $availability, ?CarbonInterface $at): bool
    {
        if (! $availability || ! $availability->enabled) {
            return true;
        }

        return $this->weekly->contains([
            'enabled' => true,
            'timezone' => $availability->timezone,
            'schedule' => $availability->schedule_json ?? [],
        ], $at);
    }
}
