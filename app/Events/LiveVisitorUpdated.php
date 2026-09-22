<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class LiveVisitorUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly int $workspaceId,
        public readonly int $conversationId,
        public readonly string $conversationUuid,
        public readonly string $lastSeenAt,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("workspace.{$this->workspaceId}")];
    }

    public function broadcastAs(): string
    {
        return 'LiveVisitorUpdated';
    }

    /** @return array<string, int|string|bool> */
    public function broadcastWith(): array
    {
        return [
            'conversation_id' => $this->conversationId,
            'conversation_uuid' => $this->conversationUuid,
            'last_seen_at' => $this->lastSeenAt,
            'online' => true,
        ];
    }
}
