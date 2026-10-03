<?php

namespace App\Modules\Inbox\Services;

use App\Modules\Shared\Models\Message;

/**
 * Smart Bot reply choices as the channel's own buttons (Smart Bot 2.0,
 * Phase 1.7): WhatsApp reply buttons, Messenger and Instagram quick replies.
 *
 * The reply stays a `text` message with `payload.quick_replies`; the driver
 * asks this class whether the choices fit the channel. They fit only when
 * there are two or three, every label fits the channel's title limit
 * unchanged (a choice is never cut short), and the text fits too. Otherwise,
 * or when the provider refuses the buttons, the driver sends the usual text
 * with the numbered choices. A tap arrives as an ordinary customer message
 * whose text is the label, so the answer path is unchanged: choices are
 * suggested customer text, never actions.
 */
class NativeReplyChoices
{
    /** Provider limits: button/quick-reply title and message text. */
    private const LIMITS = [
        'whatsapp' => ['label' => 20, 'body' => 1024],
        'messenger' => ['label' => 20, 'body' => 2000],
        'instagram' => ['label' => 20, 'body' => 1000],
    ];

    /** @return array{body:string,choices:list<array{id:string,label:string}>}|null */
    public function for(Message $message): ?array
    {
        $limits = self::LIMITS[$message->channel] ?? null;
        if (! $limits || ! config('chatbot.native_choices', true) || $message->sent_by !== 'bot' || $message->type !== 'text') {
            return null;
        }
        $payload = $message->payload ?? [];
        $body = trim((string) ($payload['native_body'] ?? ''));
        $choices = array_values(array_filter((array) ($payload['quick_replies'] ?? []), fn ($choice) => is_array($choice) && is_string($choice['label'] ?? null)));
        if ($body === '' || mb_strlen($body) > $limits['body'] || count($choices) < 2 || count($choices) > 3) {
            return null;
        }

        $native = [];
        foreach ($choices as $index => $choice) {
            $label = trim($choice['label']);
            if ($label === '' || mb_strlen($label) > $limits['label']) {
                return null;
            }
            // The id says which reply and choice a tap came from; the tap itself
            // is answered from its text.
            $native[] = ['id' => 'ai:'.$message->id.':'.mb_substr((string) ($choice['id'] ?? $index), 0, 40), 'label' => $label];
        }

        return ['body' => $body, 'choices' => $native];
    }
}
