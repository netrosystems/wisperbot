<?php

namespace App\Modules\AI\Services;

use Illuminate\Validation\ValidationException;

class VideoResourceService
{
    public function __construct(private readonly KnowledgeUrlGuard $urls) {}

    /**
     * Discover supported video links inside extracted website or file content.
     *
     * The surrounding passage is retained only for retrieval matching. It is
     * never exposed as player configuration or rendered as HTML.
     *
     * @return array<int, array<string, mixed>>
     */
    public function discover(string $content, string $fallbackTitle = 'Video'): array
    {
        preg_match_all('~https://[^\s<>"\']+~iu', $content, $matches, PREG_OFFSET_CAPTURE);

        $resources = [];
        $seen = [];
        foreach ($matches[0] ?? [] as [$candidate, $offset]) {
            $url = html_entity_decode(rtrim($candidate, '.,;:!?)]}'), ENT_QUOTES | ENT_HTML5);
            $prefix = substr($content, max(0, $offset - 180), min(180, $offset));
            $title = $fallbackTitle;
            if (preg_match('/\[([^\]\r\n]{1,160})\]\(\s*$/u', $prefix, $label)) {
                $title = trim($label[1]);
            }

            try {
                $resource = $this->normalise($url, $title ?: 'Video');
            } catch (ValidationException) {
                continue;
            }

            if (isset($seen[$resource['canonical_url']])) {
                continue;
            }

            $contextStart = max(0, $offset - 320);
            $context = substr($content, $contextStart, strlen($url) + 640);
            $resource['match_text'] = trim((string) preg_replace('/\s+/u', ' ', strip_tags($context)));
            $seen[$resource['canonical_url']] = true;
            $resources[] = $resource;
        }

        return $resources;
    }

    /** @return array<int, array<string, mixed>> */
    public function fromStoredMetadata(mixed $metadata): array
    {
        if (! is_array($metadata)) {
            return [];
        }

        if (($metadata['kind'] ?? null) === 'video') {
            return [$metadata];
        }

        if (($metadata['kind'] ?? null) !== 'video_collection' || ! is_array($metadata['videos'] ?? null)) {
            return [];
        }

        return array_values(array_filter($metadata['videos'], fn ($resource) => is_array($resource) && ($resource['kind'] ?? null) === 'video'));
    }

    /** @return array<string, mixed> */
    public function normalise(string $url, string $title, ?string $thumbnailUrl = null): array
    {
        $url = trim($url);
        $parts = $this->validatedHttpsUrl($url, 'video_url');
        $host = strtolower($parts['host']);
        $path = (string) ($parts['path'] ?? '');
        parse_str((string) ($parts['query'] ?? ''), $query);

        if ($this->isYouTubeHost($host)) {
            $id = $host === 'youtu.be'
                ? trim($path, '/')
                : ($query['v'] ?? $this->pathIdentifier($path, ['embed', 'shorts', 'live']));
            if (! is_string($id) || ! preg_match('/^[A-Za-z0-9_-]{11}$/', $id)) {
                $this->invalid('video_url', 'Enter a valid YouTube video URL.');
            }

            return $this->resource(
                provider: 'youtube',
                id: $id,
                title: $title,
                canonicalUrl: 'https://www.youtube.com/watch?v='.$id,
                playbackUrl: 'https://www.youtube.com/embed/'.$id,
                thumbnailUrl: $thumbnailUrl ?: 'https://i.ytimg.com/vi/'.$id.'/hqdefault.jpg',
            );
        }

        if ($this->isVimeoHost($host)) {
            if (! preg_match('~/(?:video/)?([0-9]+)(?:/|$)~', $path, $matches)) {
                $this->invalid('video_url', 'Enter a valid Vimeo video URL.');
            }
            $id = $matches[1];
            $hash = isset($query['h']) && preg_match('/^[A-Za-z0-9]+$/', (string) $query['h'])
                ? (string) $query['h']
                : null;
            $suffix = $hash ? '?h='.$hash : '';

            return $this->resource(
                provider: 'vimeo',
                id: $id,
                title: $title,
                canonicalUrl: 'https://vimeo.com/'.$id.$suffix,
                playbackUrl: 'https://player.vimeo.com/video/'.$id.$suffix,
                thumbnailUrl: $thumbnailUrl,
                extra: $hash ? ['unlisted_hash' => $hash] : [],
            );
        }

        if (! str_ends_with(strtolower($path), '.mp4')) {
            $this->invalid('video_url', 'Use a YouTube, Vimeo, or direct HTTPS MP4 URL.');
        }

        try {
            $this->urls->assertSafe($url);
        } catch (\InvalidArgumentException $exception) {
            $this->invalid('video_url', $exception->getMessage());
        }

        return $this->resource('direct', null, $title, $url, $url, $thumbnailUrl);
    }

