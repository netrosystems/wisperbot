<?php

namespace Tests\Feature\Api\V1;

use App\Modules\AI\Models\AiChatbot;
use App\Modules\AI\Models\AiKnowledgeBase;
use App\Modules\AI\Models\AiProviderConfig;
use App\Services\AddonEntitlementService;
use App\Support\ApiAbilities;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiChatbotApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_returns_401(): void
    {
        $this->getJson('/api/v1/ai/chatbots')->assertStatus(401);
    }

    public function test_wrong_scope_returns_403(): void
    {
        ['user' => $user] = $this->createWorkspaceContext();
        $token = $user->createToken('t', [ApiAbilities::CONTACTS_READ])->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/ai/chatbots')->assertStatus(403);
    }

    public function test_list_chatbots_returns_200(): void
    {
        ['user' => $user, 'workspace' => $workspace, 'client' => $client] = $this->createWorkspaceContext();
        $this->enableDeveloperTools($client->id, $user->id);
        $token = $user->createToken('t', ['*'])->plainTextToken;

        AiChatbot::factory()->create(['workspace_id' => $workspace->id]);

        $res = $this->withToken($token)
            ->getJson('/api/v1/ai/chatbots')
            ->assertOk();

        $this->assertCount(1, $res->json('data'));
    }

    public function test_chat_invocation_returns_reply(): void
    {
        ['user' => $user, 'workspace' => $workspace, 'client' => $client] = $this->createWorkspaceContext();
        $this->enableDeveloperTools($client->id, $user->id);
        $token = $user->createToken('t', [ApiAbilities::AI_WRITE])->plainTextToken;

        $kb = AiKnowledgeBase::factory()->create(['workspace_id' => $workspace->id]);
        $chatbot = AiChatbot::factory()->create([
            'workspace_id' => $workspace->id,
            'ai_kb_id' => $kb->id,
            'enabled' => true,
        ]);
        AiProviderConfig::create([
            'workspace_id' => $workspace->id,
            'provider' => 'openai',
            'credentials' => ['api_key' => 'sk-test'],
            'default_model_chat' => 'gpt-4o-mini',
            'default_model_embed' => 'text-embedding-3-small',
            'enabled' => true,
            'last_tested_at' => now(),
            'last_test_succeeded_at' => now(),
        ]);

        // Fake LLM API response
        Http::fake([
            '*' => Http::response([
                'choices' => [['message' => ['content' => 'Our refund policy is 30 days.']]],
                'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 20],
                'model' => 'gpt-4o',
            ], 200),
        ]);

        $this->withToken($token)
            ->postJson("/api/v1/ai/chatbots/{$chatbot->id}/chat", [
                'message' => 'What is your refund policy?',
            ])
            ->assertOk()
            ->assertJsonStructure(['reply', 'tokens_used']);
    }

    public function test_chat_on_other_workspace_chatbot_returns_404(): void
    {
        ['user' => $user, 'client' => $client] = $this->createWorkspaceContext();
        $this->enableDeveloperTools($client->id, $user->id);
        ['workspace' => $otherWs] = $this->createWorkspaceContext();
        $token = $user->createToken('t', ['*'])->plainTextToken;

        $chatbot = AiChatbot::factory()->create(['workspace_id' => $otherWs->id]);

        $this->withToken($token)
            ->postJson("/api/v1/ai/chatbots/{$chatbot->id}/chat", [
                'message' => 'Hello?',
            ])
            ->assertStatus(404);
    }

    private function enableDeveloperTools(int $clientId, int $userId): void
    {
        app(AddonEntitlementService::class)->activate(
            $clientId,
            AddonEntitlementService::DEVELOPER_TOOLS,
            $userId,
            'manual',
            'test-developer-tools-'.$clientId,
        );
    }
}
