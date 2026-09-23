<?php

namespace App\Modules\Social\Services\Drivers;

use App\Modules\Social\Exceptions\ClientSafePublishException;
use App\Modules\Social\Exceptions\PublishOutcomeUnknownException;
use App\Modules\Social\Models\SocialAccount;
use App\Modules\Social\Services\ProviderMediaCache;
use App\Modules\Social\Services\SocialMediaFiles;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

class LinkedInDriver implements SocialNetworkInterface
{
    use GuardsPublishOutcome;

    private const MAX_IMAGE_BYTES = 10 * 1024 * 1024;

    /** Capped to what the social queue can download and upload in time. */
    private const MAX_VIDEO_BYTES = 50 * 1024 * 1024;

    /** How long one publish attempt waits for LinkedIn to process a video. */
    private const PROCESSING_WAIT_SECONDS = 45;

    public function network(): string
    {
        return 'linkedin';
    }

    public function fetchAccountInfo(string $accessToken): array
    {
        // The OAuth flow requests OpenID Connect scopes, so identity must be read
        // from the OIDC UserInfo endpoint rather than the retired legacy /v2/me
        // member-profile response shape.
        $response = Http::withToken($accessToken)
            ->acceptJson()
            ->get('https://api.linkedin.com/v2/userinfo');

        if (! $response->successful()) {
            throw new \RuntimeException('LinkedIn profile lookup failed (HTTP '.$response->status().'): '.$response->body());
        }

        $res = $response->json();

        return [
            'account_id' => $res['sub'] ?? '',
            'name' => $res['name'] ?? trim(($res['given_name'] ?? '').' '.($res['family_name'] ?? '')),
            'picture_url' => $res['picture'] ?? null,
        ];
    }

