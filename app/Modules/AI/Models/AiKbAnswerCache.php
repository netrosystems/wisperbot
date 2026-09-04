<?php

namespace App\Modules\AI\Models;

use Illuminate\Database\Eloquent\Model;

class AiKbAnswerCache extends Model
{
    protected $table = 'ai_kb_answer_cache';

    protected $fillable = [
        'workspace_id', 'chatbot_id', 'revision_id', 'language', 'question_hash',
        'normalized_question', 'answer', 'resources', 'expires_at',
    ];

    protected function casts(): array
    {
        return ['resources' => 'array', 'expires_at' => 'datetime'];
    }
}
