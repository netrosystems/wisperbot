<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateMemberAvailabilityRequest;
use App\Models\User;
use App\Models\Workspace;
use App\Modules\Inbox\Models\WorkspaceMemberAvailability;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

class TeamAvailabilityController extends Controller
{
    public function update(UpdateMemberAvailabilityRequest $request, User $member): JsonResponse|RedirectResponse
    {
        $workspace = $this->workspace($request->user());
        abort_unless($member->client_id === $request->user()->client_id && $workspace->isAccessibleBy($member), 404);
        $data = $request->validated();

        $availability = WorkspaceMemberAvailability::updateOrCreate(
            ['workspace_id' => $workspace->id, 'user_id' => $member->id],
            [
                'enabled' => (bool) $data['enabled'],
                'timezone' => $data['timezone'] ?? 'UTC',
                'schedule_json' => $data['schedule'] ?? [],
            ],
        );

        if ($request->wantsJson()) {
            return response()->json(['availability' => $this->payload($availability)]);
        }

        return back()->with('success', 'Availability updated.');
    }

    public function payload(WorkspaceMemberAvailability $availability): array
    {
        return [
            'enabled' => $availability->enabled,
            'timezone' => $availability->timezone,
            'schedule' => $availability->schedule_json ?? [],
        ];
    }

    private function workspace(User $user): Workspace
    {
        $workspaceId = (int) ($user->current_workspace_id ?? $user->workspace_id);
        $workspace = Workspace::findOrFail($workspaceId);
        abort_unless($workspace->isAccessibleBy($user), 403);

        return $workspace;
    }
}
