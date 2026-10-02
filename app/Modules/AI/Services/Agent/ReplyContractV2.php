<?php

namespace App\Modules\AI\Services\Agent;

use App\Modules\AI\Services\ChatReplyOptions;

/**
 * The shape an engine v2 reply arrives in (Smart Bot 2.0, Phase 1.1; after
 * Cerqle's ReplyContractV2).
 *
 * v1 only learns "answer or clarification". v2 also learns what kind of
 * answer it is (a full answer, part of one, general guidance, a clarifying
 * question or an offer of a person), which passages it used, and short
 * quotes that prove a full answer. The widget, SDK and API keep receiving
 * the same `response_mode` values: the kind is mapped onto them.
 */
class ReplyContractV2
{
    public const NAME = 'smart_bot_reply_v2';

    public const KINDS = ['answer', 'partial', 'guidance', 'clarification', 'handoff'];

    /** @return array<string,mixed> */
    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'reply' => ['type' => 'string', 'description' => "The reply, in the customer's own language and script."],
                'answer_kind' => ['type' => 'string', 'enum' => self::KINDS],
                'used_sources' => [
                    'type' => 'array',
                    'description' => 'Numbers of the knowledge excerpts the reply relies on, for example [1, 3]. Empty if none.',
                    'items' => ['type' => 'integer'],
                ],
                'evidence' => [
                    'type' => 'array',
                    'description' => 'Up to three short quotes, copied word for word from the excerpts or the business profile, that directly answer what was asked. Empty when they do not answer it.',
                    'items' => ['type' => 'string'],
                ],
                'quick_replies' => [
                    'type' => 'array',
                    'description' => 'Two or three short replies the customer can tap, or empty.',
                    'items' => ['type' => 'string'],
                ],
                'grounded' => ['type' => 'boolean', 'description' => 'True only if every fact came from the excerpts, the business profile or the conversation.'],
                'show_video' => ['type' => 'boolean', 'description' => 'True only when a tutorial video was offered and helps this reply.'],
                'language' => ['type' => 'string', 'description' => "BCP-47 tag of the customer's language, for example bn or en."],
            ],
            'required' => ['reply', 'answer_kind', 'used_sources', 'evidence', 'quick_replies', 'grounded', 'show_video', 'language'],
            'additionalProperties' => false,
        ];
    }

    /**
     * Provider options for an answer call: structured outputs where the
     * provider supports them, JSON mode elsewhere.
     *
     * @return array<string,mixed>
     */
    public function requestOptions(int $maxTokens, float $temperature): array
    {
        return [
            'max_tokens' => $maxTokens,
            'temperature' => $temperature,
            'json_object' => true,
            'json_schema' => ['name' => self::NAME, 'strict' => true, 'schema' => $this->schema()],
        ];
    }

    /** The contract spelled out, for providers that cannot enforce a schema. */
    public function promptInstruction(): string
    {
        return 'Reply with one JSON object and nothing else: '
            .'{"reply": "...", "answer_kind": "answer" | "partial" | "guidance" | "clarification" | "handoff", '
            .'"used_sources": [1, 2], "evidence": ["..."], "quick_replies": [], "grounded": true | false, "show_video": false, "language": "<BCP-47 tag>"}. '
            .'answer_kind: "answer" only when the excerpts or the business profile directly answer what was asked; "partial" when they answer part of it; '
            .'"guidance" when you give general guidance they do not contain; "clarification" when you ask one question; '
            .'"handoff" when only a team member can help. Related information is not an answer: if they do not say what was asked, use "partial" and say which detail you cannot confirm. '
            .'used_sources lists the excerpt numbers you relied on. evidence holds up to three short quotes, copied word for word, that answer the question; an "answer" needs at least one. '
            .'Set grounded to true only when every fact in the reply appears in the excerpts, the business profile or the conversation.';
    }

    /**
     * Reads a model reply, tolerating prose or a code fence around the object.
     *
     * @return array{reply:string,answer_kind:string,used_sources:list<int>,evidence:list<string>,quick_replies:list<string>,grounded:bool,show_video:bool,language:?string}|null
     */
    public function parse(?string $raw): ?array
    {
        $decoded = app(ChatReplyOptions::class)->structuredPayload((string) $raw);
        if (! is_array($decoded) || ! is_string($decoded['reply'] ?? null)) {
            return null;
        }
        $reply = trim($decoded['reply']);
        $kind = is_string($decoded['answer_kind'] ?? null) && in_array($decoded['answer_kind'], self::KINDS, true)
            ? $decoded['answer_kind']
            : (($decoded['response_type'] ?? null) === 'clarification' ? 'clarification' : 'answer');
        if ($reply === '') {
            return null;
        }

        $sources = [];
        foreach ((array) ($decoded['used_sources'] ?? []) as $source) {
            if (is_numeric($source) && (int) $source > 0) {
                $sources[] = (int) $source;
            }
        }
        $evidence = [];
        foreach ((array) ($decoded['evidence'] ?? []) as $quote) {
            if (is_string($quote) && trim($quote) !== '') {
                $evidence[] = mb_substr(trim($quote), 0, 300);
            }
        }
        $choices = [];
        foreach ((array) ($decoded['quick_replies'] ?? []) as $choice) {
            if (is_string($choice) && trim($choice) !== '') {
                $choices[] = trim($choice);
            }
        }

        return [
            'reply' => $reply,
            'answer_kind' => $kind,
            'used_sources' => array_values(array_unique($sources)),
            'evidence' => array_slice($evidence, 0, 3),
            'quick_replies' => array_slice($choices, 0, 3),
            'grounded' => ($decoded['grounded'] ?? false) === true,
            'show_video' => ($decoded['show_video'] ?? false) === true,
            'language' => is_string($decoded['language'] ?? null) ? mb_substr($decoded['language'], 0, 16) : null,
        ];
    }
}
