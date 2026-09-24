<?php

namespace App\Modules\Social\Services\Drivers;

use App\Modules\Social\Exceptions\ClientSafePublishException;
use App\Modules\Social\Models\SocialAccount;
use App\Modules\Social\Services\ProviderMediaCache;
use App\Modules\Social\Services\SocialMediaRules;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

class InstagramSocialDriver implements ManagesPublishedPosts, SocialNetworkInterface
{
    use GuardsPublishOutcome;

    /** How long one publish attempt waits for Instagram to process media. */
    private const PROCESSING_WAIT_SECONDS = 60;

    public function network(): string
    {
        return 'instagram';
    }

    public function fetchAccountInfo(string $accessToken): array
    {
        $response = Http::timeout(15)->get('https://graph.instagram.com/me', [
            'fields' => 'id,name,profile_picture_url',
            'access_token' => $accessToken,
        ]);
        if (! $response->successful()) {
            throw new \RuntimeException('Instagram profile lookup failed (HTTP '.$response->status().'): '.$response->body());
        }

        $res = $response->json();
        if (empty($res['id'])) {
            throw new \RuntimeException('Instagram returned no account identity.');
        }

        return [
            'account_id' => $res['id'] ?? '',
            'name' => $res['name'] ?? '',
            'picture_url' => $res['profile_picture_url'] ?? null,
        ];
    }

    /**
     * One JPG image, one video (published as a Reel), or a carousel of 2–10
     * images and videos. Instagram fetches each file itself; containers stay
     * valid for 24 hours, so ready ones are reused when a publish is retried.
     */
    public function publish(SocialAccount $account, array $postData): string
    {
        $igUserId = $account->account_id;
        $token = $account->access_token;
        $caption = (string) ($postData['body'] ?? '');
        $mediaUrls = array_values(array_filter($postData['media_urls'] ?? [], fn ($u) => is_string($u) && $u !== ''));
        $cache = ($postData['media_cache'] ?? null) instanceof ProviderMediaCache ? $postData['media_cache'] : null;
        $rules = new SocialMediaRules;

        if ($mediaUrls === []) {
            throw new ClientSafePublishException('Instagram posts need at least one image or video.');
        }
        if (count($mediaUrls) > SocialMediaRules::MAX_INSTAGRAM_ITEMS) {
            throw new ClientSafePublishException('Instagram posts can have up to 10 images or videos.');
        }

        if (count($mediaUrls) === 1) {
            $url = $mediaUrls[0];
            $fields = $rules->isVideo($url)
                ? ['media_type' => 'REELS', 'video_url' => $url, 'caption' => $caption]
                : ['image_url' => $url, 'caption' => $caption];
            // The caption is part of this container, so an edited caption needs a new one.
            $creationId = $this->container($igUserId, $token, $fields, $cache, 'post:'.$url.'#'.md5($caption));
            $this->waitUntilReady([$creationId], $token, $cache, ['post:'.$url.'#'.md5($caption)]);
        } else {
            $children = [];
            $keys = [];
            foreach ($mediaUrls as $url) {
                $fields = $rules->isVideo($url)
                    ? ['media_type' => 'VIDEO', 'video_url' => $url, 'is_carousel_item' => 'true']
                    : ['image_url' => $url, 'is_carousel_item' => 'true'];
                $children[] = $this->container($igUserId, $token, $fields, $cache, 'item:'.$url);
                $keys[] = 'item:'.$url;
            }
            $this->waitUntilReady($children, $token, $cache, $keys);

            $creationId = $this->container($igUserId, $token, [
                'media_type' => 'CAROUSEL',
                'children' => implode(',', $children),
                'caption' => $caption,
            ], null, '');
            $this->waitUntilReady([$creationId], $token, null, ['']);
        }

        $response = $this->createRequest(fn () => Http::asForm()->timeout(60)
            ->post("https://graph.facebook.com/v25.0/{$igUserId}/media_publish", [
                'creation_id' => $creationId,
                'access_token' => $token,
            ]));

        return $this->createdId($response);
    }

    /** @param  array<string, string>  $fields */
    private function container(string $igUserId, string $token, array $fields, ?ProviderMediaCache $cache, string $key): string
    {
        if ($cache !== null && ($cached = $cache->get($key)) !== null) {
            return (string) $cached['id'];
        }

        $response = Http::asForm()->timeout(60)
            ->post("https://graph.facebook.com/v25.0/{$igUserId}/media", $fields + ['access_token' => $token]);
        $id = $response->json('id');
        if (! $response->successful() || ! is_string($id) || $id === '') {
            throw new \RuntimeException('Instagram container creation failed: '.mb_substr($response->body(), 0, 500));
        }

        $cache?->put($key, ['id' => $id], 23 * 3600);

        return $id;
    }

    /**
     * Instagram processes videos after accepting them. Wait a bounded time;
     * a container that is still processing is kept for the next attempt.
     *
     * @param  list<string>  $containerIds
     * @param  list<string>  $cacheKeys  Same order as the ids.
     */
    private function waitUntilReady(array $containerIds, string $token, ?ProviderMediaCache $cache, array $cacheKeys): void
    {
        $waited = 0;
        foreach ($containerIds as $index => $id) {
            while (true) {
                $status = (string) Http::timeout(20)
                    ->get("https://graph.facebook.com/v25.0/{$id}", ['fields' => 'status_code', 'access_token' => $token])
                    ->json('status_code', 'IN_PROGRESS');

                if ($status === 'FINISHED') {
                    break;
                }
                if (in_array($status, ['ERROR', 'EXPIRED'], true)) {
                    $cache?->forget($cacheKeys[$index] ?? '');
                    throw new ClientSafePublishException('Instagram could not process an image or video in this post. Check that images are JPG and videos are MP4 or MOV, then publish again.');
                }
                if ($waited >= self::PROCESSING_WAIT_SECONDS) {
                    throw new ClientSafePublishException('Instagram is still processing the video. Publish again in a few minutes; it will not be uploaded twice.');
                }

                Sleep::for(5)->seconds();
                $waited += 5;
            }
        }
    }

    public function updatePublishedPost(SocialAccount $account, string $platformPostId, array $postData): void
    {
        throw new \RuntimeException('Instagram does not support editing a published post. Create a new post instead.');
    }

    public function deletePublishedPost(SocialAccount $account, string $platformPostId): void
    {
        $response = Http::timeout(20)
            ->asForm()
            ->delete($this->objectUrl($platformPostId), [
                'access_token' => $account->access_token,
            ]);

        $this->assertDeleteSucceeded($response);
    }

    private function objectUrl(string $platformPostId): string
    {
        if ($platformPostId === '' || ! preg_match('/^[A-Za-z0-9_:\-]+$/', $platformPostId)) {
            throw new \InvalidArgumentException('Instagram returned an invalid media ID.');
        }

        return 'https://graph.facebook.com/v25.0/'.rawurlencode($platformPostId);
    }

    private function assertDeleteSucceeded(Response $response): void
    {
        $payload = $response->json();
        $success = $response->successful()
            && ($payload === true || data_get($payload, 'success') === true);

        if ($success) {
            return;
        }

        $message = (string) ($response->json('error.message') ?? 'Unknown Graph API error.');
        $code = $response->json('error.code');

        throw new \RuntimeException(sprintf(
            'Instagram delete failed%s: %s',
            $code !== null ? " (Meta code {$code})" : '',
            $message
        ));
    }
}
