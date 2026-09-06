<?php

namespace App\Modules\Social\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property array<string, bool>|null $capabilities
 * @property array<string, mixed>|null $sync_cursor
 * @property Carbon|null $previewed_at
 * @property Carbon|null $public_kb_confirmed_at
 * @property Carbon|null $last_synced_at
 */
class SocialCommentSetting extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['sync_cursor'];

    protected function casts(): array
    {
        return ['capabilities' => 'array', 'sync_cursor' => 'encrypted:array', 'public_kb_confirmed_at' => 'datetime', 'previewed_at' => 'datetime', 'last_synced_at' => 'datetime', 'sync_started_at' => 'datetime'];
    }
}
