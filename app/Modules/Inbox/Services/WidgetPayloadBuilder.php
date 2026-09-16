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
            'answer_origin' => $isAgent && in_array($message->payload['answer_origin'] ?? null, ['conversation', 'knowledge_base', 'business_guidance', 'trusted_research', 'fallback'], true)
                ? $message->payload['answer_origin'] : null,
            'response_mode' => $isAgent && in_array($message->payload['response_mode'] ?? null, ['answer', 'clarification', 'fallback'], true)
                ? $message->payload['response_mode'] : null,
            'citations' => $isAgent ? $this->citations($message->payload['citations'] ?? []) : [],
            'display_body' => $isAgent && is_string($message->payload['display_body'] ?? null)
                ? $message->payload['display_body'] : (string) $message->body,
            'sent_by' => $message->sent_by,
            'agent_name' => $isAgent
                ? ($message->sender?->name ?: ($widget->agent_name ?: 'Support'))
                : null,
            'agent_avatar_url' => $isAgent && $message->sender
                ? $this->browserSafePublicUrl($message->sender->avatarUrl())
                : null,
            'created_at' => optional($message->sent_at ?? $message->created_at)->toIso8601String(),
        ];
    }

    /** @return array<int,array{title:string,url:string}> */
    private function citations(mixed $citations): array
    {
        if (! is_array($citations)) {
            return [];
        }

        return collect($citations)->filter(fn ($citation): bool => is_array($citation)
            && is_string($citation['title'] ?? null)
            && is_string($citation['url'] ?? null)
            && str_starts_with(strtolower($citation['url']), 'https://'))
            ->take(3)
            ->map(fn (array $citation): array => [
                'title' => mb_substr(strip_tags($citation['title']), 0, 160),
                'url' => $citation['url'],
            ])->values()->all();
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
                'avatar_url' => $this->browserSafePublicUrl($joined->avatarUrl()),
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
