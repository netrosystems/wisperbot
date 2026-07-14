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
            'name'        => $res['name'] ?? trim(($res['given_name'] ?? '').' '.($res['family_name'] ?? '')),
            'picture_url' => $res['picture'] ?? null,
        ];
    }

    public function publish(SocialAccount $account, array $postData): string
    {
        $urn = "urn:li:person:{$account->account_id}";
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
