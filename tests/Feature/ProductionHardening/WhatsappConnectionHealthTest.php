<?php

namespace Tests\Feature\ProductionHardening;

use App\Http\Controllers\Admin\CronSetupController;
use App\Models\User;
use App\Models\Workspace;
use App\Modules\Integrations\Models\IntegrationConfig;
use App\Modules\Whatsapp\Http\Controllers\WhatsappConnectionHealthController;
use App\Modules\Whatsapp\Models\WhatsappBusinessAccount;
use App\Modules\Whatsapp\Models\WhatsappPhoneNumber;
use App\Modules\Whatsapp\Services\WhatsappConnectionHealthService;
use App\Modules\Whatsapp\Services\WhatsappHealthProbe;
use App\Notifications\WhatsappConnectionHealthNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class WhatsappConnectionHealthTest extends TestCase
{
    use RefreshDatabase;

    private WhatsappBusinessAccount $waba;

    private WhatsappConnectionHealthService $service;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        config(['channel_health.enabled' => true]);
        Queue::fake();
        Notification::fake();
        Http::preventStrayRequests();
        $this->owner = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $this->owner->id]);
        $this->owner->update(['workspace_id' => $workspace->id, 'current_workspace_id' => $workspace->id]);
        $this->waba = WhatsappBusinessAccount::factory()->create(['workspace_id' => $workspace->id, 'waba_id' => 'WABA', 'credentials' => ['access_token' => 'CUSTOMER_TOKEN']]);
        WhatsappPhoneNumber::create(['waba_id_fk' => $this->waba->id, 'phone_number_id' => 'PHONE']);
        IntegrationConfig::create(['provider' => 'meta_app', 'label' => 'Meta', 'enabled' => true, 'credentials' => ['app_id' => 'APP', 'app_secret' => 'SECRET', 'system_user_token' => 'OPERATOR_TOKEN']]);
        foreach ([CronSetupController::HEARTBEAT_KEY, 'wa-health:whatsapp-worker', 'wa-health:worker'] as $key) {
            Cache::put($key, now()->toIso8601String());
        }
        $this->service = app(WhatsappConnectionHealthService::class);
    }

    private function fakeMeta(bool $subscribed = true, ?array $accessError = null, bool $unknownPhone = false, bool $subscriptionRecorded = true): void
    {
        Http::fake(function ($request) use (&$subscribed, $accessError, $unknownPhone, $subscriptionRecorded) {
            $path = parse_url($request->url(), PHP_URL_PATH);

            return match ($path) {
                '/v25.0/debug_token' => Http::response(['data' => ['is_valid' => true, 'app_id' => 'APP', 'scopes' => ['whatsapp_business_management', 'whatsapp_business_messaging']]]),
                '/v25.0/WABA' => $accessError ? Http::response($accessError, 400) : Http::response(['id' => 'WABA', 'owner_business_info' => ['id' => 'OWNER']]),
                '/v25.0/WABA/subscribed_apps' => $request->method() === 'POST'
                    ? Http::response(['success' => true, 'recorded' => $subscribed = $subscriptionRecorded])
                    : Http::response(['data' => $subscribed ? [['whatsapp_business_api_data' => ['id' => 'APP']]] : [['whatsapp_business_api_data' => ['id' => 'OTHER_APP']]]]),
                '/v25.0/WABA/phone_numbers' => Http::response(['data' => [$unknownPhone ? ['id' => 'PHONE'] : ['id' => 'PHONE', 'status' => 'CONNECTED', 'verified_name' => 'Example']]]),
                '/v25.0/PHONE' => $unknownPhone ? Http::response(['error' => ['code' => 100, 'message' => 'private secret should never be shown']], 400)
                    : Http::response(['id' => 'PHONE', 'status' => 'CONNECTED', 'verified_name' => 'Example']),
                '/v25.0/APP/subscriptions' => Http::response(['data' => [[
                    'object' => 'whatsapp_business_account', 'active' => true, 'callback_url' => route('webhooks.whatsapp.global.receive'),
                    'fields' => array_map(fn ($name) => ['name' => $name], ['messages', 'message_template_status_update', 'phone_number_name_update', 'phone_number_quality_update', 'account_update', 'history', 'smb_app_state_sync', 'smb_message_echoes']),
                ]]]),
                default => throw new \RuntimeException('Unexpected provider path: '.$path),
            };
        });
    }

    private function runCheck(string $kind = 'check'): void
    {
        $operation = $this->service->enqueue($this->waba, $kind);
        $this->service->execute($operation->id, $this->waba->workspace_id);
    }

    public function test_quiet_account_passes_configuration_without_claiming_delivery(): void
    {
        $this->fakeMeta();
        $this->runCheck();
        $summary = $this->service->summary($this->waba);
        $this->assertSame('ready', $summary['state']);
        $this->assertFalse($summary['delivery_verified']);
        $this->assertNull($summary['last_message_at']);
        $this->assertSame('active', $this->waba->fresh()->status);
        $this->assertStringNotContainsString('TOKEN', json_encode($summary));
        Http::assertNotSent(fn ($r) => $r->method() === 'POST');
    }

    public function test_repair_reads_back_subscription_and_never_registers_coexistence_number(): void
    {
        $this->waba->update(['meta_json' => ['connected_via' => 'coexistence']]);
        $this->fakeMeta(false);
        $this->runCheck('repair');
        $this->assertSame('ready', $this->service->summary($this->waba)['state']);
        $this->assertNotNull($this->service->snapshot($this->waba)->repaired_at);
        Http::assertSent(fn ($r) => $r->method() === 'POST' && str_ends_with($r->url(), '/WABA/subscribed_apps') && $r->hasHeader('Authorization', 'Bearer CUSTOMER_TOKEN'));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/register') || str_contains($r->url(), '/smb_app_data') || $r->hasHeader('Authorization', 'Bearer OPERATOR_TOKEN'));
    }

    public function test_missing_subscription_is_actionable_and_notifications_are_deduplicated(): void
    {
        $this->fakeMeta(false);
        $this->runCheck();
        $this->runCheck();
        $this->assertSame('repair', $this->service->summary($this->waba)['action']);
        Notification::assertSentToTimes($this->owner, WhatsappConnectionHealthNotification::class, 1);
        $this->runCheck('repair');
        Notification::assertSentToTimes($this->owner, WhatsappConnectionHealthNotification::class, 2);
    }

    public function test_revoked_customer_token_never_falls_back_to_platform_token(): void
    {
        $this->fakeMeta(true, ['error' => ['code' => 190, 'message' => 'SECRET']]);
        $this->runCheck('repair');
        $summary = $this->service->summary($this->waba);
        $this->assertSame('reconnect_required', $summary['state']);
        $this->assertStringNotContainsString('SECRET', json_encode($summary));
        Http::assertNotSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer OPERATOR_TOKEN') || $r->method() === 'POST');
    }

    public function test_operator_token_requires_explicit_allowlist_and_verified_owner(): void
    {
        config(['channel_health.operator_business_id' => 'WRONG_OWNER', 'channel_health.operator_waba_ids' => ['WABA']]);
        $this->fakeMeta(false);
        $this->runCheck('repair');
        $this->assertSame('contact_admin', $this->service->summary($this->waba)['action']);
        Http::assertNotSent(fn ($r) => $r->method() === 'POST');
        config(['channel_health.operator_business_id' => 'OWNER']);
        $this->runCheck('repair');
        $this->assertSame('ready', $this->service->summary($this->waba)['state']);
        Http::assertSent(fn ($r) => $r->method() === 'POST' && $r->hasHeader('Authorization', 'Bearer OPERATOR_TOKEN'));
    }

    public function test_duplicate_operations_and_changed_credentials_do_not_execute_stale_work(): void
    {
        $first = $this->service->enqueue($this->waba);
        $this->assertSame($first->id, $this->service->enqueue($this->waba, 'repair')->id);
        $this->waba->update(['credentials' => ['access_token' => 'NEW_TOKEN']]);
        $this->service->execute($first->id, $this->waba->workspace_id);
        $this->assertSame('discarded', $first->fresh()->state);
        Http::assertNothingSent();
    }

    public function test_wrong_workspace_and_non_manager_cannot_repair(): void
    {
        $request = Request::create('/');
        $member = User::factory()->create(['workspace_id' => $this->waba->workspace_id]);
        $request->setUserResolver(fn () => $member);
        try {
            WhatsappConnectionHealthController::authorizeManager($request, $this->waba);
            $this->fail('Member must not manage channels');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $request->setUserResolver(fn () => $this->owner);
        $other = WhatsappBusinessAccount::factory()->create(['workspace_id' => Workspace::factory()->create()->id]);
        $this->expectException(HttpException::class);
        WhatsappConnectionHealthController::authorizeManager($request, $other);
    }

    public function test_unknown_fields_and_rate_limits_never_ask_for_reconnection(): void
    {
        $this->fakeMeta(true, null, true);
        $this->runCheck();
        $this->assertSame('check_delayed', $this->service->summary($this->waba)['state']);
    }

    public function test_rate_limits_preserve_retry_after_without_repeating_provider_calls(): void
    {
        Http::fake(['*' => Http::response(['error' => ['code' => 4]], 429, ['Retry-After' => '3600'])]);
        $result = app(WhatsappHealthProbe::class)->request('T', 'limited');
        $this->assertSame(3600, $result['retry_after']);
        $this->assertSame('temporary', app(WhatsappHealthProbe::class)->request('T', 'limited')['reason']);
    }

    public function test_only_verified_live_receipt_and_processing_establish_delivery(): void
    {
        $this->fakeMeta();
        $this->runCheck('repair');
        $entry = fn ($field) => [['id' => 'WABA', 'changes' => [['field' => $field, 'value' => ['metadata' => ['phone_number_id' => 'PHONE'], 'messages' => [['id' => 'MESSAGE']]]]]]];
        foreach (['history', 'smb_message_echoes', 'smb_app_state_sync'] as $field) {
            $this->service->received($entry($field));
        }
        $this->assertNull($this->service->summary($this->waba)['last_message_at']);
        $this->travel(1)->seconds();
        $this->service->received($entry('messages'));
        $this->travel(6)->minutes();
        $this->assertSame('processing_delayed', $this->service->summary($this->waba)['components']['platform:processing']['code']);
        $this->service->processed('WABA', 'PHONE', true);
        $this->assertTrue($this->service->summary($this->waba)['delivery_verified']);
        $this->assertSame(0, $this->service->snapshot($this->waba)->pending_live_messages);
    }

    public function test_stale_workers_do_not_disable_connection_or_clear_last_success(): void
    {
        $this->fakeMeta();
        $this->runCheck();
        $this->travel(31)->minutes();
        $summary = $this->service->summary($this->waba);
        $this->assertSame('check_delayed', $summary['state']);
        $this->assertSame('contact_admin', $summary['action']);
        $this->assertNotNull($summary['last_success_at']);
        $this->assertSame('active', $this->waba->fresh()->status);
    }

    public function test_missing_messaging_permission_is_detected_before_repair(): void
    {
        Http::fake(['*' => Http::response(['data' => ['is_valid' => true, 'app_id' => 'APP', 'scopes' => ['whatsapp_business_management']]])]);
        $this->runCheck('repair');
        $this->assertSame('reconnect_required', $this->service->summary($this->waba)['state']);
        Http::assertNotSent(fn ($r) => $r->method() === 'POST');
    }

    public function test_cross_workspace_jobs_and_disconnected_accounts_do_not_call_meta(): void
    {
        $operation = $this->service->enqueue($this->waba);
        $this->service->execute($operation->id, $this->waba->workspace_id + 999);
        $this->assertSame('queued', $operation->fresh()->state);
        $this->waba->update(['status' => 'inactive']);
        $this->service->execute($operation->id, $this->waba->workspace_id);
        $this->assertSame('discarded', $operation->fresh()->state);
        Http::assertNothingSent();
    }

    public function test_queue_endpoint_reuses_operation_and_throttles_new_work(): void
    {
        $request = Request::create('/');
        $request->setUserResolver(fn () => $this->owner);
        $controller = app(WhatsappConnectionHealthController::class);
        $first = $controller->check($request, $this->waba, $this->service);
        $second = $controller->repair($request, $this->waba, $this->service);
        $this->assertSame(202, $first->getStatusCode());
        $this->assertSame($first->getData(true)['operation_id'], $second->getData(true)['operation_id']);
        $this->assertStringNotContainsString('CUSTOMER_TOKEN', $first->getContent());
    }

    public function test_failed_processing_remains_visible_and_history_cannot_clear_it(): void
    {
        $this->service->received([['id' => 'WABA', 'changes' => [['field' => 'messages', 'value' => ['metadata' => ['phone_number_id' => 'PHONE'], 'messages' => [['id' => 'M']]]]]]]);
        $this->service->processed('WABA', 'PHONE', false);
        $summary = $this->service->summary($this->waba);
        $this->assertSame('needs_attention', $summary['state']);
        $this->assertSame('processing_failed', $summary['components']['platform:processing']['code']);
        $this->assertFalse($summary['delivery_verified']);
    }

    public function test_deleted_waba_cascades_health_history_and_job_is_harmless(): void
    {
        $operation = $this->service->enqueue($this->waba);
        $id = $this->waba->id;
        $workspace = $this->waba->workspace_id;
        $this->waba->delete();
        $this->service->execute($operation->id, $workspace);
        $this->assertDatabaseMissing('whatsapp_connection_health', ['waba_id' => $id]);
        $this->assertDatabaseMissing('whatsapp_connection_operations', ['id' => $operation->id]);
        Http::assertNothingSent();
    }

    public function test_scheduler_initially_spreads_accounts_and_only_dispatches_due_ones(): void
    {
        $this->service->tick();
        $health = $this->service->snapshot($this->waba);
        $this->assertTrue($health->next_check_at->between(now(), now()->addMinutes(15)));
        $this->assertNull($health->operation_id);
        $health->update(['next_check_at' => now()->subSecond()]);
        $this->service->tick();
        $this->assertNotNull($health->fresh()->operation_id);
        $this->assertSame('active', $this->waba->fresh()->status);
    }

    public function test_shared_worker_incidents_notify_only_super_admin_and_recover_once(): void
    {
        $admin = $this->createSuperAdmin();
        Cache::forget('wa-health:whatsapp-worker');
        for ($i = 0; $i < 5; $i++) {
            $this->service->platformIncident();
        }
        Notification::assertSentToTimes($admin, WhatsappConnectionHealthNotification::class, 1);
        Notification::assertNotSentTo($this->owner, WhatsappConnectionHealthNotification::class);
        Cache::put('wa-health:whatsapp-worker', now()->toIso8601String());
        Cache::put('wa-health:last-platform', ['webhook' => ['state' => 'passed']]);
        $this->service->platformIncident();
        $this->service->platformIncident();
        Notification::assertSentToTimes($admin, WhatsappConnectionHealthNotification::class, 2);
    }

    public function test_multiple_phones_are_checked_without_attaching_new_accounts(): void
    {
        WhatsappPhoneNumber::create(['waba_id_fk' => $this->waba->id, 'phone_number_id' => 'SECOND']);
        $this->fakeMeta();
        $this->runCheck('repair');
        $summary = $this->service->summary($this->waba);
        $this->assertSame('passed', $summary['components']['phone:PHONE']['state']);
        $this->assertSame('phone_not_in_waba', $summary['components']['phone:SECOND']['code']);
        $this->assertCount(2, $this->waba->phoneNumbers()->get());
        $this->assertSame('reconnect_required', $summary['state']);
    }

    public function test_successful_post_without_matching_readback_is_not_a_repair_success(): void
    {
        $this->fakeMeta(false, null, false, false);
        $this->runCheck('repair');
        $summary = $this->service->summary($this->waba);
        $this->assertSame('needs_attention', $summary['state']);
        $this->assertNull($this->service->snapshot($this->waba)->repaired_at);
    }

    public function test_new_manual_operations_are_rate_limited(): void
    {
        $key = 'wa-health:request:'.$this->waba->workspace_id.':'.$this->waba->id;
        for ($i = 0; $i < 3; $i++) {
            RateLimiter::hit($key, 60);
        }
        $request = Request::create('/');
        $request->setUserResolver(fn () => $this->owner);
        try {
            app(WhatsappConnectionHealthController::class)->check($request, $this->waba, $this->service);
            $this->fail('Expected a rate limit');
        } catch (HttpException $e) {
            $this->assertSame(429, $e->getStatusCode());
        }
    }
}
