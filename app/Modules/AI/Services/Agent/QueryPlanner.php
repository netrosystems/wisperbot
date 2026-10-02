<?php

namespace App\Modules\AI\Services\Agent;

use App\Modules\AI\Services\Llm\LlmTurn;

/**
 * Turns a follow-up into a question that can be searched on its own (Smart
 * Bot 2.0, Phase 1.1; after Cerqle's QueryPlanner).
 *
 * "What about Italy?" or "how much is it?" finds nothing in the knowledge on
 * its own. Only follow-ups are planned, so a plain question costs no extra
 * model call; a planner that fails or answers in the wrong shape degrades to
 * a heuristic instead of failing the answer. It is a step of the same turn,
 * so it is never charged on its own.
 */
class QueryPlanner
{
    public const MAX_QUERIES = 3;

    /** "and Italy?", "how much?", "what about it": short enough to need context. */
    private const SHORT_FOLLOW_UP_WORDS = 4;

    /**
     * Words that only make sense with what came before. Latin script only:
     * the short-message rule catches follow-ups in every language, including
     * romanised Bangla.
     */
    private const REFERENTIAL = '/\b(it|its|that|this|they|them|those|these|there|same|what about|how about|also|too|else|eta|oita|seta|tahole|tahle)\b/iu';

    /** @param array<int,array{role:string,content:string}> $history */
    public function needsPlanning(string $message, array $history): bool
    {
        $hasEarlierQuestion = collect($history)->contains(fn (array $turn) => $turn['role'] === 'user');
        if (! $hasEarlierQuestion) {
            return false;
        }
        $words = preg_split('/\s+/u', trim($message)) ?: [];

        return count($words) <= self::SHORT_FOLLOW_UP_WORDS || preg_match(self::REFERENTIAL, $message) === 1;
    }

    /**
     * @param  array<int,array{role:string,content:string}>  $history
     * @return array{standalone:string,queries:list<string>,planned:bool}
     */
    public function plan(LlmTurn $turn, string $message, array $history): array
    {
        try {
            $response = $turn->step($this->messages($message, $history), $this->options(), 'plan');
            $parsed = $this->parse($response->content);
            if ($parsed !== null) {
                return $parsed + ['planned' => true];
            }
        } catch (\Throwable) {
            // A planner that fails must not cost the customer their answer.
        }

        return $this->heuristic($message, $history);
    }

    /**
     * Without a planner: the previous customer question plus this one, which
     * keeps the subject of "what about Italy?" in the search.
     *
     * @param  array<int,array{role:string,content:string}>  $history
     * @return array{standalone:string,queries:list<string>,planned:bool}
     */
    public function heuristic(string $message, array $history): array
    {
        $previous = collect($history)->last(fn (array $turn) => $turn['role'] === 'user');
        $combined = trim(($previous['content'] ?? '').' '.$message);

        return ['standalone' => $message, 'queries' => array_values(array_unique(array_filter([$combined, $message]))), 'planned' => false];
    }

    /** @return array{standalone:string,queries:list<string>}|null */
    public function parse(?string $raw): ?array
    {
        $decoded = json_decode(trim((string) $raw), true);
        if (! is_array($decoded)) {
            $start = strpos((string) $raw, '{');
            $end = strrpos((string) $raw, '}');
            $decoded = $start !== false && $end !== false ? json_decode(substr((string) $raw, $start, $end - $start + 1), true) : null;
        }
        if (! is_array($decoded) || ! is_string($decoded['standalone_question'] ?? null) || trim($decoded['standalone_question']) === '') {
            return null;
        }
        $queries = [];
        foreach ((array) ($decoded['search_queries'] ?? []) as $query) {
            if (is_string($query) && trim($query) !== '') {
                $queries[] = mb_substr(trim($query), 0, 200);
            }
        }
        $standalone = mb_substr(trim($decoded['standalone_question']), 0, 500);

        return [
            'standalone' => $standalone,
            'queries' => array_slice(array_values(array_unique(array_merge([$standalone], $queries))), 0, self::MAX_QUERIES),
        ];
    }

    /**
     * @param  array<int,array{role:string,content:string}>  $history
     * @return array<int,array{role:string,content:string}>
     */
    private function messages(string $message, array $history): array
    {
        $transcript = collect($history)->take(-6)
            ->map(fn (array $turn) => ($turn['role'] === 'user' ? 'Customer: ' : 'Agent: ').mb_substr($turn['content'], 0, 600))
            ->implode("\n");

        return [
            ['role' => 'system', 'content' => 'You prepare a knowledge-base search for a customer support agent. '
                .'Given the conversation and the customer\'s latest message, write the latest message as a standalone question '
                .'that makes sense without the conversation, in the customer\'s language, and up to three short search queries '
                .'that would find the answer. If the customer did not write in English, make one of the queries English. '
                .'Reply with one JSON object only: {"standalone_question": "...", "search_queries": ["..."]}.'],
            ['role' => 'user', 'content' => "Conversation so far:\n{$transcript}\n\nLatest message: {$message}"],
        ];
    }

    /** @return array<string,mixed> */
    private function options(): array
    {
        return [
            'max_tokens' => 200,
            'temperature' => 0.0,
            'json_object' => true,
            'json_schema' => ['name' => 'smart_bot_search_plan', 'strict' => true, 'schema' => [
                'type' => 'object',
                'properties' => [
                    'standalone_question' => ['type' => 'string'],
                    'search_queries' => ['type' => 'array', 'items' => ['type' => 'string']],
                ],
                'required' => ['standalone_question', 'search_queries'],
                'additionalProperties' => false,
            ]],
        ];
    }
}
