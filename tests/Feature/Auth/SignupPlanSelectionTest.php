<?php

namespace Tests\Feature\Auth;

use App\Models\ClientSetting;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SignupPlanSelectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_preserves_a_valid_pricing_selection_without_repeating_plan_cards(): void
    {
        $this->freePlan();
        $pro = Plan::factory()->create([
            'name' => 'Pro',
            'monthly_price_cents' => 2900,
            'yearly_price_cents' => 29000,
        ]);
        Plan::factory()->create(['name' => 'Hidden', 'enabled' => false]);

        $this->get(route('register', ['plan_id' => $pro->id, 'cycle' => 'year']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Auth/Register')
                ->where('plan_id', $pro->id)
                ->where('cycle', 'year')
                ->where('selected_plan.id', $pro->id)
                ->where('selected_plan.name', 'Pro')
                ->where('selected_plan.price_cents', 29000)
                ->where('signup_available', true)
                ->missing('plans'));
    }

    public function test_direct_registration_automatically_starts_on_free(): void
    {
        $free = $this->freePlan();

        $this->post(route('register'), $this->registrationPayload())
            ->assertRedirect(route('client.dashboard'));

        $user = auth()->user();
        $this->assertNotNull($user);
        $this->assertDatabaseHas('subscriptions', [
            'user_id' => $user->id,
            'plan_id' => $free->id,
            'status' => 'active',
            'gateway' => 'free',
        ]);
    }

    public function test_paid_plan_registration_preserves_free_access_and_continues_to_checkout(): void
    {
        $free = $this->freePlan();
        $plan = Plan::factory()->create([
            'name' => 'Pro',
            'monthly_price_cents' => 2900,
            'yearly_price_cents' => 29000,
        ]);

        $response = $this->post(route('register'), $this->registrationPayload([
            'plan_id' => $plan->id,
        ]));

        $user = auth()->user();
        $this->assertNotNull($user);
        $response->assertRedirect(route('client.pricing'));
        $this->assertDatabaseHas('subscriptions', [
            'user_id' => $user->id,
            'plan_id' => $free->id,
            'status' => 'active',
            'gateway' => 'free',
        ]);
        $response->assertSessionHas('plan_id', $plan->id);
        $response->assertSessionHas('cycle', 'month');
        $this->assertDatabaseHas('client_settings', [
            'client_id' => $user->client_id,
            'key' => 'plan_selection_required',
            'value' => '1',
        ]);

        $user->forceFill(['email_verified_at' => now()])->save();

        $this->actingAs($user)
            ->get(route('client.dashboard'))
            ->assertOk();
    }

    public function test_registration_fails_closed_when_no_initial_free_plan_exists(): void
    {
        Plan::factory()->create([
            'monthly_price_cents' => 2900,
            'yearly_price_cents' => 29000,
        ]);

        $this->post(route('register'), $this->registrationPayload())
            ->assertSessionHasErrors('plan_id');

        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('clients', 0);
    }

    public function test_free_plan_can_be_selected_from_the_required_pricing_gate(): void
    {
        $free = $this->freePlan();
        $context = $this->createWorkspaceContext();
        ClientSetting::set($context['client']->id, 'plan_selection_required', '1');

        $this->actingAs($context['user'])
            ->post(route('client.pricing.select-free'), ['plan_id' => $free->id])
            ->assertRedirect(route('client.dashboard'));

        $this->assertDatabaseHas('subscriptions', [
            'user_id' => $context['user']->id,
            'plan_id' => $free->id,
            'status' => 'active',
            'gateway' => 'free',
        ]);

        $this->actingAs($context['user']->refresh())
            ->get(route('client.dashboard'))
            ->assertOk();
    }

    public function test_paid_plan_cannot_bypass_checkout_through_free_selection(): void
    {
        $paid = Plan::factory()->create(['monthly_price_cents' => 1900, 'yearly_price_cents' => 19000]);
        $context = $this->createWorkspaceContext();
        ClientSetting::set($context['client']->id, 'plan_selection_required', '1');

        $this->actingAs($context['user'])
            ->from(route('client.pricing'))
            ->post(route('client.pricing.select-free'), ['plan_id' => $paid->id])
            ->assertRedirect(route('client.pricing'))
            ->assertSessionHas('error');

        $this->assertDatabaseMissing('subscriptions', ['user_id' => $context['user']->id]);
    }

    public function test_an_active_plan_satisfies_the_app_gate(): void
    {
        $free = $this->freePlan();
        $context = $this->createWorkspaceContext();
        ClientSetting::set($context['client']->id, 'plan_selection_required', '1');
        Subscription::create([
            'user_id' => $context['user']->id,
            'plan_id' => $free->id,
            'status' => 'active',
            'billing_cycle' => 'month',
            'starts_at' => now(),
            'gateway' => 'free',
        ]);

        $this->actingAs($context['user'])
            ->get(route('client.dashboard'))
            ->assertOk();
    }

    public function test_a_teammate_can_access_the_app_when_another_client_member_owns_the_plan(): void
    {
        $free = $this->freePlan();
        $owner = $this->createWorkspaceContext();
        ClientSetting::set($owner['client']->id, 'plan_selection_required', '1');
        Subscription::create([
            'user_id' => $owner['user']->id,
            'plan_id' => $free->id,
            'status' => 'active',
            'billing_cycle' => 'month',
            'starts_at' => now(),
            'gateway' => 'free',
        ]);
        $teammate = User::factory()->create([
            'client_id' => $owner['client']->id,
            'role' => User::ROLE_CLIENT,
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($teammate)
            ->get(route('client.dashboard'))
            ->assertOk();
    }

    public function test_legacy_clients_without_the_enforcement_marker_are_not_locked_out(): void
    {
        $legacy = $this->createWorkspaceContext();

        $this->actingAs($legacy['user'])
            ->get(route('client.dashboard'))
            ->assertOk();
    }

    private function freePlan(): Plan
    {
        return Plan::factory()->create([
            'name' => 'Free',
            'price_cents' => 0,
            'monthly_price_cents' => 0,
            'yearly_price_cents' => 0,
            'features' => ['1 channel', '100 AI credits'],
        ]);
    }

    private function registrationPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'New Client',
            'email' => 'new-client@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'agree_terms' => true,
            'cycle' => 'month',
        ], $overrides);
    }
}
