<?php

namespace App\Notifications\Channels;

use App\Services\NotificationWorkspaceResolver;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Notifications\Channels\BroadcastChannel;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

class WorkspaceBroadcastChannel extends BroadcastChannel
{
    public function __construct(Dispatcher $events, private readonly NotificationWorkspaceResolver $workspaces)
    {
        parent::__construct($events);
    }

    /** @return array<string, mixed>|BroadcastMessage */
    protected function getData($notifiable, Notification $notification): array|BroadcastMessage
    {
        $message = parent::getData($notifiable, $notification);
        $workspaceId = $this->workspaces->forNotification($notification, $notifiable);

        if ($message instanceof BroadcastMessage) {
            $message->data = array_merge($message->data, ['workspace_id' => $workspaceId]);

            return $message;
        }

        return array_merge($message, ['workspace_id' => $workspaceId]);
    }
}
