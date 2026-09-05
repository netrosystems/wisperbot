<?php

namespace App\Modules\Whatsapp\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property int $workspace_id
 * @property int $waba_id
 * @property string $kind
 * @property string $state
 * @property string $credential_revision
 * @property array<string, mixed>|null $results
 * @property Carbon $created_at
 * @property Carbon|null $finished_at
 */
class WhatsappConnectionOperation extends Model
{
    protected $table = 'whatsapp_connection_operations';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $hidden = ['credential_revision'];

    protected function casts(): array
    {
        return ['results' => 'array', 'finished_at' => 'datetime'];
    }
}
