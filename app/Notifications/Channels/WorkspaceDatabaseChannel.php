<?php

namespace App\Notifications\Channels;

use App\Services\NotificationWorkspaceResolver;
use Illuminate\Notifications\Channels\DatabaseChannel;
use Illuminate\Notifications\Notification;

class WorkspaceDatabaseChannel extends DatabaseChannel
{
    public function __construct(private readonly NotificationWorkspaceResolver $workspaces) {}

    /** @return array<string, mixed> */
    protected function buildPayload($notifiable, Notification $notification): array
    {
        $payload = parent::buildPayload($notifiable, $notification);
        $workspaceId = $this->workspaces->forNotification($notification, $notifiable);
        $payload['workspace_id'] = $workspaceId;
        $payload['data'] = array_merge($payload['data'], ['workspace_id' => $workspaceId]);

        return $payload;
    }
}
