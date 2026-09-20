<?php

namespace App\Modules\AI\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiKbProductOffer extends Model
{
    protected $fillable = [
        'product_id', 'source_key', 'name', 'sku', 'price', 'regular_price',
        'sale_price', 'min_price', 'max_price', 'currency', 'availability',
        'attributes', 'source_url', 'verified_at',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:4',
            'regular_price' => 'decimal:4',
            'sale_price' => 'decimal:4',
            'min_price' => 'decimal:4',
            'max_price' => 'decimal:4',
            'attributes' => 'array',
            'verified_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<AiKbProduct, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(AiKbProduct::class, 'product_id');
    }
}
