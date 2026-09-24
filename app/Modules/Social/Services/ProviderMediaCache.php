<?php

namespace App\Modules\Social\Services;

use App\Modules\Social\Models\SocialPostAccount;

/**
 * Media a driver already uploaded or prepared for one post link (Facebook
 * unpublished photos, Instagram containers, LinkedIn assets), kept in
 * `provider_media` so a retry reuses it instead of uploading it again.
 * Entries use the same shape as X media: {url, id, expires_at, ...}.
 */
class ProviderMediaCache
{
    public function __construct(private readonly SocialPostAccount $link) {}

    /** @return array<string, mixed>|null */
    public function get(string $url): ?array
    {
        foreach ((array) $this->link->provider_media as $entry) {
            if (is_array($entry) && ($entry['url'] ?? null) === $url && isset($entry['id'], $entry['expires_at'])
                && now()->lt($entry['expires_at'])) {
                return $entry;
            }
        }

        return null;
    }

    /** @param  array<string, mixed>  $data  Must include `id`. */
    public function put(string $url, array $data, int $validForSeconds): void
    {
        $entries = array_values(array_filter((array) $this->link->provider_media, fn ($entry) => ! is_array($entry) || ($entry['url'] ?? null) !== $url));
        $entries[] = ['url' => $url] + $data + ['expires_at' => now()->addSeconds($validForSeconds)->toIso8601String()];
        $this->link->update(['provider_media' => $entries]);
    }

    public function forget(string $url): void
    {
        $entries = array_values(array_filter((array) $this->link->provider_media, fn ($entry) => ! is_array($entry) || ($entry['url'] ?? null) !== $url));
        $this->link->update(['provider_media' => $entries ?: null]);
    }
}
