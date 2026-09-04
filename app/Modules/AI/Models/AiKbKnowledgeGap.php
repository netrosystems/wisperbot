<?php

namespace App\Modules\AI\Models;

use Illuminate\Database\Eloquent\Model;

class AiKbKnowledgeGap extends Model
{
    protected $fillable = [
        'workspace_id', 'kb_id', 'chatbot_id', 'question_hash', 'question_sample',
        'occurrences', 'best_score', 'decision', 'status', 'last_seen_at',
    ];

    protected function casts(): array
    {
        return ['best_score' => 'float', 'occurrences' => 'integer', 'last_seen_at' => 'datetime'];
    }
}
