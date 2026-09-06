<?php

namespace Tests\Feature\Social;

use App\Models\User;
use App\Modules\AI\Models\AiChatbot;
use App\Modules\AI\Models\AiKbChunk;
use App\Modules\AI\Models\AiKbDocument;
use App\Modules\AI\Models\AiKbRevision;
use App\Modules\AI\Models\AiKnowledgeBase;
use App\Modules\AI\Services\ChatbotRunner;
use App\Modules\AI\Services\EmbeddingStore;
use App\Modules\AI\Services\LlmGateway;
use App\Modules\Integrations\Models\IntegrationConfig;
use App\Modules\Social\Events\SocialCommentsChanged;
use App\Modules\Social\Exceptions\CommentProviderException;
use App\Modules\Social\Jobs\IngestSocialComments;
use App\Modules\Social\Jobs\ProcessSocialCommentOperation;
use App\Modules\Social\Models\SocialAccount;
use App\Modules\Social\Services\MetaCommentProvider;
use App\Modules\Social\Services\SocialCommentCapabilities;
use App\Modules\Social\Services\SocialCommentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SocialCommentsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['social_comments.enabled' => true, 'cache.default' => 'array']);
        Queue::fake();
        Event::fake([SocialCommentsChanged::class]);
        Http::preventStrayRequests();
    }

    private function fixture(): array
    {
        $context = $this->createWorkspaceContext();
        $account = SocialAccount::create(['workspace_id' => $context['workspace']->id, 'network' => 'facebook', 'account_id' => (string) (1000 + $context['workspace']->id),
            'name' => 'Test Page', 'active' => true, 'access_token' => 'secret-customer-token', 'meta' => ['must_not_serialize' => 'hidden']]);
        $service = app(SocialCommentService::class);
        $service->settings($account)->update(['connection_status' => 'ready', 'capabilities' => ['read' => true, 'reply' => true, 'hide' => true, 'delete' => true]]);
        $comment = $service->ingest($account, 'post_1', ['id' => 'comment_1', 'message' => 'What are your opening hours?', 'from' => ['id' => 'customer_1', 'name' => 'Customer'], 'created_time' => now()->toIso8601String()]);

        return $context + compact('account', 'comment', 'service');
    }

    public function test_flag_fails_closed_for_web_and_mobile(): void
    {
        $f = $this->fixture();
        config(['social_comments.enabled' => false]);
        $this->actingAs($f['user'])->get('/app/social/automation/comments')->assertNotFound();
        $this->getJson('/api/v1/mobile/social/comments')->assertNotFound();
    }

    public function test_platform_catalog_does_not_claim_publishing_connections_support_comments(): void
    {
        $f = $this->fixture();
        $this->actingAs($f['user'])->getJson('/api/v1/mobile/social/comments')
            ->assertOk()->assertJsonPath('commentPlatforms.facebook.enabled', true)
            ->assertJsonPath('commentPlatforms.instagram.implemented', true)
            ->assertJsonPath('commentPlatforms.youtube.implemented', false)
            ->assertJsonPath('commentPlatforms.linkedin.enabled', false)
            ->assertJsonPath('commentPlatforms.tiktok.enabled', false);
        config(['social_comments.enabled' => false]);
        $this->assertFalse(SocialCommentCapabilities::catalog()['facebook']['enabled']);
    }

    public function test_non_meta_accounts_never_reach_meta_comment_api_even_with_stale_capabilities(): void
    {
        $f = $this->fixture();
        $f['account']->update(['network' => 'youtube']);
        $this->actingAs($f['user'])->postJson('/api/v1/mobile/social/comments/'.$f['comment']->id.'/reply', ['body' => 'Test', 'idempotency_key' => 'unsupported'])->assertUnprocessable();
        try {
            app(MetaCommentProvider::class)->verifyAndSubscribe($f['account']);
            $this->fail('Unsupported platform accepted');
        } catch (CommentProviderException $e) {
            $this->assertSame('unsupported_platform', $e->reason);
        }
        Http::assertNothingSent();
    }

    public function test_moderation_requires_its_own_capability_not_just_reply_permission(): void
    {
        $f = $this->fixture();
        $f['service']->settings($f['account'])->update(['capabilities' => ['reply' => true, 'hide' => false, 'delete' => false]]);
        $this->actingAs($f['user'])->postJson('/api/v1/mobile/social/comments/'.$f['comment']->id.'/moderate', ['action' => 'delete', 'idempotency_key' => 'denied'])->assertUnprocessable();
        $this->assertDatabaseCount('social_comment_operations', 0);
    }

    public function test_list_detail_and_mutations_are_workspace_scoped_and_safe(): void
    {
        $f = $this->fixture();
        $other = $this->fixture();
        $this->actingAs($f['user'])->getJson('/api/v1/mobile/social/comments')->assertOk()->assertJsonCount(1, 'comments.data')->assertJsonMissingPath('accounts.0.access_token');
        $this->getJson('/api/v1/mobile/social/comments/'.$f['comment']->id)->assertOk()->assertJsonPath('comment.account.name', 'Test Page')->assertJsonPath('comment.account.network', 'facebook')->assertJsonMissingPath('comment.account.meta')->assertJsonMissingPath('comment.account.access_token');
        $this->getJson('/api/v1/mobile/social/comments/'.$other['comment']->id)->assertNotFound();
        $this->postJson('/api/v1/mobile/social/comments/'.$other['comment']->id.'/reply', ['body' => 'Hi', 'idempotency_key' => 'one'])->assertNotFound();
        $this->getJson('/api/v1/mobile/social/comments?account_id='.$other['account']->id)->assertJsonCount(0, 'comments.data');
    }

    public function test_read_status_is_per_agent_and_get_has_no_read_side_effect(): void
    {
        $f = $this->fixture();
        $url = '/api/v1/mobile/social/comments';
        $this->actingAs($f['user'])->getJson($url)->assertJsonPath('comments.data.0.unread', true);
        $this->getJson($url.'/'.$f['comment']->id)->assertOk();
        $this->assertDatabaseCount('social_comment_reads', 0);
        $this->postJson($url.'/'.$f['comment']->id.'/read')->assertOk();
        $this->getJson($url)->assertJsonPath('comments.data.0.unread', false);
    }

    public function test_reply_idempotency_and_conflicting_body(): void
    {
        $f = $this->fixture();
        $url = '/api/v1/mobile/social/comments/'.$f['comment']->id.'/reply';
        $this->actingAs($f['user'])->postJson($url, ['body' => 'We open at nine.', 'idempotency_key' => 'same'])->assertAccepted();
        $this->postJson($url, ['body' => 'We open at nine.', 'idempotency_key' => 'same'])->assertAccepted();
        $this->assertDatabaseCount('social_comment_operations', 1);
        $this->postJson($url, ['body' => 'Different reply', 'idempotency_key' => 'same'])->assertUnprocessable();
        $this->assertTrue($f['comment']->fresh()->ai_paused);
    }

    public function test_viewer_cannot_reply_or_change_ai_settings(): void
    {
        $f = $this->fixture();
        $viewer = User::factory()->create(['workspace_id' => $f['workspace']->id, 'client_id' => $f['client']->id, 'client_role' => 'viewer']);
        $f['workspace']->members()->syncWithoutDetaching([$viewer->id => ['role' => 'viewer']]);
        $this->actingAs($viewer)->getJson('/api/v1/mobile/social/comments')->assertOk();
        $this->postJson('/api/v1/mobile/social/comments/'.$f['comment']->id.'/reply', ['body' => 'Hi', 'idempotency_key' => 'one'])->assertForbidden();
        $this->postJson('/api/v1/mobile/social/comments/accounts/'.$f['account']->id.'/settings', ['mode' => 'off'])->assertForbidden();
    }

    public function test_duplicate_import_and_own_echo_do_not_generate_ai(): void
    {
        $f = $this->fixture();
        $f['service']->settings($f['account'])->update(['mode' => 'automatic']);
        $f['service']->ingest($f['account'], 'post_1', ['id' => 'historical', 'message' => 'What are your hours?'], true);
        $f['service']->ingest($f['account'], 'post_1', ['id' => 'historical', 'message' => 'What are your hours?'], true);
        $f['service']->ingest($f['account'], 'post_1', ['id' => 'echo', 'message' => 'Open at nine', 'from' => ['id' => $f['account']->account_id]], false, 'comment_1');
        $this->assertDatabaseCount('social_comments', 3);
        $this->assertTrue($f['comment']->fresh()->ai_paused);
        Queue::assertNotPushed(ProcessSocialCommentOperation::class);
    }

    public function test_old_edits_do_not_overwrite_newer_comment(): void
    {
        $f = $this->fixture();
        $f['service']->ingest($f['account'], 'post_1', ['id' => 'comment_1', 'message' => 'Old body', 'updated_time' => now()->subDay()->toIso8601String()]);
        $this->assertSame('What are your opening hours?', $f['comment']->fresh()->body);
    }

    public function test_missing_parent_and_deleted_comment_are_safe(): void
    {
        $f = $this->fixture();
        $child = $f['service']->ingest($f['account'], 'post_1', ['id' => 'child', 'text' => 'A reply'], true, 'missing');
        $this->assertSame('missing', $child->parent_remote_id);
        $f['service']->ingest($f['account'], 'post_1', ['id' => 'comment_1', 'verb' => 'remove', 'updated_time' => now()->addMinute()->toIso8601String()]);
        $this->assertNull($f['comment']->fresh()->body);
        $this->actingAs($f['user'])->postJson('/api/v1/mobile/social/comments/'.$f['comment']->id.'/reply', ['body' => 'Hi', 'idempotency_key' => 'one'])->assertUnprocessable();
    }

    public function test_successful_delivery_is_not_sent_twice(): void
    {
        $f = $this->fixture();
        $op = $f['service']->enqueue($f['comment'], 'reply', 'Open at nine', 'one', $f['user']->id);
        Http::fake(['graph.facebook.com/*' => Http::response(['id' => 'reply_1'])]);
        $job = new ProcessSocialCommentOperation($op->id, $f['workspace']->id);
        $job->handle(app(MetaCommentProvider::class), $f['service'], app(ChatbotRunner::class));
        $job->handle(app(MetaCommentProvider::class), $f['service'], app(ChatbotRunner::class));
        $this->assertSame('sent', $op->fresh()->status);
        $this->assertSame('resolved', $f['comment']->fresh()->status);
        Http::assertSentCount(1);
    }

    public function test_timeout_is_unknown_and_cannot_be_blindly_retried(): void
    {
        $f = $this->fixture();
        $op = $f['service']->enqueue($f['comment'], 'reply', 'Open at nine', 'one', $f['user']->id);
        Http::fake(['graph.facebook.com/*' => Http::failedConnection()]);
        (new ProcessSocialCommentOperation($op->id, $f['workspace']->id))->handle(app(MetaCommentProvider::class), $f['service'], app(ChatbotRunner::class));
        $this->assertSame('delivery_unknown', $op->fresh()->status);
        $this->actingAs($f['user'])->postJson('/api/v1/mobile/social/comments/operations/'.$op->id.'/retry')->assertConflict();
    }

    public function test_credential_rotation_and_comment_edits_cancel_queued_actions(): void
    {
        $f = $this->fixture();
        $op = $f['service']->enqueue($f['comment'], 'reply', 'Hello', 'one', $f['user']->id);
        $f['account']->update(['access_token' => 'rotated']);
        (new ProcessSocialCommentOperation($op->id, $f['workspace']->id))->handle(app(MetaCommentProvider::class), $f['service'], app(ChatbotRunner::class));
        $this->assertSame('canceled', $op->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_ambiguous_duplicate_asset_ownership_does_not_fan_out(): void
    {
        $f = $this->fixture();
        $other = $this->fixture();
        $other['account']->update(['account_id' => $f['account']->account_id]);
        (new IngestSocialComments(['id' => $f['account']->account_id, 'changes' => [['field' => 'feed', 'value' => ['item' => 'comment', 'comment_id' => 'new', 'post_id' => 'post_1', 'message' => 'Hi']]]], 'page'))->handle($f['service']);
        $this->assertDatabaseMissing('social_comments', ['remote_id' => 'new']);
    }

    public function test_public_runner_rejects_private_requests_and_missing_published_kb_without_generation(): void
    {
        $f = $this->fixture();
        $bot = AiChatbot::factory()->create(['workspace_id' => $f['workspace']->id, 'enabled' => true, 'ai_kb_id' => null]);
        $this->mock(LlmGateway::class)->shouldNotReceive('chat');
        $runner = app(ChatbotRunner::class);
        $this->assertSame('handoff', $runner->runForPublicComment($bot, 'Where is my order?', $f['workspace']->id, 'one')['decision']);
        $this->assertSame('handoff', $runner->runForPublicComment($bot, 'Opening hours?', $f['workspace']->id, 'two')['decision']);
        $this->assertSame('handoff', $runner->runForPublicComment($bot, 'Opening hours?', 9999, 'three')['decision']);
    }

    public function test_fixed_comment_rate_is_one_credit(): void
    {
        $this->assertSame(1, config('ai_credits.actions.social_comment_reply.credits'));
    }

    public function test_provider_never_follows_arbitrary_urls(): void
    {
        $f = $this->fixture();
        $this->expectException(CommentProviderException::class);
        app(MetaCommentProvider::class)->request($f['account'], 'GET', 'https://127.0.0.1/secret');
    }

    public function test_verified_webhooks_queue_comments_and_invalid_signatures_do_not(): void
    {
        IntegrationConfig::create(['provider' => 'meta_app', 'label' => 'Meta', 'enabled' => true, 'mode' => 'live',
            'credentials' => ['app_id' => 'app_1', 'app_secret' => 'test-secret', 'verify_token' => 'verify-test']]);
        $payload = ['object' => 'page', 'entry' => [['id' => 'page_1', 'changes' => [['field' => 'feed', 'value' => ['item' => 'comment', 'comment_id' => 'comment_2', 'post_id' => 'post_1']]]]]];
        $body = json_encode($payload);
        $this->call('POST', '/webhooks/meta/verify-test', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256=bad'], $body)->assertUnauthorized();
        Queue::assertNotPushed(IngestSocialComments::class);
        $this->call('POST', '/webhooks/meta/verify-test', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'test-secret')], $body)->assertOk();
        Queue::assertPushed(IngestSocialComments::class);
    }

    public function test_subscription_preserves_messaging_fields_and_uses_customer_token_for_writes(): void
    {
        $f = $this->fixture();
        IntegrationConfig::create(['provider' => 'meta_app', 'label' => 'Meta', 'enabled' => true, 'mode' => 'live',
            'credentials' => ['app_id' => 'app_1', 'app_secret' => 'test-secret']]);
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/debug_token')) {
                return Http::response(['data' => ['app_id' => 'app_1', 'is_valid' => true, 'scopes' => ['pages_read_engagement', 'pages_read_user_content', 'pages_manage_engagement', 'pages_manage_metadata']]]);
            }
            if (str_contains($request->url(), '/app_1/subscriptions')) {
                return Http::response(['data' => [['object' => 'page', 'active' => true, 'fields' => [['name' => 'feed']]]]]);
            }
            if (str_contains($request->url(), '/subscribed_apps') && $request->method() === 'GET') {
                return Http::response(['data' => [['id' => 'app_1', 'subscribed_fields' => ['messages', 'feed']]]]);
            }

            return Http::response(['id' => 'ok', 'data' => []]);
        });
        $result = app(MetaCommentProvider::class)->verifyAndSubscribe($f['account']);
        $this->assertTrue($result['reply']);
        Http::assertSent(fn ($request) => $request->method() === 'POST' && $request['subscribed_fields'] === 'messages,feed' && $request->hasHeader('Authorization', 'Bearer secret-customer-token'));
        Http::assertNotSent(fn ($request) => $request->method() === 'POST' && $request->hasHeader('Authorization', 'Bearer app_1|test-secret'));
    }

    public function test_missing_comment_scope_never_claims_ready_or_changes_subscriptions(): void
    {
        $f = $this->fixture();
        IntegrationConfig::create(['provider' => 'meta_app', 'label' => 'Meta', 'enabled' => true, 'mode' => 'live', 'credentials' => ['app_id' => 'app_1', 'app_secret' => 'test-secret']]);
        Http::fake(['*/debug_token*' => Http::response(['data' => ['app_id' => 'app_1', 'is_valid' => true, 'scopes' => ['pages_read_engagement']]]), '*' => Http::response(['data' => []])]);
        try {
            app(MetaCommentProvider::class)->verifyAndSubscribe($f['account']);
            $this->fail('Missing scope accepted');
        } catch (CommentProviderException $e) {
            $this->assertSame('permission_required', $e->reason);
        }
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    public function test_published_revision_filter_is_enforced_even_when_legacy_kb_flag_is_off(): void
    {
        $f = $this->fixture();
        config(['knowledge_base.guarded_publishing' => false]);
        $kb = AiKnowledgeBase::create(['workspace_id' => $f['workspace']->id, 'name' => 'Public']);
        $rev = AiKbRevision::create(['kb_id' => $kb->id, 'version' => 1, 'status' => 'published']);
        foreach (['approved', 'blocked'] as $status) {
            $doc = AiKbDocument::create(['kb_id' => $kb->id, 'source_type' => 'text', 'source_ref' => 'Opening hours', 'status' => 'indexed', 'enabled' => true, 'review_status' => $status]);
            $rev->documents()->attach($doc);
            AiKbChunk::create(['kb_id' => $kb->id, 'document_id' => $doc->id, 'ord' => 0, 'content' => $status, 'embedding' => '[1,0]', 'embedding_status' => 'ready']);
        }
        $result = app(EmbeddingStore::class)->search($kb->id, [1, 0], 10, $rev->id);
        $this->assertCount(1, $result);
        $this->assertSame('approved', $result[0]['chunk']->content);
    }

    public function test_automatic_mode_requires_a_preview_and_published_public_kb(): void
    {
        $f = $this->fixture();
        $kb = AiKnowledgeBase::create(['workspace_id' => $f['workspace']->id, 'name' => 'Public']);
        $rev = AiKbRevision::create(['kb_id' => $kb->id, 'version' => 1, 'status' => 'published']);
        $kb->update(['published_revision_id' => $rev->id]);
        $bot = AiChatbot::factory()->create(['workspace_id' => $f['workspace']->id, 'enabled' => true, 'ai_kb_id' => $kb->id]);
        $url = '/api/v1/mobile/social/comments/accounts/'.$f['account']->id.'/settings';
        $this->actingAs($f['user'])->postJson($url, ['mode' => 'automatic', 'chatbot_id' => $bot->id, 'public_kb_confirmed' => true])->assertUnprocessable();
        $this->postJson($url, ['mode' => 'suggestions', 'chatbot_id' => $bot->id, 'public_kb_confirmed' => true])->assertOk();
        $f['service']->settings($f['account'])->update(['previewed_at' => now()]);
        $this->postJson($url, ['mode' => 'automatic', 'chatbot_id' => $bot->id, 'public_kb_confirmed' => true])->assertOk();
        $this->postJson($url, ['mode' => 'automatic', 'chatbot_id' => $bot->id, 'public_kb_confirmed' => false])->assertUnprocessable();
    }

    public function test_paused_ancestor_prevents_automatic_reply_to_child(): void
    {
        $f = $this->fixture();
        $f['comment']->update(['ai_paused' => true]);
        $f['service']->settings($f['account'])->update(['mode' => 'automatic']);
        $f['service']->ingest($f['account'], 'post_1', ['id' => 'child_live', 'message' => 'What are your hours?'], false, 'comment_1');
        Queue::assertNotPushed(ProcessSocialCommentOperation::class);
    }
}
