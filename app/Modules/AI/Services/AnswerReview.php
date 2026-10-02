<?php

namespace App\Modules\AI\Services;

use App\Modules\AI\Models\AiChatbot;
use App\Modules\AI\Models\AiKbChunk;

/**
 * "Why this answer" for a Smart Bot reply (Smart Bot 2.0, Phase 1.5): a short
 * summary of how the turn ended, stored with the bot message as
 * `payload.ai_review` for the team. The widget never sends it to visitors
 * (WidgetPayloadBuilder allow-lists payload fields); the inbox and the mobile
 * app, which only staff use, show it.
 */
class AnswerReview
{
    /**
     * @param  array<string,mixed>  $turn  ChatbotRunner::lastTurnContext()
     * @return array<string,mixed>|null
     */
    public function summary(array $turn, AiChatbot $bot, ?int $questionMessageId): ?array
    {
        if ($turn === []) {
            return null;
        }
        $trace = is_array($turn['trace'] ?? null) ? $turn['trace'] : [];
        $passageIds = array_values(array_filter(array_map(fn ($passage) => is_array($passage) ? (int) ($passage['chunk_id'] ?? 0) : 0, (array) ($trace['passages'] ?? []))));
        // Engine v2 says which passages it used; show those first.
        $used = array_values(array_filter(array_map(fn ($number) => $passageIds[(int) $number - 1] ?? null, (array) ($trace['used_sources'] ?? []))));

        return array_filter([
            'chatbot_id' => $bot->id,
            'kb_id' => $bot->ai_kb_id,
            'question_message_id' => $questionMessageId,
            'engine' => $turn['engine'] ?? null,
            'reason_code' => $turn['reason_code'] ?? null,
            'answer_origin' => $turn['answer_origin'] ?? null,
            'response_mode' => $turn['response_mode'] ?? null,
            'answer_kind' => $trace['answer_kind'] ?? null,
            'best_score' => isset($turn['best_score']) ? round((float) $turn['best_score'], 2) : null,
            'sources' => $this->titles($used !== [] ? $used : $passageIds, (int) $bot->ai_kb_id),
        ], fn ($value) => $value !== null && $value !== []);
    }

    /**
     * @param  list<int>  $chunkIds
     * @return list<string>
     */
    private function titles(array $chunkIds, int $kbId): array
    {
        if ($chunkIds === [] || $kbId === 0) {
            return [];
        }

        return AiKbChunk::with('document:id,title,source_ref')->where('kb_id', $kbId)->whereIn('id', array_slice($chunkIds, 0, 8))->get()
            ->map(fn (AiKbChunk $chunk) => trim((string) ($chunk->document?->title ?: $chunk->document?->source_ref)))
            ->filter()->unique()->take(5)->values()->all();
    }
}
