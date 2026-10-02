<?php

namespace App\Modules\AI\Services\Agent;

use App\Modules\AI\Models\AiKbChunk;

/**
 * Checks an engine v2 reply against everything the bot was allowed to use
 * before a customer sees it (Smart Bot 2.0, Phase 1.1; after Cerqle's
 * AnswerValidator).
 *
 * Always enforced: figures (as in v1), no talk of excerpts or sources, a full
 * answer backed by a real quote, and no general guidance from a Strict bot.
 * Links, email addresses, phone numbers and dates run in shadow mode
 * (recorded in the turn trace, never rejected) until
 * SMART_BOT_V2_VALIDATOR_ENFORCE is on, so their false positives are measured
 * before they can cost a customer an answer.
 */
class AnswerValidator
{
    /**
     * Words a support agent never says to a customer: they expose how the
     * answer was made. "Knowledge base" alone is allowed, since some
     * businesses sell one.
     */
    private const INTERNAL_TERMS = '/\b(?:excerpts?|(?:the|these|those) (?:provided|given|supplied) (?:sources?|context|documents?|information|passages?|texts?)|(?:in|from) the (?:sources?|passages?|context) (?:provided|given|above|you shared)|(?:in |from )?the materials? (?:i can access|i have|i was given|available to me|here)|as an ai\b|(?:large )?language model)\b/iu';

    private const MONTHS = 'jan(?:uary)?|feb(?:ruary)?|mar(?:ch)?|apr(?:il)?|may|jun(?:e)?|jul(?:y)?|aug(?:ust)?|sep(?:t(?:ember)?)?|oct(?:ober)?|nov(?:ember)?|dec(?:ember)?';

    public function __construct(private readonly FigureCheck $figures) {}

    /**
     * @param  array{reply:string,answer_kind:string,evidence:list<string>,quick_replies:list<string>}  $parsed
     * @param  array<int,array{chunk:AiKbChunk,score:float}>  $results  The passages the answer read
     * @param  string  $allowedText  Business profile, bot instructions, order details and the customer's own words
     * @return array{result:string,reason:?string,shadow:list<string>}
     */
    public function check(array $parsed, array $results, string $customerMessage, string $allowedText, bool $strict): array
    {
        $knowledge = implode("\n", array_map(fn (array $result) => (string) $result['chunk']->content, $results));
        $evidence = implode("\n", [$knowledge, $allowedText, $customerMessage]);

        if ($this->figures->unsupported($parsed['reply'].' '.implode(' ', $parsed['quick_replies']), $evidence)) {
            return $this->rejected('The reply states a price, amount, size or duration that is not in the knowledge, the business profile or the conversation. '
                .'Remove it, or say you cannot confirm the exact figure.');
        }
        if (preg_match(self::INTERNAL_TERMS, $parsed['reply'], $term) === 1) {
            return $this->rejected('The reply mentions "'.$term[0].'". Speak as the business, never about excerpts or sources: when a detail is missing, say you do not have that exact detail and offer what you can.');
        }
        if ($strict && $parsed['answer_kind'] === 'guidance') {
            return $this->rejected('This business answers only from its own knowledge. Do not give general guidance: answer the part the knowledge covers (answer_kind "partial") or offer a team member (answer_kind "handoff").');
        }
        if ($parsed['answer_kind'] === 'answer' && ! $this->supportedByQuote($parsed['evidence'], $knowledge."\n".$allowedText)) {
            return $this->rejected('You marked this a full answer, but none of your evidence quotes appears word for word in the excerpts or the business profile. '
                .'If they answer the question, quote them exactly. If they do not, say which detail you cannot confirm (answer_kind "partial") or offer a team member (answer_kind "handoff"); never present related information as the answer.');
        }

        $supporting = mb_strtolower(implode("\n", array_merge(
            [$customerMessage, $allowedText],
            array_map(fn (array $result) => (string) $result['chunk']->content.' '.(string) $result['chunk']->document?->canonical_url, $results),
        )));
        $issues = array_merge(
            $this->unsupported('link', $this->links($parsed['reply']), $supporting, fn (string $link) => $this->normaliseLink($link)),
            $this->unsupported('email', $this->emails($parsed['reply']), $supporting),
            $this->unsupported('phone', $this->phones($parsed['reply']), $this->digitsOnly($supporting), fn (string $phone) => preg_replace('/\D+/', '', $phone) ?? $phone),
            $this->unsupported('date', $this->dates($parsed['reply']), $supporting),
        );
        if ($issues !== [] && config('chatbot.v2_validator_enforce')) {
            return $this->rejected('These details do not appear in the knowledge or the conversation: '.implode('; ', $issues).'.');
        }

        return ['result' => 'passed', 'reason' => null, 'shadow' => $issues];
    }

