<?php

namespace App\Modules\AI\Models;

use Illuminate\Database\Eloquent\Model;

class AiWorkspaceSetting extends Model
{
    public const MODES = ['managed', 'byok', 'auto_fallback'];

    public const DEFAULT_MODE = 'auto_fallback';

    protected $fillable = ['workspace_id', 'provider_mode'];

    public static function modeFor(int $workspaceId): string
    {
        $stored = static::where('workspace_id', $workspaceId)->value('provider_mode');
        if (in_array($stored, self::MODES, true)) {
            return $stored;
        }

        return self::DEFAULT_MODE;
    }
}
