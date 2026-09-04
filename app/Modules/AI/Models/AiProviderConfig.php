<?php

namespace App\Modules\AI\Models;

use Illuminate\Database\Eloquent\Model;

class AiProviderConfig extends Model
{
    protected $table = 'ai_provider_configs';

    protected $fillable = [
        'workspace_id', 'provider', 'credentials', 'default_model_chat', 'default_model_embed',
        'enabled', 'last_tested_at', 'last_test_succeeded_at', 'last_test_error_code',
    ];

    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'enabled' => 'boolean',
            'last_tested_at' => 'datetime',
            'last_test_succeeded_at' => 'datetime',
        ];
    }
}
