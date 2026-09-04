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

    public function test_rollout_allowances_match_all_four_monthly_prices(): void
    {
        $this->assertSame([
            0 => 100,
            2000 => 1000,
            4000 => 3000,
            15000 => 15000,
        ], config('ai_credits.allowances_by_monthly_price_cents'));
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

    public function test_missing_or_null_managed_allowance_is_finite_zero(): void
    {
        $owner = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
        $plan = Plan::factory()->create(['limits' => ['ai_credits_per_month' => null]]);
        Subscription::create([
            'user_id' => $owner->id, 'plan_id' => $plan->id, 'status' => 'active',
            'billing_cycle' => 'month', 'starts_at' => now()->subDay(), 'gateway' => 'manual',
        ]);

        $this->expectException(AiCreditsException::class);
        $this->expectExceptionMessage('credits are exhausted');
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

    public function test_cancelled_subscription_keeps_current_credits_until_access_end(): void
    {
        [, $workspace, $subscription] = $this->subscribedWorkspace(5);
        $subscription->update(['status' => 'canceled', 'ends_at' => now()->addWeek()]);

        $this->assertSame(5, app(AiCreditService::class)->usage($workspace->id)['remaining']);

        $subscription->update(['ends_at' => now()->subSecond()]);
        $this->assertSame(0, app(AiCreditService::class)->usage($workspace->id)['remaining']);
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
        $owner = User::factory()->create();
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
