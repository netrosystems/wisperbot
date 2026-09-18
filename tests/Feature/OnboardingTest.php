<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OnboardingTest extends TestCase
{
    use RefreshDatabase;

    private function clientUser(): User
    {
        $user = User::factory()->create([
            'role' => 'client',
            'email_verified_at' => now(),
        ]);

        $plan = Plan::factory()->create([
            'price_cents' => 0,
            'monthly_price_cents' => 0,
            'yearly_price_cents' => 0,
        ]);
        Subscription::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'billing_cycle' => 'month',
            'starts_at' => now(),
            'gateway' => 'free',
        ]);

        return $user;
    }

    public function test_user_can_view_onboarding_wizard(): void
    {
        $user = $this->clientUser();
        $this->actingAs($user)
            ->get(route('client.onboarding.show'))
            ->assertOk();
    }

    public function test_user_cannot_manually_complete_an_unmet_step(): void
    {
        $user = $this->clientUser();

        $this->actingAs($user)
            ->postJson(route('client.onboarding.complete'), ['step' => 'connect_first_channel'])
            ->assertOk()
            ->assertJson(['ok' => false]);

        $this->assertDatabaseMissing('onboarding_steps', [
            'user_id' => $user->id,
            'step' => 'connect_first_channel',
        ]);
    }

    public function test_guest_cannot_view_onboarding(): void
    {
        $this->get(route('client.onboarding.show'))
            ->assertRedirect(route('login'));
    }
}
