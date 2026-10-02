<?php

namespace App\Modules\AI\Services\Agent;

use App\Modules\AI\Models\AiKbChunk;
use App\Modules\AI\Services\Llm\LlmTurn;

/**
 * Does the knowledge say what the reply tells the customer? (Smart Bot 2.0,
 * Phase 1.1; after Cerqle's SupportCheck.)
 *
 * The evidence check proves a quote is real, not that it answers the
 * question. Asked "do you handle my customer messages for me?", Cerqle's bot
 * quoted "Free 'Done For You' service" and answered "Yes": a real quote, and a
 * claim the knowledge never made. This small step of the same turn reads the
 * question, the passages the answer read and the reply, and says whether the
 * reply's answer is stated there. Paraphrase, translation and extra details
 * the passages state are fine; confirming, denying or promising what they do
 * not settle is not.
 *
 * Only replies that state facts are checked (answer, partial). A check that
 * fails or answers in the wrong shape lets the reply through: the figure and
 * evidence checks still apply, and the customer never loses an answer
 * because the checker was unavailable.
 */
class SupportCheck
{
    /** Kinds that tell the customer something as fact. */
    public const KINDS = ['answer', 'partial'];

    private const KNOWLEDGE_CHARS = 60000;

    private const DETAILS_CHARS = 3000;

    /**
     * @param  array{reply:string,answer_kind:string,evidence:list<string>}  $parsed
     * @param  array<int,array{chunk:AiKbChunk,score:float}>  $results  The passages the answer read, numbered as it saw them
     * @param  array<int,array{role:string,content:string}>  $history
     * @param  string  $details  Business profile, order details and the bot's instructions
     * @return array{supported:bool,unsupported:?string,checked:bool}
     */
    public function check(LlmTurn $turn, array $parsed, array $results, string $message, array $history, string $details): array
    {
        if (! in_array($parsed['answer_kind'], self::KINDS, true)) {
            return ['supported' => true, 'unsupported' => null, 'checked' => false];
        }

        try {
            $response = $turn->step($this->messages($parsed, $results, $message, $history, $details), $this->options(), 'verify');
            $verdict = $this->parse($response->content);
        } catch (\Throwable) {
            $verdict = null;
        }

        return $verdict === null
            ? ['supported' => true, 'unsupported' => null, 'checked' => false]
            : $verdict + ['checked' => true];
    }

    /** @return array{supported:bool,unsupported:?string}|null */
    public function parse(?string $raw): ?array
    {
        $decoded = json_decode(trim((string) $raw), true);
        if (! is_array($decoded)) {
            $start = strpos((string) $raw, '{');
            $end = strrpos((string) $raw, '}');
            $decoded = $start !== false && $end !== false ? json_decode(substr((string) $raw, $start, $end - $start + 1), true) : null;
        }
        if (! is_array($decoded) || ! is_bool($decoded['supported'] ?? null)) {
            return null;
        }
        $unsupported = is_string($decoded['unsupported'] ?? null) ? trim($decoded['unsupported']) : '';

        return [
            'supported' => $decoded['supported'],
            'unsupported' => $decoded['supported'] || $unsupported === '' ? null : mb_substr($unsupported, 0, 200),
        ];
    }

    /**
     * @param  array{reply:string,evidence:list<string>}  $parsed
     * @param  array<int,array{chunk:AiKbChunk,score:float}>  $results
     * @param  array<int,array{role:string,content:string}>  $history
     * @return array<int,array{role:string,content:string}>
     */
    private function messages(array $parsed, array $results, string $message, array $history, string $details): array
    {
        $transcript = collect($history)->take(-4)
            ->map(fn (array $turn) => ($turn['role'] === 'user' ? 'Customer: ' : 'Agent: ').mb_substr($turn['content'], 0, 400))
            ->implode("\n");
        // Numbered as the answer saw them, so its sources and quotes line up.
        $knowledge = mb_substr(collect($results)->values()
            ->map(fn (array $result, int $index) => '['.($index + 1).'] '.$result['chunk']->content)
            ->implode("\n\n"), 0, self::KNOWLEDGE_CHARS);
        $quotes = implode("\n", array_map(fn (string $quote) => '- '.$quote, $parsed['evidence']));

        return [
            ['role' => 'system', 'content' => 'You check a customer support reply before it is sent. Decide whether what the reply tells the customer '
                .'about what they asked is stated in the business knowledge and business details below. Paraphrase, translation, summarising '
                .'and extra details the knowledge states are fine. A reply that says it does not have, or cannot confirm, a detail is always '
                .'supported, even when the knowledge has that detail. It is not supported when the reply confirms, denies or promises something '
                .'about what was asked that the knowledge does not state: for example answering yes because the knowledge mentions something '
                .'related, or giving a capability, policy, price or date the knowledge does not give. Read all of the knowledge before deciding. '
                .'Reply with one JSON object only: {"supported": true or false, "unsupported": "what the reply states without support, in a few words; empty when supported"}.'
                ."\n\nBusiness details:\n".(trim($details) !== '' ? mb_substr($details, 0, self::DETAILS_CHARS) : '(none)')
                ."\n\nBusiness knowledge:\n".($knowledge !== '' ? $knowledge : '(none)')],
            ['role' => 'user', 'content' => trim(($transcript !== '' ? "Conversation so far:\n{$transcript}\n\n" : '')
                ."Customer's question: {$message}\n\n"
                .($quotes !== '' ? "Quotes the reply relies on:\n{$quotes}\n\n" : '')
                ."Reply to check:\n".$parsed['reply'])],
        ];
    }

    /** @return array<string,mixed> */
    private function options(): array
    {
        return [
            'max_tokens' => 200,
            'temperature' => 0.0,
            'json_object' => true,
            'json_schema' => ['name' => 'smart_bot_support_check', 'strict' => true, 'schema' => [
                'type' => 'object',
                'properties' => [
                    'supported' => ['type' => 'boolean'],
                    'unsupported' => ['type' => 'string'],
                ],
                'required' => ['supported', 'unsupported'],
                'additionalProperties' => false,
            ]],
        ];
    }
}
