<?php

namespace App\Modules\Social\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property Carbon|null $posted_at
 * @property Carbon|null $remote_updated_at
 */
class SocialComment extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_own' => 'boolean', 'imported' => 'boolean', 'ai_paused' => 'boolean', 'hidden' => 'boolean', 'deleted' => 'boolean', 'posted_at' => 'datetime', 'remote_updated_at' => 'datetime'];
    }

    /** @return BelongsTo<SocialAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class, 'social_account_id');
    }

    /** @return BelongsTo<SocialCommentPost, $this> */
    public function post(): BelongsTo
    {
        return $this->belongsTo(SocialCommentPost::class, 'post_id');
    }

    /** @return HasMany<SocialCommentOperation, $this> */
    public function operations(): HasMany
    {
        return $this->hasMany(SocialCommentOperation::class, 'comment_id');
    }
}
