<?php

namespace App\Modules\Whatsapp\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $workspace_id
 * @property int $waba_id
 * @property string $state
 * @property string|null $operation_id
 * @property string|null $credential_revision
 * @property string|null $incident_key
 * @property int $transient_failures
 * @property int $pending_live_messages
 * @property array<string, array<string, string>>|null $components
 * @property Carbon|null $checked_at
 * @property Carbon|null $last_success_at
 * @property Carbon|null $next_check_at
 * @property Carbon|null $last_webhook_at
 * @property Carbon|null $last_live_received_at
 * @property Carbon|null $last_message_at
 * @property Carbon|null $last_processing_error_at
 * @property Carbon|null $repaired_at
 */
class WhatsappConnectionHealth extends Model
{
    protected $table = 'whatsapp_connection_health';

    protected $guarded = ['id'];

    protected $hidden = ['credential_revision'];

    protected function casts(): array
    {
        return [
            'components' => 'array',
            'checked_at' => 'datetime',
            'last_success_at' => 'datetime',
            'next_check_at' => 'datetime',
            'last_webhook_at' => 'datetime',
            'last_live_received_at' => 'datetime',
            'last_message_at' => 'datetime',
            'last_processing_error_at' => 'datetime',
            'repaired_at' => 'datetime',
        ];
    }
}
