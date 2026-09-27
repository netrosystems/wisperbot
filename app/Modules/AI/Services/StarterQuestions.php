<?php

namespace App\Modules\AI\Services;

use App\Listeners\AutoReplyListener;
use App\Modules\Inbox\Models\ChatWidget;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Client-written starter questions for the website widget and customer SDK:
 * shown as options at the top of the chat and answered with the saved text,
 * never by AI and never for credits. They belong to the widget (Widget Setup),
 * so they work whether or not a Smart Bot is answering.
 */
class StarterQuestions
{
    public const MAX_ITEMS = 5;

    public const MAX_QUESTION_LENGTH = 80;

    public const MAX_ANSWER_LENGTH = 1000;

    /**
     * Request validation rules for `starter_questions_enabled` and `starter_questions`.
     *
     * @return array<string, array<int, string>>
     */
    public static function rules(): array
    {
        return [
            'starter_questions_enabled' => ['boolean'],
            'starter_questions' => ['nullable', 'array', 'max:'.self::MAX_ITEMS],
            'starter_questions.*' => ['array'],
            'starter_questions.*.id' => ['nullable', 'string', 'max:32'],
            // Same safety as AI reply options: a label, never markup or a link.
            'starter_questions.*.question' => ['required', 'string', 'max:'.self::MAX_QUESTION_LENGTH, 'not_regex:/[<>\[\]{}\x00-\x1F\x7F]|(?:https?:|javascript:|data:|www\.)/iu'],
            'starter_questions.*.answer' => ['required', 'string', 'max:'.self::MAX_ANSWER_LENGTH, 'not_regex:/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u'],
        ];
    }

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
    public function active(ChatWidget $widget): array
    {
        if (! $widget->starter_questions_enabled) {
            return [];
        }

        return array_values(array_filter(
            is_array($widget->starter_questions) ? $widget->starter_questions : [],
            fn ($item): bool => is_array($item)
                && is_string($item['id'] ?? null)
                && trim((string) ($item['question'] ?? '')) !== ''
                && trim((string) ($item['answer'] ?? '')) !== '',
        ));
    }

    /** @return array{id:string,question:string,answer:string}|null */
    public function match(ChatWidget $widget, string $message): ?array
    {
        $normalized = $this->normalize($message);
        if ($normalized === '') {
            return null;
        }

        foreach ($this->active($widget) as $item) {
            if ($this->normalize($item['question']) === $normalized) {
                return $item;
            }
        }

        return null;
    }

    /** @return array<int,array{id:string,label:string}> */
    public function publicLabels(ChatWidget $widget): array
    {
        return array_map(
            fn (array $item): array => ['id' => $item['id'], 'label' => trim($item['question'])],
            $this->active($widget),
        );
    }

    /**
     * Each question must be distinct once case and punctuation are ignored, so
     * a typed message maps to one answer, and must not be a handover phrase,
     * which would reach a person instead of the saved answer.
     *
     * @param  array<int,array<string,mixed>>  $items
     *
     * @throws ValidationException
     */
    public function assertUsable(array $items): void
    {
        $errors = [];
        $seen = [];
        foreach ($items as $index => $item) {
            $question = (string) ($item['question'] ?? '');
            $normalized = $this->normalize($question);
            if ($normalized === '') {
                $errors["starter_questions.{$index}.question"] = 'Use words or numbers in the question.';
            } elseif (isset($seen[$normalized])) {
                $errors["starter_questions.{$index}.question"] = 'This question is already in the list.';
            } else {
                foreach (AutoReplyListener::HANDOVER_PHRASES as $phrase) {
                    if (str_contains(mb_strtolower($question), $phrase)) {
                        $errors["starter_questions.{$index}.question"] = 'This wording asks for a person, so it would open a handover instead of your answer.';
                        break;
                    }
                }
            }
            $seen[$normalized] = true;
            if (trim((string) ($item['answer'] ?? '')) === '') {
                $errors["starter_questions.{$index}.answer"] = 'Add an answer.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
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
