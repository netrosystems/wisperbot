<?php

namespace App\Services;

use App\Models\User;
use App\Models\Workspace;
use App\Modules\Shared\Models\Conversation;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class WorkspaceNotificationService
{
    /** @var array<string, string|null> */
    private array $conversationUuids = [];

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
        $data = array_merge($notification->data, ['workspace_id' => $workspaceId]);
        $conversationId = $data['conversation_id'] ?? null;

        if (! filled($data['conversation_uuid'] ?? null) && is_numeric($conversationId) && (int) $conversationId > 0) {
            $cacheKey = $workspaceId.':'.(int) $conversationId;

            if (! array_key_exists($cacheKey, $this->conversationUuids)) {
                $this->conversationUuids[$cacheKey] = Conversation::query()
                    ->where('workspace_id', $workspaceId)
                    ->whereKey((int) $conversationId)
                    ->value('uuid');
            }

            if ($this->conversationUuids[$cacheKey] !== null) {
                $data['conversation_uuid'] = $this->conversationUuids[$cacheKey];
            }
        }

        return [
            'id' => $notification->id,
            'type' => $notification->type,
            'type_name' => class_basename($notification->type),
            'workspace_id' => $workspaceId,
            'data' => $data,
            'read_at' => $notification->read_at?->toIso8601String(),
            'created_at' => $notification->created_at->toIso8601String(),
        ];
    }
}
