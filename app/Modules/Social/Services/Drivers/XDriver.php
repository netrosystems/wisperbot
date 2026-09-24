<?php

namespace App\Modules\Social\Services\Drivers;

use App\Modules\Social\Exceptions\XPublishException;
use App\Modules\Social\Models\SocialAccount;
use App\Modules\Social\Services\XContentRules;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * X (formerly Twitter) publishing: text with up to 3 images or 1 video.
 * Deliberately implements no update or delete, and sends no media metadata
 * (alt text): WisperBot never edits or removes X posts remotely.
 */
class XDriver implements SocialNetworkInterface
{
    private const MEDIA_URL = 'https://api.x.com/2/media/upload';

    /** X recommends segments of 5 MB or less. */
    private const CHUNK_BYTES = 4 * 1024 * 1024;

    public function network(): string
    {
        return 'twitter';
    }

    /** @return array{account_id: string, name: string, username: ?string, picture_url: ?string} */
    public function fetchAccountInfo(string $accessToken): array
    {
        try {
            $response = Http::withToken($accessToken)->acceptJson()->withoutRedirecting()->timeout(30)
                ->get('https://api.x.com/2/users/me', ['user.fields' => 'profile_image_url,username']);
        } catch (ConnectionException) {
            throw new XPublishException('X profile lookup failed. Please try connecting again.', 'provider');
        }

        if (! $response->successful()) {
            throw XPublishException::fromResponse($response);
        }

        $id = $response->json('data.id');
        if (! is_string($id) || $id === '') {
            throw new XPublishException('X did not return an account. Please try connecting again.', 'provider');
        }

        $username = $response->json('data.username');

        return [
            'account_id' => $id,
            'name' => $response->json('data.name') ?: ($username ? '@'.$username : 'X account'),
            'username' => $username,
            'picture_url' => $response->json('data.profile_image_url'),
        ];
    }

    /**
     * Chunked upload (initialize, append, finalize). X bills each uploaded
     * media object, so callers keep the returned id and reuse it on retry.
     *
     * @return array{id: string, state: string, check_after: int, expires_at: string}
     */
    public function uploadMedia(SocialAccount $account, string $path, string $mime, string $category): array
    {
        $init = $this->mediaRequest(fn () => Http::withToken($account->access_token)->acceptJson()->withoutRedirecting()->timeout(30)
            ->post(self::MEDIA_URL.'/initialize', [
                'media_type' => $mime,
                'total_bytes' => (int) filesize($path),
                'media_category' => $category,
            ]));

        $id = $init->json('data.id');
        if (! is_string($id) || $id === '') {
            throw new XPublishException('X did not accept the image or video upload. Try again.', 'provider');
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new XPublishException('An image or video for this post could not be read.', 'content');
        }

        try {
            for ($segment = 0; ! feof($handle); $segment++) {
                $chunk = fread($handle, self::CHUNK_BYTES);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $this->mediaRequest(fn () => Http::withToken($account->access_token)->acceptJson()->withoutRedirecting()->timeout(60)
                    ->attach('media', $chunk, 'media')
                    ->post(self::MEDIA_URL.'/'.$id.'/append', ['segment_index' => (string) $segment]));
            }
        } finally {
            fclose($handle);
        }

        $finalize = $this->mediaRequest(fn () => Http::withToken($account->access_token)->acceptJson()->withoutRedirecting()->timeout(30)
            ->post(self::MEDIA_URL.'/'.$id.'/finalize'));

        return [
            'id' => $id,
            'state' => (string) ($finalize->json('data.processing_info.state') ?? 'succeeded'),
            'check_after' => (int) ($finalize->json('data.processing_info.check_after_secs') ?? 0),
            // Media ids expire (about 24 hours); stop reusing one an hour early.
            'expires_at' => now()->addSeconds(max(0, (int) ($init->json('data.expires_after_secs') ?? 86400) - 3600))->toIso8601String(),
        ];
    }

    /** @return array{state: string, check_after: int} */
    public function mediaStatus(SocialAccount $account, string $mediaId): array
    {
        $response = $this->mediaRequest(fn () => Http::withToken($account->access_token)->acceptJson()->withoutRedirecting()->timeout(30)
            ->get(self::MEDIA_URL, ['command' => 'STATUS', 'media_id' => $mediaId]));

        return [
            'state' => (string) ($response->json('data.processing_info.state') ?? 'succeeded'),
            'check_after' => (int) ($response->json('data.processing_info.check_after_secs') ?? 0),
        ];
    }

    /** @param  array<string, mixed>  $postData  `x_media_ids` holds ids from uploadMedia(). */
    public function publish(SocialAccount $account, array $postData): string
    {
        // Composer, planner and API already enforce these rules; checking
        // again here means no path can spend credits on a disallowed post.
        $text = (string) ($postData['body'] ?? '');
        $mediaUrls = array_values(array_unique(array_filter(array_map('strval', (array) ($postData['media_urls'] ?? [])))));
        $mediaIds = array_values((array) ($postData['x_media_ids'] ?? []));
        $errors = app(XContentRules::class)->errors($text, $mediaUrls);
        if ($errors !== []) {
            throw new XPublishException($errors[0], 'content');
        }
        if (count($mediaIds) !== count($mediaUrls)) {
            // Never publish a post without the media the client attached.
            throw new XPublishException('The images or video for this post were not uploaded to X. Try again.', 'content');
        }

        $payload = ['text' => $text];
        if ($mediaIds !== []) {
            $payload['media'] = ['media_ids' => $mediaIds];
        }

        try {
            $response = Http::withToken($account->access_token)->acceptJson()->withoutRedirecting()->timeout(30)
                ->post('https://api.x.com/2/tweets', $payload);
        } catch (ConnectionException) {
            throw new XPublishException('X did not confirm this post. Check X before publishing it again.', 'unknown', false);
        }

        if (! $response->successful()) {
            throw XPublishException::fromResponse($response, true);
        }

        $id = $response->json('data.id');
        if (! is_string($id) || $id === '') {
            throw new XPublishException('X did not confirm this post. Check X before publishing it again.', 'unknown', false);
        }

        return $id;
    }

    /**
     * Media requests never create a post, so every failure is definite: a
     * retry cannot publish twice.
     *
     * @param  callable(): Response  $request
     */
    private function mediaRequest(callable $request): Response
    {
        try {
            $response = $request();
        } catch (ConnectionException) {
            throw new XPublishException('The image or video upload to X did not finish. Try again.', 'provider');
        }

        if (! $response->successful()) {
            $error = XPublishException::fromResponse($response);
            throw $error->category === 'provider'
                ? new XPublishException('X rejected an image or video. Check its format and size.', 'provider')
                : $error;
        }

        return $response;
    }
}
