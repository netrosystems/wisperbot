<?php

namespace App\Modules\AI\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiCreditPeriod extends Model
{
    protected $fillable = [
        'account_type', 'account_id', 'subscription_type', 'subscription_id',
        'period_start', 'period_end', 'allowance', 'adjustment_credits',
        'used_credits', 'reserved_credits', 'status',
        'warned_80_at', 'warned_100_at',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'datetime',
            'period_end' => 'datetime',
            'allowance' => 'integer',
            'adjustment_credits' => 'integer',
            'used_credits' => 'integer',
            'reserved_credits' => 'integer',
            'warned_80_at' => 'datetime',
            'warned_100_at' => 'datetime',
        ];
    }

    public function ledger(): HasMany
    {
        return $this->hasMany(AiCreditLedger::class, 'period_id');
    }
}
