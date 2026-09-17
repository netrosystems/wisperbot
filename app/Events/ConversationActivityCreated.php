<?php

namespace App\Events;

use App\Modules\Shared\Models\Message;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ConversationActivityCreated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly Message $message) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("conversation.{$this->message->conversation_id}")];
    }

    public function broadcastAs(): string
    {
        return 'ConversationActivityCreated';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        $this->message->loadMissing('sender');

        return [
            'id' => $this->message->id,
            'conversation_id' => $this->message->conversation_id,
            'direction' => 'system',
            'channel' => $this->message->channel,
            'type' => 'event',
            'body' => $this->message->body,
            'payload' => $this->message->payload,
            'status' => $this->message->status,
            'sent_by' => 'system',
            'user_id' => $this->message->user_id,
            'sender' => $this->message->sender ? [
                'id' => $this->message->sender->id,
                'name' => $this->message->sender->name,
                'avatar_url' => $this->message->sender->avatarUrl(),
            ] : null,
            'sent_at' => $this->message->sent_at?->toIso8601String(),
            'created_at' => $this->message->created_at?->toIso8601String(),
        ];
    }
}
