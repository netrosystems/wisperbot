<?php

namespace Tests\Feature\Social;

use App\Modules\Integrations\Models\IntegrationConfig;
use App\Modules\Social\Models\SocialAccount;
use App\Modules\Social\Models\SocialPost;
use App\Modules\Social\Models\SocialPostAccount;
use App\Modules\Social\Services\PublishedPostLifecycle;
use App\Modules\Social\Services\SocialPublisher;
use App\Modules\Social\Services\XContentRules;
use App\Support\ApiAbilities;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\UsesDeveloperApi;
use Tests\TestCase;

class XPublishingTest extends TestCase
{
    use RefreshDatabase;
    use UsesDeveloperApi;

    // ── OAuth ──────────────────────────────────────────────────────────────

    public function test_connect_uses_pkce_and_asks_only_for_posting_scopes(): void
    {
        ['user' => $user] = $this->createWorkspaceContext();
        $this->xCredentials();

        $location = $this->actingAs($user)
            ->get(route('client.social.accounts.connect', ['network' => 'twitter']))
            ->headers->get('Location');

        $this->assertStringStartsWith('https://x.com/i/oauth2/authorize?', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame('x-client-id', $query['client_id']);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertSame('tweet.read tweet.write users.read media.write offline.access', $query['scope']);
        $this->assertStringEndsWith('/app/social/accounts/callback/twitter', $query['redirect_uri']);

        $stored = Session::get('social_oauth_state');
        $this->assertSame($query['state'], $stored['state']);
        $this->assertSame(
            rtrim(strtr(base64_encode(hash('sha256', $stored['code_verifier'], true)), '+/', '-_'), '='),
            $query['code_challenge'],
        );
    }

    public function test_callback_connects_the_x_account_with_a_refresh_token(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();
        $this->xCredentials();
        $this->pendingXState();
        Http::preventStrayRequests();
        Http::fake([
            'api.x.com/2/oauth2/token' => Http::response([
                'access_token' => 'x-access', 'refresh_token' => 'x-refresh', 'expires_in' => 7200,
                'scope' => 'tweet.write users.read tweet.read media.write offline.access',
            ]),
            'api.x.com/2/users/me*' => Http::response(['data' => ['id' => '42', 'name' => 'Acme', 'username' => 'acme', 'profile_image_url' => 'https://pbs.test/a.png']]),
        ]);

        $this->actingAs($user)
            ->get(route('client.social.oauth.callback', ['network' => 'twitter', 'code' => 'the-code', 'state' => 'state-1']))
            ->assertRedirect();

        $account = SocialAccount::where('workspace_id', $workspace->id)->where('network', 'twitter')->firstOrFail();
        $this->assertSame('42', $account->account_id);
        $this->assertSame('Acme', $account->name);
        $this->assertSame('x-access', $account->access_token);
        $this->assertSame('x-refresh', $account->refresh_token);
        $this->assertTrue($account->token_expires_at->isFuture());

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.x.com/2/oauth2/token'
            && $request['code_verifier'] === 'verifier-1'
            && $request['grant_type'] === 'authorization_code'
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('x-client-id:x-client-secret')));
    }

    public function test_callback_refuses_an_authorization_without_every_scope(): void
    {
        ['user' => $user] = $this->createWorkspaceContext();
        $this->xCredentials();
        $this->pendingXState();
        Http::preventStrayRequests();
        Http::fake(['api.x.com/2/oauth2/token' => Http::response([
            'access_token' => 'x-access', 'expires_in' => 7200, 'scope' => 'tweet.read users.read',
        ])]);

        $this->actingAs($user)
            ->get(route('client.social.oauth.callback', ['network' => 'twitter', 'code' => 'the-code', 'state' => 'state-1']))
            ->assertSessionHas('error', fn (string $message): bool => str_starts_with($message, 'X authorization failed'));

        $this->assertSame(0, SocialAccount::count());
    }

    // ── Content rules ──────────────────────────────────────────────────────

    public function test_content_rules_reject_links_extra_media_and_overlong_text(): void
    {
        $rules = new XContentRules;

        $this->assertSame([], $rules->errors('Opening hours change on Friday. Call us to book.'));
        foreach (['See https://example.com', 'www.example.com today', 'Visit example.com', 'bit.ly/abc', 'Mail hi@example.com'] as $text) {
            $this->assertTrue($rules->containsLink($text), $text);
        }
        $this->assertSame([], $rules->errors('Three photos', ['https://cdn.test/1.jpg', 'https://cdn.test/2.png', 'https://cdn.test/3.webp']));
        $this->assertSame([], $rules->errors('One video', ['https://cdn.test/clip.mp4']));
        $this->assertSame([XContentRules::MEDIA_COMBINATION_ERROR], $rules->errors('Four photos', ['https://cdn.test/1.jpg', 'https://cdn.test/2.jpg', 'https://cdn.test/3.jpg', 'https://cdn.test/4.jpg']));
        $this->assertSame([XContentRules::MEDIA_COMBINATION_ERROR], $rules->errors('Two videos', ['https://cdn.test/a.mp4', 'https://cdn.test/b.mov']));
        $this->assertSame([XContentRules::MEDIA_COMBINATION_ERROR], $rules->errors('Mixed', ['https://cdn.test/a.mp4', 'https://cdn.test/b.jpg']));
        $this->assertSame([XContentRules::MEDIA_COMBINATION_ERROR], $rules->errors('GIF and photo', ['https://cdn.test/a.gif', 'https://cdn.test/b.jpg']));
        $this->assertSame([XContentRules::UNSUPPORTED_MEDIA_ERROR], $rules->errors('Vector', ['https://cdn.test/logo.svg']));
        $this->assertNotSame([], $rules->errors('   '));

        $this->assertSame([], $rules->errors(str_repeat('a', 280)));
        $this->assertNotSame([], $rules->errors(str_repeat('a', 281)));
        // Emoji and CJK characters weigh 2.
        $this->assertSame(4, $rules->weightedLength('👍🏽中'));
        $this->assertNotSame([], $rules->errors(str_repeat('中', 141)));
    }

    public function test_composer_rejects_a_link_only_when_x_is_selected(): void
    {
        Bus::fake();
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();
        $x = $this->xAccount($workspace->id);
        $facebook = $this->facebookAccount($workspace->id);

        $this->actingAs($user)->post(route('client.social.posts.store'), $this->payload([$x->id, $facebook->id], 'Read more at example.com'))
            ->assertSessionHasErrors('body');
        $fourPhotos = ['https://cdn.test/1.png', 'https://cdn.test/2.png', 'https://cdn.test/3.png', 'https://cdn.test/4.png'];
        $this->actingAs($user)->post(route('client.social.posts.store'), $this->payload([$x->id], 'Four photos', $fourPhotos))
            ->assertSessionHasErrors('media_urls');
        $this->assertSame(0, SocialPost::count());

        $this->actingAs($user)->post(route('client.social.posts.store'), $this->payload([$facebook->id], 'Read more at example.com'))
            ->assertSessionHasNoErrors();
        $this->actingAs($user)->post(route('client.social.posts.store'), $this->payload([$x->id], 'We are open late tonight.', array_slice($fourPhotos, 0, 3)))
            ->assertSessionHasNoErrors();
        $this->assertSame(2, SocialPost::count());
    }

    public function test_ai_planner_and_api_apply_the_same_x_rules(): void
    {
        Bus::fake();
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();
        $x = $this->xAccount($workspace->id);

        // Bearer request first: a session login would take precedence over it.
        $token = $user->createToken('t', [ApiAbilities::SOCIAL_WRITE])->plainTextToken;
        $this->withToken($token)->postJson('/api/v1/social/posts', ['body' => 'Book at www.example.com', 'account_ids' => [$x->id]])
            ->assertUnprocessable()->assertJsonValidationErrors('body');

        $this->actingAs($user)->postJson(route('client.social.posts.bulk'), ['posts' => [
            ['body' => 'Plain first post', 'target_accounts' => [$x->id]],
            ['body' => 'Book at https://example.com', 'target_accounts' => [$x->id]],
        ]])->assertUnprocessable()->assertJsonValidationErrors('posts.1.body');

        $this->assertSame(0, SocialPost::count());
    }

    public function test_composer_lists_x_accounts_whose_short_lived_token_expired(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();
        $x = $this->xAccount($workspace->id, ['token_expires_at' => now()->subHour()]);

        $this->actingAs($user)->get(route('client.social.automation.schedule'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('accounts.0.id', $x->id));
    }

    // ── Customised X version ───────────────────────────────────────────────

    public function test_a_customised_x_version_frees_the_shared_post_for_other_networks(): void
    {
        Bus::fake();
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();
        $x = $this->xAccount($workspace->id);
        $facebook = $this->facebookAccount($workspace->id);
        $media = ['https://cdn.test/1.png', 'https://cdn.test/2.png', 'https://cdn.test/3.png', 'https://cdn.test/4.png'];

        $this->actingAs($user)->post(route('client.social.posts.store'), $this->payload([$x->id, $facebook->id], 'Full menu at example.com', $media) + [
            'network_content' => ['twitter' => ['body' => 'Our spring menu is live.', 'media_urls' => ['https://cdn.test/2.png']]],
        ])->assertSessionHasNoErrors();

        $post = SocialPost::latest('id')->firstOrFail();
        $this->assertSame('Full menu at example.com', $post->body);
        $this->assertSame($media, $post->media_urls);
        $this->assertSame(['body' => 'Our spring menu is live.', 'media_urls' => ['https://cdn.test/2.png']], $post->contentFor('twitter'));
        $this->assertSame(['body' => 'Full menu at example.com', 'media_urls' => $media], $post->contentFor('facebook'));
    }

    public function test_only_the_x_version_must_follow_x_rules(): void
    {
        Bus::fake();
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();
        $x = $this->xAccount($workspace->id);
        $facebook = $this->facebookAccount($workspace->id);
        $base = $this->payload([$x->id, $facebook->id], 'Full menu at example.com', ['https://cdn.test/1.png']);

        $this->actingAs($user)->post(route('client.social.posts.store'), $base + [
            'network_content' => ['twitter' => ['body' => 'Menu at example.com', 'media_urls' => []]],
        ])->assertSessionHasErrors('network_content.twitter.body');

        // X media must come from the post's own media.
        $this->actingAs($user)->post(route('client.social.posts.store'), $base + [
            'network_content' => ['twitter' => ['body' => 'Menu is live', 'media_urls' => ['https://elsewhere.test/x.png']]],
        ])->assertSessionHasErrors('network_content.twitter.media_urls');

        $this->assertSame(0, SocialPost::count());

        // Without X selected, an X version is not stored.
        $this->actingAs($user)->post(route('client.social.posts.store'), $this->payload([$facebook->id], 'Menu at example.com') + [
            'network_content' => ['twitter' => ['body' => 'Menu', 'media_urls' => []]],
        ])->assertSessionHasNoErrors();
        $this->assertNull(SocialPost::latest('id')->firstOrFail()->network_content);
    }

    public function test_editing_can_add_and_remove_the_x_version(): void
    {
        Bus::fake();
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();
        $x = $this->xAccount($workspace->id);
        $post = SocialPost::create([
            'workspace_id' => $workspace->id, 'body' => 'Menu is live', 'media_urls' => [],
            'target_accounts' => [$x->id], 'status' => 'draft',
        ]);
        $payload = ['title' => null, 'body' => 'Menu is live', 'media_urls' => [], 'target_accounts' => [$x->id], 'scheduled_at' => null, 'timezone' => 'UTC'];

        $this->actingAs($user)->put(route('client.social.posts.update', $post), $payload + [
            'network_content' => ['twitter' => ['body' => 'Short X text', 'media_urls' => []]],
        ])->assertSessionHasNoErrors();
        $this->assertSame('Short X text', $post->fresh()->contentFor('twitter')['body']);

        $this->actingAs($user)->put(route('client.social.posts.update', $post), $payload + ['network_content' => null])
            ->assertSessionHasNoErrors();
        $this->assertNull($post->fresh()->network_content);
    }

    public function test_the_api_accepts_an_x_version(): void
    {
        Bus::fake();
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();
        $x = $this->xAccount($workspace->id);
        $token = $user->createToken('t', [ApiAbilities::SOCIAL_WRITE])->plainTextToken;

        $this->withToken($token)->postJson('/api/v1/social/posts', [
            'body' => 'Full menu at www.example.com',
            'account_ids' => [$x->id],
            'network_content' => ['twitter' => ['body' => 'Menu is live']],
        ])->assertCreated();

        $this->assertSame('Menu is live', SocialPost::latest('id')->firstOrFail()->contentFor('twitter')['body']);
    }

    public function test_publishing_sends_the_x_version_to_x(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $x = $this->xAccount($workspace->id);
        $shared = [$this->storedFile('a.png', self::PNG), $this->storedFile('b.png', self::PNG)];
        $post = SocialPost::create([
            'workspace_id' => $workspace->id,
            'body' => 'Full menu at example.com',
            'media_urls' => $shared,
            'network_content' => ['twitter' => ['body' => 'Menu is live', 'media_urls' => [$shared[1]]]],
            'target_accounts' => [$x->id],
            'status' => 'publishing',
        ]);
        Http::preventStrayRequests();
        Http::fake([
            'api.x.com/2/media/upload/initialize' => Http::response(['data' => ['id' => 'm9', 'expires_after_secs' => 86400]]),
            'api.x.com/2/media/upload/*/append' => Http::response(null, 204),
            'api.x.com/2/media/upload/*/finalize' => Http::response(['data' => ['id' => 'm9']]),
            'api.x.com/2/tweets' => Http::response(['data' => ['id' => '1920']], 201),
        ]);

        app(SocialPublisher::class)->publish($post);

        $this->assertCount(1, Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/initialize')));
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/2/tweets')
            && $request->data() === ['text' => 'Menu is live', 'media' => ['media_ids' => ['m9']]]);
    }

    // ── Publishing ─────────────────────────────────────────────────────────

    public function test_publish_sends_text_only_and_records_the_post_id(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $x = $this->xAccount($workspace->id);
        $post = $this->xPost($workspace->id, [$x->id]);
        Http::preventStrayRequests();
        Http::fake(['api.x.com/2/tweets' => Http::response(['data' => ['id' => '1900', 'text' => 'We are open late tonight.']], 201)]);

        app(SocialPublisher::class)->publish($post);

        $link = SocialPostAccount::where('post_id', $post->id)->firstOrFail();
        $this->assertSame('published', $link->status);
        $this->assertSame('1900', $link->platform_post_id);
        $this->assertNull($link->provider_attempted_at);
        Http::assertSent(fn (Request $request): bool => $request->data() === ['text' => 'We are open late tonight.']
            && $request->hasHeader('Authorization', 'Bearer x-access'));
    }

    public function test_an_unconfirmed_post_is_never_sent_again_automatically(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();
        $x = $this->xAccount($workspace->id);
        $post = $this->xPost($workspace->id, [$x->id]);
        Http::preventStrayRequests();
        Http::fake(['api.x.com/2/tweets' => Http::sequence()->push(['title' => 'Service Unavailable'], 503)->push(['data' => ['id' => '1901']], 201)]);

        $this->publishIgnoringRetrySignal($post);
        $this->publishIgnoringRetrySignal($post->fresh());

        Http::assertSentCount(1);
        $link = SocialPostAccount::where('post_id', $post->id)->firstOrFail();
        $this->assertSame('failed', $link->status);
        $this->assertSame('X did not confirm this post. Check X before publishing it again.', $link->error);
        $this->assertNotNull($link->provider_attempted_at);

        // The client checked X and chose to publish again.
        Bus::fake();
        $this->actingAs($user)->post(route('client.social.posts.publish-now', $post))->assertRedirect();
        $this->assertNull($link->fresh()->provider_attempted_at);
    }

    public function test_definite_failures_explain_the_cause_and_allow_a_retry(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $x = $this->xAccount($workspace->id);
        Http::preventStrayRequests();
        Http::fake(['api.x.com/2/tweets' => Http::sequence()
            ->push(['title' => 'CreditsDepleted', 'detail' => 'Your enrolled account does not have any credits'], 402)
            ->push(['title' => 'Unauthorized'], 401)]);

        $post = $this->xPost($workspace->id, [$x->id]);
        $this->publishIgnoringRetrySignal($post);
        $link = SocialPostAccount::where('post_id', $post->id)->firstOrFail();
        $this->assertSame('X API credits are unavailable. Contact your administrator.', $link->error);
        $this->assertNull($link->provider_attempted_at);
        $this->assertTrue($x->fresh()->active);

        $post = $this->xPost($workspace->id, [$x->id]);
        $this->publishIgnoringRetrySignal($post);
        $this->assertSame('Reconnect your X account.', SocialPostAccount::where('post_id', $post->id)->firstOrFail()->error);
        $this->assertFalse($x->fresh()->active);
    }

    public function test_an_expired_token_is_refreshed_and_rotated_before_publishing(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $this->xCredentials();
        $x = $this->xAccount($workspace->id, ['token_expires_at' => now()->subMinute()]);
        $post = $this->xPost($workspace->id, [$x->id]);
        Http::preventStrayRequests();
        Http::fake([
            'api.x.com/2/oauth2/token' => Http::response(['access_token' => 'new-access', 'refresh_token' => 'new-refresh', 'expires_in' => 7200, 'scope' => 'tweet.write']),
            'api.x.com/2/tweets' => Http::response(['data' => ['id' => '1902']], 201),
        ]);

        app(SocialPublisher::class)->publish($post);

        $x->refresh();
        $this->assertSame('new-access', $x->access_token);
        $this->assertSame('new-refresh', $x->refresh_token);
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/2/oauth2/token')
            && $request['grant_type'] === 'refresh_token' && $request['refresh_token'] === 'x-refresh');
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/2/tweets')
            && $request->hasHeader('Authorization', 'Bearer new-access'));
    }

    // ── Media ──────────────────────────────────────────────────────────────

    public function test_images_are_uploaded_then_posted_with_their_media_ids(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $x = $this->xAccount($workspace->id);
        $post = $this->xPost($workspace->id, [$x->id], [$this->storedFile('a.png', self::PNG), $this->storedFile('b.png', self::PNG)]);
        Http::preventStrayRequests();
        Http::fake([
            'api.x.com/2/media/upload/initialize' => Http::sequence()
                ->push(['data' => ['id' => 'm1', 'expires_after_secs' => 86400]])
                ->push(['data' => ['id' => 'm2', 'expires_after_secs' => 86400]]),
            'api.x.com/2/media/upload/*/append' => Http::response(null, 204),
            'api.x.com/2/media/upload/*/finalize' => Http::response(['data' => ['id' => 'ok']]),
            'api.x.com/2/tweets' => Http::response(['data' => ['id' => '1910']], 201),
        ]);

        app(SocialPublisher::class)->publish($post);

        $link = SocialPostAccount::where('post_id', $post->id)->firstOrFail();
        $this->assertSame('published', $link->status);
        $this->assertNull($link->provider_media);
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/2/media/upload/initialize')
            && $request['media_type'] === 'image/png' && $request['media_category'] === 'tweet_image'
            && $request['total_bytes'] === strlen(base64_decode(self::PNG)) + 64);
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/2/tweets')
            && $request->data() === ['text' => 'We are open late tonight.', 'media' => ['media_ids' => ['m1', 'm2']]]);
        // No media metadata (alt text) is ever sent.
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'metadata'));
    }

    public function test_a_retry_reuses_uploaded_media_instead_of_paying_again(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $x = $this->xAccount($workspace->id);
        $post = $this->xPost($workspace->id, [$x->id], [$this->storedFile('a.png', self::PNG)]);
        Http::preventStrayRequests();
        Http::fake([
            'api.x.com/2/media/upload/initialize' => Http::response(['data' => ['id' => 'm1', 'expires_after_secs' => 86400]]),
            'api.x.com/2/media/upload/*/append' => Http::response(null, 204),
            'api.x.com/2/media/upload/*/finalize' => Http::response(['data' => ['id' => 'm1']]),
            'api.x.com/2/tweets' => Http::sequence()->push(['title' => 'Too Many Requests'], 429)->push(['data' => ['id' => '1911']], 201),
        ]);

        $this->publishIgnoringRetrySignal($post);
        $this->assertSame('m1', SocialPostAccount::where('post_id', $post->id)->firstOrFail()->provider_media[0]['id']);

        $this->publishIgnoringRetrySignal($post->fresh());

        $this->assertSame('published', SocialPostAccount::where('post_id', $post->id)->firstOrFail()->status);
        $this->assertCount(1, Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/initialize')));
    }

    public function test_media_is_checked_before_any_paid_call(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        Http::preventStrayRequests();

        // Larger than X's 5 MB image limit.
        $x = $this->xAccount($workspace->id);
        $post = $this->xPost($workspace->id, [$x->id], [$this->storedFile('big.png', self::PNG, 6 * 1024 * 1024)]);
        $this->publishIgnoringRetrySignal($post);
        $this->assertSame('X images must be 5 MB or smaller.', SocialPostAccount::where('post_id', $post->id)->firstOrFail()->error);

        // A private-network address is never downloaded.
        $post = $this->xPost($workspace->id, [$x->id], ['https://127.0.0.1/secret.png']);
        $this->publishIgnoringRetrySignal($post);
        $this->assertStringStartsWith('An image or video address is not allowed.', SocialPostAccount::where('post_id', $post->id)->firstOrFail()->error);

        // Accounts connected before media was allowed must reconnect for media.
        $old = $this->xAccount($workspace->id, ['account_id' => '43', 'scopes' => ['tweet.read', 'tweet.write', 'users.read', 'offline.access']]);
        $post = $this->xPost($workspace->id, [$old->id], [$this->storedFile('c.png', self::PNG)]);
        $this->publishIgnoringRetrySignal($post);
        $this->assertSame('Reconnect your X account to allow images and video, then publish again.', SocialPostAccount::where('post_id', $post->id)->firstOrFail()->error);
        $this->assertTrue($old->fresh()->active);

        Http::assertNothingSent();
    }

    public function test_a_slow_video_resumes_on_retry_without_a_second_upload(): void
    {
        Sleep::fake();
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $x = $this->xAccount($workspace->id);
        $post = $this->xPost($workspace->id, [$x->id], [$this->storedFile('clip.mp4', self::MP4)]);
        Http::preventStrayRequests();
        Http::fake([
            'api.x.com/2/media/upload/initialize' => Http::response(['data' => ['id' => 'v1', 'expires_after_secs' => 86400]]),
            'api.x.com/2/media/upload/*/append' => Http::response(null, 204),
            'api.x.com/2/media/upload/*/finalize' => Http::response(['data' => ['id' => 'v1', 'processing_info' => ['state' => 'pending', 'check_after_secs' => 20]]]),
            'api.x.com/2/media/upload?*' => Http::sequence()
                ->push(['data' => ['processing_info' => ['state' => 'in_progress', 'check_after_secs' => 20]]])
                ->push(['data' => ['processing_info' => ['state' => 'in_progress', 'check_after_secs' => 20]]])
                ->push(['data' => ['processing_info' => ['state' => 'succeeded']]]),
            'api.x.com/2/tweets' => Http::response(['data' => ['id' => '1912']], 201),
        ]);

        $this->publishIgnoringRetrySignal($post);
        $link = SocialPostAccount::where('post_id', $post->id)->firstOrFail();
        $this->assertSame('failed', $link->status);
        $this->assertStringStartsWith('X is still processing the video.', $link->error);
        $this->assertNull($link->provider_attempted_at);

        $this->publishIgnoringRetrySignal($post->fresh());

        $this->assertSame('published', SocialPostAccount::where('post_id', $post->id)->firstOrFail()->status);
        $this->assertCount(1, Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/initialize')));
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/initialize') && $request['media_category'] === 'tweet_video');
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/2/tweets') && $request['media'] === ['media_ids' => ['v1']]);
    }

    // ── Removing ───────────────────────────────────────────────────────────

    public function test_a_published_x_post_is_removed_from_wisperbot_only(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();
        $x = $this->xAccount($workspace->id);
        $post = $this->publishedPost($workspace->id, [$x->id => '1900']);
        Http::preventStrayRequests();

        $capabilities = app(PublishedPostLifecycle::class)->capabilities($post);
        $this->assertFalse($capabilities['can_delete']);
        $this->assertFalse($capabilities['can_update']);
        $this->assertTrue($capabilities['can_remove_local']);

        $this->actingAs($user)->delete(route('client.social.posts.destroy', $post))->assertSessionHas('error');
        $this->actingAs($user)->delete(route('client.social.posts.remove-local', $post))->assertSessionHas('success', 'Post removed from WisperBot. It stays on X.');

        $this->assertModelMissing($post);
        Http::assertNothingSent();
    }

    public function test_deleting_a_mixed_post_leaves_the_x_copy_on_x(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();
        $x = $this->xAccount($workspace->id);
        $facebook = $this->facebookAccount($workspace->id);
        $post = $this->publishedPost($workspace->id, [$x->id => '1900', $facebook->id => 'PAGE_1_POST_1']);
        Http::preventStrayRequests();
        Http::fake(['graph.facebook.com/*/PAGE_1_POST_1' => Http::response(['success' => true])]);

        $this->actingAs($user)->delete(route('client.social.posts.destroy', $post))
            ->assertSessionHas('success', fn (string $message): bool => str_starts_with($message, 'The X copy stays on X.'));

        Http::assertSentCount(1);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'api.x.com'));
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

    private function xCredentials(): void
    {
        IntegrationConfig::updateOrCreate(['provider' => 'oauth_twitter'], [
            'label' => 'X OAuth',
            'credentials' => ['client_id' => 'x-client-id', 'client_secret' => 'x-client-secret'],
            'enabled' => true,
        ]);
    }

    private function pendingXState(): void
    {
        Session::put('social_oauth_state', ['network' => 'twitter', 'code_verifier' => 'verifier-1', 'client_id' => 'x-client-id', 'state' => 'state-1']);
    }

    private function xAccount(int $workspaceId, array $overrides = []): SocialAccount
    {
        return SocialAccount::create(array_merge([
            'workspace_id' => $workspaceId,
            'network' => 'twitter',
            'account_id' => '42',
            'name' => 'Acme',
            'access_token' => 'x-access',
            'refresh_token' => 'x-refresh',
            'token_expires_at' => now()->addHour(),
            'scopes' => ['tweet.read', 'tweet.write', 'users.read', 'media.write', 'offline.access'],
            'active' => true,
        ], $overrides));
    }

    private function facebookAccount(int $workspaceId): SocialAccount
    {
        return SocialAccount::create([
            'workspace_id' => $workspaceId,
            'network' => 'facebook',
            'account_id' => 'PAGE_1',
            'name' => 'Acme Page',
            'access_token' => 'token-PAGE_1',
            'meta' => ['page_id' => 'PAGE_1'],
            'active' => true,
        ]);
    }

    private function payload(array $accountIds, string $body, array $media = []): array
    {
        return [
            'title' => 'Update',
            'body' => $body,
            'media_urls' => $media,
            'target_accounts' => $accountIds,
            'scheduled_at' => now()->addHour()->toIso8601String(),
            'timezone' => 'UTC',
        ];
    }

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private const MP4 = 'AAAAGGZ0eXBtcDQyAAAAAG1wNDJpc29t';

    /** Stores a file in the public disk and returns its /storage URL. */
    private bool $storageFaked = false;

    private function storedFile(string $name, string $base64, int $padTo = 0): string
    {
        if (! $this->storageFaked) {
            Storage::fake('public');
            $this->storageFaked = true;
        }
        $bytes = base64_decode($base64);
        Storage::disk('public')->put('media/'.$name, str_pad($bytes, max($padTo, strlen($bytes) + 64), "\0"));

        return rtrim((string) config('app.url'), '/').'/storage/media/'.$name;
    }

    private function xPost(int $workspaceId, array $accountIds, array $mediaUrls = []): SocialPost
    {
        return SocialPost::create([
            'workspace_id' => $workspaceId,
            'body' => 'We are open late tonight.',
            'media_urls' => $mediaUrls,
            'target_accounts' => $accountIds,
            'status' => 'publishing',
        ]);
    }

    /** @param array<int, string> $platformIds account id => platform post id */
    private function publishedPost(int $workspaceId, array $platformIds): SocialPost
    {
        $post = SocialPost::create([
            'workspace_id' => $workspaceId,
            'body' => 'We are open late tonight.',
            'media_urls' => [],
            'target_accounts' => array_keys($platformIds),
            'status' => 'published',
            'published_at' => now(),
        ]);
        foreach ($platformIds as $accountId => $platformId) {
            SocialPostAccount::create([
                'post_id' => $post->id,
                'social_account_id' => $accountId,
                'status' => 'published',
                'platform_post_id' => $platformId,
                'published_at' => now(),
            ]);
        }

        return $post;
    }
}