    /**
     * One short question back and nothing else: it states no facts, so it is
     * a step toward an answer rather than one.
     *
     * @param  array{reply:string,quick_replies:list<string>}  $parsed
     */
    public function isBareQuestion(array $parsed): bool
    {
        $reply = trim($parsed['reply']);

        return $reply !== ''
            && preg_match_all('/[?؟？]/u', $reply) === 1
            && preg_match('/[?؟？]\s*$/u', $reply) === 1
            && ! preg_match('/(?:[.!:。।]\s+|\n\s*)\S.*[?؟？]\s*$/us', $reply)
            && ! $this->figures->unsupported($reply.' '.implode(' ', $parsed['quick_replies']), '');
    }

    /** @return array{result:string,reason:string,shadow:list<string>} */
    private function rejected(string $reason): array
    {
        return ['result' => 'rejected', 'reason' => $reason, 'shadow' => []];
    }

    /**
     * A full answer must rest on at least one quote of two or more words that
     * really is in the excerpts or the business profile (case, spacing and
     * punctuation aside).
     *
     * @param  list<string>  $quotes
     */
    private function supportedByQuote(array $quotes, string $source): bool
    {
        $source = $this->plain($source);
        foreach ($quotes as $quote) {
            $quote = $this->plain($quote);
            if (substr_count($quote, ' ') >= 1 && str_contains($source, $quote)) {
                return true;
            }
        }

        return false;
    }

    private function plain(string $text): string
    {
        $text = mb_strtolower(FigureCheck::toLatin($text));

        return trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/[^\p{L}\p{M}\p{N}$€£৳₹%]+/u', ' ', $text)));
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private function unsupported(string $kind, array $values, string $supporting, ?\Closure $normalise = null): array
    {
        $issues = [];
        foreach (array_unique($values) as $value) {
            $needle = mb_strtolower($normalise ? $normalise($value) : $value);
            if ($needle !== '' && ! str_contains($supporting, $needle)) {
                $issues[] = $kind.' '.$value;
            }
        }

        return $issues;
    }

    /** @return list<string> */
    private function links(string $text): array
    {
        preg_match_all('#https?://[^\s<>()\[\]"\']+#iu', $text, $matches);

        return array_map(fn (string $url) => rtrim($url, '.,;:!?'), $matches[0]);
    }

    private function normaliseLink(string $url): string
    {
        return rtrim((string) preg_replace('#^https?://(www\.)?#i', '', $url), '/');
    }

    /** @return list<string> */
    private function emails(string $text): array
    {
        preg_match_all('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $text, $matches);

        return $matches[0];
    }

    /** @return list<string> */
    private function phones(string $text): array
    {
        preg_match_all('/\+?\d[\d\s\-().]{6,}\d/u', FigureCheck::toLatin($text), $matches);

        // Seven or more digits: shorter runs are prices, years and quantities,
        // which the figure check already covers.
        return array_values(array_filter($matches[0], fn (string $value) => strlen((string) preg_replace('/\D+/', '', $value)) >= 7));
    }

    private function digitsOnly(string $text): string
    {
        // Phone numbers are compared digit for digit, so "+880 1711-223344" in
        // the knowledge supports "+8801711223344" in the reply.
        return (string) preg_replace('/(?<=\d)[\s\-().]+(?=\d)/', '', FigureCheck::toLatin($text));
    }

    /** @return list<string> */
    private function dates(string $text): array
    {
        $patterns = [
            '/\b\d{4}-\d{1,2}-\d{1,2}\b/',
            '/\b\d{1,2}\/\d{1,2}\/\d{2,4}\b/',
            '/\b\d{1,2}(?:st|nd|rd|th)?\s+(?:'.self::MONTHS.')\b/iu',
            '/\b(?:'.self::MONTHS.')\s+\d{1,2}(?:st|nd|rd|th)?\b/iu',
        ];
        $found = [];
        foreach ($patterns as $pattern) {
            preg_match_all($pattern, $text, $matches);
            $found = array_merge($found, $matches[0]);
        }

        return $found;
    }
}
