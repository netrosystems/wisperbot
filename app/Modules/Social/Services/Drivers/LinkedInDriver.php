<?php

namespace App\Modules\Social\Services\Drivers;

use App\Modules\Social\Models\SocialAccount;
use Illuminate\Support\Facades\Http;

class LinkedInDriver implements SocialNetworkInterface
{
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

    public function publish(SocialAccount $account, array $postData): string
    {
        // A page connection posts as the organization; everything else posts as
        // the member who authorized it.
        $urn = ($account->meta['actor_type'] ?? 'member') === 'organization'
            ? "urn:li:organization:{$account->account_id}"
            : "urn:li:person:{$account->account_id}";
        $response = Http::withToken($account->access_token)
            ->withHeaders(['X-Restli-Protocol-Version' => '2.0.0'])
            ->post('https://api.linkedin.com/v2/ugcPosts', [
                'author' => $urn,
                'lifecycleState' => 'PUBLISHED',
                'specificContent' => [
                    'com.linkedin.ugc.ShareContent' => [
                        'shareCommentary' => ['text' => $postData['body'] ?? ''],
                        'shareMediaCategory' => 'NONE',
                    ],
                ],
                'visibility' => ['com.linkedin.ugc.MemberNetworkVisibility' => 'PUBLIC'],
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException('LinkedIn publish failed (HTTP '.$response->status().'): '.$response->body());
        }

        $id = $response->header('X-RestLi-Id') ?: $response->json('id');

        return is_string($id) && $id !== ''
            ? $id
            : throw new \RuntimeException('LinkedIn publish succeeded but returned no post ID.');
    }
}
