<?php

namespace App\Modules\Inbox\Services;

use App\Models\User;
use App\Models\Workspace;
use App\Modules\AI\Models\AiChatbot;
use App\Modules\Inbox\Models\ChatWidget;
use App\Modules\Inbox\Models\WorkspaceAiAnsweringPolicy;
use App\Modules\Shared\Models\ChannelAccount;

/**
 * Where each Smart Bot answers: the website widget, the inbox channels
 * (WhatsApp, Messenger, Instagram, Telegram, eBay) and email. Read from the
 * settings Widget Setup and Channel Setup already save.
 */
class SmartBotPlacements
{
    public function __construct(private readonly SegmentAiPolicyService $policies) {}

    /**
     * The places for one bot's "Where it answers" step.
     *
     * @return array{widget: array<string,mixed>|null, segments: array<string, array<string,mixed>>}
     */
    public function forBot(AiChatbot $bot): array
    {
        $workspaceId = (int) $bot->workspace_id;
        $names = AiChatbot::where('workspace_id', $workspaceId)->pluck('name', 'id');
        $widget = ChatWidget::where('workspace_id', $workspaceId)->latest()->first();
        $widgetBot = $widget?->ai_enabled ? $widget->ai_chatbot_id : null;

        $segments = [];
        foreach (WorkspaceAiAnsweringPolicy::SEGMENTS as $segment) {
            $policy = $this->policies->payload($workspaceId, $segment);
            $holder = $policy['mode'] !== 'off' ? $policy['chatbot_id'] : null;
            $channels = $segment === 'email' ? ['email'] : SegmentAiPolicyService::OMNI_CHANNELS;
            $segments[$segment] = $policy + [
                'on' => $holder !== null && (int) $holder === (int) $bot->id,
                'other_bot' => $holder !== null && (int) $holder !== (int) $bot->id ? ($names[$holder] ?? null) : null,
                'connected' => ChannelAccount::where('workspace_id', $workspaceId)->whereIn('channel', $channels)->where('status', 'active')
                    ->distinct()->pluck('channel')->values()->all(),
            ];
        }

        return [
            'widget' => $widget ? [
                'id' => $widget->id,
                'name' => $widget->name,
                'on' => $widgetBot !== null && (int) $widgetBot === (int) $bot->id,
                'other_bot' => $widgetBot !== null && (int) $widgetBot !== (int) $bot->id ? ($names[$widgetBot] ?? null) : null,
            ] : null,
            'segments' => $segments,
        ];
    }

    /**
     * The places each of a workspace's bots answers, for the Smart Bots list.
     *
     * @return array<int, list<string>> bot id => place keys (`widget`, `omni`, `email`)
     */
    public function summary(int $workspaceId): array
    {
        $places = [];
        $widget = ChatWidget::where('workspace_id', $workspaceId)->latest()->first();
        if ($widget?->ai_enabled && $widget->ai_chatbot_id) {
            $places[(int) $widget->ai_chatbot_id][] = 'widget';
        }
        foreach (WorkspaceAiAnsweringPolicy::where('workspace_id', $workspaceId)->where('mode', '!=', 'off')->whereNotNull('chatbot_id')->get() as $policy) {
            $places[(int) $policy->chatbot_id][] = $policy->segment;
        }

        return $places;
    }

    /** Owners and administrators turn AI answering on and off, as on Channel Setup. */
    public function canManage(User $user): bool
    {
        $workspace = Workspace::find((int) ($user->current_workspace_id ?? $user->workspace_id));

        return $workspace !== null && ((int) $workspace->owner_id === (int) $user->id
            || $user->isClientAdministrator()
            || $workspace->members()->where('user_id', $user->id)->wherePivotIn('role', ['owner', 'admin', 'administrator'])->exists());
    }
}
