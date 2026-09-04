<?php

namespace App\Modules\AI\Models;

use Illuminate\Database\Eloquent\Model;

class AiKbEmbeddingCache extends Model
{
    protected $table = 'ai_kb_embedding_cache';

    protected $fillable = ['content_hash', 'model', 'embedding', 'expires_at'];

    protected function casts(): array
    {
        return ['embedding' => 'array', 'expires_at' => 'datetime'];
    }
}
