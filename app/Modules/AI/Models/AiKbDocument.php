<?php

namespace App\Modules\AI\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class AiKbDocument extends Model
{
    protected $table = 'ai_kb_documents';

    protected static function boot(): void
    {
        parent::boot();
        static::creating(function (self $model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected $fillable = [
        'kb_id', 'source_type', 'source_ref', 'original_source_ref', 'canonical_url', 'resource_json', 'title', 'status',
        'enabled', 'authoritative', 'priority', 'detected_language', 'review_status',
        'publication_status', 'quality_score', 'quality_findings', 'extracted_content',
        'content_hash', 'index_version', 'active_index_generation', 'pending_index_generation',
        'reviewed_by', 'reviewed_at', 'last_refreshed_at',
        'next_refresh_at', 'product_detection_status', 'product_detection_message',
        'products_verified_at', 'error_message', 'tokens', 'last_indexed_at',
    ];

    protected function casts(): array
    {
        return [
            'last_indexed_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'last_refreshed_at' => 'datetime',
            'next_refresh_at' => 'datetime',
            'products_verified_at' => 'datetime',
            'tokens' => 'integer',
            'enabled' => 'boolean',
            'authoritative' => 'boolean',
            'priority' => 'integer',
            'quality_score' => 'integer',
            'index_version' => 'integer',
            'resource_json' => 'array',
            'quality_findings' => 'array',
        ];
    }

    /** @return BelongsTo<AiKnowledgeBase, $this> */
    public function knowledgeBase(): BelongsTo
    {
        return $this->belongsTo(AiKnowledgeBase::class, 'kb_id');
    }

    /** @return HasMany<AiKbChunk, $this> */
    public function chunks(): HasMany
    {
        return $this->hasMany(AiKbChunk::class, 'document_id');
    }

    /** @return BelongsToMany<AiKbRevision, $this> */
    public function revisions(): BelongsToMany
    {
        return $this->belongsToMany(AiKbRevision::class, 'ai_kb_revision_documents', 'document_id', 'revision_id')->withTimestamps();
    }

    /** @return HasMany<AiKbProduct, $this> */
    public function products(): HasMany
    {
        return $this->hasMany(AiKbProduct::class, 'document_id');
    }
}
