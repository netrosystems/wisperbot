<?php

namespace App\Modules\Inbox\Services;

use App\Modules\AI\Services\BusinessAwareTurnRouter;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;

/**
 * A customer's quick run of messages, answered as one question.
 *
 * Customers often split one question over several messages ("hi", "do you
 * deliver to Sylhet?", "and how much?"). The channel reply job answers the
 * latest message; this gathers the earlier ones it would otherwise ignore:
 * inbound text sent since the last outbound reply and within a short window
 * of the latest. Greetings and thanks among them add nothing and are left out.
 */
class MessageBurst
{
    public function __construct(private BusinessAwareTurnRouter $router) {}

    /**
     * The earlier messages to answer together with `$latest`, oldest first.
     *
     * @return list<Message>
     */
    public function earlier(Conversation $conversation, Message $latest): array
    {
        $max = (int) config('inbox.ai_burst_max_messages', 5);
        $window = (int) config('inbox.ai_burst_window_seconds', 90);
        if ($max < 2 || $window <= 0 || $latest->channel === 'email') {
            return [];
        }

        $lastReplyId = (int) $conversation->messages()->where('direction', 'out')->max('id');
        $since = ($latest->sent_at ?? now())->copy()->subSeconds($window);

        return $conversation->messages()
            ->where('direction', 'in')
            ->whereIn('type', ['text', 'interactive'])
            ->where('id', '>', $lastReplyId)
            ->where('id', '<', $latest->id)
            ->where('sent_at', '>=', $since)
            ->orderByDesc('id')
            ->take($max - 1)
            ->get()
            ->reverse()
            ->filter(fn (Message $message): bool => trim((string) $message->body) !== '' && ! $this->router->isSmallTalk((string) $message->body))
            ->values()
            ->all();
    }

    /** @param  list<Message>  $earlier */
    public function question(array $earlier, Message $latest): string
    {
        return implode("\n", array_map(fn (Message $message): string => trim((string) $message->body), [...$earlier, $latest]));
    }
}
