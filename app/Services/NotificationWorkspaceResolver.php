<?php

namespace App\Services;

use App\Contracts\WorkspaceScopedNotification;
use App\Models\User;
use Illuminate\Notifications\Notification;

class NotificationWorkspaceResolver
{
    public function forNotification(Notification $notification, object $notifiable): ?int
    {
        if ($notification instanceof WorkspaceScopedNotification) {
            return $notification->workspaceId($notifiable);
        }

        if (! $notifiable instanceof User) {
            return null;
        }

        $workspaceId = (int) ($notifiable->workspace_id ?? 0);

        return $workspaceId > 0 ? $workspaceId : null;
    }
}
