<?php

namespace App\Modules\Social\Services;

use App\Modules\Social\Exceptions\XPublishException;
use App\Modules\Social\Models\SocialAccount;
use App\Modules\Social\Models\SocialPostAccount;
use App\Modules\Social\Services\Drivers\XDriver;
use Illuminate\Support\Sleep;

/**
 * Gets a post's media onto X at the lowest credit cost. Every file is fetched
 * and checked before the first (billed) upload, and each uploaded media id is
 * saved on the post link at once, so a retry reuses it instead of paying to
 * upload the same file again.
 */
class XMediaUploader
{
    /** How long one publish attempt waits for X to process a video. */
    private const PROCESSING_WAIT_SECONDS = 45;

    public function __construct(
        private readonly XMediaFetcher $fetcher,
        private readonly XContentRules $rules,
    ) {}

    /**
     * @param  array<int, mixed>  $mediaUrls
     * @return list<string> Media ids in the order of the URLs.
     */
    public function prepare(SocialAccount $account, SocialPostAccount $link, array $mediaUrls, XDriver $driver): array
    {
        $urls = array_values(array_unique(array_filter(array_map('strval', $mediaUrls))));
        if ($urls === []) {
            return [];
        }

        if (! in_array('media.write', (array) $account->scopes, true)) {
            throw new XPublishException('Reconnect your X account to allow images and video, then publish again.', 'reconnect_media');
        }

        $cache = $this->reusableMedia($link);
        $files = [];

        try {
            foreach ($urls as $url) {
                if (! isset($cache[$url])) {
                    $files[$url] = $this->fetcher->fetch($url);
                }
            }

            $kinds = array_map(fn (string $url) => $cache[$url]['kind'] ?? $files[$url]['kind'], $urls);
            if (($error = $this->rules->mediaError($kinds)) !== null) {
                throw new XPublishException($error, 'content');
            }

            foreach ($files as $url => $file) {
                $cache[$url] = ['url' => $url, 'kind' => $file['kind']]
                    + $driver->uploadMedia($account, $file['path'], $file['mime'], $file['category']);
                $this->remember($link, $cache);
            }
        } finally {
            foreach ($files as $file) {
                if ($file['temporary']) {
                    @unlink($file['path']);
                }
            }
        }

        $waited = 0;
        foreach ($urls as $url) {
            while (in_array($cache[$url]['state'], ['pending', 'in_progress'], true)) {
                $delay = max(1, (int) $cache[$url]['check_after']);
                if ($waited + $delay > self::PROCESSING_WAIT_SECONDS) {
                    // The upload is kept; the queue retry resumes from here.
                    throw new XPublishException('X is still processing the video. Publish again in a few minutes; it will not be uploaded twice.', 'processing');
                }
                Sleep::for($delay)->seconds();
                $waited += $delay;
                $cache[$url] = array_merge($cache[$url], $driver->mediaStatus($account, $cache[$url]['id']));
                $this->remember($link, $cache);
            }

            if ($cache[$url]['state'] !== 'succeeded') {
                unset($cache[$url]);
                $this->remember($link, $cache);
                throw new XPublishException('X could not process this video. Check that it is a standard MP4 or MOV file and try again.', 'content');
            }
        }

        return array_map(fn (string $url) => $cache[$url]['id'], $urls);
    }

    /** @return array<string, array{url: string, kind: string, id: string, state: string, check_after: int, expires_at: string}> */
    private function reusableMedia(SocialPostAccount $link): array
    {
        $reusable = [];
        foreach ((array) $link->provider_media as $media) {
            if (is_array($media) && isset($media['url'], $media['id'], $media['kind'], $media['expires_at'])
                && now()->lt($media['expires_at'])) {
                $reusable[$media['url']] = $media + ['state' => 'succeeded', 'check_after' => 0];
            }
        }

        return $reusable;
    }

    /** @param  array<string, array<string, mixed>>  $cache */
    private function remember(SocialPostAccount $link, array $cache): void
    {
        $link->update(['provider_media' => array_values($cache) ?: null]);
    }
}
