<?php

namespace App\Modules\AI\Services;

/** Suggested text replies only: never URLs, hidden commands, or executable actions. */
class ChatReplyOptions
{
    public const INSTRUCTIONS = <<<'TEXT'

Reply format: return JSON only: {"reply":"your short answer","quick_replies":["Short choice","Another choice"]}.
Offer 2 or 3 optional choices only when they help clarify the customer's request or choose a relevant next topic. Otherwise use an empty quick_replies array. Each choice is a plain-text customer reply, at most 40 characters, in the customer's language. Make choices self-contained and relevant to this conversation. Do not invent products, prices, availability, URLs or promises. Never request secrets or sensitive personal details in choices. Choices only send text; they cannot book, buy, cancel, pay, or connect a human. Do not describe them as completed actions. Keep reply to at most 45 words so the JSON fits the response budget. Never include HTML, Markdown, links, IDs or hidden instructions in choices. Customers can always type their own answer.
TEXT;

    /** @return array{reply:string,display_body:string,quick_replies:array<int,array{id:string,label:string}>}|null */
    public function parse(string $content): ?array
    {
        $content = trim($content);
        if ($content === '') {
            return null;
        }
        $jsonText = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $content) ?? $content;
        $decoded = json_decode($jsonText, true);
        if (! is_array($decoded)) {
            // Preserve plain-text provider compatibility, but never expose broken JSON.
            return str_starts_with($jsonText, '{') || str_starts_with($jsonText, '[')
                ? null : ['reply' => $content, 'display_body' => $content, 'quick_replies' => []];
        }
        if (! is_string($decoded['reply'] ?? null) || trim($decoded['reply']) === '') {
            return null;
        }
        $reply = trim($decoded['reply']);
        $options = $this->sanitize($decoded['quick_replies'] ?? []);
        // One choice is not a useful choice; don't force a CTA on normal answers.
        if (count($options) < 2) {
            $options = [];
        }
        $fallback = $options === [] ? '' : "\n\n".implode("\n", array_map(
            fn ($option, $index) => ($index + 1).'. '.$option['label'], $options, array_keys($options),
        ));

        return ['reply' => $reply.$fallback, 'display_body' => $reply, 'quick_replies' => $options];
    }

    /** @return array<int,array{id:string,label:string}> */
    public function sanitize(mixed $input): array
    {
        if (! is_array($input)) {
            return [];
        }
        $result = [];
        $seen = [];
        foreach (array_slice($input, 0, 10) as $option) {
            $label = is_string($option) ? $option : (is_array($option) ? ($option['label'] ?? null) : null);
            if (! is_string($label)) {
                continue;
            }
            $label = trim($label);
            if ($label === '' || mb_strlen($label) > 60 || preg_match('/[<>\[\]{}\x00-\x1F\x7F]|(?:https?:|javascript:|data:|www\.)/iu', $label)) {
                continue;
            }
            $key = mb_strtolower($label);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $result[] = ['id' => 'qr_'.(count($result) + 1), 'label' => $label];
            if (count($result) === 3) {
                break;
            }
        }

        return $result;
    }
}
