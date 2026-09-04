<?php

namespace App\Modules\AI\Models;

use Database\Factories\AiKnowledgeBaseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class AiKnowledgeBase extends Model
{
    use HasFactory;

    protected static function newFactory()
    {
        return AiKnowledgeBaseFactory::new();
    }

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

    protected $table = 'ai_knowledge_bases';

    protected $fillable = [
        'workspace_id', 'name', 'purpose', 'language', 'brand', 'audience',
        'embedding_model', 'dimensions', 'status', 'draft_revision_id',
        'published_revision_id', 'readiness_score', 'regression_status', 'last_published_at',
    ];

    protected function casts(): array
    {
        return [
            'readiness_score' => 'integer',
            'last_published_at' => 'datetime',
        ];
    }

    public function documents(): HasMany
    {
        return $this->hasMany(AiKbDocument::class, 'kb_id');
    }

    public function chatbots(): HasMany
    {
        return $this->hasMany(AiChatbot::class, 'ai_kb_id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(AiKbRevision::class, 'kb_id');
    }

    public function publishedRevision()
    {
        return $this->belongsTo(AiKbRevision::class, 'published_revision_id');
    }

    public function draftRevision()
    {
        return $this->belongsTo(AiKbRevision::class, 'draft_revision_id');
    }

    public function testCases(): HasMany
    {
        return $this->hasMany(AiKbTestCase::class, 'kb_id');
    }

    public function knowledgeGaps(): HasMany
    {
        return $this->hasMany(AiKbKnowledgeGap::class, 'kb_id');
    }

    public function retrievalDiagnostics(): HasMany
    {
        return $this->hasMany(AiKbRetrievalDiagnostic::class, 'kb_id');
    }
}
