<?php

namespace App\Modules\AI\Services;

use App\Modules\AI\Models\AiChatbot;
use Illuminate\Support\Str;

/**
 * Client-written starter questions: shown as options in customer chat and
 * answered with the saved text, never by AI and never for credits.
 */
class StarterQuestions
{
    public const MAX_ITEMS = 5;

    public const MAX_QUESTION_LENGTH = 80;

    public const MAX_ANSWER_LENGTH = 1000;

    /**
     * Case, punctuation and spacing are ignored. Combining marks are kept, so
     * Bengali words that differ only by a vowel sign stay different.
     */
    public function normalize(string $text): string
    {
        if (class_exists(\Normalizer::class)) {
            $text = (string) (\Normalizer::normalize($text, \Normalizer::FORM_C) ?: $text);
        }

        return trim((string) preg_replace('/[^\p{L}\p{M}\p{N}]+/u', ' ', mb_strtolower($text)));
    }

    /** @return array<int,array{id:string,question:string,answer:string}> */
    public function active(AiChatbot $bot): array
    {
        if (! $bot->starter_questions_enabled) {
            return [];
        }

        return array_values(array_filter(
            is_array($bot->starter_questions) ? $bot->starter_questions : [],
            fn ($item): bool => is_array($item)
                && is_string($item['id'] ?? null)
                && trim((string) ($item['question'] ?? '')) !== ''
                && trim((string) ($item['answer'] ?? '')) !== '',
        ));
    }

    /** @return array{id:string,question:string,answer:string}|null */
    public function match(AiChatbot $bot, string $message): ?array
    {
        $normalized = $this->normalize($message);
        if ($normalized === '') {
            return null;
        }

        foreach ($this->active($bot) as $item) {
            if ($this->normalize($item['question']) === $normalized) {
                return $item;
            }
        }

        return null;
    }

    /** @return array<int,array{id:string,label:string}> */
    public function publicLabels(AiChatbot $bot): array
    {
        return array_map(
            fn (array $item): array => ['id' => $item['id'], 'label' => trim($item['question'])],
            $this->active($bot),
        );
    }

    /**
     * Keeps the ids of existing items so the SDK can key its UI on them, and
     * gives new items a fresh id.
     *
     * @param  array<int,array<string,mixed>>  $items
     * @param  array<int,array<string,mixed>>|null  $existing
     * @return array<int,array{id:string,question:string,answer:string}>
     */
    public function prepareForStorage(array $items, ?array $existing): array
    {
        $knownIds = array_filter(array_map(fn (array $item) => $item['id'] ?? null, $existing ?? []), 'is_string');

        return array_map(function (array $item) use ($knownIds): array {
            $id = is_string($item['id'] ?? null) && in_array($item['id'], $knownIds, true)
                ? $item['id']
                : 'sq_'.Str::lower(Str::random(8));

            return [
                'id' => $id,
                'question' => trim((string) $item['question']),
                'answer' => trim(str_replace("\r\n", "\n", (string) $item['answer'])),
            ];
        }, array_slice($items, 0, self::MAX_ITEMS));
    }
}
