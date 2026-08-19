<?php

namespace App\Modules\Social\Services\Drivers;

use App\Modules\Social\Models\SocialAccount;

/**
 * Optional provider capability for content that already exists remotely.
 *
 * Publishing remains part of SocialNetworkInterface because every social
 * driver supports it. Updating and deleting are deliberately separate: a
 * local edit must never imply that an unsupported provider was also updated.
 */
interface ManagesPublishedPosts
{
    /** Update an existing remote post. */
    public function updatePublishedPost(SocialAccount $account, string $platformPostId, array $postData): void;

    /** Delete an existing remote post. */
    public function deletePublishedPost(SocialAccount $account, string $platformPostId): void;
}
