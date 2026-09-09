<?php

namespace App\Notifications\Concerns;

use App\Models\User;

trait HasWorkspaceScope
{
    private ?int $notificationWorkspaceId = null;

    protected function forWorkspace(int|string|null $workspaceId): void
    {
        $workspaceId = (int) $workspaceId;
        $this->notificationWorkspaceId = $workspaceId > 0 ? $workspaceId : null;
    }

    public function workspaceId(object $notifiable): ?int
    {
        if ($this->notificationWorkspaceId !== null) {
            return $this->notificationWorkspaceId;
        }

        if (! $notifiable instanceof User) {
            return null;
        }

        $workspaceId = (int) ($notifiable->workspace_id ?? 0);

        return $workspaceId > 0 ? $workspaceId : null;
    }
}
