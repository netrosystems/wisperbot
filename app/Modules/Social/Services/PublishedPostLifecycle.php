<?php

namespace App\Modules\Social\Services;

use App\Modules\Social\Exceptions\PublishedPostLifecycleException;
use App\Modules\Social\Models\SocialAccount;
use App\Modules\Social\Models\SocialPost;
use App\Modules\Social\Models\SocialPostAccount;
use App\Modules\Social\Services\Drivers\FacebookDriver;
use App\Modules\Social\Services\Drivers\InstagramSocialDriver;
use App\Modules\Social\Services\Drivers\ManagesPublishedPosts;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PublishedPostLifecycle
{
    /** @var array<string, ManagesPublishedPosts> */
    private array $drivers;

    public function __construct()
    {
        $this->drivers = [
            'facebook' => new FacebookDriver,
            'instagram' => new InstagramSocialDriver,
        ];
    }

    /**
     * Return safe UI capabilities without exposing account tokens or provider
     * internals. A published legacy row without per-account IDs is considered
     * immutable because WisperBot cannot prove which remote object it controls.
     *
     * @return array{has_remote_posts: bool, can_update: bool, can_delete: bool, reason: ?string}
     */
    public function capabilities(SocialPost $post): array
    {
        if ($post->status === 'publishing') {
            return $this->capability(false, false, false, 'Wait for publishing to finish before changing this post.');
        }

        $this->recoverPublishedLinks($post);
        $links = $this->activePublishedLinks($post);

        if ($links->isEmpty()) {
            if ($this->hasCompletedRemoteDeletes($post)) {
                return $this->capability(true, false, true, 'Facebook deletion is complete. Delete again to finish local cleanup.');
            }

            if ($post->status === 'published') {
                return $this->capability(
                    false,
                    false,
                    false,
                    'This older published post has no remote post mapping, so changing it could leave Facebook out of sync.'
                );
            }

            return $this->capability(false, true, true, null);
        }

        $deleteReason = $this->unsupportedReason($post, $links, 'delete');
        $updateReason = $this->unsupportedReason($post, $links, 'update');

        return $this->capability(
            true,
            $updateReason === null,
            $deleteReason === null,
            $updateReason ?? $deleteReason,
            $updateReason,
            $deleteReason,
        );
    }

    /**
     * Update all already-published provider copies, then update the local row.
     * Re-sending the same text is idempotent, which makes a retry safe after a
     * partial provider failure.
     */
    public function update(SocialPost $post, array $validated): void
    {
        $this->withPostLock($post, function () use ($post, $validated): void {
            $post->refresh();
            $this->recoverPublishedLinks($post);
            $links = $this->activePublishedLinks($post);

            if ($links->isEmpty()) {
                if ($this->hasCompletedRemoteDeletes($post)) {
                    throw new PublishedPostLifecycleException(
                        'This post was already removed from Facebook and is awaiting local cleanup. Delete it again to finish.'
                    );
                }

                if ($post->status === 'published') {
                    throw new PublishedPostLifecycleException(
                        'This published post cannot be updated because its Facebook post ID is unavailable. Publish a new post instead.'
                    );
                }

                $post->update($validated);

                return;
            }

            $this->assertSupported($post, $links, 'update');
            $this->assertPublishedFieldsAreSafe($post, $validated);

            $failures = [];

            foreach ($links as $link) {
                try {
                    $driver = $this->drivers[$link->account->network];
                    $driver->updatePublishedPost($link->account, $link->platform_post_id, $validated);
                    $link->update(['error' => null]);
                } catch (\Throwable $e) {
                    $failures[] = $link->account->name ?: 'social account';
                    $link->update(['error' => 'The remote post update failed. Retry to reconcile this account.']);
                    Log::error('Published social post update failed', [
                        'post_id' => $post->id,
                        'social_account_id' => $link->social_account_id,
                        'network' => $link->account->network,
                        'provider_post_id' => $link->platform_post_id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            if ($failures !== []) {
                throw new PublishedPostLifecycleException(
                    'Facebook could not update '.count($failures).' Page post(s). Some Pages may already contain the new text; retry the same update to reconcile them.'
                );
            }

            // Only fields Facebook can keep consistent are mutable after publish.
            $post->update([
                'title' => $validated['title'] ?? null,
                'body' => $validated['body'],
            ]);
        });
    }

    /**
     * Delete every remote copy before removing the local record. Successful
     * remote deletes are checkpointed so a retry after partial failure will not
     * call Meta again for an object that is already gone.
     */
    public function delete(SocialPost $post): void
    {
        $this->withPostLock($post, function () use ($post): void {
            $post->refresh();
            $this->recoverPublishedLinks($post);
            $links = $this->activePublishedLinks($post);

            if ($links->isEmpty()) {
                if ($this->hasCompletedRemoteDeletes($post)) {
                    $this->deleteLocalPost($post);

                    return;
                }

                if ($post->status === 'published') {
                    throw new PublishedPostLifecycleException(
                        'This published post cannot be deleted because its Facebook post ID is unavailable. Remove it directly on Facebook.'
                    );
                }

                $this->deleteLocalPost($post);

                return;
            }

            $this->assertSupported($post, $links, 'delete');
            $failures = [];

            foreach ($links as $link) {
                try {
                    $driver = $this->drivers[$link->account->network];
                    $driver->deletePublishedPost($link->account, $link->platform_post_id);
                    $link->update(['deleted_at' => now(), 'error' => null]);
                } catch (\Throwable $e) {
                    $failures[] = $link->account->name ?: 'social account';
                    $link->update(['error' => 'The remote post deletion failed. Retry to finish deleting it.']);
                    Log::error('Published social post delete failed', [
                        'post_id' => $post->id,
                        'social_account_id' => $link->social_account_id,
                        'network' => $link->account->network,
                        'provider_post_id' => $link->platform_post_id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            if ($failures !== []) {
                throw new PublishedPostLifecycleException(
                    'Meta could not delete '.count($failures).' published post(s). Successful deletions were saved; retry to finish the remaining accounts.'
                );
            }

            $this->deleteLocalPost($post);
        });
    }

    private function hasCompletedRemoteDeletes(SocialPost $post): bool
    {
        return $post->accountLinks()
            ->where('status', 'published')
            ->whereNotNull('platform_post_id')
            ->whereNotNull('deleted_at')
            ->exists();
    }

    private function deleteLocalPost(SocialPost $post): void
    {
        DB::transaction(function () use ($post): void {
            $post->accountLinks()->delete();
            $post->delete();
        });
    }

    private function activePublishedLinks(SocialPost $post): Collection
    {
        return $post->accountLinks()
            ->where('status', 'published')
            ->whereNotNull('platform_post_id')
            ->whereNull('deleted_at')
            ->with(['account' => fn ($query) => $query->select([
                'id', 'workspace_id', 'network', 'account_id', 'name', 'access_token', 'meta',
            ])])
            ->get();
    }

    /**
     * Older publisher workers stored provider IDs in publish_results but did
     * not always persist the corresponding social_media_post_accounts row.
     * Recover only mappings that can be proven to belong to this post's own
     * workspace and selected accounts. This keeps published-post management
     * available after an upgrade without trusting arbitrary JSON IDs.
     */
    private function recoverPublishedLinks(SocialPost $post): void
    {
        if ($post->status !== 'published' || ! is_array($post->publish_results)) {
            return;
        }

        $targetIds = collect($post->target_accounts ?? [])
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique();

        if ($targetIds->isEmpty()) {
            return;
        }

        $accounts = SocialAccount::query()
            ->where('workspace_id', $post->workspace_id)
            ->whereIn('id', $targetIds->all())
            ->get(['id'])
            ->keyBy('id');

        foreach ($post->publish_results as $accountId => $result) {
            $accountId = (int) $accountId;
            $platformId = is_array($result) ? trim((string) ($result['post_id'] ?? '')) : '';

            if (
                ! $targetIds->contains($accountId)
                || ! $accounts->has($accountId)
                || ($result['status'] ?? null) !== 'published'
                || $platformId === ''
                || strlen($platformId) > 256
            ) {
                continue;
            }

            SocialPostAccount::firstOrCreate(
                ['post_id' => $post->id, 'social_account_id' => $accountId],
                [
                    'status' => 'published',
                    'platform_post_id' => $platformId,
                    'published_at' => $post->published_at ?? now(),
                ],
            );
        }
    }

    private function unsupportedReason(SocialPost $post, Collection $links, string $operation): ?string
    {
        foreach ($links as $link) {
            if (! $link->account || (int) $link->account->workspace_id !== (int) $post->workspace_id) {
                return 'A connected account is no longer available. Reconnect it before managing the remote post.';
            }

            if (! isset($this->drivers[$link->account->network])) {
                return 'This post also exists on a platform that WisperBot cannot safely update or delete yet.';
            }

            if ($operation === 'update' && $link->account->network === 'instagram') {
                return 'Instagram does not allow published captions or media to be edited through its API. Delete this post and publish a corrected version instead.';
            }
        }

        return null;
    }

    private function assertSupported(SocialPost $post, Collection $links, string $operation): void
    {
        if ($post->status === 'publishing') {
            $verb = $operation === 'delete' ? 'deleted' : 'updated';
            throw new PublishedPostLifecycleException("Wait for publishing to finish before this post is {$verb}.");
        }

        if ($reason = $this->unsupportedReason($post, $links, $operation)) {
            throw new PublishedPostLifecycleException($reason);
        }
    }

    private function assertPublishedFieldsAreSafe(SocialPost $post, array $validated): void
    {
        $currentTargets = collect($post->target_accounts ?? [])->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();
        $newTargets = collect($validated['target_accounts'] ?? [])->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();

        if ($currentTargets !== $newTargets) {
            throw new PublishedPostLifecycleException(
                'Connected Pages cannot be changed after publication. Create a new post for a different Page.'
            );
        }

        $currentMedia = array_values(array_filter($post->media_urls ?? []));
        $newMedia = array_values(array_filter($validated['media_urls'] ?? []));

        if ($currentMedia !== $newMedia) {
            throw new PublishedPostLifecycleException(
                'Media cannot be replaced after Facebook publication. You can update the text or create a new post with different media.'
            );
        }

        if (! empty($validated['scheduled_at'])) {
            throw new PublishedPostLifecycleException('A published Facebook post cannot be scheduled again.');
        }
    }

    private function withPostLock(SocialPost $post, callable $callback): void
    {
        try {
            Cache::lock("social-post-lifecycle:{$post->id}", 120)->block(5, $callback);
        } catch (LockTimeoutException) {
            throw new PublishedPostLifecycleException('Another update is already running for this post. Please try again.');
        }
    }

    private function capability(
        bool $hasRemote,
        bool $canUpdate,
        bool $canDelete,
        ?string $reason,
        ?string $updateReason = null,
        ?string $deleteReason = null,
    ): array {
        return [
            'has_remote_posts' => $hasRemote,
            'can_update' => $canUpdate,
            'can_delete' => $canDelete,
            'reason' => $reason,
            'update_reason' => $updateReason,
            'delete_reason' => $deleteReason,
        ];
    }
}
