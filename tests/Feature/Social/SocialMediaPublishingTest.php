<?php

namespace Tests\Feature\Social;

use App\Modules\Social\Jobs\DispatchScheduledPostsJob;
use App\Modules\Social\Jobs\PublishSocialPostJob;
use App\Modules\Social\Models\SocialAccount;
use App\Modules\Social\Models\SocialPost;
use App\Modules\Social\Models\SocialPostAccount;
use App\Modules\Social\Services\Drivers\FacebookDriver;
use App\Modules\Social\Services\SocialPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class SocialMediaPublishingTest extends TestCase
{
    use RefreshDatabase;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    // ── Never posting twice ────────────────────────────────────────────────

    public function test_a_timed_out_post_is_never_sent_again_automatically(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $page = $this->facebookAccount($workspace->id);
        $post = $this->socialPost($workspace->id, [$page->id], 'Open late tonight.');
        Http::preventStrayRequests();
        $sent = 0;
        Http::fake(function () use (&$sent) {
            $sent++;
            throw new ConnectionException('cURL error 28: Operation timed out');
        });

        $this->publishIgnoringRetrySignal($post);
        $this->publishIgnoringRetrySignal($post->fresh());

        $this->assertSame(1, $sent);
        $link = SocialPostAccount::where('post_id', $post->id)->firstOrFail();
        $this->assertSame('failed', $link->status);
        $this->assertSame('Facebook did not confirm this post. Check Facebook before publishing it again.', $link->error);
        $this->assertNotNull($link->provider_attempted_at);
    }

    public function test_a_server_error_is_treated_as_unconfirmed_and_a_refusal_as_retryable(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $page = $this->facebookAccount($workspace->id);
        Http::preventStrayRequests();
        Http::fake(['graph.facebook.com/v25.0/PAGE_1/feed' => Http::sequence()
            ->push(['error' => ['message' => 'An unexpected error has occurred.', 'code' => 2]], 500)
            ->push(['error' => ['message' => 'Invalid parameter', 'code' => 100]], 400)
            ->push(['id' => 'PAGE_1_POST_2'])]);

        $unknown = $this->socialPost($workspace->id, [$page->id], 'First');
        $this->publishIgnoringRetrySignal($unknown);
        $this->assertNotNull(SocialPostAccount::where('post_id', $unknown->id)->firstOrFail()->provider_attempted_at);

        // A 4xx means nothing was posted, so the queue retry may send it again.
        $refused = $this->socialPost($workspace->id, [$page->id], 'Second');
        $this->publishIgnoringRetrySignal($refused);
        $this->assertNull(SocialPostAccount::where('post_id', $refused->id)->firstOrFail()->provider_attempted_at);
        $this->publishIgnoringRetrySignal($refused->fresh());
        $this->assertSame('published', SocialPostAccount::where('post_id', $refused->id)->firstOrFail()->status);
    }

    public function test_a_worker_stopped_mid_request_does_not_cause_a_second_post(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $page = $this->facebookAccount($workspace->id);
        $post = $this->socialPost($workspace->id, [$page->id], 'Open late tonight.');
        // The previous worker marked the attempt and was killed before recording the result.
        SocialPostAccount::create(['post_id' => $post->id, 'social_account_id' => $page->id, 'status' => 'pending', 'provider_attempted_at' => now()]);
        Http::preventStrayRequests();

        $this->publishIgnoringRetrySignal($post);

        Http::assertNothingSent();
        $this->assertSame('failed', SocialPostAccount::where('post_id', $post->id)->firstOrFail()->status);
    }

    public function test_publish_now_queues_a_post_only_once(): void
    {
        Bus::fake();
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();
        $page = $this->facebookAccount($workspace->id);
        $post = $this->socialPost($workspace->id, [$page->id], 'Open late tonight.', status: 'draft');

        $this->actingAs($user)->post(route('client.social.posts.publish-now', $post))->assertRedirect();
        $this->actingAs($user)->post(route('client.social.posts.publish-now', $post))->assertStatus(422);

        Bus::assertDispatchedTimes(PublishSocialPostJob::class, 1);
    }

    public function test_the_scheduler_and_the_delayed_job_publish_a_scheduled_post_once(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $page = $this->facebookAccount($workspace->id);
        $post = $this->socialPost($workspace->id, [$page->id], 'Open late tonight.', status: 'scheduled');
        $post->update(['scheduled_at' => now()->subMinute()]);
        Http::preventStrayRequests();
        Http::fake(['graph.facebook.com/v25.0/PAGE_1/feed' => Http::response(['id' => 'PAGE_1_POST_1'])]);

        Bus::fake([PublishSocialPostJob::class]);
        (new DispatchScheduledPostsJob)->handle();
        (new DispatchScheduledPostsJob)->handle();
        Bus::assertDispatchedTimes(PublishSocialPostJob::class, 1);

        // Both the scheduler's job and the original delayed job run.
        (new PublishSocialPostJob($post->id))->handle(app(SocialPublisher::class));
        (new PublishSocialPostJob($post->id))->handle(app(SocialPublisher::class));

        Http::assertSentCount(1);
        $this->assertSame('published', $post->fresh()->status);
    }

    // ── Facebook ───────────────────────────────────────────────────────────

    public function test_facebook_publishes_a_video_as_a_page_video(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $page = $this->facebookAccount($workspace->id);
        $post = $this->socialPost($workspace->id, [$page->id], 'Tour our spa', ['https://cdn.test/tour.mp4']);
        Http::preventStrayRequests();
        Http::fake(['graph-video.facebook.com/v25.0/PAGE_1/videos' => Http::response(['id' => '771'])]);

        app(SocialPublisher::class)->publish($post);

        $this->assertSame('771', SocialPostAccount::where('post_id', $post->id)->firstOrFail()->platform_post_id);
        Http::assertSent(fn (Request $request): bool => $request['file_url'] === 'https://cdn.test/tour.mp4' && $request['description'] === 'Tour our spa');
    }

    public function test_facebook_reuses_uploaded_photos_when_a_multi_photo_post_is_retried(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $page = $this->facebookAccount($workspace->id);
        $post = $this->socialPost($workspace->id, [$page->id], 'Two rooms', ['https://cdn.test/a.jpg', 'https://cdn.test/b.jpg']);
        Http::preventStrayRequests();
        Http::fake([
            'graph.facebook.com/v25.0/PAGE_1/photos' => Http::sequence()->push(['id' => 'P1'])->push(['id' => 'P2']),
            'graph.facebook.com/v25.0/PAGE_1/feed' => Http::sequence()
                ->push(['error' => ['message' => 'Rate limited', 'code' => 32]], 400)
                ->push(['id' => 'PAGE_1_POST_9']),
        ]);

        $this->publishIgnoringRetrySignal($post);
        $this->publishIgnoringRetrySignal($post->fresh());

        $this->assertSame('published', SocialPostAccount::where('post_id', $post->id)->firstOrFail()->status);
        $this->assertCount(2, Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/photos')));
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/feed')
            && $request['attached_media'] === [['media_fbid' => 'P1'], ['media_fbid' => 'P2']]);
    }

    public function test_editing_a_facebook_video_updates_its_description(): void
    {
        Http::fake(['graph.facebook.com/v25.0/771' => Http::response(['success' => true])]);

        (new FacebookDriver)->updatePublishedPost(new SocialAccount(['access_token' => 'token']), '771', ['body' => 'New text']);

        Http::assertSent(fn (Request $request): bool => $request['description'] === 'New text' && ! isset($request['message']));
    }

    // ── Instagram ──────────────────────────────────────────────────────────

    public function test_instagram_publishes_several_items_as_a_carousel(): void
    {
        Sleep::fake();
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $instagram = $this->instagramAccount($workspace->id);
        $post = $this->socialPost($workspace->id, [$instagram->id], 'Our rooms', ['https://cdn.test/a.jpg', 'https://cdn.test/tour.mp4']);
        $containers = [];
        Http::preventStrayRequests();
        Http::fake(function (Request $request) use (&$containers) {
            if (str_ends_with($request->url(), '/IG_1/media')) {
                $containers[] = $request->data();

                return Http::response(['id' => 'C'.count($containers)]);
            }
            if (str_contains($request->url(), 'fields=status_code')) {
                return Http::response(['status_code' => 'FINISHED']);
            }
            if (str_ends_with($request->url(), '/IG_1/media_publish')) {
                return Http::response(['id' => 'IGPOST_1']);
            }

            return Http::response([], 404);
        });

        app(SocialPublisher::class)->publish($post);

        $this->assertSame('IGPOST_1', SocialPostAccount::where('post_id', $post->id)->firstOrFail()->platform_post_id);
        $this->assertSame('https://cdn.test/a.jpg', $containers[0]['image_url']);
        $this->assertSame('true', $containers[0]['is_carousel_item']);
        $this->assertSame(['VIDEO', 'https://cdn.test/tour.mp4'], [$containers[1]['media_type'], $containers[1]['video_url']]);
        $this->assertSame(['CAROUSEL', 'C1,C2', 'Our rooms'], [$containers[2]['media_type'], $containers[2]['children'], $containers[2]['caption']]);
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/media_publish') && $request['creation_id'] === 'C3');
    }

    public function test_an_instagram_video_still_processing_is_resumed_without_a_new_upload(): void
    {
        Sleep::fake();
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $instagram = $this->instagramAccount($workspace->id);
        $post = $this->socialPost($workspace->id, [$instagram->id], 'Tour', ['https://cdn.test/tour.mp4']);
        $created = 0;
        $statusChecks = 0;
        Http::preventStrayRequests();
        Http::fake(function (Request $request) use (&$created, &$statusChecks) {
            if (str_ends_with($request->url(), '/IG_1/media')) {
                $created++;

                return Http::response(['id' => 'REEL_1']);
            }
            if (str_contains($request->url(), 'fields=status_code')) {
                // Still processing during the whole first attempt.
                return Http::response(['status_code' => ++$statusChecks > 13 ? 'FINISHED' : 'IN_PROGRESS']);
            }

            return Http::response(['id' => 'IGPOST_2']);
        });

        $this->publishIgnoringRetrySignal($post);
        $link = SocialPostAccount::where('post_id', $post->id)->firstOrFail();
        $this->assertStringStartsWith('Instagram is still processing the video.', $link->error);

        $this->publishIgnoringRetrySignal($post->fresh());

        $this->assertSame(1, $created);
        $this->assertSame('published', SocialPostAccount::where('post_id', $post->id)->firstOrFail()->status);
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/IG_1/media') && $request['media_type'] === 'REELS');
    }

    // ── LinkedIn ───────────────────────────────────────────────────────────

    public function test_linkedin_uploads_an_image_and_shares_it_as_the_page(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $page = SocialAccount::create([
            'workspace_id' => $workspace->id, 'network' => 'linkedin', 'account_id' => '5566', 'name' => 'Acme',
            'access_token' => 'li-token', 'meta' => ['actor_type' => 'organization'], 'active' => true,
        ]);
        $post = $this->socialPost($workspace->id, [$page->id], 'We are hiring', [$this->storedFile('hiring.png')]);
        Http::preventStrayRequests();
        Http::fake([
            'api.linkedin.com/v2/assets?action=registerUpload' => Http::response(['value' => [
                'asset' => 'urn:li:digitalmediaAsset:A1',
                'uploadMechanism' => ['com.linkedin.digitalmedia.uploading.MediaUploadHttpRequest' => ['uploadUrl' => 'https://api.linkedin.com/mediaUpload/A1/0']],
            ]]),
            'api.linkedin.com/mediaUpload/*' => Http::response(null, 201),
            'api.linkedin.com/v2/ugcPosts' => Http::response([], 201, ['X-RestLi-Id' => 'urn:li:share:77']),
        ]);

        app(SocialPublisher::class)->publish($post);

        $this->assertSame('urn:li:share:77', SocialPostAccount::where('post_id', $post->id)->firstOrFail()->platform_post_id);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'registerUpload')
            && $request['registerUploadRequest']['owner'] === 'urn:li:organization:5566'
            && $request['registerUploadRequest']['recipes'] === ['urn:li:digitalmediaRecipe:feedshare-image']);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT' && str_contains($request->url(), '/mediaUpload/A1/0'));
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/ugcPosts')
            && $request['specificContent']['com.linkedin.ugc.ShareContent']['shareMediaCategory'] === 'IMAGE'
            && $request['specificContent']['com.linkedin.ugc.ShareContent']['media'][0]['media'] === 'urn:li:digitalmediaAsset:A1');
    }

    // ── What each network accepts ──────────────────────────────────────────

    public function test_each_network_rejects_media_it_cannot_publish(): void
    {
        Bus::fake();
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();
        $facebook = $this->facebookAccount($workspace->id);
        $instagram = $this->instagramAccount($workspace->id);
        $linkedin = SocialAccount::create(['workspace_id' => $workspace->id, 'network' => 'linkedin', 'account_id' => 'm1', 'name' => 'Me', 'access_token' => 't', 'active' => true]);
        $youtube = SocialAccount::create(['workspace_id' => $workspace->id, 'network' => 'youtube', 'account_id' => 'y1', 'name' => 'Channel', 'access_token' => 't', 'active' => true]);

        $cases = [
            [[$facebook->id], ['https://cdn.test/a.mp4', 'https://cdn.test/b.jpg'], 'Facebook posts can have up to 10 images, or 1 video.'],
            [[$instagram->id], ['https://cdn.test/a.png'], 'Instagram only accepts JPG images.'],
            [[$instagram->id], array_map(fn ($i) => "https://cdn.test/{$i}.jpg", range(1, 11)), 'Instagram posts can have up to 10 images or videos.'],
            [[$linkedin->id], ['https://cdn.test/a.jpg', 'https://cdn.test/b.jpg'], 'LinkedIn posts can have 1 image or 1 video.'],
            [[$youtube->id], ['https://cdn.test/a.jpg'], 'YouTube and TikTok publishing require a publicly reachable video'],
        ];
        foreach ($cases as [$accounts, $media, $message]) {
            $this->actingAs($user)->post(route('client.social.posts.store'), $this->payload($accounts, $media))
                ->assertSessionHasErrors('media_urls');
            $this->assertStringStartsWith($message, session('errors')->first('media_urls'));
        }
        $this->assertSame(0, SocialPost::count());

        $this->actingAs($user)->post(route('client.social.posts.store'), $this->payload([$facebook->id, $instagram->id], ['https://cdn.test/a.jpg', 'https://cdn.test/b.jpg']))
            ->assertSessionHasNoErrors();
        $this->assertSame(1, SocialPost::count());
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    private function publishIgnoringRetrySignal(SocialPost $post): void
    {
        try {
            app(SocialPublisher::class)->publish($post);
        } catch (\RuntimeException) {
            // The publisher throws so the queue retries failed links.
        }
    }

    private function facebookAccount(int $workspaceId): SocialAccount
    {
        return SocialAccount::create([
            'workspace_id' => $workspaceId, 'network' => 'facebook', 'account_id' => 'PAGE_1', 'name' => 'Acme Page',
            'access_token' => 'token-PAGE_1', 'meta' => ['page_id' => 'PAGE_1'], 'active' => true,
        ]);
    }

    private function instagramAccount(int $workspaceId): SocialAccount
    {
        return SocialAccount::create([
            'workspace_id' => $workspaceId, 'network' => 'instagram', 'account_id' => 'IG_1', 'name' => '@acme',
            'access_token' => 'token-IG_1', 'active' => true,
        ]);
    }

    private function socialPost(int $workspaceId, array $accountIds, string $body, array $media = [], string $status = 'publishing'): SocialPost
    {
        return SocialPost::create([
            'workspace_id' => $workspaceId, 'body' => $body, 'media_urls' => $media,
            'target_accounts' => $accountIds, 'status' => $status,
        ]);
    }

    private function payload(array $accountIds, array $media): array
    {
        return [
            'title' => 'Update', 'body' => 'Our rooms', 'media_urls' => $media, 'target_accounts' => $accountIds,
            'scheduled_at' => now()->addHour()->toIso8601String(), 'timezone' => 'UTC',
        ];
    }

    private function storedFile(string $name): string
    {
        Storage::fake('public');
        Storage::disk('public')->put('media/'.$name, base64_decode(self::PNG));

        return rtrim((string) config('app.url'), '/').'/storage/media/'.$name;
    }
}
