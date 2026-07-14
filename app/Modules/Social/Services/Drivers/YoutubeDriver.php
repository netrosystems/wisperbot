<?php

namespace App\Modules\Social\Services\Drivers;

use App\Modules\Social\Models\SocialAccount;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class YoutubeDriver implements SocialNetworkInterface
{
    private const UPLOAD_URL = 'https://www.googleapis.com/upload/youtube/v3/videos';
    private const MAX_VIDEO_BYTES = 512 * 1024 * 1024;

    public function network(): string
    {
        return 'youtube';
    }

    /**
     * Block non-HTTPS schemes and RFC-1918 / link-local addresses to prevent SSRF.
     */
    private function assertSafeVideoUrl(string $url): void
    {
        $parsed = parse_url($url);
        if (($parsed['scheme'] ?? '') !== 'https') {
            throw new \RuntimeException('Video URL must use HTTPS.');
        }

        $host = strtolower($parsed['host'] ?? '');
        if ($host === '' || $host === 'localhost') {
            throw new \RuntimeException('Video URL points to a disallowed host.');
        }

        // Resolve to IP and block private/link-local ranges.
        $ip = gethostbyname($host);
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            throw new \RuntimeException('Video URL resolves to a disallowed network address.');
        }
    }

    public function fetchAccountInfo(string $accessToken): array
    {
        $response = Http::withToken($accessToken)
            ->timeout(15)
            ->get('https://www.googleapis.com/youtube/v3/channels?part=snippet&mine=true');
        if (! $response->successful()) {
            throw new \RuntimeException('YouTube channel lookup failed (HTTP '.$response->status().'): '.$response->body());
        }

        $res = $response->json();
        $channel = $res['items'][0]['snippet'] ?? [];

        if (empty($res['items'][0]['id'])) {
            throw new \RuntimeException('YouTube returned no channel for this account.');
        }

        return [
            'account_id' => $res['items'][0]['id'] ?? '',
            'name' => $channel['title'] ?? '',
            'picture_url' => $channel['thumbnails']['default']['url'] ?? null,
        ];
    }

    public function publish(SocialAccount $account, array $postData): string
    {
        $videoPath = $postData['video_path'] ?? null;
        $videoUrl = $postData['media_urls'][0] ?? null;
        $temporaryDownload = false;

        if (! $videoPath && ! $videoUrl) {
            throw new \RuntimeException('YouTube publish requires a video_path or media_urls[0].');
        }

        // Download to temp if URL given — validate to prevent SSRF.
        if (! $videoPath && $videoUrl) {
            $this->assertSafeVideoUrl($videoUrl);
            $videoPath = tempnam(sys_get_temp_dir(), 'yt_');
            $temporaryDownload = true;
            try {
                $download = Http::timeout(120)
                    ->sink($videoPath)
                    ->get($videoUrl);
                if (! $download->successful()) {
                    throw new \RuntimeException('Video download returned HTTP '.$download->status().'.');
                }
                if ((int) ($download->header('Content-Length') ?? 0) > self::MAX_VIDEO_BYTES
                    || (int) filesize($videoPath) > self::MAX_VIDEO_BYTES) {
                    throw new \RuntimeException('Video exceeds the 512 MB upload limit.');
                }
            } catch (\Throwable $e) {
                @unlink($videoPath);
                throw new \RuntimeException('Failed to download video: '.$e->getMessage());
            }
        }

        $size     = filesize($videoPath);
        $mimeType = mime_content_type($videoPath) ?: 'video/mp4';

        $metadata = [
            'snippet' => [
                'title'       => $postData['title'] ?? ($postData['body'] ?? 'New Video'),
                'description' => $postData['description'] ?? ($postData['body'] ?? ''),
                'tags'        => $postData['tags'] ?? [],
            ],
            'status' => [
                'privacyStatus' => $postData['privacy'] ?? 'private',
            ],
        ];

        try {
            // 1. Initiate resumable upload
            $initResp = Http::withToken($account->access_token)
                ->withHeaders([
                    'X-Upload-Content-Type'   => $mimeType,
                    'X-Upload-Content-Length' => $size,
                ])
                ->post(self::UPLOAD_URL.'?uploadType=resumable&part=snippet,status', $metadata);

            if (! $initResp->successful()) {
                throw new \RuntimeException('YouTube upload init failed.');
            }

            $uploadUri = $initResp->header('Location');
            if (! $uploadUri) {
                throw new \RuntimeException('YouTube upload init returned no Location header.');
            }

            // 2. Stream the file body instead of loading it all into RAM.
            $stream = fopen($videoPath, 'rb');
            if ($stream === false) {
                throw new \RuntimeException('Could not open video file for streaming.');
            }

            $uploadResp = Http::withToken($account->access_token)
                ->withHeaders(['Content-Type' => $mimeType, 'Content-Length' => $size])
                ->withBody($stream, $mimeType)
                ->put($uploadUri);

            if (is_resource($stream)) {
                fclose($stream);
            }

            if (! $uploadResp->successful()) {
                throw new \RuntimeException('YouTube video upload failed.');
            }

            $videoId = $uploadResp->json('id', '');
            Log::info('YouTube video uploaded', ['video_id' => $videoId, 'account_id' => $account->id]);

            return $videoId;
        } finally {
            // Never delete a caller-owned local file; only clean up the file this
            // driver downloaded into the system temp directory.
            if ($temporaryDownload && isset($videoPath) && file_exists($videoPath)) {
                @unlink($videoPath);
            }
        }
    }
}
