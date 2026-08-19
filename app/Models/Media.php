<?php

namespace App\Models;

use App\Services\StorageManager;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Storage;

class Media extends Model
{
    use HasFactory;

    protected $table = 'media';

    protected $fillable = [
        'mediable_type',
        'mediable_id',
        'disk',
        'path',
        'filename',
        'mime_type',
        'size_bytes',
        'collection',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
        'size_bytes' => 'integer',
    ];

    public function mediable(): MorphTo
    {
        return $this->morphTo();
    }

    public function url(): string
    {
        $this->ensureDiskConfigured();

        $url = Storage::disk($this->disk)->url($this->path);
        $urlHost = strtolower((string) parse_url($url, PHP_URL_HOST));
        $appUrl = (string) config('app.url');
        $appHost = strtolower((string) parse_url($appUrl, PHP_URL_HOST));
        $appUsesHttps = strtolower((string) parse_url($appUrl, PHP_URL_SCHEME)) === 'https';
        $requestUsesHttps = app()->bound('request') && request()->isSecure();

        if (($appUsesHttps || $requestUsesHttps) && $urlHost !== '' && $urlHost === $appHost) {
            return preg_replace('/^http:\/\//i', 'https://', $url, 1);
        }

        return $url;
    }

    public function delete(): bool
    {
        $this->ensureDiskConfigured();
        Storage::disk($this->disk)->delete($this->path);

        return parent::delete();
    }

    private function ensureDiskConfigured(): void
    {
        app(StorageManager::class)->ensureDiskReady($this->disk);
    }
}
