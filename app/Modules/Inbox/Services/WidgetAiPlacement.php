<?php

namespace App\Modules\Inbox\Services;

use App\Modules\Inbox\Models\ChatWidget;

/**
 * Whether a Smart Bot answers a website widget, and which one. Widget Setup
 * and the bot page write it the same way: the widget keeps its toggle and
 * bot, and its webchat channel account carries `ai_chatbot_id`, the field
 * AutoReplyListener reads.
 */
class WidgetAiPlacement
{
    /** @return array<string, int> */
    public static function meta(bool $enabled, mixed $chatbotId): array
    {
        return $enabled && ! empty($chatbotId) ? ['ai_chatbot_id' => (int) $chatbotId] : [];
    }

    /** Let `$chatbotId` answer the widget, or nobody with null. The widget's AI hours stay as they are. */
    public function apply(ChatWidget $widget, ?int $chatbotId): void
    {
        $widget->update(['ai_enabled' => $chatbotId !== null, 'ai_chatbot_id' => $chatbotId ?? $widget->ai_chatbot_id]);
        $widget->channelAccount?->update(['meta_json' => self::meta($chatbotId !== null, $chatbotId)]);
    }
}
