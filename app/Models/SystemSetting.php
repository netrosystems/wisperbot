<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class SystemSetting extends Model
{
    protected $fillable = ['key', 'value', 'is_secret', 'group'];

    protected function casts(): array
    {
        return ['is_secret' => 'boolean'];
    }

    public function getValueAttribute($value): ?string
    {
        $raw = $this->attributes['value'] ?? $value;
        if ($this->is_secret && $raw) {
            try {
                return Crypt::decryptString($raw);
            } catch (\Throwable) {
                // Older saves could mark a value as secret after assigning the
                // value, leaving the raw secret stored as plain text. Return the
                // raw value so existing Pusher/API settings do not become
                // unreadable; the next save will re-encrypt it correctly.
                return $raw;
            }
        }

        return $raw;
    }

    public function setValueAttribute($value): void
    {
        if ($this->is_secret && $value !== null && $value !== '') {
            $this->attributes['value'] = Crypt::encryptString($value);
        } else {
            $this->attributes['value'] = $value;
        }
    }

    public static function get(string $key, $default = null)
    {
        $s = static::where('key', $key)->first();

        return $s ? $s->value : $default;
    }

    public static function set(string $key, $value, bool $isSecret = false, ?string $group = null): void
    {
        $s = static::firstOrNew(['key' => $key]);
        $s->is_secret = $isSecret;
        $s->value = $value;
        $s->group = $group;
        $s->save();
    }
}
