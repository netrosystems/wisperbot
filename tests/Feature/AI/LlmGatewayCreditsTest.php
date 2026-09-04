<?php

namespace Tests\Feature\AI;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Modules\AI\Exceptions\AiCreditsException;
use App\Modules\AI\Models\AiCreditLedger;
use App\Modules\AI\Models\AiProviderConfig;
use App\Modules\AI\Models\AiWorkspaceSetting;
use App\Modules\AI\Services\LlmGateway;
use App\Modules\Integrations\Models\IntegrationConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LlmGatewayCreditsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('ai_credits.enforce', true);
    }

    public function test_unset_workspace_mode_defaults_to_credits_then_provider(): void
    {
        $workspace = $this->workspaceWithCredits(5);

        $this->assertSame('auto_fallback', AiWorkspaceSetting::modeFor($workspace->id));
    }

    public function test_explicit_workspace_mode_is_preserved(): void
    {
        $workspace = $this->workspaceWithCredits(5);
        AiWorkspaceSetting::create(['workspace_id' => $workspace->id, 'provider_mode' => 'managed']);

        $this->assertSame('managed', AiWorkspaceSetting::modeFor($workspace->id));
    }

    public function test_managed_success_is_charged_once_and_replayed(): void
    {
        $workspace = $this->workspaceWithCredits(5);
        $this->managedOpenAi();
        AiWorkspaceSetting::create(['workspace_id' => $workspace->id, 'provider_mode' => 'managed']);
        Http::fake(['api.openai.com/*' => Http::response($this->openAiResponse('Hello'))]);

        $gateway = app(LlmGateway::class);
        $first = $gateway->chat($workspace->id, [['role' => 'user', 'content' => 'Hi']], [
            'feature' => 'chatbot_reply', 'idempotency_key' => 'stable',
        ]);
        $second = $gateway->chat($workspace->id, [['role' => 'user', 'content' => 'Hi']], [
            'feature' => 'chatbot_reply', 'idempotency_key' => 'stable',
        ]);

        $this->assertSame('Hello', $first->content);
        $this->assertSame('Hello', $second->content);
        $this->assertSame(1, AiCreditLedger::where('status', 'succeeded')->value('credits'));
        Http::assertSentCount(1);
    }

    public function test_provider_failure_refunds_managed_reservation(): void
    {
        $workspace = $this->workspaceWithCredits(2);
        $this->managedOpenAi();
        AiWorkspaceSetting::create(['workspace_id' => $workspace->id, 'provider_mode' => 'managed']);
        Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'failed']], 500)]);

        try {
            app(LlmGateway::class)->chat($workspace->id, [['role' => 'user', 'content' => 'Hi']], [
                'feature' => 'email_generate', 'idempotency_key' => 'failure',
            ]);
            $this->fail('Expected provider failure.');
        } catch (\Throwable) {
            $this->assertSame('refunded', AiCreditLedger::sole()->status);
            $this->assertSame(0, AiCreditLedger::sole()->period->used_credits);
            $this->assertSame(0, AiCreditLedger::sole()->period->reserved_credits);
        }
    }

    public function test_auto_fallback_uses_only_a_successfully_tested_customer_key_when_exhausted(): void
    {
        $workspace = $this->workspaceWithCredits(0);
        $this->managedOpenAi();
        AiWorkspaceSetting::create(['workspace_id' => $workspace->id, 'provider_mode' => 'auto_fallback']);
        AiProviderConfig::create([
            'workspace_id' => $workspace->id,
            'provider' => 'openai',
            'credentials' => ['api_key' => 'sk-customer'],
            'enabled' => true,
            'last_tested_at' => now(),
            'last_test_succeeded_at' => now(),
        ]);
        Http::fake(['api.openai.com/*' => Http::response($this->openAiResponse('Fallback'))]);

        $response = app(LlmGateway::class)->chat($workspace->id, [['role' => 'user', 'content' => 'Hi']], [
            'feature' => 'chatbot_reply', 'idempotency_key' => 'fallback',
        ]);

        $this->assertSame('Fallback', $response->content);
        $this->assertDatabaseHas('ai_credit_ledgers', [
            'workspace_id' => $workspace->id,
            'provider_source' => 'byok',
            'credits' => 0,
            'status' => 'succeeded',
        ]);
    }

    public function test_auto_fallback_never_uses_a_workspace_deepseek_key(): void
    {
        $workspace = $this->workspaceWithCredits(0);
        $this->managedOpenAi();
        AiWorkspaceSetting::create(['workspace_id' => $workspace->id, 'provider_mode' => 'auto_fallback']);
        AiProviderConfig::create([
            'workspace_id' => $workspace->id,
            'provider' => 'deepseek',
            'credentials' => ['api_key' => 'sk-legacy-client-deepseek'],
            'enabled' => true,
            'last_tested_at' => now(),
            'last_test_succeeded_at' => now(),
        ]);
        Http::fake();

        try {
            app(LlmGateway::class)->chat($workspace->id, [['role' => 'user', 'content' => 'Hi']], [
                'feature' => 'chatbot_reply', 'idempotency_key' => 'no-client-deepseek',
            ]);
            $this->fail('Expected the unavailable client fallback to fail closed.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('successfully test your fallback AI provider', $exception->getMessage());
            Http::assertNothingSent();
        }
    }

    public function test_unknown_feature_fails_before_calling_provider(): void
    {
        $workspace = $this->workspaceWithCredits(5);
        Http::fake();

        $this->expectException(AiCreditsException::class);
        try {
            app(LlmGateway::class)->chat($workspace->id, [], ['feature' => 'not_registered']);
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_customer_embedding_key_takes_precedence_without_consuming_credits(): void
    {
        $workspace = $this->workspaceWithCredits(5);
        $this->managedOpenAi();
        AiWorkspaceSetting::create(['workspace_id' => $workspace->id, 'provider_mode' => 'managed']);
        AiProviderConfig::create([
            'workspace_id' => $workspace->id,
            'provider' => 'openai',
            'credentials' => ['api_key' => 'sk-customer'],
            'enabled' => true,
        ]);
        Http::fake(['api.openai.com/*' => Http::response(['data' => [['embedding' => [0.1, 0.2]]]])]);

        $vectors = app(LlmGateway::class)->embed($workspace->id, ['Index this']);

        $this->assertSame([[0.1, 0.2]], $vectors);
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer sk-customer'));
        $this->assertDatabaseCount('ai_credit_ledgers', 0);
    }

    public function test_selected_qwen_powers_managed_generation_while_openai_handles_embeddings(): void
    {
        $workspace = $this->workspaceWithCredits(5);
        AiWorkspaceSetting::create(['workspace_id' => $workspace->id, 'provider_mode' => 'managed']);
        IntegrationConfig::create([
            'provider' => 'llm_qwen_default',
            'label' => 'Alibaba Qwen 3.7 Flash (System)',
            'mode' => 'live',
            'enabled' => true,
            'is_default' => true,
            'last_test_status' => 'ok',
            'credentials' => [
                'api_key' => 'sk-qwen',
                'region' => 'ap-southeast-1',
                'workspace_id' => 'workspace-123',
            ],
        ]);
        $this->managedOpenAi();
        Http::fake([
            'workspace-123.ap-southeast-1.maas.aliyuncs.com/compatible-mode/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'Qwen answer']]],
                'usage' => ['prompt_tokens' => 8, 'completion_tokens' => 3],
                'model' => 'qwen3.7-flash',
            ]),
            'api.openai.com/v1/embeddings' => Http::response([
                'data' => [['embedding' => [0.1, 0.2]]],
            ]),
        ]);

        $gateway = app(LlmGateway::class);
        $response = $gateway->chat($workspace->id, [['role' => 'user', 'content' => 'Hi']], [
            'feature' => 'chatbot_reply', 'idempotency_key' => 'qwen-managed',
        ]);
        $vectors = $gateway->embed($workspace->id, ['Index this']);

        $this->assertSame('Qwen answer', $response->content);
        $this->assertSame([[0.1, 0.2]], $vectors);
        $this->assertDatabaseHas('ai_credit_ledgers', [
            'workspace_id' => $workspace->id,
            'provider' => 'qwen',
            'model' => 'qwen3.7-flash',
            'cost_microusd' => 1,
            'status' => 'succeeded',
        ]);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'maas.aliyuncs.com')
            && $request['enable_thinking'] === false);
        Http::assertSent(fn ($request) => $request->url() === 'https://api.openai.com/v1/embeddings');
    }

    private function workspaceWithCredits(int $credits): Workspace
    {
        $owner = User::factory()->create();
        $plan = Plan::factory()->create(['limits' => ['ai_credits_per_month' => $credits]]);
        Subscription::create([
            'user_id' => $owner->id, 'plan_id' => $plan->id, 'status' => 'active',
            'billing_cycle' => 'month', 'starts_at' => now()->subDay(), 'gateway' => 'manual',
        ]);

        return Workspace::factory()->create(['owner_id' => $owner->id]);
    }

    private function managedOpenAi(): void
    {
        IntegrationConfig::create([
            'provider' => 'llm_openai_default',
            'label' => 'OpenAI',
            'mode' => 'live',
            'enabled' => true,
            'credentials' => ['api_key' => 'sk-managed'],
        ]);
    }

    private function openAiResponse(string $content): array
    {
        return [
            'choices' => [['message' => ['content' => $content]]],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 4],
            'model' => 'gpt-5-nano',
        ];
    }
}
