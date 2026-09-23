<?php

namespace App\Modules\Social\Services\Drivers;

use App\Modules\Social\Exceptions\ClientSafePublishException;
use App\Modules\Social\Models\SocialAccount;
use App\Modules\Social\Services\ProviderMediaCache;
use App\Modules\Social\Services\SocialMediaRules;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class FacebookDriver implements ManagesPublishedPosts, SocialNetworkInterface
{
    use GuardsPublishOutcome;

    public function network(): string
    {
        return 'facebook';
    }

    public function fetchAccountInfo(string $accessToken): array
    {
        $response = Http::timeout(15)->get('https://graph.facebook.com/v25.0/me', [
            'fields' => 'id,name,picture',
            'access_token' => $accessToken,
        ]);
        if (! $response->successful()) {
            throw new \RuntimeException('Facebook profile lookup failed (HTTP '.$response->status().'): '.$response->body());
        }

        $res = $response->json();
        if (empty($res['id'])) {
            throw new \RuntimeException('Facebook returned no account identity.');
        }

        return [
            'account_id' => $res['id'] ?? '',
            'name' => $res['name'] ?? '',
            'picture_url' => $res['picture']['data']['url'] ?? null,
        ];
    }

    /**
     * Up to 10 images (one photo post, or unpublished photos attached to one
     * feed post), 1 video, or text. Photos uploaded for a multi-photo post are
     * kept in the media cache so a retry does not upload them again.
     */
    public function publish(SocialAccount $account, array $postData): string
    {
        $pageId = $account->meta['page_id'] ?? $account->account_id;
        $token = $account->access_token;
        $message = $postData['body'] ?? '';
        $mediaUrls = array_values(array_filter($postData['media_urls'] ?? [], fn ($u) => is_string($u) && $u !== ''));
        $cache = ($postData['media_cache'] ?? null) instanceof ProviderMediaCache ? $postData['media_cache'] : null;

        if (($videoUrl = (new SocialMediaRules)->firstVideo($mediaUrls)) !== null) {
            if (count($mediaUrls) > 1) {
                throw new ClientSafePublishException('Facebook posts can have up to 10 images, or 1 video.');
            }

            // Facebook fetches the file itself and processes it after replying.
            $response = $this->createRequest(fn () => Http::asForm()->timeout(60)
                ->post("https://graph-video.facebook.com/v25.0/{$pageId}/videos", [
                    'file_url' => $videoUrl,
                    'description' => $message,
                    'access_token' => $token,
                ]));

            return $this->createdId($response);
        }

        // Single image → POST /{page}/photos
        if (count($mediaUrls) === 1) {
            $response = $this->createRequest(fn () => Http::asForm()->timeout(60)
                ->post("https://graph.facebook.com/v25.0/{$pageId}/photos", [
                    'url' => $mediaUrls[0],
                    'caption' => $message,
                    'access_token' => $token,
                ]));

            return is_string($response->json('post_id')) && $response->successful()
                ? $response->json('post_id')
                : $this->createdId($response);
        }

        // Multiple images → upload each as unpublished, then publish together
        if (count($mediaUrls) > 1) {
            if (count($mediaUrls) > SocialMediaRules::MAX_FACEBOOK_IMAGES) {
                throw new ClientSafePublishException('Facebook posts can have up to 10 images, or 1 video.');
            }

            $attachedMedia = [];
            foreach ($mediaUrls as $url) {
                $cached = $cache !== null ? $cache->get($url) : null;
                $photoId = $cached['id'] ?? null;
                if ($photoId === null) {
                    $upload = Http::asForm()->timeout(60)->post("https://graph.facebook.com/v25.0/{$pageId}/photos", [
                        'url' => $url,
                        'published' => 'false',
                        'access_token' => $token,
                    ]);
                    $photoId = $upload->json('id');
                    if (! $upload->successful() || ! is_string($photoId) || $photoId === '') {
                        throw new \RuntimeException('Facebook photo upload failed: '.mb_substr($upload->body(), 0, 500));
                    }
                    if ($cache !== null) {
                        $cache->put($url, ['id' => $photoId], 23 * 3600);
                    }
                }

                $attachedMedia[] = ['media_fbid' => $photoId];
            }

            $response = $this->createRequest(fn () => Http::timeout(60)
                ->post("https://graph.facebook.com/v25.0/{$pageId}/feed", [
                    'message' => $message,
                    'attached_media' => $attachedMedia,
                    'access_token' => $token,
                ]));

            return $this->createdId($response);
        }

        // Text-only post
        $response = $this->createRequest(fn () => Http::timeout(60)
            ->post("https://graph.facebook.com/v25.0/{$pageId}/feed", array_filter([
                'message' => $message,
                'link' => $postData['link'] ?? null,
                'access_token' => $token,
            ])));

        return $this->createdId($response);
    }

    public function updatePublishedPost(SocialAccount $account, string $platformPostId, array $postData): void
    {
        // A video post's id has no page prefix; videos keep their text in
        // `description`, feed and photo posts in `message`.
        $field = str_contains($platformPostId, '_') ? 'message' : 'description';
        $response = Http::timeout(20)
            ->asForm()
            ->post($this->objectUrl($platformPostId), [
                $field => $postData['body'] ?? '',
                'access_token' => $account->access_token,
            ]);

        $this->assertMutationSucceeded($response, 'update');
    }

    public function deletePublishedPost(SocialAccount $account, string $platformPostId): void
    {
        $response = Http::timeout(20)
            ->asForm()
            ->delete($this->objectUrl($platformPostId), [
                'access_token' => $account->access_token,
            ]);

        $this->assertMutationSucceeded($response, 'delete');
    }

    private function objectUrl(string $platformPostId): string
    {
        if ($platformPostId === '' || ! preg_match('/^[A-Za-z0-9_:\-]+$/', $platformPostId)) {
            throw new \InvalidArgumentException('Facebook returned an invalid post ID.');
        }

        return 'https://graph.facebook.com/v25.0/'.rawurlencode($platformPostId);
    }

    private function assertMutationSucceeded(Response $response, string $operation): void
    {
        $payload = $response->json();
        $success = $response->successful()
            && ($payload === true || data_get($payload, 'success') === true || is_string(data_get($payload, 'id')));

        if ($success) {
            return;
        }

        $providerMessage = (string) ($response->json('error.message') ?? 'Unknown Graph API error.');
        $providerCode = $response->json('error.code');

        throw new \RuntimeException(sprintf(
            'Facebook %s failed (HTTP %d%s): %s',
            $operation,
            $response->status(),
            $providerCode !== null ? ", code {$providerCode}" : '',
            mb_substr($providerMessage, 0, 500)
        ));
    }
}
