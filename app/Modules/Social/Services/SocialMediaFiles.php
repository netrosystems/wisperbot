<?php

namespace App\Modules\Social\Services;

use App\Modules\AI\Services\KnowledgeUrlGuard;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Turns a post's media URL into a local file for providers that need the
 * bytes (X, LinkedIn). Files in this app's public storage are read from disk;
 * any other URL must be public HTTPS and is downloaded without following
 * redirects to private networks. Messages are safe to show to clients.
 */
class SocialMediaFiles
{
    private const MAX_REDIRECTS = 3;

    public function __construct(private readonly KnowledgeUrlGuard $guard) {}

    /**
     * @return array{path: string, mime: string, size: int, temporary: bool}
     *
     * @throws \RuntimeException
     */
    public function fetch(string $url, int $maxBytes): array
    {
        $local = $this->localStoragePath($url);
        $path = $local ?? $this->download($url, $maxBytes);

        return [
            'path' => $path,
            'mime' => strtolower((string) (new \finfo(FILEINFO_MIME_TYPE))->file($path)),
            'size' => (int) filesize($path),
            'temporary' => $local === null,
        ];
    }

    /** @param  array{path: string, temporary: bool}  $file */
    public function release(array $file): void
    {
        if ($file['temporary']) {
            @unlink($file['path']);
        }
    }

    /** A file under this app's public disk, addressed by its /storage URL. */
    private function localStoragePath(string $url): ?string
    {
        $appHost = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $path = rawurldecode((string) parse_url($url, PHP_URL_PATH));

        if ($appHost === '' || $host !== $appHost || ! str_starts_with($path, '/storage/')) {
            return null;
        }

        $root = realpath(Storage::disk('public')->path(''));
        $file = realpath(Storage::disk('public')->path(substr($path, strlen('/storage/'))));
        if ($root === false || $file === false || ! str_starts_with($file, $root.DIRECTORY_SEPARATOR) || ! is_file($file)) {
            throw new \RuntimeException('An image or video for this post is missing from the media library.');
        }

        return $file;
    }

    private function download(string $url, int $maxBytes): string
    {
        $target = tempnam(sys_get_temp_dir(), 'social_media_');
        $current = $url;
        $failure = 'An image or video for this post could not be downloaded. Check that the link works and the file is not too large.';

        try {
            for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
                try {
                    $this->guard->assertSafe($current);
                } catch (\InvalidArgumentException) {
                    throw new \DomainException('An image or video address is not allowed. Use a public HTTPS link or the media library.');
                }

                $connectedIp = null;
                $response = Http::withOptions([
                    'allow_redirects' => false,
                    'sink' => $target,
                    'on_stats' => function ($stats) use (&$connectedIp): void {
                        $connectedIp = $stats->getHandlerStats()['primary_ip'] ?? null;
                    },
                    // Abort as soon as the file is larger than the caller allows.
                    'progress' => function ($total, $downloaded) use ($maxBytes): void {
                        if ($total > $maxBytes || $downloaded > $maxBytes) {
                            throw new \RuntimeException('Media file is too large.');
                        }
                    },
                ])->connectTimeout(10)->timeout(60)->get($current);

                if ($connectedIp !== null) {
                    $this->guard->assertPublicIp($connectedIp);
                }

                if (in_array($response->status(), [301, 302, 303, 307, 308], true)) {
                    $location = trim((string) $response->header('Location'));
                    if ($location === '' || ! str_starts_with(strtolower($location), 'https://')) {
                        break;
                    }
                    $current = $location;

                    continue;
                }

                if ($response->successful()) {
                    return $target;
                }

                break;
            }
        } catch (\DomainException $e) {
            @unlink($target);
            throw new \RuntimeException($e->getMessage());
        } catch (\Throwable) {
            @unlink($target);
            throw new \RuntimeException($failure);
        }

        @unlink($target);
        throw new \RuntimeException($failure);
    }
}
