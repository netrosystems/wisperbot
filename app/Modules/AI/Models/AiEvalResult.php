<?php

namespace App\Modules\AI\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One scored answer of a test run (Smart Bot 2.0, Phase 1.6). */
class AiEvalResult extends Model
{
    public const PASS = 'pass';

    public const FAIL = 'fail';

    protected $fillable = [
        'run_id', 'case_id', 'expected', 'reply', 'response_mode', 'answer_origin', 'reason_code', 'verdict',
        'failure', 'facts_missing', 'invented_figures', 'judge_score', 'judge_note', 'tokens', 'latency_ms', 'trace',
    ];

    protected function casts(): array
    {
        return [
            'facts_missing' => 'array',
            'invented_figures' => 'array',
            'trace' => 'array',
            'judge_score' => 'integer',
            'tokens' => 'integer',
            'latency_ms' => 'integer',
        ];
    }

    /** @return BelongsTo<AiEvalCase, $this> */
    public function evalCase(): BelongsTo
    {
        return $this->belongsTo(AiEvalCase::class, 'case_id');
    }
}
