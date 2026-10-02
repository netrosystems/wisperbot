<?php

namespace App\Modules\AI\Models;

use Illuminate\Database\Eloquent\Model;

/** The team's rating of one Smart Bot reply (Smart Bot 2.0, Phase 1.5). */
class AiAnswerFeedback extends Model
{
    protected $table = 'ai_answer_feedback';

    protected $fillable = ['workspace_id', 'message_id', 'chatbot_id', 'kb_id', 'user_id', 'rating', 'improved'];

    protected function casts(): array
    {
        return ['improved' => 'boolean'];
    }
}
