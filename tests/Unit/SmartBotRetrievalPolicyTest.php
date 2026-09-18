<?php

namespace Tests\Unit;

use App\Modules\AI\Services\SmartBotRetrievalPolicy;
use Tests\TestCase;

class SmartBotRetrievalPolicyTest extends TestCase
{
    public function test_it_returns_the_managed_private_answering_policy(): void
    {
        config()->set('knowledge_base.max_context_chunks', 3);
        config()->set('knowledge_base.retrieval_match_threshold', 0.60);
        config()->set('knowledge_base.max_context_tokens', 1200);
        config()->set('knowledge_base.video_match_threshold', 0.72);

        $policy = app(SmartBotRetrievalPolicy::class)->privateAnswering();

        $this->assertSame(3, $policy['max_context_chunks']);
        $this->assertSame(0.60, $policy['answer_threshold']);
        $this->assertSame(1200, $policy['max_context_tokens']);
        $this->assertSame(0.72, $policy['video_match_threshold']);
    }

    public function test_it_clamps_unsafe_operator_values(): void
    {
        config()->set('knowledge_base.max_context_chunks', 100);
        config()->set('knowledge_base.retrieval_match_threshold', 0.05);
        config()->set('knowledge_base.max_context_tokens', 100000);
        config()->set('knowledge_base.video_match_threshold', 0.10);

        $policy = app(SmartBotRetrievalPolicy::class)->privateAnswering();

        $this->assertSame(8, $policy['max_context_chunks']);
        $this->assertSame(0.45, $policy['answer_threshold']);
        $this->assertSame(2400, $policy['max_context_tokens']);
        $this->assertSame(0.55, $policy['video_match_threshold']);
    }

    public function test_public_comments_never_use_a_broader_policy(): void
    {
        config()->set('knowledge_base.max_context_chunks', 8);
        config()->set('knowledge_base.retrieval_match_threshold', 0.50);
        config()->set('knowledge_base.max_context_tokens', 2400);

        $policy = app(SmartBotRetrievalPolicy::class)->publicComments();

        $this->assertSame(3, $policy['max_context_chunks']);
        $this->assertSame(0.60, $policy['answer_threshold']);
        $this->assertSame(1200, $policy['max_context_tokens']);
    }

    public function test_compatibility_defaults_match_the_managed_runtime_policy(): void
    {
        $service = app(SmartBotRetrievalPolicy::class);
        $runtime = $service->privateAnswering();

        $this->assertSame([
            'max_context_chunks' => $runtime['max_context_chunks'],
            'retrieval_match_threshold' => $runtime['answer_threshold'],
            'max_context_tokens' => $runtime['max_context_tokens'],
            'video_match_threshold' => $runtime['video_match_threshold'],
        ], $service->compatibilityDefaults());
    }
}
