<?php

namespace App\Modules\AI\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One run of a Smart Bot against its test set; platform-billed (Smart Bot 2.0, Phase 1.6). */
class AiEvalRun extends Model
{
    public const RUNNING = 'running';

    public const COMPLETED = 'completed';

    public const FAILED = 'failed';

    protected $fillable = [
        'workspace_id', 'chatbot_id', 'engine', 'status', 'judged', 'cases_total', 'summary', 'passed',
        'error', 'started_at', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'judged' => 'boolean',
            'summary' => 'array',
            'passed' => 'boolean',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /** @return HasMany<AiEvalResult, $this> */
    public function results(): HasMany
    {
        return $this->hasMany(AiEvalResult::class, 'run_id');
    }
}
