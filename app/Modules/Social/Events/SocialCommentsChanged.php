<?php

namespace App\Modules\Social\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

class SocialCommentsChanged implements ShouldBroadcast
{
    use Dispatchable;

    public function __construct(public int $workspaceId, public int $commentId) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('workspace.'.$this->workspaceId)];
    }

    public function broadcastAs(): string
    {
        return 'social.comments.changed';
    }

    public function broadcastQueue(): string
    {
        return 'social';
    }
}
