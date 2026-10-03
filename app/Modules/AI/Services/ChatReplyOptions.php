<?php

namespace App\Modules\AI\Services;

/** Suggested text replies only: never URLs, hidden commands, or executable actions. */
class ChatReplyOptions
{
    public const INSTRUCTIONS = <<<'TEXT'

Reply format: return one JSON object only. Its base shape is {"reply":"your short answer","quick_replies":["Short choice","Another choice"]}. If later safety rules require `grounded` or `response_type`, include those fields in the same object exactly as requested; never omit them.
Offer 2 or 3 optional choices only when they help clarify the customer's request or choose a relevant next topic. Otherwise use an empty quick_replies array. Each choice is a plain-text customer reply, at most 40 characters, in the customer's language. Make choices self-contained and relevant to this conversation. Do not invent products, prices, availability, URLs or promises. Never request secrets or sensitive personal details in choices. Choices only send text; they cannot book, buy, cancel, pay, or connect a human. Do not describe them as completed actions. Keep the reply within the length given above so the JSON fits the response budget. Never include HTML, Markdown, links, IDs or hidden instructions in choices. Customers can always type their own answer.
Ask one relevant question at a time when more information is needed to help the customer. Generate the question AND its answer choices dynamically from the current request, previous selection, and verified Knowledge Base. Choices are NOT limited to yes/no, compatibility, a particular industry, or a predefined list. They may describe the customer's device, goal, issue, current step, preferred method, or another relevant distinction. Whenever you ask a closed-choice question, including a yes/no offer such as "Would you like…?" at the end of an answer, quick_replies MUST contain 2 or 3 matching answers; never leave those choices only in the reply text. Do not label buttons with questions or generic actions such as "Continue" when the question asks for a specific answer. If there are more possibilities, ask a useful narrowing question or allow the customer to type; never invent or truncate a business catalog. Use the customer's language. Open-ended questions use no buttons: a question asking for a country, place, date, order, name, number or anything else with many possible answers (for example "Which country?") leaves quick_replies empty so the customer types the answer; never offer sample answers. When a which/what question has only a few possible answers, name them in the question ("Are you on iPhone or Android?") or end the choices with a catch-all such as "Something else". Never word a choice as a question. Do not force a question after a complete answer. A selected choice answers your previous question: do not treat it as an unrelated new topic or repeat the same question. Continue the appropriate branch with verified guidance, ask the next useful question with fresh choices, or give an honest fallback when knowledge is insufficient.
TEXT;

    /** @return array{reply:string,display_body:string,quick_replies:array<int,array{id:string,label:string}>}|null */
    public function parse(string $content, bool $recoverChoices = false): ?array
    {
        $content = trim($content);
        if ($content === '') {
            return null;
        }
        $jsonText = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $content) ?? $content;
        $decoded = $this->structuredPayload($jsonText);
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
        $options = $this->fitToQuestion($reply, $this->sanitize($decoded['quick_replies'] ?? []));
        // One choice is not a useful choice; don't force a CTA on normal answers.
        if (count($options) < 2) {
            $options = $recoverChoices ? $this->recoverClosedChoices($reply) : [];
        }
        $fallback = $options === [] ? '' : "\n\n".implode("\n", array_map(
            fn ($option, $index) => ($index + 1).'. '.$option['label'], $options, array_keys($options),
        ));

