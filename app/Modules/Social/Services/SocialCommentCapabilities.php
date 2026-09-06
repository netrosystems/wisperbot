<?php

namespace App\Modules\Social\Services;

/** Platform support is distinct from OAuth connection and verified account grants. */
class SocialCommentCapabilities
{
    public static function implemented(string $network): bool
    {
        return in_array($network, ['facebook', 'instagram'], true);
    }

    public static function catalog(): array
    {
        $enabled = (bool) config('social_comments.enabled');

        return [
            'facebook' => ['label' => 'Facebook', 'implemented' => true, 'enabled' => $enabled, 'summary' => $enabled ? 'Comments require Page access' : 'Comments not enabled', 'detail' => 'Facebook Pages only. Reading, public replies, hiding and deletion require approved permissions and verified Page access. Personal profiles are not supported.'],
            'instagram' => ['label' => 'Instagram', 'implemented' => true, 'enabled' => $enabled, 'summary' => $enabled ? 'Comments require professional access' : 'Comments not enabled', 'detail' => 'Professional accounts linked to a Facebook Page through this connection. Public replies, hiding and deletion require comment permissions. Personal accounts and complete ad coverage are not supported.'],
            'linkedin' => ['label' => 'LinkedIn', 'implemented' => false, 'enabled' => false, 'summary' => 'Comments not integrated', 'detail' => 'This connection is for publishing. Comments require a separate Community Management integration, approved access and the appropriate member or organization permissions.'],
            'youtube' => ['label' => 'YouTube', 'implemented' => false, 'enabled' => false, 'summary' => 'Comments not integrated', 'detail' => 'This connection is for publishing. YouTube supports comments through a separate integration with additional authorization, quota controls and thread-specific reply permissions.'],
            'tiktok' => ['label' => 'TikTok', 'implemented' => false, 'enabled' => false, 'summary' => 'Comments not integrated', 'detail' => 'The current posting connection does not provide comment management. TikTok business comment APIs require separate approved business access; research comment access does not allow customer-service replies.'],
        ];
    }
}
