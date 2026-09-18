<?php

namespace App\Modules\AI\Services;

/**
 * Platform-owned retrieval controls for private Smart Bot answering.
 *
 * These values intentionally do not come from tenant-editable chatbot fields.
 * The legacy columns remain available for rollback/data compatibility, while
 * runtime behavior is managed and bounded here so a client cannot accidentally
 * make retrieval too permissive, too strict, or unnecessarily expensive.
 */
class SmartBotRetrievalPolicy
{
    /**
     * @return array{max_context_chunks:int,answer_threshold:float,max_context_tokens:int,video_match_threshold:float}
     */
    public function privateAnswering(): array
    {
        $answerThreshold = $this->float(
            config('knowledge_base.retrieval_match_threshold', 0.60),
            0.45,
            0.85,
        );

        return [
            'max_context_chunks' => $this->integer(
                config('knowledge_base.max_context_chunks', 3),
                1,
                8,
            ),
            'answer_threshold' => $answerThreshold,
            'max_context_tokens' => $this->integer(
                config('knowledge_base.max_context_tokens', 1200),
                600,
                2400,
            ),
            'video_match_threshold' => max(
                $answerThreshold,
                $this->float(config('knowledge_base.video_match_threshold', 0.72), 0.55, 0.95),
            ),
        ];
    }

    /**
     * Public comments deliberately keep a smaller, stricter evidence window.
     *
     * @return array{max_context_chunks:int,answer_threshold:float,max_context_tokens:int}
     */
    public function publicComments(): array
    {
        $private = $this->privateAnswering();

        return [
            'max_context_chunks' => min(3, $private['max_context_chunks']),
            'answer_threshold' => max(0.60, $private['answer_threshold']),
            'max_context_tokens' => min(1200, $private['max_context_tokens']),
        ];
    }

    /**
     * Values written to legacy columns for compatibility with older readers.
     * Runtime code still resolves the managed policy above.
     *
     * @return array{max_context_chunks:int,retrieval_match_threshold:float,max_context_tokens:int,video_match_threshold:float}
     */
    public function compatibilityDefaults(): array
    {
        $policy = $this->privateAnswering();

        return [
            'max_context_chunks' => $policy['max_context_chunks'],
            'retrieval_match_threshold' => $policy['answer_threshold'],
            'max_context_tokens' => $policy['max_context_tokens'],
            'video_match_threshold' => $policy['video_match_threshold'],
        ];
    }

    private function integer(mixed $value, int $minimum, int $maximum): int
    {
        return max($minimum, min($maximum, (int) $value));
    }

    private function float(mixed $value, float $minimum, float $maximum): float
    {
        return max($minimum, min($maximum, (float) $value));
    }
}
