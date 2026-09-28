<?php

namespace App\Modules\Social\Services;

use App\Models\User;
use App\Models\Workspace;
use App\Modules\Social\Models\SocialAccount;
use App\Notifications\SocialConnectionAttentionNotification;
use App\Services\WorkspaceNotificationRecipients;

/** Tells a workspace's owner and admins that a social connection needs them. */
class SocialConnectionAlerts
{
    /** `$daysLeft` null means the connection needs reconnecting now. */
    public function notify(SocialAccount $account, ?int $daysLeft): void
    {
        $workspace = Workspace::find($account->workspace_id);
        if (! $workspace) {
            return;
        }

        $managerIds = $workspace->members()->wherePivotIn('role', ['owner', 'admin'])->pluck('users.id');
        app(WorkspaceNotificationRecipients::class)->for((int) $workspace->id)
            ->filter(fn (User $user) => (int) $workspace->owner_id === (int) $user->id
                || $user->client_role === User::CLIENT_ROLE_ADMINISTRATOR
                || $managerIds->contains($user->id))
            ->unique('id')
            ->each(fn (User $user) => $user->notify(new SocialConnectionAttentionNotification($account->network, (string) $account->name, $daysLeft, (int) $workspace->id)));
    }
}
