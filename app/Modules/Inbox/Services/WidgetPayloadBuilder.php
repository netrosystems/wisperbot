<?php

namespace App\Modules\Inbox\Services;

use App\Modules\AI\Services\ChatReplyOptions;
use App\Modules\AI\Services\VideoResourceService;
use App\Modules\Inbox\Models\ChatWidget;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;

class WidgetPayloadBuilder
{
    private const PUBLIC_ACTIVITY_TYPES = [
        'conversation.joined',
        'conversation.transferred',
        'conversation.resolved',
    ];

    public function __construct(private VideoResourceService $videos) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function messages(int $conversationId, ChatWidget $widget, int $afterId): array
    {
        $query = Message::where('conversation_id', $conversationId)
            ->with('sender')
            ->where('id', '>', $afterId)
            ->where(function ($query): void {
                $query->whereIn('direction', ['in', 'out'])
                    ->orWhere(function ($activityQuery): void {
                        $activityQuery->where('direction', 'system')
                            ->whereIn('payload->activity->type', self::PUBLIC_ACTIVITY_TYPES);
                    });
            })
            ->where('status', '!=', 'failed');

        $messages = $afterId > 0
            ? $query->orderBy('id')->limit(100)->get()
            : $query->orderByDesc('id')->limit(100)->get()->reverse()->values();

        return $messages
            ->map(fn (Message $message) => $this->message($message, $widget))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function message(Message $message, ChatWidget $widget): array
    {
        $message->loadMissing('sender');
        $isActivity = $message->direction === 'system' && $message->type === 'event';
        $isAgent = $message->direction === 'out' || $isActivity;
        $activity = $isActivity ? $this->publicActivity($message) : null;
        $agentName = $isAgent ? ($message->sender?->name ?: ($widget->agent_name ?: 'Support')) : null;
        if ($isActivity && is_array($activity)) {
            $agentName = $activity['actor_name'];
        }

        $body = $isActivity && ($message->payload['activity']['type'] ?? null) === 'conversation.transferred'
            ? "{$agentName} joined the chat"
            : (string) $message->body;

        return [
            'id' => $message->id,
            'role' => $isAgent ? 'agent' : 'visitor',
            'kind' => $isActivity ? 'activity' : 'message',
            'status' => $message->status,
            'body' => $body,
            'type' => $message->type,
            'attachment_url' => $this->browserSafePublicUrl($message->payload['preview_url'] ?? null),
            'filename' => $message->payload['filename'] ?? null,
            'mime_type' => $message->payload['mime_type'] ?? null,
            'file_size' => $message->payload['file_size'] ?? null,
            'resources' => $isActivity ? [] : $this->videos->sanitisePublicList($message->payload['resources'] ?? []),
            'quick_replies' => $isAgent && ! $isActivity ? app(ChatReplyOptions::class)->sanitize($message->payload['quick_replies'] ?? []) : [],
            'display_body' => $isAgent && ! $isActivity && is_string($message->payload['display_body'] ?? null)
                ? $message->payload['display_body'] : $body,
            'sent_by' => $isActivity ? 'system' : $message->sent_by,
            'agent_name' => $agentName,
            'activity' => $activity,
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

    /** @return array{type:string,actor_name:string}|null */
    private function publicActivity(Message $message): ?array
    {
        $activity = $message->payload['activity'] ?? null;
        $type = is_array($activity) ? ($activity['type'] ?? null) : null;
        $actor = is_array($activity) ? ($activity['actor'] ?? null) : null;
        $actorName = is_array($actor) ? ($actor['name'] ?? null) : null;

        if (! is_string($type) || ! in_array($type, self::PUBLIC_ACTIVITY_TYPES, true) || ! is_string($actorName)) {
            return null;
        }

        if ($type === 'conversation.transferred') {
            $type = 'conversation.joined';
        }

        return ['type' => $type, 'actor_name' => $actorName];
    }

    public function isPublicActivity(Message $message): bool
    {
        return $message->direction === 'system'
            && $message->type === 'event'
            && in_array($message->payload['activity']['type'] ?? null, self::PUBLIC_ACTIVITY_TYPES, true);
    }
}
