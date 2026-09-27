<?php

namespace App\Modules\Broadcasting\Models;

use App\Models\Concerns\ExcludesDeletedWorkspaces;
use Illuminate\Database\Eloquent\Model;

class SmsProviderConfig extends Model
{
    use ExcludesDeletedWorkspaces;

    protected $table = 'sms_provider_configs';

    protected $fillable = [
        'workspace_id', 'provider', 'credentials', 'sender_id', 'default',
    ];

    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'default' => 'boolean',
        ];
    }
}
