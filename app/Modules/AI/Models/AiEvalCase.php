<?php

namespace App\Modules\AI\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One question of a Smart Bot's answer-quality test set (Smart Bot 2.0, Phase 1.6). */
class AiEvalCase extends Model
{
    public const EXPECT_ANSWER = 'answer';

    public const EXPECT_DECLINE = 'decline';

    public const SOURCE_GAP = 'gap';

    public const SOURCE_KNOWLEDGE = 'knowledge';

    public const SOURCE_UNANSWERABLE = 'unanswerable';

    public const SOURCE_TRANSLATION = 'translation';

    public const SOURCE_KB_TEST = 'kb_test';

    public const ACTIVE = 'active';

    public const RETIRED = 'retired';

    protected $fillable = [
        'workspace_id', 'chatbot_id', 'knowledge_gap_id', 'kb_test_case_id', 'question', 'history', 'expected',
        'expected_facts', 'language', 'source', 'source_document_id', 'fingerprint', 'status',
    ];

    protected function casts(): array
    {
        return ['history' => 'array', 'expected_facts' => 'array'];
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::ACTIVE);
    }

    /** @return BelongsTo<AiChatbot, $this> */
    public function chatbot(): BelongsTo
    {
        return $this->belongsTo(AiChatbot::class, 'chatbot_id');
    }

    /** @param array<int,array<string,mixed>>|null $history */
    public static function fingerprint(string $question, ?array $history = null): string
    {
        $normal = fn (string $text) => trim((string) preg_replace('/\s+/u', ' ', mb_strtolower($text)));

        return hash('sha256', $normal($question).'|'.$normal(implode(' ', array_map(fn ($turn) => (string) ($turn['content'] ?? ''), $history ?? []))));
    }
}
