<?php

namespace App\Services;

use App\Modules\Shared\Models\Message;

class EmailInboxNotificationPolicy
{
    public function allows(object $notifiable, Message $message): bool
    {
        if ($message->channel !== 'email') {
            return true;
        }

        return (bool) ($notifiable->email_inbox_notifications_enabled ?? true);
    }
}
