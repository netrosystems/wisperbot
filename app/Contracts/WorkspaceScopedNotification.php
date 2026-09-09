<?php

namespace App\Contracts;

interface WorkspaceScopedNotification
{
    public function workspaceId(object $notifiable): ?int;
}
