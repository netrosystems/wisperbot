<?php

namespace App\Modules\AI\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
        'kb_id', 'source_type', 'source_ref', 'resource_json', 'title', 'status',
        'enabled', 'authoritative', 'priority', 'detected_language', 'review_status',
        'publication_status', 'quality_score', 'quality_findings', 'extracted_content',
        'content_hash', 'reviewed_by', 'reviewed_at', 'last_refreshed_at',
        'next_refresh_at', 'error_message', 'tokens', 'last_indexed_at',
    ];

    protected function casts(): array
    {
        return [
            'last_indexed_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'last_refreshed_at' => 'datetime',
            'next_refresh_at' => 'datetime',
            'tokens' => 'integer',
            'enabled' => 'boolean',
            'authoritative' => 'boolean',
            'priority' => 'integer',
            'quality_score' => 'integer',
            'resource_json' => 'array',
            'quality_findings' => 'array',
        ];
    }

    public function knowledgeBase(): BelongsTo
    {
        return $this->belongsTo(AiKnowledgeBase::class, 'kb_id');
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(AiKbChunk::class, 'document_id');
    }

    public function revisions()
    {
        return $this->belongsToMany(AiKbRevision::class, 'ai_kb_revision_documents', 'document_id', 'revision_id')->withTimestamps();
    }
}