    /** @return array<string, mixed> */
    public function publicSnapshot(array $resource, float $score): array
    {
        return array_filter([
            'version' => 1,
            'kind' => 'video',
            'provider' => $resource['provider'] ?? null,
            'video_id' => $resource['video_id'] ?? null,
            'title' => $resource['title'] ?? 'Video',
            'canonical_url' => $resource['canonical_url'] ?? null,
            'playback_url' => $resource['playback_url'] ?? null,
            'thumbnail_url' => $resource['thumbnail_url'] ?? null,
            'match_score' => round($score, 4),
        ], fn ($value) => $value !== null && $value !== '');
    }

    /** @return array<int, array<string, mixed>> */
    public function sanitisePublicList(mixed $resources): array
    {
        if (! is_array($resources)) {
            return [];
        }

        $safe = [];
        foreach ($resources as $resource) {
            if (! is_array($resource) || ($resource['kind'] ?? null) !== 'video') {
                continue;
            }
            try {
                $normalised = $this->normalise(
                    (string) ($resource['canonical_url'] ?? ''),
                    (string) ($resource['title'] ?? 'Video'),
                    $resource['thumbnail_url'] ?? null,
                );
                $safe[] = $this->publicSnapshot($normalised, (float) ($resource['match_score'] ?? 0));
            } catch (\Throwable) {
                continue;
            }
        }

        return $safe;
    }

    /** @return array<string, mixed> */
    private function resource(string $provider, ?string $id, string $title, string $canonicalUrl, string $playbackUrl, ?string $thumbnailUrl, array $extra = []): array
    {
        if ($thumbnailUrl) {
            $this->validatedHttpsUrl($thumbnailUrl, 'thumbnail_url');
            try {
                $this->urls->assertSafe($thumbnailUrl);
            } catch (\InvalidArgumentException $exception) {
                $this->invalid('thumbnail_url', $exception->getMessage());
            }
        }

        return array_merge([
            'version' => 1,
            'kind' => 'video',
            'provider' => $provider,
            'video_id' => $id,
            'title' => trim($title),
            'canonical_url' => $canonicalUrl,
            'playback_url' => $playbackUrl,
            'thumbnail_url' => $thumbnailUrl,
        ], $extra);
    }

    /** @return array<string, mixed> */
    private function validatedHttpsUrl(string $url, string $field): array
    {
        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            $this->invalid($field, 'Enter a valid URL.');
        }
        $parts = parse_url($url);
        if (($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            $this->invalid($field, 'Only public HTTPS URLs without embedded credentials are allowed.');
        }
        $host = strtolower((string) $parts['host']);
        if ($host === 'localhost' || str_ends_with($host, '.localhost') || $this->isNonPublicIp($host)) {
            $this->invalid($field, 'Private or local network URLs are not allowed.');
        }

        return $parts;
    }

    private function isNonPublicIp(string $host): bool
    {
        return filter_var($host, FILTER_VALIDATE_IP) !== false
            && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }

    private function isYouTubeHost(string $host): bool
    {
        return in_array($host, ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'youtu.be'], true);
    }

    private function isVimeoHost(string $host): bool
    {
        return in_array($host, ['vimeo.com', 'www.vimeo.com', 'player.vimeo.com'], true);
    }

    private function pathIdentifier(string $path, array $prefixes): ?string
    {
        $segments = array_values(array_filter(explode('/', trim($path, '/'))));

        return count($segments) >= 2 && in_array($segments[0], $prefixes, true) ? $segments[1] : null;
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
