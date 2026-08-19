<?php

namespace App\Modules\Social\Jobs;

use App\Modules\Social\Models\SocialPost;
use App\Modules\Social\Services\SocialPublisher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class PublishSocialPostJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public array $backoff = [5, 15, 30];

    public function __construct(public readonly int $postId) {}

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("social-post:{$this->postId}"))
                ->releaseAfter(5)
                ->expireAfter($this->timeout + 30),
        ];
    }

    public function handle(SocialPublisher $publisher): void
    {
        $post = SocialPost::find($this->postId);

        // Post deleted or already fully published — nothing to do.
        if (! $post || $post->status === 'published') {
            return;
        }

        if ($post->status === 'scheduled') {
            // A post may be rescheduled after its first delayed job was
            // created. Never let that older job publish before the new time.
            if ($post->scheduled_at?->isFuture()) {
                $this->release(max(1, now()->diffInSeconds($post->scheduled_at)));

                return;
            }

            // Atomically claim due work. This cooperates with the minute-based
            // recovery scheduler and prevents both paths from publishing it.
            $claimed = SocialPost::whereKey($post->id)
                ->where('status', 'scheduled')
                ->where('scheduled_at', '<=', now())
                ->update(['status' => 'publishing']);

            if (! $claimed) {
                return;
            }

            $post->refresh();
        }

        // Only explicit publish work and retries are allowed through. A stale
        // delayed job must never revive a cancelled draft.
        if (! in_array($post->status, ['publishing', 'failed'], true)) {
            return;
        }

        $publisher->publish($post);
    }
}
