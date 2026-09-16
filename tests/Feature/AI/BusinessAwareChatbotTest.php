<?php

namespace Tests\Feature\AI;

use App\Modules\AI\Models\AiChatbot;
use App\Modules\AI\Models\AiCreditLedger;
use App\Modules\AI\Models\AiKnowledgeBase;
use App\Modules\AI\Services\ChatbotRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BusinessAwareChatbotTest extends TestCase
{
    use RefreshDatabase;

    private bool $originalRoutingFlag;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalRoutingFlag = (bool) config('chatbot.business_aware_routing_enabled');
        config(['chatbot.business_aware_routing_enabled' => true]);
    }

    protected function tearDown(): void
    {
        config(['chatbot.business_aware_routing_enabled' => $this->originalRoutingFlag]);
        parent::tearDown();
    }

    public function test_greeting_returns_a_zero_credit_conversation_answer(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $kb = AiKnowledgeBase::create([
            'workspace_id' => $workspace->id,
            'name' => 'Support knowledge',
            'purpose' => 'Help customers understand and use Acme services.',
            'brand' => 'Acme',
            'audience' => 'Acme customers',
            'status' => 'active',
        ]);
        $bot = AiChatbot::create([
            'workspace_id' => $workspace->id,
            'name' => 'Acme Assistant',
            'ai_kb_id' => $kb->id,
            'answer_scope' => 'business_only',
            'unsupported_fallback_action' => 'clarify_then_handoff',
        ]);
        Http::fake();

        $result = app(ChatbotRunner::class)->runForApi($bot, 'Hello 👋', $workspace->id, [], 'greeting');

        $this->assertSame('conversation', $result['answer_origin']);
        $this->assertSame(0, $result['tokens_used']);
        $this->assertStringContainsString('Acme', $result['reply']);
        $this->assertSame(0, AiCreditLedger::count());
        Http::assertNothingSent();
        $this->assertDatabaseHas('ai_kb_retrieval_diagnostics', [
            'workspace_id' => $workspace->id,
            'chatbot_id' => $bot->id,
            'answer_origin' => 'conversation',
            'credit_result' => 'zero_cost',
        ]);
    }

    public function test_missing_business_profile_falls_back_without_generation(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $kb = AiKnowledgeBase::create([
            'workspace_id' => $workspace->id,
            'name' => 'Incomplete knowledge',
            'status' => 'active',
        ]);
        $bot = AiChatbot::create([
            'workspace_id' => $workspace->id,
            'name' => 'Safe Assistant',
            'ai_kb_id' => $kb->id,
            'answer_scope' => 'business_only',
            'fallback_reply' => 'Please share a business-related detail, or ask for human help.',
        ]);
        Http::fake();

        $result = app(ChatbotRunner::class)->runForApi($bot, 'Tell me about recent politics.', $workspace->id);

        $this->assertSame('fallback', $result['answer_origin']);
        $this->assertSame(0, $result['tokens_used']);
        $this->assertSame(0, AiCreditLedger::count());
        Http::assertNothingSent();
    }
}
