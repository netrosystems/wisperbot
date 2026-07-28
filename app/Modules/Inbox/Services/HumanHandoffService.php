<?php

namespace App\Modules\Inbox\Services;

use App\Events\ConversationAssigned;
use App\Models\User;
use App\Modules\Shared\Models\Conversation;
use App\Notifications\ConversationHandoverNotification;
use Illuminate\Support\Facades\Log;

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
            ->each(function (User $member) use ($conversation, $reason): void {
                try {
                    $member->notify(new ConversationHandoverNotification($conversation, $reason));
                } catch (\Throwable $exception) {
                    // A push/broadcast provider outage must never undo or mask a
                    // handoff that has already been saved successfully.
                    Log::warning('Human handoff notification failed', [
                        'conversation_id' => $conversation->id,
                        'user_id' => $member->id,
                        'error' => $exception->getMessage(),
                    ]);
                }
            });

        // Refresh both the open conversation and the workspace inbox list.
        try {
            ConversationAssigned::dispatch($conversation->fresh(), null);
        } catch (\Throwable $exception) {
            Log::warning('Human handoff realtime broadcast failed', [
                'conversation_id' => $conversation->id,
                'error' => $exception->getMessage(),
            ]);
        }

        return $conversation->refresh();
    }
}
