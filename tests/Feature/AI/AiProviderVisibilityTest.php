<?php

namespace Tests\Feature\AI;

use App\Models\User;
use App\Models\Workspace;
use App\Modules\AI\Http\Controllers\AiProviderController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AiProviderVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_clients_only_receive_supported_workspace_providers(): void
    {
        [$user] = $this->clientWorkspace();

        $this->actingAs($user)
            ->get(route('client.ai.providers.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('AI/Providers/Index')
                ->where('providers', fn ($providers) => collect($providers)->pluck('provider')->all() === AiProviderController::CLIENT_PROVIDERS));
    }

    public function test_clients_cannot_update_or_test_system_only_providers_directly(): void
    {
        [$user] = $this->clientWorkspace();

        $this->actingAs($user)
            ->put(route('client.ai.providers.update', 'deepseek'), [
                'api_key' => 'sk-client-deepseek',
                'enabled' => true,
            ])
            ->assertNotFound();

        $this->actingAs($user)
            ->postJson(route('client.ai.providers.test', 'deepseek'))
            ->assertNotFound();

        $this->actingAs($user)
            ->put(route('client.ai.providers.update', 'qwen'), [
                'api_key' => 'sk-client-qwen',
                'enabled' => true,
            ])
            ->assertNotFound();

        $this->actingAs($user)
            ->postJson(route('client.ai.providers.test', 'qwen'))
            ->assertNotFound();
    }

    private function clientWorkspace(): array
    {
        $user = User::factory()->create(['role' => 'client', 'email_verified_at' => now()]);
        $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
        $user->update(['workspace_id' => $workspace->id]);

        return [$user, $workspace];
    }
}
