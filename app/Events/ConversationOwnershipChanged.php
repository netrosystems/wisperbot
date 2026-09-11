<?php

namespace App\Events;

use App\Modules\Inbox\Services\TeamAvailabilityService;
use App\Modules\Shared\Models\Conversation;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ConversationOwnershipChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly Conversation $conversation) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("workspace.{$this->conversation->workspace_id}"),
            new PrivateChannel("conversation.{$this->conversation->id}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'ConversationOwnershipChanged';
    }

    public function broadcastWith(): array
    {
        $this->conversation->loadMissing('joinedUser');
        $joined = $this->conversation->joinedUser;

        return [
            'conversation_id' => $this->conversation->id,
            'status' => $this->conversation->status,
            'assigned_user_id' => $this->conversation->assigned_user_id,
            'joined_at' => $this->conversation->joined_at?->toIso8601String(),
            'joined_user' => $joined ? [
                'id' => $joined->id,
                'name' => $joined->name,
                'avatar' => $joined->avatar,
                'available' => app(TeamAvailabilityService::class)->isAvailable((int) $this->conversation->workspace_id, $joined),
            ] : null,
        ];
    }
}
