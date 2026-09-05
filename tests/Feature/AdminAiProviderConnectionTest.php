<?php

namespace Tests\Feature;

use App\Modules\AI\Services\Llm\QwenProvider;
use App\Modules\Integrations\Models\IntegrationConfig;
use App\Modules\Integrations\Services\ConnectionTester;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AdminAiProviderConnectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_connection_checks_both_configured_runtime_models(): void
    {
        config(['ai_credits.managed.routine_model' => 'gpt-4o-mini', 'ai_credits.managed.complex_model' => 'gpt-4o']);
        $config = IntegrationConfig::create(['provider' => 'llm_openai_default', 'label' => 'OpenAI', 'mode' => 'live', 'enabled' => true, 'credentials' => ['api_key' => 'test']]);
        Http::fake(['api.openai.com/*' => fn ($r) => $r['model'] === 'gpt-4o'
            ? Http::response(['error' => ['code' => 'model_not_found']], 403)
            : Http::response(['choices' => [['message' => ['content' => 'OK']]]])]);
        $result = app(ConnectionTester::class)->test($config);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('gpt-4o', $result['message']);
        Http::assertSentCount(2);
    }

    #[Test]
    public function openai_admin_test_checks_chat_and_knowledge_base_embeddings(): void
    {
        config(['ai_credits.managed.routine_model' => 'gpt-4o-mini', 'ai_credits.managed.complex_model' => 'gpt-4o-mini']);
        $config = IntegrationConfig::create([
            'provider' => 'llm_openai_default',
            'label' => 'OpenAI (Default)',
            'mode' => 'live',
            'enabled' => true,
            'credentials' => ['api_key' => 'sk-test'],
        ]);

        Http::fake([
            'api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'OK']]],
            ]),
            'api.openai.com/v1/embeddings' => Http::response([
                'data' => [['embedding' => [0.1, 0.2]]],
            ]),
        ]);

        $result = app(ConnectionTester::class)->test($config);

        $this->assertTrue($result['ok']);
        $this->assertStringContainsString('chat and Knowledge Base embeddings', $result['message']);
        Http::assertSentCount(2);
        $this->assertSame('ok', $config->refresh()->last_test_status);
    }

    #[Test]
    public function openai_admin_test_explains_embedding_failure_without_leaking_provider_body(): void
    {
        $config = IntegrationConfig::create([
            'provider' => 'llm_openai_default',
            'label' => 'OpenAI (Default)',
            'mode' => 'live',
            'enabled' => true,
            'credentials' => ['api_key' => 'sk-invalid'],
        ]);

        Http::fake([
            'api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'OK']]],
            ]),
            'api.openai.com/v1/embeddings' => Http::response([
                'error' => [
                    'code' => 'invalid_api_key',
                    'message' => 'secret-admin-provider-body',
                ],
            ], 401),
        ]);

        $result = app(ConnectionTester::class)->test($config);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Knowledge Base embeddings failed', $result['message']);
        $this->assertStringContainsString('rejected the credentials', $result['message']);
        $this->assertStringNotContainsString('secret-admin-provider-body', $result['message']);
        $this->assertStringNotContainsString('secret-admin-provider-body', $config->refresh()->last_test_message);
    }

    #[Test]
    public function deepseek_is_available_as_a_super_admin_system_integration(): void
    {
        $this->assertContains('llm_deepseek_default', IntegrationConfig::PROVIDERS);
        $this->assertSame('AI / LLM', IntegrationConfig::CATEGORIES['llm_deepseek_default']);

        $config = IntegrationConfig::create([
            'provider' => 'llm_deepseek_default',
            'label' => 'DeepSeek (System)',
            'mode' => 'live',
            'enabled' => true,
            'credentials' => ['api_key' => 'sk-system-deepseek'],
        ]);

        Http::fake([
            'api.deepseek.com/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'OK']]],
            ]),
        ]);

        $result = app(ConnectionTester::class)->test($config);

        $this->assertTrue($result['ok']);
        $this->assertStringContainsString('system chat connected', $result['message']);
        Http::assertSentCount(1);
        $this->assertSame('ok', $config->refresh()->last_test_status);
    }

    #[Test]
    public function qwen_is_tested_through_its_region_bound_runtime_endpoint(): void
    {
        $this->assertContains('llm_qwen_default', IntegrationConfig::PROVIDERS);

        $config = IntegrationConfig::create([
            'provider' => 'llm_qwen_default',
            'label' => 'Alibaba Qwen 3.7 Flash (System)',
            'mode' => 'live',
            'enabled' => true,
            'credentials' => [
                'api_key' => 'sk-system-qwen',
                'region' => 'ap-southeast-1',
                'workspace_id' => 'workspace-123',
            ],
        ]);

        Http::fake([
            'workspace-123.ap-southeast-1.maas.aliyuncs.com/compatible-mode/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'OK']]],
                'usage' => ['prompt_tokens' => 4, 'completion_tokens' => 1],
                'model' => QwenProvider::MODEL,
            ]),
        ]);

        $result = app(ConnectionTester::class)->test($config);

        $this->assertTrue($result['ok']);
        $this->assertStringContainsString('Qwen 3.7 Flash connected', $result['message']);
        Http::assertSent(function ($request) {
            return $request->url() === 'https://workspace-123.ap-southeast-1.maas.aliyuncs.com/compatible-mode/v1/chat/completions'
                && $request['model'] === QwenProvider::MODEL
                && $request['enable_thinking'] === false
                && $request->hasHeader('Authorization', 'Bearer sk-system-qwen');
        });
        $this->assertSame('ok', $config->refresh()->last_test_status);
    }

    #[Test]
    public function qwen_rejects_unapproved_regions_before_sending_a_request(): void
    {
        $config = IntegrationConfig::create([
            'provider' => 'llm_qwen_default',
            'label' => 'Alibaba Qwen 3.7 Flash (System)',
            'mode' => 'live',
            'enabled' => true,
            'credentials' => [
                'api_key' => 'sk-system-qwen',
                'region' => 'attacker.example',
                'workspace_id' => 'workspace-123',
            ],
        ]);
        Http::fake();

        $result = app(ConnectionTester::class)->test($config);

        $this->assertFalse($result['ok']);
        $this->assertSame('fail', $config->refresh()->last_test_status);
        Http::assertNothingSent();
    }

    #[Test]
    public function only_a_tested_enabled_ai_provider_can_be_selected_for_managed_generation(): void
    {
        $admin = $this->createSuperAdmin();
        $openAi = IntegrationConfig::create([
            'provider' => 'llm_openai_default',
            'label' => 'OpenAI (Default)',
            'mode' => 'live',
            'enabled' => true,
            'is_default' => true,
            'last_test_status' => 'ok',
            'credentials' => ['api_key' => 'sk-openai'],
        ]);
        $qwen = IntegrationConfig::create([
            'provider' => 'llm_qwen_default',
            'label' => 'Alibaba Qwen 3.7 Flash (System)',
            'mode' => 'live',
            'enabled' => true,
            'last_test_status' => 'ok',
            'credentials' => [
                'api_key' => 'sk-qwen',
                'region' => 'ap-southeast-1',
                'workspace_id' => 'workspace-123',
            ],
        ]);

        $this->withoutMiddleware()
            ->actingAs($admin, 'admin')
            ->post(route('admin.integrations.set-default', 'llm_qwen_default'))
            ->assertSessionHas('success');

        $this->assertFalse($openAi->refresh()->is_default);
        $this->assertTrue($qwen->refresh()->is_default);
    }
}
