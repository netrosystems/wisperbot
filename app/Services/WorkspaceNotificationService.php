<?php

namespace App\Services;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class WorkspaceNotificationService
{
    public function currentWorkspaceId(Request $request): int
    {
        $user = $request->user();
        if (! $user instanceof User) {
            throw new AccessDeniedHttpException;
        }

        $sessionWorkspaceId = $request->hasSession() ? $request->session()->get('current_workspace_id') : null;
        $workspaceId = (int) ($sessionWorkspaceId ?? $user->workspace_id ?? 0);
        $workspace = $workspaceId > 0 ? Workspace::find($workspaceId) : null;
        $isPrimaryWorkspace = $workspace && (int) $user->workspace_id === (int) $workspace->id;
        if (! $workspace || (! $isPrimaryWorkspace && ! $workspace->isAccessibleBy($user))) {
            throw new AccessDeniedHttpException('Select an accessible workspace to view notifications.');
        }

        return $workspaceId;
    }

    /** @return MorphMany<DatabaseNotification, User> */
    public function query(User $user, int $workspaceId): MorphMany
    {
        return $user->notifications()->where('workspace_id', $workspaceId);
    }

    /** @return array<string, mixed> */
    public function serialize(object $notification): array
    {
        $workspaceId = (int) $notification->workspace_id;

        return [
            'id' => $notification->id,
            'type' => $notification->type,
            'type_name' => class_basename($notification->type),
            'workspace_id' => $workspaceId,
            'data' => array_merge($notification->data, ['workspace_id' => $workspaceId]),
            'read_at' => $notification->read_at?->toIso8601String(),
            'created_at' => $notification->created_at->toIso8601String(),
        ];
    }
}
