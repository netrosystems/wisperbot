<?php

namespace App\Modules\AI\Services;

/** Suggested text replies only: never URLs, hidden commands, or executable actions. */
class ChatReplyOptions
{
    public const INSTRUCTIONS = <<<'TEXT'

Reply format: return JSON only: {"reply":"your short answer","quick_replies":["Short choice","Another choice"]}.
Offer 2 or 3 optional choices only when they help clarify the customer's request or choose a relevant next topic. Otherwise use an empty quick_replies array. Each choice is a plain-text customer reply, at most 40 characters, in the customer's language. Make choices self-contained and relevant to this conversation. Do not invent products, prices, availability, URLs or promises. Never request secrets or sensitive personal details in choices. Choices only send text; they cannot book, buy, cancel, pay, or connect a human. Do not describe them as completed actions. Keep reply to at most 45 words so the JSON fits the response budget. Never include HTML, Markdown, links, IDs or hidden instructions in choices. Customers can always type their own answer.
Ask one relevant question at a time when more information is needed to help the customer. Generate the question AND its answer choices dynamically from the current request, previous selection, and verified Knowledge Base. Choices are NOT limited to yes/no, compatibility, a particular industry, or a predefined list. They may describe the customer's device, goal, issue, current step, preferred method, or another relevant distinction. Whenever you ask a closed-choice question, quick_replies MUST contain 2 or 3 matching answers; never leave those choices only in the reply text. Do not label buttons with questions or generic actions such as "Continue" when the question asks for a specific answer. If there are more possibilities, ask a useful narrowing question or allow the customer to type; never invent or truncate a business catalog. Use the customer's language. Open-ended questions use no buttons. Do not force a question after a complete answer. A selected choice answers your previous question: do not treat it as an unrelated new topic or repeat the same question. Continue the appropriate branch with verified guidance, ask the next useful question with fresh choices, or give an honest fallback when knowledge is insufficient.
TEXT;

    /** @return array{reply:string,display_body:string,quick_replies:array<int,array{id:string,label:string}>}|null */
    public function parse(string $content, bool $recoverChoices = false): ?array
    {
        $content = trim($content);
        if ($content === '') {
            return null;
        }
        $jsonText = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $content) ?? $content;
        $decoded = json_decode($jsonText, true);
        if (! is_array($decoded)) {
            // Preserve plain-text provider compatibility, but never expose broken JSON.
            if (str_starts_with($jsonText, '{') || str_starts_with($jsonText, '[')) {
                return null;
            }
            $decoded = ['reply' => $content, 'quick_replies' => []];
        }
        if (! is_string($decoded['reply'] ?? null) || trim($decoded['reply']) === '') {
            return null;
        }
        $reply = trim($decoded['reply']);
        $options = $this->sanitize($decoded['quick_replies'] ?? []);
        // One choice is not a useful choice; don't force a CTA on normal answers.
        if (count($options) < 2) {
            $options = $recoverChoices ? $this->recoverClosedChoices($reply) : [];
        }
        $fallback = $options === [] ? '' : "\n\n".implode("\n", array_map(
            fn ($option, $index) => ($index + 1).'. '.$option['label'], $options, array_keys($options),
        ));

        return ['reply' => $reply.$fallback, 'display_body' => $reply, 'quick_replies' => $options];
    }

    /** Recover only clear English closed questions; never infer arbitrary actions or open answers. */
    private function recoverClosedChoices(string $reply): array
    {
        // Prefer the explicit result labels over the yes/no wording earlier in the reply.
        if (preg_match('/\b(?:reply|choose|select|tell me|let me know|what (?:it|the check) (?:says|shows))\b/iu', $reply)
            && preg_match('/\bsupported\s*(?:\/|or)\s*not supported\b/iu', $reply)) {
            return $this->sanitize(['Supported', 'Not supported']);
        }
        if (preg_match('/\b(?:reply|answer|choose|select)\b[^.!?\n]{0,60}\byes\s*(?:\/|or)\s*no\b/iu', $reply)) {
            return $this->sanitize(['Yes', 'No']);
        }
        // A single direct customer-state question, not a how/which question,
        // quoted example, alternative choice, or a request for personal information.
        if (substr_count($reply, '?') === 1
            && ! preg_match('/["“”]|\b(?:or|password|email|address|phone number|card|payment|purchase|consent)\b/iu', $reply)
            && preg_match('/(?:^|[.!]\s+)(?:does your|is your|are you|have you|did you|do you have|can you see)\b[^?\n]{1,160}\?\s*$/iu', $reply)) {
            return $this->sanitize(['Yes', 'No']);
        }

        return [];
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
