<?php

namespace App\Modules\Inbox\Services;

use App\Events\ConversationAssigned;
use App\Events\WidgetHandoffUpdated;
use App\Models\User;
use App\Modules\Inbox\Models\ChatWidget;
use App\Modules\Shared\Models\Conversation;
use App\Notifications\ConversationHandoverNotification;
use App\Services\WorkspaceNotificationRecipients;
use Illuminate\Support\Facades\Log;

class HumanHandoffService
{
    public function __construct(
        private readonly WorkspaceNotificationRecipients $recipients,
        private readonly TeamAvailabilityService $availability,
    ) {}

    public function request(Conversation $conversation, string $reason = 'user_request'): Conversation
    {
        if (($conversation->assigned_to ?? 'bot') === 'human') {
            return $conversation;
        }

        $conversation->update([
            'assigned_to' => 'human',
            'handover_at' => $conversation->handover_at ?: now(),
            'ai_paused_at' => $conversation->ai_paused_at ?: now(),
            'ai_pause_reason' => 'handoff',
            'status' => 'open',
        ]);

        $conversation->loadMissing('contact');

        $this->availability->available(
            (int) $conversation->workspace_id,
            $this->recipients->for((int) $conversation->workspace_id),
        )
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
            $widget = ChatWidget::where('channel_account_id', $conversation->channel_account_id)->first();
            if ($widget) {
                WidgetHandoffUpdated::dispatch(
                    $conversation->id,
                    app(WidgetPayloadBuilder::class)->handoff($widget, $conversation->fresh()),
                );
            }
        } catch (\Throwable $exception) {
            Log::warning('Human handoff realtime broadcast failed', [
                'conversation_id' => $conversation->id,
                'error' => $exception->getMessage(),
            ]);
        }

        return $conversation->refresh();
    }
}
