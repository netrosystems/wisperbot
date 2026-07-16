<?php

namespace Tests\Feature;

use App\Modules\Integrations\Models\IntegrationConfig;
use App\Modules\Integrations\Services\ConnectionTester;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AdminAiProviderConnectionTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function openai_admin_test_checks_chat_and_knowledge_base_embeddings(): void
    {
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
}