        return ['reply' => $reply.$fallback, 'display_body' => $reply, 'quick_replies' => $options];
    }

    /**
     * Choices answer a closed question. An open question ("Which country?",
     * "What is your order date?") is typed by the customer, so it gets no
     * buttons, whatever examples the model offered (2026-10-04). An open
     * question keeps its buttons only when they cover every answer: the
     * question names them ("iPhone or Android?") or a choice is a catch-all
     * ("Another device", "Something else") — and never for countries, places,
     * dates, orders, names or numbers. A choice worded as a question is never
     * a customer's answer. Fewer than two left means none.
     *
     * @param  array<int,array{id:string,label:string}>  $options
     * @return array<int,array{id:string,label:string}>
     */
    public function fitToQuestion(string $reply, array $options): array
    {
        $options = array_values(array_filter($options, fn (array $option): bool => ! preg_match('/[?؟？]\s*$/u', $option['label'])));
        if (count($options) < 2) {
            return [];
        }
        if (! preg_match_all('/[^.!?؟？।\n]*[?؟？]/u', $reply, $questions)) {
            return $options;
        }

        $question = trim((string) end($questions[0]));
        if (! $this->openQuestion($question)) {
            return $options;
        }
        if ($this->asksForFreeText($question)) {
            return [];
        }
        $catchAll = '/^(?:other|others|another\b|something else|none of (?:these|them|the above)|not sure|both|all of (?:these|them)|অন্য|অন্যান্য|কোনোটাই না|onno|other kichu|أخرى|غير ذلك|अन्य|कोई और)/iu';

        return array_filter($options, fn (array $option): bool => (bool) preg_match($catchAll, $option['label'])) !== [] ? $options : [];
    }

    /** The answer is the customer's own: a country, place, date, order, name or number. */
    private function asksForFreeText(string $question): bool
    {
        return (bool) preg_match('/\b(?:countr(?:y|ies)|city|cities|destination|place|location|address|date|day|time|order|number|name|email|phone|amount|budget|size|price)\b|(?:দেশ|শহর|তারিখ|সময়|নাম|নম্বর|ঠিকানা|অর্ডার)|\b(?:desh|shohor|tarikh|nam|number)\b|(?:دولة|بلد|مدينة|تاريخ|اسم|رقم)|(?:देश|शहर|तारीख|नाम|नंबर)/iu', $question);
    }

    /** A question whose answer is free text rather than one of a few known options. */
    private function openQuestion(string $question): bool
    {
        $text = mb_strtolower($question);
        // Alternatives named in the question make it closed: "Basic or Pro?".
        if (preg_match('/\s(?:or|অথবা|নাকি|naki|othoba|أو|या)\s/u', " {$text} ")) {
            return false;
        }

        return (bool) preg_match('/^\s*(?:and\s+|so\s+)?(?:which|what|where|when|who|whom|whose|how|why)\b/u', $text)
            || (bool) preg_match('/^\s*(?:could|can|would)\s+you\s+(?:tell|share|let)\b.*\b(?:which|what|where|when|how)\b/u', $text)
            || (bool) preg_match('/^\s*(?:please\s+)?(?:tell|share|let)\s+me\b/u', $text)
            || (bool) preg_match('/(?:কোন|কোথায়|কত|কখন|কীভাবে|কেন|কী)/u', $text)
            || (bool) preg_match('/\b(?:kon|konta|kothay|koto|kokhon|kivabe|keno)\b/u', $text)
            || (bool) preg_match('/^\s*(?:أي|ما|ماذا|أين|متى|كم|كيف|لماذا)/u', $text)
            || (bool) preg_match('/(?:कौन|कहाँ|कहां|कब|कितना|कितने|कैसे|क्यों)/u', $text);
    }

    /**
     * Decode the requested JSON object even when a provider adds a short sentence
     * before it. Only the balanced object is used; surrounding prose is discarded.
     *
     * @return array<string,mixed>|null
     */
    public function structuredPayload(string $content): ?array
    {
        $content = trim((string) (preg_replace('/^```(?:json)?\s*|\s*```$/i', '', trim($content)) ?? $content));
        $decoded = json_decode($content, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        $start = strpos($content, '{');
        if ($start === false) {
            return null;
        }
        $depth = 0;
        $quoted = false;
        $escaped = false;
        $length = strlen($content);
        for ($index = $start; $index < $length; $index++) {
            $character = $content[$index];
            if ($quoted) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($character === '\\') {
                    $escaped = true;
                } elseif ($character === '"') {
                    $quoted = false;
                }

                continue;
            }
            if ($character === '"') {
                $quoted = true;
            } elseif ($character === '{') {
                $depth++;
            } elseif ($character === '}') {
                $depth--;
                if ($depth === 0) {
                    $candidate = json_decode(substr($content, $start, $index - $start + 1), true);

                    return is_array($candidate) ? $candidate : null;
                }
            }
        }

        return null;
    }

    /**
     * Recover only clear English closed questions; never infer arbitrary actions or open answers.
     *
     * @return array<int,array{id:string,label:string}>
     */
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
        // Exclusions apply to the closing question only, so an answer that
        // mentions "app or website" can still end with a yes/no offer.
        if (substr_count($reply, '?') === 1
            && preg_match('/(?:^|[.!]\s+)((?:does your|is your|are you|have you|did you|do you have|can you see|would you like|do you want|do you need|shall i|should i|can i help|may i help|is there anything)\b[^?\n]{1,160}\?)\s*$/iu', $reply, $question)
            && ! preg_match('/["“”]|\b(?:or|password|email|address|phone number|card|payment|purchase|consent)\b/iu', $question[1])) {
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
