<?php

namespace App\Modules\AI\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class AiKbRevision extends Model
{
    protected $fillable = [
        'kb_id', 'version', 'status', 'created_by', 'readiness_score',
        'regression_status', 'published_at',
    ];

    protected function casts(): array
    {
        return ['version' => 'integer', 'readiness_score' => 'integer', 'published_at' => 'datetime'];
    }

    public function knowledgeBase(): BelongsTo
    {
        return $this->belongsTo(AiKnowledgeBase::class, 'kb_id');
    }

    public function documents(): BelongsToMany
    {
        return $this->belongsToMany(AiKbDocument::class, 'ai_kb_revision_documents', 'revision_id', 'document_id')->withTimestamps();
    }
}