    /**
     * Company Pages the member administers, from the Community Management API.
     * Returns [] when the token lacks r_organization_admin, so a member-only
     * connection still succeeds.
     *
     * @return list<array{id:string,name:string,vanity_name:?string,picture_url:?string}>
     */
    public function fetchOrganizations(string $accessToken): array
    {
        $response = Http::withToken($accessToken)
            ->acceptJson()
            ->withHeaders(['X-Restli-Protocol-Version' => '2.0.0'])
            ->get('https://api.linkedin.com/v2/organizationAcls', [
                'q' => 'roleAssignee',
                'role' => 'ADMINISTRATOR',
                'state' => 'APPROVED',
                'projection' => '(elements*(organization~(id,localizedName,vanityName,logoV2(original~:playableStreams))))',
            ]);

        if ($response->status() === 403 || $response->status() === 401) {
            return [];
        }

        if (! $response->successful()) {
            throw new \RuntimeException('LinkedIn organization lookup failed (HTTP '.$response->status().'): '.$response->body());
        }

        $organizations = [];
        foreach ($response->json('elements') ?? [] as $element) {
            $organization = $element['organization~'] ?? null;
            if (! is_array($organization)) {
                continue;
            }
            $id = (string) ($organization['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $organizations[$id] = [
                'id' => $id,
                'name' => (string) ($organization['localizedName'] ?? ('Company '.$id)),
                'vanity_name' => $organization['vanityName'] ?? null,
                'picture_url' => $this->logoUrl($organization),
            ];
        }

        return array_values($organizations);
    }

    /** The logo is nested behind LinkedIn's decorated image response. */
    private function logoUrl(array $organization): ?string
    {
        $elements = $organization['logoV2']['original~']['elements'] ?? [];
        foreach ($elements as $element) {
            $url = $element['identifiers'][0]['identifier'] ?? null;
            if (is_string($url) && $url !== '') {
                return $url;
            }
        }

        return null;
    }

    /**
     * Text with at most 1 image or 1 video. LinkedIn needs the file itself:
     * it is registered, uploaded, and kept in the media cache so a retry
     * reuses the asset instead of uploading the file again.
     */
    public function publish(SocialAccount $account, array $postData): string
    {
        // A page connection posts as the organization; everything else posts as
        // the member who authorized it.
        $urn = ($account->meta['actor_type'] ?? 'member') === 'organization'
            ? "urn:li:organization:{$account->account_id}"
            : "urn:li:person:{$account->account_id}";
        $mediaUrls = array_values(array_filter($postData['media_urls'] ?? [], fn ($u) => is_string($u) && $u !== ''));
        $cache = ($postData['media_cache'] ?? null) instanceof ProviderMediaCache ? $postData['media_cache'] : null;

        if (count($mediaUrls) > 1) {
            throw new ClientSafePublishException('LinkedIn posts can have 1 image or 1 video.');
        }

        $shareContent = [
            'shareCommentary' => ['text' => $postData['body'] ?? ''],
            'shareMediaCategory' => 'NONE',
        ];

        if ($mediaUrls !== []) {
            [$asset, $category] = $this->asset($account, $urn, $mediaUrls[0], $cache);
            $shareContent['shareMediaCategory'] = $category;
            $shareContent['media'] = [['status' => 'READY', 'media' => $asset]];
        }

        $response = $this->createRequest(fn () => Http::withToken($account->access_token)
            ->withHeaders(['X-Restli-Protocol-Version' => '2.0.0'])
            ->timeout(60)
            ->post('https://api.linkedin.com/v2/ugcPosts', [
                'author' => $urn,
                'lifecycleState' => 'PUBLISHED',
                'specificContent' => ['com.linkedin.ugc.ShareContent' => $shareContent],
                'visibility' => ['com.linkedin.ugc.MemberNetworkVisibility' => 'PUBLIC'],
            ]));

        if (! $response->successful()) {
            throw new \RuntimeException('LinkedIn publish failed (HTTP '.$response->status().'): '.$response->body());
        }

        $id = $response->header('X-RestLi-Id') ?: $response->json('id');

        return is_string($id) && $id !== ''
            ? $id
            : throw new PublishOutcomeUnknownException('linkedin', new \RuntimeException('LinkedIn publish succeeded but returned no post ID.'));
    }

    /**
     * Register and upload one file, or reuse the asset uploaded on an earlier
     * attempt. Videos must finish processing before they can be shared.
     *
     * @return array{0: string, 1: string} Asset URN and share media category.
     */
    private function asset(SocialAccount $account, string $ownerUrn, string $url, ?ProviderMediaCache $cache): array
    {
        $cached = $cache?->get($url);
        if ($cached !== null) {
            $asset = (string) $cached['id'];
            $category = (string) ($cached['category'] ?? 'IMAGE');
        } else {
            $files = app(SocialMediaFiles::class);
            try {
                $file = $files->fetch($url, self::MAX_VIDEO_BYTES);
            } catch (\RuntimeException $e) {
                throw new ClientSafePublishException($e->getMessage());
            }

            try {
                $category = match (true) {
                    in_array($file['mime'], ['image/jpeg', 'image/png', 'image/gif'], true) => 'IMAGE',
                    in_array($file['mime'], ['video/mp4', 'video/quicktime'], true) => 'VIDEO',
                    default => throw new ClientSafePublishException('LinkedIn accepts JPG, PNG and GIF images, and MP4 or MOV videos.'),
                };
                if ($category === 'IMAGE' && $file['size'] > self::MAX_IMAGE_BYTES) {
                    throw new ClientSafePublishException('LinkedIn images must be 10 MB or smaller.');
                }

                $register = Http::withToken($account->access_token)->acceptJson()->timeout(30)
                    ->post('https://api.linkedin.com/v2/assets?action=registerUpload', [
                        'registerUploadRequest' => [
                            'recipes' => [$category === 'VIDEO' ? 'urn:li:digitalmediaRecipe:feedshare-video' : 'urn:li:digitalmediaRecipe:feedshare-image'],
                            'owner' => $ownerUrn,
                            'serviceRelationships' => [['relationshipType' => 'OWNER', 'identifier' => 'urn:li:userGeneratedContent']],
                        ],
                    ]);
                $asset = $register->json('value.asset');
                // The mechanism key contains dots, so it cannot be read with dot notation.
                $mechanism = (array) ($register->json('value.uploadMechanism') ?? []);
                $uploadUrl = $mechanism['com.linkedin.digitalmedia.uploading.MediaUploadHttpRequest']['uploadUrl'] ?? null;
                if (! $register->successful() || ! is_string($asset) || ! is_string($uploadUrl) || ! str_starts_with($uploadUrl, 'https://')) {
                    throw new \RuntimeException('LinkedIn media registration failed (HTTP '.$register->status().'): '.mb_substr($register->body(), 0, 500));
                }

                $stream = Utils::streamFor(Utils::tryFopen($file['path'], 'rb'));
                try {
                    $upload = Http::withToken($account->access_token)->timeout(90)
                        ->withBody($stream, 'application/octet-stream')
                        ->put($uploadUrl);
                } finally {
                    $stream->close();
                }
                if (! $upload->successful()) {
                    throw new \RuntimeException('LinkedIn media upload failed (HTTP '.$upload->status().').');
                }
            } finally {
                $files->release($file);
            }

            $cache?->put($url, ['id' => $asset, 'category' => $category], 23 * 3600);
        }

        if ($category === 'VIDEO') {
            $this->waitForVideo($account, $asset);
        }

        return [$asset, $category];
    }

    private function waitForVideo(SocialAccount $account, string $asset): void
    {
        $id = rawurlencode(str_replace('urn:li:digitalmediaAsset:', '', $asset));
        for ($waited = 0; ; $waited += 5) {
            $status = Http::withToken($account->access_token)->acceptJson()->timeout(20)
                ->get("https://api.linkedin.com/v2/assets/{$id}")
                ->json('recipes.0.status');

            if ($status === 'AVAILABLE') {
                return;
            }
            if (in_array($status, ['CLIENT_ERROR', 'SERVER_ERROR', 'INCOMPLETE'], true)) {
                throw new ClientSafePublishException('LinkedIn could not process this video. Check that it is a standard MP4 or MOV file, then publish again.');
            }
            if ($waited >= self::PROCESSING_WAIT_SECONDS) {
                throw new ClientSafePublishException('LinkedIn is still processing the video. Publish again in a few minutes; it will not be uploaded twice.');
            }

            Sleep::for(5)->seconds();
        }
    }
}
