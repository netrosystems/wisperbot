<?php

namespace App\Modules\AI\Models;

use Illuminate\Database\Eloquent\Model;

class AiKbRetrievalDiagnostic extends Model
{
    protected $fillable = [
        'workspace_id', 'kb_id', 'chatbot_id', 'revision_id', 'best_score',
        'passages_used', 'system_tokens', 'context_tokens', 'history_tokens',
        'customer_tokens', 'completion_tokens', 'decision', 'cache_source',
    ];
}
