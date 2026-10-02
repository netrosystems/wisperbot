<?php

namespace App\Modules\AI\Services\Llm;

class LlmResponse
{
    public function __construct(
        public readonly string $content,
        public readonly int $promptTokens,
        public readonly int $completionTokens,
        public readonly string $model,
        public readonly int $latencyMs,
        /** Why the provider stopped: stop, length, content_filter, or the provider's own value. */
        public readonly ?string $finishReason = null,
    ) {}

    /** True when the provider stopped because the output budget ran out. */
    public function truncated(): bool
    {
        return $this->finishReason === 'length';
    }

    /** Maps each provider's stop value onto one vocabulary. */
    public static function normalizeFinishReason(mixed $reason): ?string
    {
        if (! is_string($reason) || $reason === '') {
            return null;
        }

        return match (strtolower($reason)) {
            'stop', 'end_turn', 'stop_sequence' => 'stop',
            'length', 'max_tokens' => 'length',
            'content_filter', 'safety', 'recitation', 'prohibited_content', 'blocklist', 'spii', 'refusal' => 'content_filter',
            default => mb_substr(strtolower($reason), 0, 32),
        };
    }
}
