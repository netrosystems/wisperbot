<?php

namespace Tests\Feature\Social;

use App\Modules\Social\Models\SocialAccount;
use App\Modules\Social\Models\SocialPost;
use App\Modules\Social\Models\SocialPostAccount;
use App\Modules\Social\Jobs\PublishSocialPostJob;
use App\Modules\Social\Services\SocialPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PublishedFacebookPostLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_composer_upgrades_same_origin_media_to_https_and_redirects_to_posts(): void
    {
        Bus::fake();
        $context = $this->createWorkspaceContext();
        $account = $this->facebookAccount($context['workspace']->id, 'PAGE_1', 'Review Page');

        $this->actingAs($context['user'])
            ->post('/app/social/posts', [
                'title' => 'Scheduled post',
                'body' => 'This post should be scheduled.',
                'media_urls' => ['http://127.0.0.1/storage/media/post.png'],
                'target_accounts' => [$account->id],
                'scheduled_at' => now()->addHour()->toIso8601String(),
                'timezone' => 'UTC',
            ])
            ->assertRedirect(route('client.social.posts.index'))
            ->assertSessionHas('success', 'Post scheduled.');

        $post = SocialPost::query()->latest('id')->firstOrFail();

        $this->assertSame(['https://127.0.0.1/storage/media/post.png'], $post->media_urls);
        $this->assertSame('scheduled', $post->status);
        Bus::assertDispatched(PublishSocialPostJob::class, function (PublishSocialPostJob $job) use ($post): bool {
            return $job->postId === $post->id
                && $job->queue === 'social'
                && $job->delay?->equalTo($post->scheduled_at);
        });
    }

    public function test_due_delayed_job_claims_and_publishes_a_scheduled_post(): void
    {
        $context = $this->createWorkspaceContext();
        $account = $this->facebookAccount($context['workspace']->id, 'PAGE_1', 'Review Page');
        $post = SocialPost::create([
            'workspace_id' => $context['workspace']->id,
            'title' => 'Precisely scheduled post',
            'body' => 'Publish at the requested time.',
            'media_urls' => [],
            'target_accounts' => [$account->id],
            'status' => 'scheduled',
            'scheduled_at' => now()->subSecond(),
            'timezone' => 'UTC',
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'graph.facebook.com/v25.0/PAGE_1/feed' => Http::response(['id' => 'PAGE_1_POST_PRECISE']),
        ]);

        (new PublishSocialPostJob($post->id))->handle(app(SocialPublisher::class));

        $this->assertSame('published', $post->fresh()->status);
        $this->assertDatabaseHas('social_media_post_accounts', [
            'post_id' => $post->id,
            'social_account_id' => $account->id,
            'platform_post_id' => 'PAGE_1_POST_PRECISE',
        ]);
    }

    public function test_a_stale_delayed_job_cannot_publish_a_cancelled_draft(): void
    {
        $context = $this->createWorkspaceContext();
        $account = $this->facebookAccount($context['workspace']->id, 'PAGE_1', 'Review Page');
        $post = SocialPost::create([
            'workspace_id' => $context['workspace']->id,
            'body' => 'This schedule was cancelled.',
            'media_urls' => [],
            'target_accounts' => [$account->id],
            'status' => 'draft',
            'scheduled_at' => null,
            'timezone' => 'UTC',
        ]);

        Http::preventStrayRequests();

        (new PublishSocialPostJob($post->id))->handle(app(SocialPublisher::class));

        $this->assertSame('draft', $post->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_composer_still_rejects_insecure_external_media_urls(): void
    {
        $context = $this->createWorkspaceContext();
        $account = $this->facebookAccount($context['workspace']->id, 'PAGE_1', 'Review Page');

        $this->actingAs($context['user'])
            ->from(route('client.social.composer'))
            ->post('/app/social/posts', [
                'body' => 'External media must remain HTTPS-only.',
                'media_urls' => ['http://cdn.example.com/post.png'],
                'target_accounts' => [$account->id],
                'scheduled_at' => now()->addHour()->toIso8601String(),
                'timezone' => 'UTC',
            ])
            ->assertRedirect(route('client.social.composer'))
            ->assertSessionHasErrors('media_urls.0');

        $this->assertDatabaseCount('social_media_posts', 0);
    }

    public function test_facebook_page_post_creation_uses_the_page_token_and_saves_the_remote_id(): void
    {
        $context = $this->createWorkspaceContext();
        $account = $this->facebookAccount($context['workspace']->id, 'PAGE_1', 'Review Page');
        $post = SocialPost::create([
            'workspace_id' => $context['workspace']->id,
            'title' => 'App Review create test',
            'body' => 'Created by WisperBot for the pages_manage_posts review.',
            'media_urls' => [],
            'target_accounts' => [$account->id],
            'status' => 'publishing',
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'graph.facebook.com/v25.0/PAGE_1/feed' => Http::response(['id' => 'PAGE_1_POST_1']),
        ]);

        app(SocialPublisher::class)->publish($post);

        $this->assertDatabaseHas('social_media_posts', [
            'id' => $post->id,
            'status' => 'published',
        ]);
        $this->assertDatabaseHas('social_media_post_accounts', [
            'post_id' => $post->id,
            'social_account_id' => $account->id,
            'status' => 'published',
            'platform_post_id' => 'PAGE_1_POST_1',
        ]);
        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request->url() === 'https://graph.facebook.com/v25.0/PAGE_1/feed'
            && $request['message'] === 'Created by WisperBot for the pages_manage_posts review.'
            && $request['access_token'] === $account->access_token
        );
    }

    public function test_a_published_facebook_post_is_updated_remotely_before_local_content_changes(): void
    {
        $context = $this->createWorkspaceContext();
        [$post, $account] = $this->publishedPost($context['workspace']->id);

        Http::preventStrayRequests();
        Http::fake([
            'graph.facebook.com/v25.0/PAGE_1_POST_1' => Http::response(['success' => true]),
        ]);

        $this->actingAs($context['user'])
            ->put(route('client.social.posts.update', $post), $this->updatePayload($post, [
                'body' => 'Updated on Facebook',
            ]))
            ->assertRedirect(route('client.social.posts.index'))
            ->assertSessionHas('success', 'Facebook Page post updated successfully.');

        $this->assertDatabaseHas('social_media_posts', [
            'id' => $post->id,
            'body' => 'Updated on Facebook',
            'status' => 'published',
        ]);

        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request->url() === 'https://graph.facebook.com/v25.0/PAGE_1_POST_1'
            && $request['message'] === 'Updated on Facebook'
            && $request['access_token'] === $account->access_token
        );
    }

    public function test_a_published_scheduled_post_ignores_its_historical_schedule_during_edit(): void
    {
        $context = $this->createWorkspaceContext();
        [$post] = $this->publishedPost($context['workspace']->id, [
            'scheduled_at' => now()->subHour(),
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'graph.facebook.com/v25.0/PAGE_1_POST_1' => Http::response(['success' => true]),
        ]);

        $this->actingAs($context['user'])
            ->put(route('client.social.posts.update', $post), $this->updatePayload($post, [
                'body' => 'Historical schedule no longer blocks edits',
                'scheduled_at' => $post->scheduled_at->toIso8601String(),
            ]))
            ->assertRedirect(route('client.social.posts.index'))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Facebook Page post updated successfully.');

        $post->refresh();
        $this->assertSame('Historical schedule no longer blocks edits', $post->body);
        $this->assertNotNull($post->scheduled_at);
    }

    public function test_a_published_facebook_post_is_deleted_remotely_before_local_records_are_removed(): void
    {
        $context = $this->createWorkspaceContext();
        [$post] = $this->publishedPost($context['workspace']->id);

        Http::preventStrayRequests();
        Http::fake([
            'graph.facebook.com/v25.0/PAGE_1_POST_1' => Http::response(['success' => true]),
        ]);

        $this->actingAs($context['user'])
            ->delete(route('client.social.posts.destroy', $post))
            ->assertSessionHas('success', 'Post deleted from Facebook and WisperBot.');

        $this->assertDatabaseMissing('social_media_posts', ['id' => $post->id]);
        $this->assertDatabaseMissing('social_media_post_accounts', ['post_id' => $post->id]);

        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE'
            && $request->url() === 'https://graph.facebook.com/v25.0/PAGE_1_POST_1'
        );
    }

    public function test_failed_remote_update_does_not_claim_the_local_post_was_updated(): void
    {
        $context = $this->createWorkspaceContext();
        [$post] = $this->publishedPost($context['workspace']->id);

        Http::preventStrayRequests();
        Http::fake([
            'graph.facebook.com/v25.0/PAGE_1_POST_1' => Http::response([
                'error' => ['message' => 'Missing permission', 'code' => 200],
            ], 403),
        ]);

        $this->actingAs($context['user'])
            ->from(route('client.social.posts.edit', $post))
            ->put(route('client.social.posts.update', $post), $this->updatePayload($post, [
                'body' => 'Must not persist locally',
            ]))
            ->assertRedirect(route('client.social.posts.edit', $post))
            ->assertSessionHas('error');

        $this->assertSame('Original Facebook post', $post->fresh()->body);
        $this->assertNotNull($post->accountLinks()->firstOrFail()->error);
    }

    public function test_partial_remote_delete_is_checkpointed_and_retry_only_deletes_the_remaining_page(): void
    {
        $context = $this->createWorkspaceContext();
        [$post] = $this->publishedPost($context['workspace']->id);
        $second = $this->facebookAccount($context['workspace']->id, 'PAGE_2', 'Second Page');
        SocialPostAccount::create([
            'post_id' => $post->id,
            'social_account_id' => $second->id,
            'status' => 'published',
            'platform_post_id' => 'PAGE_2_POST_2',
            'published_at' => now(),
        ]);
        $post->update(['target_accounts' => [$post->target_accounts[0], $second->id]]);

        Http::preventStrayRequests();
        $pageTwoAttempts = 0;
        $retryUrls = [];
        Http::fake(function (Request $request) use (&$pageTwoAttempts, &$retryUrls) {
            if (str_ends_with($request->url(), 'PAGE_1_POST_1')) {
                return Http::response(['success' => true]);
            }

            $pageTwoAttempts++;
            if ($pageTwoAttempts === 1) {
                return Http::response(['error' => ['message' => 'Temporary outage', 'code' => 2]], 503);
            }

            $retryUrls[] = $request->url();

            return Http::response(['success' => true]);
        });

        $this->actingAs($context['user'])
            ->delete(route('client.social.posts.destroy', $post))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('social_media_posts', ['id' => $post->id]);
        $this->assertNotNull(SocialPostAccount::where('platform_post_id', 'PAGE_1_POST_1')->firstOrFail()->deleted_at);
        $this->assertNull(SocialPostAccount::where('platform_post_id', 'PAGE_2_POST_2')->firstOrFail()->deleted_at);

        $this->actingAs($context['user'])
            ->delete(route('client.social.posts.destroy', $post))
            ->assertSessionHas('success');

        $this->assertSame(['https://graph.facebook.com/v25.0/PAGE_2_POST_2'], $retryUrls);
        $this->assertDatabaseMissing('social_media_posts', ['id' => $post->id]);
    }

    public function test_mixed_provider_published_post_is_not_mutated_partially(): void
    {
        $context = $this->createWorkspaceContext();
        [$post] = $this->publishedPost($context['workspace']->id);
        $linkedIn = SocialAccount::create([
            'workspace_id' => $context['workspace']->id,
            'network' => 'linkedin',
            'account_id' => 'LINKEDIN_1',
            'name' => 'LinkedIn Account',
            'access_token' => 'linkedin-token',
            'active' => true,
        ]);
        SocialPostAccount::create([
            'post_id' => $post->id,
            'social_account_id' => $linkedIn->id,
            'status' => 'published',
            'platform_post_id' => 'urn:li:share:1',
            'published_at' => now(),
        ]);
        $post->update(['target_accounts' => [$post->target_accounts[0], $linkedIn->id]]);

        Http::preventStrayRequests();

        $this->actingAs($context['user'])
            ->put(route('client.social.posts.update', $post), $this->updatePayload($post, [
                'body' => 'Unsafe mixed-provider update',
            ]))
            ->assertSessionHas('error');

        $this->assertSame('Original Facebook post', $post->fresh()->body);
        Http::assertNothingSent();
    }

    public function test_retry_after_remote_deletes_completed_finishes_only_local_cleanup(): void
    {
        $context = $this->createWorkspaceContext();
        [$post] = $this->publishedPost($context['workspace']->id);
        $post->accountLinks()->update(['deleted_at' => now()]);

        Http::preventStrayRequests();

        $this->actingAs($context['user'])
            ->delete(route('client.social.posts.destroy', $post))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('social_media_posts', ['id' => $post->id]);
        $this->assertDatabaseMissing('social_media_post_accounts', ['post_id' => $post->id]);
        Http::assertNothingSent();
    }

    public function test_published_post_targets_and_media_are_immutable(): void
    {
        $context = $this->createWorkspaceContext();
        [$post] = $this->publishedPost($context['workspace']->id, ['media_urls' => ['https://cdn.example.com/original.jpg']]);

        Http::preventStrayRequests();

        $this->actingAs($context['user'])
            ->put(route('client.social.posts.update', $post), $this->updatePayload($post, [
                'media_urls' => ['https://cdn.example.com/replacement.jpg'],
            ]))
            ->assertSessionHas('error');

        $this->assertSame(['https://cdn.example.com/original.jpg'], $post->fresh()->media_urls);
        Http::assertNothingSent();
    }

    public function test_another_workspace_cannot_manage_the_remote_post(): void
    {
        $owner = $this->createWorkspaceContext();
        $attacker = $this->createWorkspaceContext();
        [$post] = $this->publishedPost($owner['workspace']->id);

        Http::preventStrayRequests();

        $this->actingAs($attacker['user'])
            ->delete(route('client.social.posts.destroy', $post))
            ->assertForbidden();

        $this->assertDatabaseHas('social_media_posts', ['id' => $post->id]);
        Http::assertNothingSent();
    }

    private function publishedPost(int $workspaceId, array $attributes = []): array
    {
        $account = $this->facebookAccount($workspaceId, 'PAGE_1', 'Review Page');
        $post = SocialPost::create(array_merge([
            'workspace_id' => $workspaceId,
            'title' => 'Review post',
            'body' => 'Original Facebook post',
            'media_urls' => [],
            'target_accounts' => [$account->id],
            'status' => 'published',
            'published_at' => now(),
            'publish_results' => [
                $account->id => ['status' => 'published', 'post_id' => 'PAGE_1_POST_1'],
            ],
        ], $attributes));

        SocialPostAccount::create([
            'post_id' => $post->id,
            'social_account_id' => $account->id,
            'status' => 'published',
            'platform_post_id' => 'PAGE_1_POST_1',
            'published_at' => now(),
        ]);

        return [$post, $account];
    }

    private function facebookAccount(int $workspaceId, string $pageId, string $name): SocialAccount
    {
        return SocialAccount::create([
            'workspace_id' => $workspaceId,
            'network' => 'facebook',
            'account_id' => $pageId,
            'name' => $name,
            'access_token' => "token-{$pageId}",
            'meta' => ['page_id' => $pageId],
            'active' => true,
        ]);
    }

    private function updatePayload(SocialPost $post, array $overrides = []): array
    {
        return array_merge([
            'title' => $post->title,
            'body' => $post->body,
            'media_urls' => $post->media_urls ?? [],
            'target_accounts' => $post->target_accounts,
            'scheduled_at' => null,
            'timezone' => 'UTC',
        ], $overrides);
    }
}
