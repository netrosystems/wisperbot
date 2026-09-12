<?php

namespace App\Modules\Shared\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChannelAccount extends Model
{
    protected static function booted(): void
    {
        static::creating(function (self $account): void {
            if (! $account->ai_eligible_from_at && in_array($account->channel, ['whatsapp', 'messenger', 'instagram', 'telegram', 'ebay', 'email'], true)) {
                $account->ai_eligible_from_at = now();
            }
        });
    }

    protected $fillable = [
        'workspace_id', 'channel', 'provider', 'credentials',
        'display_name', 'phone_number_id', 'business_account_id', 'status', 'meta_json',
        'ai_eligible_from_at',
    ];

    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'meta_json' => 'array',
            'ai_eligible_from_at' => 'datetime',
        ];
    }

    /** @return HasMany<Conversation, $this> */
    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }
}
