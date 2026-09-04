<?php

namespace App\Modules\AI\Models;

use Illuminate\Database\Eloquent\Model;

class AiKbTestCase extends Model
{
    protected $fillable = [
        'kb_id', 'question', 'expected_facts', 'expected_document_id', 'critical',
        'last_status', 'last_result', 'last_run_at',
    ];

    protected function casts(): array
    {
        return ['critical' => 'boolean', 'last_result' => 'array', 'last_run_at' => 'datetime'];
    }
}
