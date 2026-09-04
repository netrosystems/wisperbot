<?php

namespace Tests\Feature\AI;

use App\Http\Middleware\EnsureLicensed;
use App\Http\Middleware\EnsureNotDemoMode;
use App\Models\Plan;
use App\Modules\AI\Models\AiCreditLedger;
use App\Modules\AI\Models\AiCreditPeriod;
use App\Modules\AI\Services\AiCreditService;
use App\Modules\AI\Services\Llm\LlmResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AiCreditAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_report_and_adjustment_are_attributed_and_audited(): void
    {
        $this->withoutMiddleware([EnsureLicensed::class, EnsureNotDemoMode::class]);
        config()->set('ai_credits.enforce', true);
        $admin = $this->createSuperAdmin();
        $context = $this->createWorkspaceContext();
        $plan = Plan::factory()->create([
            'name' => 'Managed 20',
            'monthly_price_cents' => 2000,
            'limits' => ['ai_credits_per_month' => 1000],
        ]);
        $this->attachPlanToClient($context['client'], $plan);
        $service = app(AiCreditService::class);
        $reservation = $service->reserve($context['workspace']->id, 'email_generate', 'admin-report');
        $service->succeed($reservation->ledger, new LlmResponse('Done', 100, 50, 'gpt-5-mini', 20), 'openai');

        $this->actingAs($admin, 'admin')->getJson(route('admin.ai-credits.report'))
            ->assertOk()
            ->assertJsonPath('data.by_feature.0.feature', 'email_generate')
            ->assertJsonPath('data.by_model.0.model', 'gpt-5-mini')
            ->assertJsonPath('data.by_plan.0.plan_name', 'Managed 20');

        $this->actingAs($admin, 'admin')->get(route('admin.ai-credits.report'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/AiCredits/Index')
                ->where('report.by_feature.0.feature', 'email_generate'));

        $period = AiCreditPeriod::firstOrFail();
        $this->actingAs($admin, 'admin')->postJson(route('admin.ai-credits.adjust', $period), [
            'credits' => 25,
            'reason' => 'Support-approved service recovery',
        ])->assertOk()->assertJsonPath('period.adjustment_credits', 25);

        $audit = AiCreditLedger::where('feature', 'admin_adjustment')->firstOrFail();
        $this->assertSame($context['workspace']->id, $audit->workspace_id);
        $this->assertSame('granted', $audit->status);
        $this->assertSame(25, $audit->adjustment_delta);
    }

    public function test_admin_cannot_revoke_consumed_or_reserved_credits(): void
    {
        $this->withoutMiddleware([EnsureLicensed::class, EnsureNotDemoMode::class]);
        config()->set('ai_credits.enforce', true);
        $admin = $this->createSuperAdmin();
        $context = $this->createWorkspaceContext();
        $plan = Plan::factory()->create(['limits' => ['ai_credits_per_month' => 5]]);
        $this->attachPlanToClient($context['client'], $plan);
        app(AiCreditService::class)->reserve($context['workspace']->id, 'workflow_generate', 'reserved');

        $period = AiCreditPeriod::firstOrFail();
        $this->actingAs($admin, 'admin')->postJson(route('admin.ai-credits.adjust', $period), [
            'credits' => -1,
            'reason' => 'Invalid reduction test',
        ])->assertUnprocessable();

        $this->assertSame(0, $period->fresh()->adjustment_credits);
        $this->assertDatabaseMissing('ai_credit_ledgers', ['feature' => 'admin_adjustment']);
    }
}
