<?php

namespace App\Modules\Inbox\Services;

use App\Modules\AI\Services\ChatReplyOptions;
use App\Modules\AI\Services\VideoResourceService;
use App\Modules\Inbox\Models\ChatWidget;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;

class WidgetPayloadBuilder
{
    public function __construct(private VideoResourceService $videos) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function messages(int $conversationId, ChatWidget $widget, int $afterId): array
    {
        return Message::where('conversation_id', $conversationId)
            ->with('sender')
            ->where('id', '>', $afterId)
            ->whereIn('direction', ['in', 'out'])
            ->where('status', '!=', 'failed')
            ->orderBy('id')
            ->limit(100)
            ->get()
            ->map(fn (Message $message) => $this->message($message, $widget))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function message(Message $message, ChatWidget $widget): array
    {
        $message->loadMissing('sender');
        $isAgent = $message->direction === 'out';

        return [
            'id' => $message->id,
            'role' => $isAgent ? 'agent' : 'visitor',
            'status' => $message->status,
            'body' => (string) $message->body,
            'type' => $message->type,
            'attachment_url' => $this->browserSafePublicUrl($message->payload['preview_url'] ?? null),
            'filename' => $message->payload['filename'] ?? null,
            'mime_type' => $message->payload['mime_type'] ?? null,
            'file_size' => $message->payload['file_size'] ?? null,
            'resources' => $this->videos->sanitisePublicList($message->payload['resources'] ?? []),
            'quick_replies' => $isAgent ? app(ChatReplyOptions::class)->sanitize($message->payload['quick_replies'] ?? []) : [],
            'display_body' => $isAgent && is_string($message->payload['display_body'] ?? null)
                ? $message->payload['display_body'] : (string) $message->body,
            'sent_by' => $message->sent_by,
            'agent_name' => $isAgent
                ? ($message->sender?->name ?: ($widget->agent_name ?: 'Support'))
                : null,
            'created_at' => optional($message->sent_at ?? $message->created_at)->toIso8601String(),
        ];
    }

    /**
     * @return array{enabled:bool,eligible:bool,status:string}
     */
    public function handoff(ChatWidget $widget, Conversation $conversation): array
    {
        $conversation->loadMissing('joinedUser');
        $enabled = $widget->shouldAiAnswerNow();
        $waiting = ($conversation->assigned_to ?? 'bot') === 'human';
        $joined = $conversation->joinedUser;

        return [
            'enabled' => $enabled,
            'eligible' => $enabled && ! $waiting && ! $joined && $this->hasTwoCustomerMessages($conversation),
            'status' => $joined ? 'connected' : ($waiting ? 'waiting' : 'bot'),
            'agent' => $joined ? [
                'name' => $joined->name,
                'avatar_url' => $this->browserSafePublicUrl($joined->avatar),
            ] : null,
            'joined_at' => $joined ? $conversation->joined_at?->toIso8601String() : null,
        ];
    }

    public function hasTwoCustomerMessages(Conversation $conversation): bool
    {
        return $conversation->messages()
            ->where('direction', 'in')
            ->orderBy('id')
            ->limit(2)
            ->get(['id'])
            ->count() >= 2;
    }

    private function browserSafePublicUrl(?string $url): ?string
    {
        if (! $url || ! str_starts_with(strtolower($url), 'http://')) {
            return $url;
        }

        $assetHost = parse_url($url, PHP_URL_HOST);
        $requestHost = request()->getHost();
        $shouldUseHttps = request()->isSecure() || app()->environment('production');

        if ($shouldUseHttps && $assetHost && strcasecmp($assetHost, $requestHost) === 0) {
            return 'https://'.substr($url, strlen('http://'));
        }

        return $url;
    }
}
