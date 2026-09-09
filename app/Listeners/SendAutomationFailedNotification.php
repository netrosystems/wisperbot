<?php

namespace App\Listeners;

use App\Events\AutomationFailed;
use App\Notifications\AutomationFailedNotification;
use App\Services\WorkspaceNotificationRecipients;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

class SendAutomationFailedNotification
{
    private readonly WorkspaceNotificationRecipients $recipients;

    public function __construct(?WorkspaceNotificationRecipients $recipients = null)
    {
        $this->recipients = $recipients ?? app(WorkspaceNotificationRecipients::class);
    }

    public function handle(AutomationFailed $event): void
    {
        if (! Cache::add("notif_automation_failed:{$event->run->id}", 1, 300)) {
            return;
        }

        $workspaceId = $event->run->automation->workspace_id ?? null;

        if (! $workspaceId) {
            return;
        }

        $recipients = $this->recipients->for((int) $workspaceId);

        if ($recipients->isEmpty()) {
            return;
        }

        Notification::send($recipients, new AutomationFailedNotification($event->run, $event->errorMessage));
    }
}
