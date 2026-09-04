<?php

namespace App\Modules\AI\Models;

use Illuminate\Database\Eloquent\Model;

class AiRun extends Model
{
    protected $table = 'ai_runs';

    protected $fillable = ['chatbot_id', 'conversation_id', 'prompt_tokens', 'completion_tokens', 'cost_cents', 'latency_ms', 'model', 'status', 'metadata_json'];

    protected function casts(): array
    {
        return ['metadata_json' => 'array'];
    }
}
