<?php

namespace App\Modules\AI\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiCreditLedger extends Model
{
    protected $fillable = [
        'period_id', 'workspace_id', 'actor_id', 'feature', 'rate_version',
        'idempotency_key', 'provider_source', 'provider', 'model', 'credits',
        'prompt_tokens', 'completion_tokens', 'cost_microusd', 'status',
        'result_json', 'error_code', 'reserved_at', 'finalized_at', 'request_fingerprint',
        'adjustment_delta', 'adjustment_reason',
    ];

    protected $hidden = ['result_json'];

    protected function casts(): array
    {
        return [
            'credits' => 'integer',
            'prompt_tokens' => 'integer',
            'completion_tokens' => 'integer',
            'cost_microusd' => 'integer',
            'adjustment_delta' => 'integer',
            'result_json' => 'encrypted:array',
            'reserved_at' => 'datetime',
            'finalized_at' => 'datetime',
        ];
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(AiCreditPeriod::class, 'period_id');
    }
}
