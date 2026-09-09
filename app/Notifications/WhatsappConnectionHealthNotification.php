<?php

namespace App\Notifications;

use App\Contracts\WorkspaceScopedNotification;
use App\Notifications\Concerns\HasWorkspaceScope;
use Illuminate\Notifications\Notification;

class WhatsappConnectionHealthNotification extends Notification implements WorkspaceScopedNotification
{
    use HasWorkspaceScope;

    public function __construct(private readonly bool $recovered, private readonly bool $platform = false, ?int $workspaceId = null)
    {
        $this->forWorkspace($platform ? null : $workspaceId);
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, string> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'whatsapp_connection_health',
            'message' => $this->recovered
                ? 'WhatsApp connection checks are passing again.'
                : ($this->platform ? 'WhatsApp platform checks need administrator attention.' : 'A WhatsApp connection needs attention. Open Channel Setup to review it.'),
            'url' => $this->platform ? route('admin.cron-setup.index') : route('client.inbox.setup'),
        ];
    }
}
