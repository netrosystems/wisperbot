<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class SmtpConfiguration extends Model
{
    protected $fillable = [
        'host',
        'port',
        'username',
        'password',
        'encryption',
        'from_email',
        'from_name',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'port' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    protected $hidden = ['password'];

    public function setPasswordAttribute(?string $value): void
    {
        if ($value === null || $value === '') {
            return;
        }
        $this->attributes['password'] = Crypt::encryptString($value);
    }

    public function getPasswordAttribute(string $value): ?string
    {
        if (empty($value)) {
            return null;
        }
        try {
            return Crypt::decryptString($value);
        } catch (\Throwable) {
            return null;
        }
    }

    public function getDecryptedPassword(): ?string
    {
        return $this->password;
    }

    public static function getActive(): ?self
    {
        return static::where('is_active', true)->first();
    }

    public function getSummaryAttribute(): string
    {
        return "{$this->host}:{$this->port}";
    }
}
