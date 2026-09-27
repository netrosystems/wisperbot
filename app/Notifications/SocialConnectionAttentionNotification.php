<?php

namespace App\Notifications;

use App\Contracts\WorkspaceScopedNotification;
use App\Notifications\Concerns\HasWorkspaceScope;
use Illuminate\Notifications\Notification;

/**
 * A social connection that needs the client: it expires soon and cannot be
 * renewed automatically (LinkedIn without a refresh token), or the network
 * revoked it and it must be reconnected now.
 */
class SocialConnectionAttentionNotification extends Notification implements WorkspaceScopedNotification
{
    use HasWorkspaceScope;

    public function __construct(
        private readonly string $network,
        private readonly string $accountName,
        private readonly ?int $daysLeft,
        int $workspaceId,
    ) {
        $this->forWorkspace($workspaceId);
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, string> */
    public function toArray(object $notifiable): array
    {
        $label = ['linkedin' => 'LinkedIn', 'youtube' => 'YouTube', 'tiktok' => 'TikTok', 'twitter' => 'X'][$this->network] ?? ucfirst($this->network);

        return [
            'type' => 'social_connection_attention',
            'message' => $this->daysLeft === null
                ? "{$label} disconnected {$this->accountName}. Reconnect it in Social Media Automation to keep publishing."
                : "The {$label} connection for {$this->accountName} expires in {$this->daysLeft} ".($this->daysLeft === 1 ? 'day' : 'days').'. Reconnect it in Social Media Automation to keep publishing.',
            'url' => route('client.social.automation.index'),
        ];
    }
}
