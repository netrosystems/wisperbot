<?php

namespace App\Modules\AI\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiKbChunk extends Model
{
    protected $table = 'ai_kb_chunks';

    protected $fillable = [
        'kb_id', 'document_id', 'ord', 'content', 'content_hash', 'tokens',
        'embedding', 'embedding_model', 'embedding_status', 'revision_id',
    ];

    protected function casts(): array
    {
        return ['tokens' => 'integer', 'ord' => 'integer'];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(AiKbDocument::class, 'document_id');
    }
}
