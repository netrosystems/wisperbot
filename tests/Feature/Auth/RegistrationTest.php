<?php

namespace Tests\Feature\Auth;

use App\Models\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_screen_can_be_rendered(): void
    {
        $response = $this->get('/register');

        $response->assertStatus(200);
    }

    public function test_new_users_can_register(): void
    {
        $plan = Plan::factory()->create([
            'name' => 'Free',
            'price_cents' => 0,
            'monthly_price_cents' => 0,
            'yearly_price_cents' => 0,
        ]);

        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'agree_terms' => true,
            'cycle' => 'month',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('client.dashboard', absolute: false));
        $this->assertDatabaseHas('subscriptions', [
            'plan_id' => $plan->id,
            'status' => 'active',
            'gateway' => 'free',
        ]);
    }
}
