<?php

namespace App\Modules\Social\Services\Drivers;

use App\Modules\Social\Models\SocialAccount;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class FacebookDriver implements ManagesPublishedPosts, SocialNetworkInterface
{
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

    public function publish(SocialAccount $account, array $postData): string
    {
        $pageId = $account->meta['page_id'] ?? $account->account_id;
        $token = $account->access_token;
        $message = $postData['body'] ?? '';
        $mediaUrls = array_values(array_filter($postData['media_urls'] ?? [], fn ($u) => $u !== null && $u !== ''));

        // Single image → POST /{page}/photos
        if (count($mediaUrls) === 1) {
            $res = Http::post("https://graph.facebook.com/v25.0/{$pageId}/photos", [
                'url' => $mediaUrls[0],
                'caption' => $message,
                'access_token' => $token,
            ])->json();

            if (! isset($res['id'])) {
                throw new \RuntimeException('Facebook photo publish failed: '.json_encode($res));
            }

            return $res['post_id'] ?? $res['id'];
        }

        // Multiple images → upload each as unpublished, then publish together
        if (count($mediaUrls) > 1) {
            $attachedMedia = [];

            foreach ($mediaUrls as $url) {
                $upload = Http::post("https://graph.facebook.com/v25.0/{$pageId}/photos", [
                    'url' => $url,
                    'published' => false,
                    'access_token' => $token,
                ])->json();

                if (! isset($upload['id'])) {
                    throw new \RuntimeException('Facebook photo upload failed: '.json_encode($upload));
                }

                $attachedMedia[] = ['media_fbid' => $upload['id']];
            }

            $res = Http::post("https://graph.facebook.com/v25.0/{$pageId}/feed", [
                'message' => $message,
                'attached_media' => $attachedMedia,
                'access_token' => $token,
            ])->json();

            if (! isset($res['id'])) {
                throw new \RuntimeException('Facebook multi-photo publish failed: '.json_encode($res));
            }

            return $res['id'];
        }

        // Text-only post
        $res = Http::post("https://graph.facebook.com/v25.0/{$pageId}/feed", [
            'message' => $message,
            'link' => $postData['link'] ?? null,
            'access_token' => $token,
        ])->json();

        if (! isset($res['id'])) {
            throw new \RuntimeException('Facebook publish failed: '.json_encode($res));
        }

        return $res['id'];
    }

    public function updatePublishedPost(SocialAccount $account, string $platformPostId, array $postData): void
    {
        $response = Http::timeout(20)
            ->asForm()
            ->post($this->objectUrl($platformPostId), [
                'message' => $postData['body'] ?? '',
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
