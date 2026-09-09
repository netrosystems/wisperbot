<?php

namespace App\Notifications;

use App\Contracts\WorkspaceScopedNotification;
use App\Notifications\Channels\OneSignalChannel;
use App\Notifications\Concerns\HasWorkspaceScope;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WorkspaceExportReadyNotification extends Notification implements WorkspaceScopedNotification
{
    use HasWorkspaceScope, Queueable;

    public function __construct(private string $downloadUrl, ?int $workspaceId = null)
    {
        $this->forWorkspace($workspaceId);
    }

    public function via(object $notifiable): array
    {
        return ['mail', 'database', OneSignalChannel::class];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->markdown('mail.workspace.export-ready', [
                'name' => $notifiable->name ?? 'there',
                'downloadUrl' => $this->downloadUrl,
            ])
            ->subject('Your data export is ready');
    }

    public function toOneSignal(object $notifiable): array
    {
        return [
            'title' => 'Export ready',
            'body' => 'Your workspace data export is ready to download',
            'url' => $this->downloadUrl,
        ];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'workspace_export_ready',
            'download_url' => $this->downloadUrl,
            'message' => 'Your workspace data export is ready to download.',
        ];
    }
}
