<?php

namespace Tests\Unit;

use App\Modules\AI\Models\AiChatbot;
use App\Modules\AI\Models\AiKnowledgeBase;
use App\Modules\AI\Services\BusinessAwareTurnRouter;
use Tests\TestCase;

class BusinessAwareTurnRouterTest extends TestCase
{
    public function test_standalone_social_turns_are_handled_without_generation(): void
    {
        $router = new BusinessAwareTurnRouter;
        $kb = new AiKnowledgeBase(['brand' => 'Acme']);

        foreach (['Hello 👋', 'THANK YOU!', 'ঠিক আছে', 'مرحبا', 'Au revoir'] as $message) {
            $result = $router->conversationalResult($message, $kb, 'friendly');
            $this->assertNotNull($result, $message);
            $this->assertSame('conversation', $result['answer_origin']);
            $this->assertSame(0, $result['tokens_used']);
            $this->assertSame([], $result['quick_replies']);
        }
    }

    public function test_mixed_greeting_and_business_question_continues_to_retrieval(): void
    {
        $result = (new BusinessAwareTurnRouter)->conversationalResult(
            'Hi, what is your return policy?',
            new AiKnowledgeBase(['brand' => 'Acme']),
        );

        $this->assertNull($result);
    }

    public function test_business_only_allows_related_guidance_but_rejects_unrelated_topics(): void
    {
        $router = new BusinessAwareTurnRouter;
        $kb = $this->profile();
        $bot = new AiChatbot(['answer_scope' => 'business_only', 'trusted_research_enabled' => false]);

        $related = $router->routeMissingContext($bot, $kb, 'How does device activation generally work?', 0.78, 0.10);
        $unrelated = $router->routeMissingContext($bot, $kb, 'Was Barack Obama the best president?', 0.08, 0.05);

        $this->assertSame('guidance', $related['mode']);
        $this->assertSame('business_guidance', $related['intent']);
        $this->assertSame('fallback', $unrelated['mode']);
        $this->assertSame('unrelated', $unrelated['intent']);
    }

    public function test_company_specific_or_current_fact_requires_approved_research(): void
    {
        $router = new BusinessAwareTurnRouter;
        $kb = $this->profile();

        $disabled = new AiChatbot(['answer_scope' => 'business_only', 'trusted_research_enabled' => false]);
        $enabled = new AiChatbot(['answer_scope' => 'business_only', 'trusted_research_enabled' => true]);

        $this->assertSame('fallback', $router->routeMissingContext($disabled, $kb, 'What is your current price?', 0.8, 0.2)['mode']);
        $this->assertSame('research', $router->routeMissingContext($enabled, $kb, 'What is your current price?', 0.8, 0.2)['mode']);
    }

    public function test_purchase_and_fresh_fact_questions_are_eligible_for_approved_research(): void
    {
        $router = new BusinessAwareTurnRouter;

        $this->assertTrue($router->shouldResearchQuestion('I want to buy the travel package'));
        $this->assertTrue($router->shouldResearchQuestion('What is the current availability?'));
        $this->assertFalse($router->shouldResearchQuestion('How does activation generally work?'));
    }

    public function test_incomplete_profile_fails_closed_to_verified_sources(): void
    {
        $router = new BusinessAwareTurnRouter;
        $bot = new AiChatbot(['answer_scope' => 'business_only']);
        $kb = new AiKnowledgeBase(['brand' => 'X', 'purpose' => 'test', 'audience' => 'all']);

        $route = $router->routeMissingContext($bot, $kb, 'How does this service work?', 0.99, 0.99);

        $this->assertFalse($route['profile_complete']);
        $this->assertSame('fallback', $route['mode']);
    }

    private function profile(): AiKnowledgeBase
    {
        return new AiKnowledgeBase([
            'name' => 'Device help',
            'brand' => 'Acme Mobile',
            'purpose' => 'Help customers activate and use connected mobile services.',
            'audience' => 'Mobile customers',
        ]);
    }
}
