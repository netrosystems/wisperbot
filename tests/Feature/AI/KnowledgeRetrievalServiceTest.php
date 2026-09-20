<?php

namespace Tests\Feature\AI;

use App\Modules\AI\Models\AiKbChunk;
use App\Modules\AI\Models\AiKbDocument;
use App\Modules\AI\Models\AiKnowledgeBase;
use App\Modules\AI\Services\BusinessAwareTurnRouter;
use App\Modules\AI\Services\EmbeddingStore;
use App\Modules\AI\Services\KnowledgeRetrievalService;
use App\Modules\AI\Services\LlmGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class KnowledgeRetrievalServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_short_business_shorthand_ignores_greeting_history_and_clarifies_from_active_generation(): void
    {
        [$kb, $workspaceId] = $this->seedKnowledge();
        $llm = Mockery::mock(LlmGateway::class);
        $llm->shouldReceive('embed')->once()->andReturnUsing(function (int $actualWorkspace, array $queries) use ($workspaceId): array {
            $this->assertSame($workspaceId, $actualWorkspace);
            $this->assertNotEmpty($queries);
            $this->assertFalse(collect($queries)->contains(fn (string $query): bool => str_contains(mb_strtolower($query), 'hello')));

            return array_fill(0, count($queries), [0.45, sqrt(1 - (0.45 ** 2))]);
        });

        $result = $this->service($llm)->retrieve(
            $kb,
            $workspaceId,
            'e sim want',
            [
                ['role' => 'user', 'content' => 'Hello', 'answer_origin' => 'conversation'],
                ['role' => 'assistant', 'content' => 'Hi! How can we help?', 'answer_origin' => 'conversation'],
            ],
            3,
            null,
            0.60,
            1200,
        );

        $this->assertSame('clarification', $result['response_mode']);
        $this->assertSame('hybrid_current_turn', $result['retrieval_strategy']);
        $this->assertSame('grounded_clarification', $result['acceptance_reason']);
        $this->assertStringContainsString('eSIM', $result['context']);
        $this->assertGreaterThanOrEqual(0.40, $result['best_score']);
    }

    public function test_semantic_paraphrase_answers_without_exact_word_overlap(): void
    {
        [$kb, $workspaceId] = $this->seedKnowledge();
        $llm = Mockery::mock(LlmGateway::class);
        $llm->shouldReceive('embed')->once()->andReturn([[1.0, 0.0]]);

        $result = $this->service($llm)->retrieve(
            $kb,
            $workspaceId,
            'Can I activate service without receiving a physical card?',
            [],
            3,
            null,
            0.60,
            1200,
        );

        $this->assertSame('answer', $result['response_mode']);
        $this->assertSame('strong_evidence', $result['acceptance_reason']);
        $this->assertSame(1.0, $result['semantic_score']);
    }

    public function test_clear_business_request_with_medium_semantics_and_strong_lexical_evidence_answers(): void
    {
        [$kb, $workspaceId] = $this->seedKnowledge();
        $llm = Mockery::mock(LlmGateway::class);
        $llm->shouldReceive('embed')->once()->andReturn([[0.42, sqrt(1 - (0.42 ** 2))]]);

        $result = $this->service($llm)->retrieve(
            $kb,
            $workspaceId,
            'choose digital SIM activation',
            [],
            3,
            null,
            0.60,
            1200,
        );

        $this->assertSame('answer', $result['response_mode']);
        $this->assertSame('strong_evidence', $result['acceptance_reason']);
        $this->assertGreaterThanOrEqual(0.55, $result['lexical_score']);
    }

    public function test_unrelated_message_falls_back_and_stale_generation_is_never_selected(): void
    {
        [$kb, $workspaceId, $document] = $this->seedKnowledge();
        AiKbChunk::create([
            'kb_id' => $kb->id,
            'document_id' => $document->id,
            'ord' => 99,
            'content' => 'Celebrity politics and unrelated trivia.',
            'content_hash' => hash('sha256', 'stale'),
            'tokens' => 10,
            'embedding' => json_encode([0.0, 1.0]),
            'embedding_model' => 'test',
            'embedding_status' => 'ready',
            'index_generation' => 'stale-generation',
        ]);
        $llm = Mockery::mock(LlmGateway::class);
        $llm->shouldReceive('embed')->once()->andReturn([[0.0, 1.0]]);

        $result = $this->service($llm)->retrieve(
            $kb,
            $workspaceId,
            'Who was the best celebrity president?',
            [],
            3,
            null,
            0.60,
            1200,
        );

        $this->assertSame('fallback', $result['response_mode']);
        $this->assertSame(0.0, $result['semantic_score']);
        $this->assertSame('', $result['context']);
    }

    public function test_a_second_ambiguous_turn_does_not_loop_the_same_clarification(): void
    {
        [$kb, $workspaceId] = $this->seedKnowledge();
        $llm = Mockery::mock(LlmGateway::class);
        $llm->shouldReceive('embed')->once()->andReturn([[0.45, sqrt(1 - (0.45 ** 2))]]);

        $result = $this->service($llm)->retrieve(
            $kb,
            $workspaceId,
            'eSIM please',
            [['role' => 'assistant', 'content' => 'Which device do you use?', 'response_mode' => 'clarification']],
            3,
            null,
            0.60,
            1200,
        );

        $this->assertSame('fallback', $result['response_mode']);
        $this->assertSame('clarification_already_asked', $result['acceptance_reason']);
    }

    public function test_a_selected_quick_reply_uses_the_grounded_question_as_context(): void
    {
        [$kb, $workspaceId] = $this->seedKnowledge();
        $llm = Mockery::mock(LlmGateway::class);
        $llm->shouldReceive('embed')->once()->andReturnUsing(function (int $workspace, array $queries) use ($workspaceId): array {
            $this->assertSame($workspaceId, $workspace);
            $this->assertCount(2, $queries);
            $this->assertStringContainsString('Which task', $queries[1]);

            return array_fill(0, count($queries), [1.0, 0.0]);
        });

        $result = $this->service($llm)->retrieve(
            $kb,
            $workspaceId,
            'Activate a plan',
            [[
                'role' => 'assistant',
                'content' => 'Which task do you need help with?',
                'response_mode' => 'clarification',
                'quick_replies' => [['label' => 'Choose a plan'], ['label' => 'Activate a plan']],
            ]],
            3,
            null,
            0.60,
            1200,
        );

        $this->assertSame('hybrid_contextual', $result['retrieval_strategy']);
        $this->assertSame('answer', $result['response_mode']);
    }

    /** @return array{0:AiKnowledgeBase,1:int,2:AiKbDocument} */
    private function seedKnowledge(): array
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $kb = AiKnowledgeBase::create([
            'workspace_id' => $workspace->id,
            'name' => 'Business support',
            'purpose' => 'Help customers choose and activate connectivity services.',
            'brand' => 'Example Telecom',
            'audience' => 'Mobile customers',
            'status' => 'active',
        ]);
        $document = AiKbDocument::create([
            'kb_id' => $kb->id,
            'title' => 'Digital SIM guide',
            'source_type' => 'faq',
            'source_ref' => 'internal',
            'status' => 'indexed',
            'enabled' => true,
            'review_status' => 'auto_approved',
            'publication_status' => 'published',
            'active_index_generation' => 'generation-v2',
            'index_version' => 2,
        ]);
        AiKbChunk::create([
            'kb_id' => $kb->id,
            'document_id' => $document->id,
            'ord' => 0,
            'content' => 'An eSIM is a digital SIM. Customers can choose iOS, Android, or ask whether their device supports activation.',
            'content_hash' => hash('sha256', 'active'),
            'tokens' => 24,
            'embedding' => json_encode([1.0, 0.0]),
            'embedding_model' => 'test',
            'embedding_status' => 'ready',
            'index_generation' => 'generation-v2',
            'section_label' => 'Getting started',
            'chunk_kind' => 'faq',
        ]);

        return [$kb, (int) $workspace->id, $document];
    }

    private function service(LlmGateway $llm): KnowledgeRetrievalService
    {
        return new KnowledgeRetrievalService(
            $llm,
            app(EmbeddingStore::class),
            app(BusinessAwareTurnRouter::class),
        );
    }
}
