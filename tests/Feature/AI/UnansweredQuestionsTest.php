<?php

namespace Tests\Feature\AI;

use App\Modules\AI\Jobs\IndexDocumentJob;
use App\Modules\AI\Models\AiChatbot;
use App\Modules\AI\Models\AiKbChunk;
use App\Modules\AI\Models\AiKbDocument;
use App\Modules\AI\Models\AiKbKnowledgeGap;
use App\Modules\AI\Models\AiKnowledgeBase;
use App\Modules\AI\Models\AiProviderConfig;
use App\Modules\AI\Services\ChatbotRunner;
use App\Modules\AI\Services\EmbeddingStore;
use App\Modules\AI\Services\UnansweredQuestionService;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Unanswered questions (Smart Bot 2.0, Phase 1.5): recorded for every turn
 * the knowledge could not answer, without personal details, never from the
 * playground; answered into the Knowledge Base or dismissed by the client.
 */
class UnansweredQuestionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_customer_turn_the_knowledge_cannot_answer_is_recorded_without_personal_details(): void
    {
        [$bot, $kb, $user] = $this->bot();
        $this->fakeOpenAi([0.0, 1.0, 0.0]);
        $runner = app(ChatbotRunner::class);

        foreach (['Do you ship to Mars? Mail me at sam@example.com or +880 1711-223344', 'do you ship to mars? mail me at sam@example.com or +880 1711-223344'] as $question) {
            $runner->run($bot, $this->message($bot, $question));
        }

        $gap = AiKbKnowledgeGap::sole();
        $this->assertSame($kb->id, $gap->kb_id);
        // The latest wording is kept, with its personal details replaced.
        $this->assertSame('do you ship to mars? mail me at [email] or [phone]', $gap->question_sample);
        $this->assertSame(2, $gap->occurrences);
        $this->assertSame('open', $gap->status);
    }

    public function test_the_playground_never_fills_the_list(): void
    {
        [$bot, , $user] = $this->bot();
        $this->fakeOpenAi([0.0, 1.0, 0.0]);

        $this->actingAs($user)->postJson(route('client.ai.chatbots.playground', $bot->uuid), ['message' => 'Do you ship to Mars?']);

        $this->assertSame(0, AiKbKnowledgeGap::count());
    }

    public function test_an_answer_joins_one_answers_source_and_closes_the_question(): void
    {
        [, $kb, $user] = $this->bot();
        Queue::fake();
        $first = $this->gap($kb, 'Do you ship to Mars?');
        $second = $this->gap($kb, 'Is there a student discount?');

        $this->actingAs($user)->post(route('client.ai.knowledge-bases.unanswered.answer', ['kb' => $kb->uuid, 'gap' => $first->id]), [
            'question' => 'Do you ship to Mars?', 'answer' => 'No, we only sell eSIMs for travel on Earth.',
        ])->assertRedirect();
        $this->actingAs($user)->post(route('client.ai.knowledge-bases.unanswered.answer', ['kb' => $kb->uuid, 'gap' => $second->id]), [
            'question' => 'Is there a student discount?', 'answer' => 'Students get 10% off with a valid student ID.',
        ])->assertRedirect();

        $source = AiKbDocument::where('original_source_ref', UnansweredQuestionService::ANSWERS_SOURCE)->sole();
        $this->assertSame('faq', $source->source_type);
        $this->assertTrue($source->authoritative);
        $this->assertSame(['Do you ship to Mars?', 'Is there a student discount?'], array_column(json_decode($source->source_ref, true), 'question'));
        $this->assertSame(['resolved', 'resolved'], [$first->fresh()->status, $second->fresh()->status]);
        Queue::assertPushedOn('ai', IndexDocumentJob::class);
    }

    public function test_dismissed_questions_leave_the_list_and_other_workspaces_are_refused(): void
    {
        [, $kb, $user] = $this->bot();
        $gap = $this->gap($kb, 'What is the weather today?');
        $stranger = $this->createWorkspaceContext()['user'];

        $this->actingAs($stranger)->post(route('client.ai.knowledge-bases.unanswered.dismiss', ['kb' => $kb->uuid, 'gap' => $gap->id]))->assertForbidden();
        $this->actingAs($user)->post(route('client.ai.knowledge-bases.unanswered.dismiss', ['kb' => $kb->uuid, 'gap' => $gap->id]))->assertRedirect();

        $this->assertSame('ignored', $gap->fresh()->status);
        $this->actingAs($user)->get(route('client.ai.knowledge-bases.show', $kb->uuid))
            ->assertInertia(fn ($page) => $page->where('kb.knowledge_gaps', []));
    }

    public function test_a_question_from_another_knowledge_base_cannot_be_answered_here(): void
    {
        [, $kb, $user] = $this->bot();
        $otherKb = AiKnowledgeBase::create(['workspace_id' => $kb->workspace_id, 'name' => 'Other', 'embedding_model' => 'text-embedding-3-small', 'dimensions' => 3, 'status' => 'active']);
        $gap = $this->gap($otherKb, 'Question elsewhere');

        $this->actingAs($user)->post(route('client.ai.knowledge-bases.unanswered.answer', ['kb' => $kb->uuid, 'gap' => $gap->id]), ['question' => 'x', 'answer' => 'y'])->assertNotFound();
    }

    /** @return array{0:AiChatbot,1:AiKnowledgeBase,2:mixed} */
    private function bot(): array
    {
        $context = $this->createWorkspaceContext();
        $workspaceId = $context['workspace']->id;
        $kb = AiKnowledgeBase::create([
            'workspace_id' => $workspaceId, 'name' => 'Travel eSIM knowledge', 'brand' => 'Telzen',
            'purpose' => 'Sells travel eSIM data plans for international trips', 'audience' => 'Travellers',
            'embedding_model' => 'text-embedding-3-small', 'dimensions' => 3, 'status' => 'active',
        ]);
        $document = AiKbDocument::create([
            'kb_id' => $kb->id, 'title' => 'Installing an eSIM', 'source_type' => 'file', 'source_ref' => 'install.md',
            'status' => 'indexed', 'review_status' => 'approved', 'publication_status' => 'published',
        ]);
        $chunk = AiKbChunk::create([
            'kb_id' => $kb->id, 'document_id' => $document->id, 'ord' => 0, 'content' => 'Scan the QR code to install your eSIM.',
            'tokens' => 10, 'index_generation' => 'legacy', 'embedding_status' => 'ready',
        ]);
        app(EmbeddingStore::class)->storeEmbedding($chunk, [1.0, 0.0, 0.0]);
        AiProviderConfig::create([
            'workspace_id' => $workspaceId, 'provider' => 'openai', 'credentials' => ['api_key' => 'sk-test'],
            'default_model_chat' => 'gpt-4o-mini', 'default_model_embed' => 'text-embedding-3-small',
            'enabled' => true, 'last_tested_at' => now(), 'last_test_succeeded_at' => now(),
        ]);
        $bot = AiChatbot::create([
            'workspace_id' => $workspaceId, 'name' => 'Support Bot', 'ai_kb_id' => $kb->id,
            'unsupported_answer_action' => 'clarify_then_handoff', 'enabled' => true,
        ]);

        return [$bot, $kb, $context['user']];
    }

    private function message(AiChatbot $bot, string $body): Message
    {
        $conversation = Conversation::create([
            'workspace_id' => $bot->workspace_id,
            'contact_id' => Contact::factory()->create(['workspace_id' => $bot->workspace_id])->id,
            'status' => 'open',
        ]);
        $message = new Message(['body' => $body, 'direction' => 'in', 'channel' => 'webchat']);
        $message->setRelation('conversation', $conversation);

        return $message;
    }

    private function gap(AiKnowledgeBase $kb, string $question): AiKbKnowledgeGap
    {
        return AiKbKnowledgeGap::create([
            'workspace_id' => $kb->workspace_id, 'kb_id' => $kb->id, 'question_hash' => hash('sha256', $question),
            'question_sample' => $question, 'occurrences' => 3, 'decision' => 'handoff', 'status' => 'open', 'last_seen_at' => now(),
        ]);
    }

    /** @param array<int,float> $embedding */
    private function fakeOpenAi(array $embedding): void
    {
        Http::fake([
            'api.openai.com/v1/embeddings' => Http::response(['data' => [['embedding' => $embedding]]]),
            'api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => '{"reply":"unused","quick_replies":[],"grounded":true,"response_type":"answer","show_video":false}'], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1], 'model' => 'gpt-4o-mini',
            ]),
        ]);
    }
}
