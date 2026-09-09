<?php

namespace App\Listeners;

use App\Events\CampaignCompleted;
use App\Notifications\CampaignCompletedNotification;
use App\Services\WorkspaceNotificationRecipients;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

class SendCampaignCompletedNotification
{
    private readonly WorkspaceNotificationRecipients $recipients;

    public function __construct(?WorkspaceNotificationRecipients $recipients = null)
    {
        $this->recipients = $recipients ?? app(WorkspaceNotificationRecipients::class);
    }

    public function handle(CampaignCompleted $event): void
    {
        $campaign = $event->campaign;
        if (! Cache::add("notif_campaign_completed:{$campaign->id}", 1, 300)) {
            return;
        }

        $workspaceId = $campaign->workspace_id;

        if (! $workspaceId) {
            return;
        }

        $recipients = $this->recipients->for((int) $workspaceId);

        if ($recipients->isEmpty()) {
            return;
        }

        Notification::send($recipients, new CampaignCompletedNotification($campaign));
    }
}
