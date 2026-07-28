<?php

namespace App\Modules\Inbox\Services;

use App\Events\ConversationAssigned;
use App\Models\User;
use App\Modules\Shared\Models\Conversation;
use App\Notifications\ConversationHandoverNotification;

class HumanHandoffService
{
    public function request(Conversation $conversation, string $reason = 'user_request'): Conversation
    {
        if (($conversation->assigned_to ?? 'bot') === 'human') {
            return $conversation;
        }

        $conversation->update([
            'assigned_to' => 'human',
            'handover_at' => $conversation->handover_at ?: now(),
            'status' => 'open',
        ]);

        $conversation->loadMissing('contact');

        User::where('workspace_id', $conversation->workspace_id)
            ->each(fn (User $member) => $member->notify(
                new ConversationHandoverNotification($conversation, $reason),
            ));

        // Refresh both the open conversation and the workspace inbox list.
        ConversationAssigned::dispatch($conversation->fresh(), null);

        return $conversation->refresh();
    }
}
