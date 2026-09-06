<?php

namespace App\Modules\Social\Models;

use Illuminate\Database\Eloquent\Model;

/** @property array<string, mixed>|null $diagnostics */
class SocialCommentOperation extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $hidden = ['credential_fingerprint', 'idempotency_key'];

    protected function casts(): array
    {
        return ['diagnostics' => 'array'];
    }
}
