<?php

namespace App\Modules\AI\Models;

use Illuminate\Database\Eloquent\Model;

class AiKbRetrievalDiagnostic extends Model
{
    protected $fillable = [
        'workspace_id', 'kb_id', 'chatbot_id', 'revision_id', 'best_score',
        'passages_used', 'system_tokens', 'context_tokens', 'history_tokens',
        'customer_tokens', 'completion_tokens', 'decision', 'cache_source',
        'intent', 'answer_origin', 'response_mode', 'retrieval_strategy', 'semantic_score',
        'lexical_score', 'acceptance_reason', 'research_outcome', 'research_latency_ms',
        'citations', 'product_diagnostics', 'credit_result',
    ];

    protected function casts(): array
    {
        return [
            'citations' => 'array',
            'product_diagnostics' => 'array',
            'best_score' => 'float',
            'semantic_score' => 'float',
            'lexical_score' => 'float',
        ];
    }
}
