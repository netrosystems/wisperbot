<?php

namespace App\Modules\AI\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiKbProduct extends Model
{
    protected $fillable = [
        'workspace_id', 'kb_id', 'document_id', 'source_key', 'canonical_url_hash',
        'canonical_url', 'name', 'sku', 'image_url', 'description', 'extraction_method',
        'parser_confidence', 'status', 'verified_at', 'last_error',
    ];

    protected function casts(): array
    {
        return [
            'parser_confidence' => 'float',
            'verified_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<AiKbDocument, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(AiKbDocument::class, 'document_id');
    }

    /** @return HasMany<AiKbProductOffer, $this> */
    public function offers(): HasMany
    {
        return $this->hasMany(AiKbProductOffer::class, 'product_id');
    }
}
