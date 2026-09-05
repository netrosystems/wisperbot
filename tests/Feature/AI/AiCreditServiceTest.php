<?php

namespace Tests\Feature\AI;

use App\Models\Client;
use App\Models\ClientSubscription;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Modules\AI\Exceptions\AiCreditsException;
use App\Modules\AI\Models\AiCreditLedger;
use App\Modules\AI\Services\AiCreditService;
use App\Modules\AI\Services\Llm\LlmResponse;
use App\Notifications\AiCreditsThresholdNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AiCreditServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('ai_credits.enforce', true);
    }

    public function test_action_catalog_is_the_single_source_for_fixed_rates(): void
    {
        $service = app(AiCreditService::class);

        $this->assertNull(config('ai_credits.allowances_by_monthly_price_cents'));
        $this->assertSame(1, $service->creditsFor('chatbot_reply'));
        $this->assertSame(2, $service->creditsFor('social_post'));
        $this->assertSame(5, $service->creditsFor('workflow_generate'));
        $this->assertSame(0, $service->creditsFor('document_embedding'));
        $this->assertSame(config('ai_credits.rates'), collect(config('ai_credits.actions'))->map(fn ($action) => $action['credits'])->all());
    }

    public function test_workspaces_owned_by_the_same_account_share_one_period(): void
    {
        [$owner, $first] = $this->subscribedWorkspace(5);
        $second = Workspace::factory()->create(['owner_id' => $owner->id]);
        $service = app(AiCreditService::class);

        $one = $service->reserve($first->id, 'email_generate', 'action-one', $owner->id);
        $service->succeed($one->ledger, $this->response(), 'openai');
        $two = $service->reserve($second->id, 'social_post', 'action-two', $owner->id);
        $service->succeed($two->ledger, $this->response(), 'openai');

        $usage = $service->usage($first->id);
        $this->assertSame(4, $usage['used']);
        $this->assertSame(1, $usage['remaining']);
        $this->assertSame($usage, $service->usage($second->id));
    }

    public function test_usage_summary_and_action_breakdown_use_plan_limit_and_catalog_labels(): void
    {
        [, $workspace] = $this->subscribedWorkspace(100);
        $service = app(AiCreditService::class);
        $reservation = $service->reserve($workspace->id, 'social_post', 'social-copy');
        $service->succeed($reservation->ledger, $this->response(), 'openai');

        $usage = $service->usage($workspace->id);
        $this->assertSame(100, $usage['limit']);
        $this->assertSame(2, $usage['used']);
        $this->assertSame(98, $usage['remaining']);
        $this->assertSame('available', $usage['status']);

        $details = $service->usageDetails($workspace->id);
        $this->assertSame('Generate one social post', $details['by_action'][0]['label']);
        $this->assertSame(2, $details['by_action'][0]['credits_per_action']);
        $this->assertSame(2, $details['by_action'][0]['credits_used']);
        $this->assertNotEmpty($details['rates']);
    }

    public function test_authenticated_client_can_refresh_workspace_scoped_header_summary(): void
    {
        [$owner, $workspace] = $this->subscribedWorkspace(100);
        $owner->update(['workspace_id' => $workspace->id]);

        $this->actingAs($owner)
            ->getJson(route('client.subscription.ai-credits'))
            ->assertOk()
            ->assertJsonPath('data.limit', 100)
            ->assertJsonPath('data.remaining', 100)
            ->assertJsonPath('data.status', 'available')
            ->assertJsonMissingPath('data.rates');
    }

    public function test_header_summary_rejects_an_inaccessible_workspace(): void
    {
        [$owner] = $this->subscribedWorkspace(100);
        $otherWorkspace = Workspace::factory()->create();
        $owner->update(['workspace_id' => $otherWorkspace->id]);

        $this->actingAs($owner)
            ->getJson(route('client.subscription.ai-credits'))
            ->assertForbidden();
    }

    public function test_usage_distinguishes_unconfigured_allowance_from_zero_credit_plan(): void
    {
        $owner = User::factory()->create(['role' => User::ROLE_CLIENT, 'status' => User::STATUS_ACTIVE]);
        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
        $plan = Plan::factory()->create(['limits' => []]);
        Subscription::create([
            'user_id' => $owner->id, 'plan_id' => $plan->id, 'status' => 'active',
            'billing_cycle' => 'month', 'starts_at' => now()->subDay(), 'gateway' => 'manual',
        ]);

        $this->assertSame('allowance_not_configured', app(AiCreditService::class)->usage($workspace->id)['status']);

        $plan->update(['limits' => ['ai_credits_per_month' => 0]]);
        $owner->unsetRelation('activeSubscription');
        $this->assertSame('not_included', app(AiCreditService::class)->usage($workspace->id)['status']);
    }

    public function test_final_credit_cannot_be_reserved_twice(): void
    {
        [$owner, $workspace] = $this->subscribedWorkspace(1);
        $service = app(AiCreditService::class);
        $first = $service->reserve($workspace->id, 'chatbot_reply', 'first', $owner->id);

        try {
            $service->reserve($workspace->id, 'chatbot_reply', 'second', $owner->id);
            $this->fail('Expected credit exhaustion.');
        } catch (AiCreditsException $e) {
            $this->assertSame('ai_credits_exhausted', $e->errorCode);
        }

        $this->assertSame(1, $service->usage($workspace->id)['reserved']);
        $service->refund($first->ledger, 'provider_failed');
        $this->assertSame(1, $service->usage($workspace->id)['remaining']);
    }

    public function test_failed_and_stale_actions_refund_reserved_credits(): void
    {
        [, $workspace] = $this->subscribedWorkspace(5);
        $service = app(AiCreditService::class);
        $reservation = $service->reserve($workspace->id, 'workflow_generate', 'workflow');
        $reservation->ledger->update(['reserved_at' => now()->subMinutes(11)]);

        $this->assertSame(1, $service->reconcileStaleReservations());
        $this->assertSame(5, $service->usage($workspace->id)['remaining']);
        $this->assertSame('refunded', $reservation->ledger->fresh()->status);
    }

    public function test_successful_idempotent_retry_replays_without_a_second_charge(): void
    {
        [, $workspace] = $this->subscribedWorkspace(5);
        $service = app(AiCreditService::class);
        $reservation = $service->reserve($workspace->id, 'email_generate', 'same-action');
        $service->succeed($reservation->ledger, $this->response('Saved response'), 'openai');

        $replayed = $service->reserve($workspace->id, 'email_generate', 'same-action');

        $this->assertSame('Saved response', $replayed->replayedResponse?->content);
        $this->assertSame(2, $service->usage($workspace->id)['used']);
        $this->assertSame(1, AiCreditLedger::count());
    }

    public function test_free_managed_credits_require_verified_owner_email(): void
    {
        $owner = User::factory()->unverified()->create();
        $plan = Plan::factory()->create(['price_cents' => 0, 'limits' => ['ai_credits_per_month' => 100]]);
        Subscription::create([
            'user_id' => $owner->id, 'plan_id' => $plan->id, 'status' => 'active',
            'billing_cycle' => 'month', 'starts_at' => now()->subDay(), 'gateway' => 'manual',
        ]);
        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);

        $this->expectException(AiCreditsException::class);
        $this->expectExceptionMessage('Verify the workspace owner email');
        app(AiCreditService::class)->reserve($workspace->id, 'chatbot_reply', 'unverified');
    }

    public function test_missing_or_null_managed_allowance_is_reported_as_not_configured(): void
    {
        $owner = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
        $plan = Plan::factory()->create(['limits' => ['ai_credits_per_month' => null]]);
        Subscription::create([
            'user_id' => $owner->id, 'plan_id' => $plan->id, 'status' => 'active',
            'billing_cycle' => 'month', 'starts_at' => now()->subDay(), 'gateway' => 'manual',
        ]);

        $this->expectException(AiCreditsException::class);
        $this->expectExceptionMessage('allowance is not configured');
        app(AiCreditService::class)->reserve($workspace->id, 'chatbot_reply', 'null-allowance');
    }

    public function test_paid_managed_credits_do_not_require_verified_owner_email(): void
    {
        $owner = User::factory()->unverified()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
        $plan = Plan::factory()->create([
            'monthly_price_cents' => 2000,
            'limits' => ['ai_credits_per_month' => 1000],
        ]);
        Subscription::create([
            'user_id' => $owner->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'billing_cycle' => 'month',
            'starts_at' => now()->subDay(),
            'gateway' => 'manual',
        ]);

        $reservation = app(AiCreditService::class)->reserve($workspace->id, 'chatbot_reply', 'paid-unverified');

        $this->assertSame(1, $reservation->ledger->credits);
        $this->assertSame('reserved', $reservation->ledger->status);
    }

    public function test_mid_period_upgrade_increases_allowance_but_downgrade_does_not_remove_credits(): void
    {
        [$owner, $workspace, $subscription] = $this->subscribedWorkspace(5);
        $service = app(AiCreditService::class);
        $this->assertSame(5, $service->usage($workspace->id)['allowance']);

        $upgrade = Plan::factory()->create(['limits' => ['ai_credits_per_month' => 15]]);
        $subscription->update(['plan_id' => $upgrade->id]);
        $owner->unsetRelation('activeSubscription');
        $this->assertSame(15, $service->usage($workspace->id)['allowance']);

        $downgrade = Plan::factory()->create(['limits' => ['ai_credits_per_month' => 1]]);
        $subscription->update(['plan_id' => $downgrade->id]);
        $owner->unsetRelation('activeSubscription');
        $this->assertSame(15, $service->usage($workspace->id)['allowance']);
    }

    public function test_annual_subscription_still_resets_monthly_without_rollover(): void
    {
        Carbon::setTestNow('2026-09-04 12:00:00');
        [$owner, $workspace, $subscription] = $this->subscribedWorkspace(5);
        $subscription->update(['billing_cycle' => 'year', 'starts_at' => '2026-08-15 09:30:00']);
        $owner->unsetRelation('activeSubscription');
        $service = app(AiCreditService::class);
        $reservation = $service->reserve($workspace->id, 'email_generate', 'august');
        $service->succeed($reservation->ledger, $this->response(), 'openai');
        $this->assertSame(3, $service->usage($workspace->id)['remaining']);

        Carbon::setTestNow('2026-09-15 10:00:00');
        $owner->unsetRelation('activeSubscription');
        $usage = $service->usage($workspace->id);
        $this->assertSame(5, $usage['remaining']);
        $this->assertSame(0, $usage['used']);
        Carbon::setTestNow();
    }

    public function test_client_organization_pools_credits_across_different_workspace_owners(): void
    {
        $client = Client::create(['name' => 'Shared Org', 'status' => 'active']);
        $plan = Plan::factory()->create(['limits' => ['ai_credits_per_month' => 5]]);
        ClientSubscription::create([
            'client_id' => $client->id,
            'plan_id' => $plan->id,
            'billing_cycle' => 'yearly',
            'starts_at' => now()->subWeek(),
            'status' => 'active',
        ]);
        $firstOwner = User::factory()->create(['client_id' => $client->id]);
        $secondOwner = User::factory()->create(['client_id' => $client->id]);
        $first = Workspace::factory()->create(['owner_id' => $firstOwner->id, 'client_id' => $client->id]);
        $second = Workspace::factory()->create(['owner_id' => $secondOwner->id, 'client_id' => $client->id]);
        $service = app(AiCreditService::class);
        $reservation = $service->reserve($first->id, 'social_post', 'org-action');
        $service->succeed($reservation->ledger, $this->response(), 'openai');

        $this->assertSame(3, $service->usage($second->id)['remaining']);
        $this->assertSame('client', $reservation->ledger->period->account_type);
    }

    public function test_organization_uses_a_members_self_service_plan_when_no_client_subscription_exists(): void
    {
        $client = Client::create(['name' => 'Self-service Org', 'status' => 'active']);
        $owner = User::factory()->create([
            'client_id' => $client->id,
            'role' => User::ROLE_CLIENT,
            'status' => User::STATUS_ACTIVE,
        ]);
        $plan = Plan::factory()->create(['limits' => ['ai_credits_per_month' => 100]]);
        Subscription::create([
            'user_id' => $owner->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'billing_cycle' => 'month',
            'starts_at' => now()->subDay(),
            'gateway' => 'manual',
        ]);
        $workspace = Workspace::factory()->create(['owner_id' => $owner->id, 'client_id' => $client->id]);

        $usage = app(AiCreditService::class)->usage($workspace->id);

        $this->assertSame(100, $usage['limit']);
        $this->assertSame(100, $usage['remaining']);
        $this->assertSame('available', $usage['status']);
    }

    public function test_cancelled_subscription_keeps_current_credits_until_access_end(): void
    {
        [, $workspace, $subscription] = $this->subscribedWorkspace(5);
        $subscription->update(['status' => 'canceled', 'ends_at' => now()->addWeek()]);

        $this->assertSame(5, app(AiCreditService::class)->usage($workspace->id)['remaining']);

        $subscription->update(['ends_at' => now()->subSecond()]);
        $this->assertSame(0, app(AiCreditService::class)->usage($workspace->id)['remaining']);
    }

    public function test_active_client_subscription_uses_plan_credits_when_previous_cycle_end_date_is_stale(): void
    {
        $client = Client::create(['name' => 'Renewing Org', 'status' => 'active']);
        $owner = User::factory()->create([
            'client_id' => $client->id,
            'role' => User::ROLE_CLIENT,
            'status' => User::STATUS_ACTIVE,
        ]);
        $plan = Plan::factory()->create(['limits' => ['ai_credits_per_month' => 100]]);
        ClientSubscription::create([
            'client_id' => $client->id,
            'plan_id' => $plan->id,
            'billing_cycle' => 'monthly',
            'starts_at' => now()->subMonths(5),
            'ends_at' => now()->subMonth(),
            'status' => ClientSubscription::STATUS_ACTIVE,
        ]);
        $workspace = Workspace::factory()->create([
            'owner_id' => $owner->id,
            'client_id' => $client->id,
        ]);

        $usage = app(AiCreditService::class)->usage($workspace->id);

        $this->assertSame(100, $usage['limit']);
        $this->assertSame(100, $usage['remaining']);
        $this->assertSame('available', $usage['status']);
        $this->assertTrue(Carbon::parse($usage['resets_at'])->isFuture());
    }

    public function test_threshold_notifications_are_sent_only_once_per_period(): void
    {
        Notification::fake();
        [$owner, $workspace] = $this->subscribedWorkspace(5);
        $service = app(AiCreditService::class);

        $first = $service->reserve($workspace->id, 'workflow_generate', 'threshold-one');
        $service->succeed($first->ledger, $this->response(), 'openai');
        $service->succeed($first->ledger, $this->response(), 'openai');

        Notification::assertSentToTimes($owner, AiCreditsThresholdNotification::class, 2);
        Notification::assertSentTo($owner, AiCreditsThresholdNotification::class, fn ($notice) => $notice->threshold === 80);
        Notification::assertSentTo($owner, AiCreditsThresholdNotification::class, fn ($notice) => $notice->threshold === 100);
    }

    public function test_notification_failure_does_not_undo_a_completed_credit_charge(): void
    {
        [, $workspace] = $this->subscribedWorkspace(1);
        Notification::shouldReceive('send')->once()->andThrow(new \RuntimeException('mail transport unavailable'));

        $service = app(AiCreditService::class);
        $reservation = $service->reserve($workspace->id, 'chatbot_reply', 'notification-outage');
        $service->succeed($reservation->ledger, $this->response(), 'openai');

        $this->assertSame('succeeded', $reservation->ledger->fresh()->status);
        $this->assertSame(1, $service->usage($workspace->id)['used']);
        $this->assertSame(0, $service->usage($workspace->id)['reserved']);
    }

    private function subscribedWorkspace(int $credits): array
    {
        $owner = User::factory()->create(['role' => User::ROLE_CLIENT, 'status' => User::STATUS_ACTIVE]);
        $plan = Plan::factory()->create(['limits' => ['ai_credits_per_month' => $credits]]);
        $subscription = Subscription::create([
            'user_id' => $owner->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'billing_cycle' => 'month',
            'starts_at' => now()->subDay(),
            'gateway' => 'manual',
        ]);
        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);

        return [$owner, $workspace, $subscription];
    }

    private function response(string $content = 'OK'): LlmResponse
    {
        return new LlmResponse($content, 10, 5, 'gpt-5-nano', 25);
    }
}
