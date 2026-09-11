<?php

namespace App\Listeners;

use App\Events\MessageReceived;
use App\Notifications\NewMessageNotification;
use App\Modules\Inbox\Services\TeamAvailabilityService;
use App\Services\WorkspaceNotificationRecipients;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

class SendNewMessageNotification
{
    private readonly WorkspaceNotificationRecipients $recipients;

    public function __construct(?WorkspaceNotificationRecipients $recipients = null)
    {
        $this->recipients = $recipients ?? app(WorkspaceNotificationRecipients::class);
    }

    public function handle(MessageReceived $event): void
    {
        $msgId = $event->message->id ?? null;
        if ($msgId && ! Cache::add("notif_new_msg:{$msgId}", 1, 60)) {
            return;
        }

        $conversation = $event->message->conversation;

        if (! $conversation) {
            return;
        }

        // Previously: only notify assigned agent if set
        // if ($conversation->assigned_user_id) {
        //     $recipients = User::where('id', $conversation->assigned_user_id)->get();
        // } else {
        //     $workspaceId = $conversation->workspace_id;
        //     $recipients = User::where('workspace_id', $workspaceId)->get();
        // }

        // Notify all workspace team members for all messages
        $workspaceId = $conversation->workspace_id;
        $allRecipients = $this->recipients->for((int) $workspaceId);
        $availability = app(TeamAvailabilityService::class);
        if ($conversation->joined_user_id) {
            $owner = $allRecipients->firstWhere('id', (int) $conversation->joined_user_id);
            $recipients = $owner && $availability->isAvailable((int) $workspaceId, $owner)
                ? new \Illuminate\Database\Eloquent\Collection([$owner])
                : $availability->available((int) $workspaceId, $allRecipients)
                    ->reject(fn ($user) => (int) $user->id === (int) $conversation->joined_user_id)
                    ->values();
        } else {
            $recipients = $availability->available((int) $workspaceId, $allRecipients);
        }

        if ($recipients->isEmpty()) {
            return;
        }

        Notification::send(
            $recipients,
            new NewMessageNotification($event->message, $conversation),
        );
    }
}
