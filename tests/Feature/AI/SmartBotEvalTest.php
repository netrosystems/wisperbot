<?php

namespace Tests\Feature\AI;

use App\Modules\AI\Models\AiChatbot;
use App\Modules\AI\Models\AiCreditLedger;
use App\Modules\AI\Models\AiEvalCase;
use App\Modules\AI\Models\AiEvalRun;
use App\Modules\AI\Models\AiKbChunk;
use App\Modules\AI\Models\AiKbDocument;
use App\Modules\AI\Models\AiKbRetrievalDiagnostic;
use App\Modules\AI\Models\AiKbTestCase;
use App\Modules\AI\Models\AiKnowledgeBase;
use App\Modules\AI\Models\AiProviderConfig;
use App\Modules\AI\Models\AiRun;
use App\Modules\AI\Models\AiWorkspaceSetting;
use App\Modules\AI\Services\EmbeddingStore;
use App\Modules\AI\Services\Eval\EvalRunner;
use App\Modules\AI\Services\Eval\EvalScorer;
use App\Modules\Integrations\Models\IntegrationConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The answer-quality test set (Smart Bot 2.0, Phase 1.6): questions written
 * and checked against the bot's own knowledge, runs billed to the platform,
 * and deterministic scores against the Phase 1 targets.
 */
class SmartBotEvalTest extends TestCase
{
    use RefreshDatabase;

    private const PASSAGE = 'The Japan travel eSIM plan costs 12 USD for 7 days and includes 5 GB of data. Install it by scanning the QR code.';

    public function test_questions_are_written_from_the_knowledge_and_their_facts_checked(): void
    {
        [$bot] = $this->bot();
        AiKbTestCase::create(['kb_id' => $bot->ai_kb_id, 'question' => 'How long is the Japan plan?', 'expected_facts' => "7 days\n5 GB"]);
        $this->fakeOpenAi([
            ['cases' => [[
                'passage' => 1, 'question' => 'How much is the Japan eSIM?', 'answer' => 'It costs 12 USD.',
                'facts' => ['12 USD', '99 USD', 'a sentence that is far too long to ever be a short key fact'], 'language' => 'English',
                'follow_up_question' => 'And how much data?', 'follow_up_facts' => ['5 GB'],
            ]]],
            ['questions' => ['Do you have a shop in Dhaka?', 'How do I install the Japan eSIM?']],
            ['labels' => [
                ['question' => 1, 'answerable' => false, 'facts' => [], 'language' => 'en'],
                ['question' => 2, 'answerable' => true, 'facts' => ['QR code'], 'language' => 'en'],
            ]],
        ]);

        $this->assertSame(0, Artisan::call('ai:eval:synthesize', ['bot' => $bot->id, '--count' => 8, '--languages' => 'none']));

        $cases = AiEvalCase::orderBy('id')->get();
        $this->assertSame(['kb_test', 'knowledge', 'knowledge', 'unanswerable'], $cases->pluck('source')->all());
        $this->assertSame(['7 days', '5 GB'], $cases[0]->expected_facts);
        // Only facts that really are in the passage survive.
        $this->assertSame(['12 USD'], $cases[1]->expected_facts);
        $this->assertSame('en', $cases[1]->language);
        $this->assertSame('And how much data?', $cases[2]->question);
        $this->assertSame('How much is the Japan eSIM?', $cases[2]->history[0]['content']);
        $this->assertSame([AiEvalCase::EXPECT_DECLINE, 'Do you have a shop in Dhaka?'], [$cases[3]->expected, $cases[3]->question]);
        $this->assertSame(0, AiCreditLedger::count());
    }

    public function test_a_run_answers_through_the_real_path_without_charging_or_recording_the_workspace(): void
    {
        [$bot, $workspaceId] = $this->bot();
        $this->cases($bot, $workspaceId);
        $this->fakeOpenAi([[
            'reply' => 'The Japan plan costs 12 USD for 7 days.', 'quick_replies' => [], 'grounded' => true, 'response_type' => 'answer', 'show_video' => false,
        ]]);

        $run = app(EvalRunner::class)->run($bot);

        $this->assertSame(AiEvalRun::COMPLETED, $run->status);
        $this->assertTrue($run->passed);
        $this->assertSame('v1', $run->engine);
        $this->assertEquals(1, $run->summary['answered_rate']);
        $this->assertEquals(1, $run->summary['declined_rate']);
        $this->assertSame(0, $run->summary['invented_figures']);
        $this->assertSame(0, AiCreditLedger::count());
        $this->assertSame(0, AiRun::count());
        $this->assertSame(0, AiKbRetrievalDiagnostic::count());
        $this->assertSame('no_context', $run->results()->where('expected', 'decline')->sole()->reason_code);
    }

    public function test_a_run_on_engine_v2_is_platform_billed_too(): void
    {
        [$bot, $workspaceId] = $this->bot();
        config()->set('chatbot.engine_v2_enabled', true);
        // The search path: a Strict bot declines the unanswerable question without a model call.
        config()->set('chatbot.v2_full_context_max_tokens', 0);
        $bot->update(['engine' => 'v2', 'answer_scope' => 'verified_only']);
        $this->cases($bot, $workspaceId);
        $this->fakeOpenAi([
            ['reply' => 'The Japan plan costs 12 USD for 7 days.', 'answer_kind' => 'answer', 'used_sources' => [1], 'evidence' => ['costs 12 USD for 7 days'],
                'quick_replies' => [], 'grounded' => true, 'show_video' => false, 'language' => 'en'],
            ['supported' => true, 'unsupported' => ''],
        ]);

        $run = app(EvalRunner::class)->run($bot->fresh());

        $this->assertSame('v2', $run->engine);
        $this->assertTrue($run->passed);
        $this->assertSame('answer', $run->results()->where('expected', 'answer')->sole()->trace['answer_kind']);
        $this->assertSame(0, AiCreditLedger::count());
        $this->assertSame(0, AiRun::count());
    }

    public function test_a_workspace_on_its_own_key_needs_explicit_consent(): void
    {
        [$bot, $workspaceId] = $this->bot();
        $this->cases($bot, $workspaceId);
        AiWorkspaceSetting::create(['workspace_id' => $workspaceId, 'provider_mode' => 'byok']);

        $this->expectExceptionMessage('--byok');
        app(EvalRunner::class)->run($bot);
    }

    public function test_the_scorer_fails_invented_figures_and_answers_to_unanswerable_questions(): void
    {
        $scorer = app(EvalScorer::class);
        $answerable = new AiEvalCase(['question' => 'How much is Japan?', 'expected' => 'answer', 'expected_facts' => ['12 USD']]);
        $unanswerable = new AiEvalCase(['question' => 'Do you have a shop in Dhaka?', 'expected' => 'decline']);
        $answer = fn (string $reply, string $origin = 'knowledge_base') => ['reply' => $reply, 'answer_origin' => $origin, 'response_mode' => 'answer'];

        $this->assertSame('pass', $scorer->score($answerable, $answer('It is $12 (12 USD).'), [], self::PASSAGE)['verdict']);
        $this->assertSame('invented_figures', $scorer->score($answerable, $answer('It is 15 USD.'), [], self::PASSAGE)['failure']);
        $this->assertSame('missing_facts', $scorer->score($answerable, $answer('It is cheap.'), [], self::PASSAGE)['failure']);
        $this->assertSame('not_answered', $scorer->score($answerable, $answer('Sorry.', 'fallback'), [], self::PASSAGE)['failure']);
        $this->assertSame('answered_unanswerable', $scorer->score($unanswerable, $answer('Yes, on Road 12.'), [], self::PASSAGE.' Road 12')['failure']);
        $this->assertSame('pass', $scorer->score($unanswerable, $answer('We sell online; our team can tell you about shops.', 'business_guidance'), ['trace' => ['answer_kind' => 'guidance']], self::PASSAGE)['verdict']);
    }

    public function test_word_facts_need_half_and_translated_questions_are_checked_on_figures(): void
    {
        // From the first production run (2026-10-04): correct answers failed for one vague phrase.
        $scorer = app(EvalScorer::class);
        $answer = fn (string $reply) => ['reply' => $reply, 'answer_origin' => 'knowledge_base', 'response_mode' => 'answer'];
        $bkash = new AiEvalCase(['question' => 'Can I pay with Bkash or Nagad?', 'expected' => 'answer', 'expected_facts' => ['Bkash', 'Nagad', 'payment method from Bangladesh']]);
        $roaming = new AiEvalCase(['question' => 'Japan-e roaming-er dam koto?', 'expected' => 'answer', 'source' => 'translation', 'expected_facts' => ['$1.2', 'Japan roaming price']]);

        $this->assertSame('pass', $scorer->score($bkash, $answer('Yes, you can pay with Bkash or Nagad.'), [], self::PASSAGE.' $1.2')['verdict']);
        $this->assertSame('missing_facts', $scorer->score($bkash, $answer('Yes, several wallets work.'), [], self::PASSAGE)['failure']);
        $this->assertSame('pass', $scorer->score($roaming, $answer('Japan-e roaming $1.2 theke shuru.'), [], self::PASSAGE.' $1.2')['verdict']);
        $this->assertSame('missing_facts', $scorer->score($roaming, $answer('Package-er upor nirbhor kore.'), [], self::PASSAGE)['failure']);
    }

    /** @return array{0:AiChatbot,1:int} */
    private function bot(): array
    {
        $workspaceId = $this->createWorkspaceContext()['workspace']->id;
        $kb = AiKnowledgeBase::create([
            'workspace_id' => $workspaceId, 'name' => 'Travel eSIM knowledge', 'brand' => 'Telzen',
            'purpose' => 'Sells travel eSIM data plans for international trips', 'audience' => 'Travellers',
            'embedding_model' => 'text-embedding-3-small', 'dimensions' => 3, 'status' => 'active',
        ]);
        $document = AiKbDocument::create([
            'kb_id' => $kb->id, 'title' => 'Japan plan', 'source_type' => 'file', 'source_ref' => 'japan.md',
            'status' => 'indexed', 'review_status' => 'approved', 'publication_status' => 'published',
        ]);
        $chunk = AiKbChunk::create([
            'kb_id' => $kb->id, 'document_id' => $document->id, 'ord' => 0, 'content' => self::PASSAGE,
            'tokens' => 30, 'index_generation' => 'legacy', 'embedding_status' => 'ready',
        ]);
        app(EmbeddingStore::class)->storeEmbedding($chunk, [1.0, 0.0, 0.0]);
        AiProviderConfig::create([
            'workspace_id' => $workspaceId, 'provider' => 'openai', 'credentials' => ['api_key' => 'sk-test'],
            'default_model_chat' => 'gpt-4o-mini', 'default_model_embed' => 'text-embedding-3-small',
            'enabled' => true, 'last_tested_at' => now(), 'last_test_succeeded_at' => now(),
        ]);
        IntegrationConfig::create([
            'provider' => 'llm_openai_default', 'label' => 'OpenAI', 'mode' => 'live', 'enabled' => true,
            'credentials' => ['api_key' => 'sk-managed'],
        ]);
        $bot = AiChatbot::create([
            'workspace_id' => $workspaceId, 'name' => 'Support Bot', 'ai_kb_id' => $kb->id,
            'answer_scope' => 'business_only', 'unsupported_answer_action' => 'clarify_then_handoff', 'enabled' => true,
        ]);

        return [$bot, $workspaceId];
    }

    private function cases(AiChatbot $bot, int $workspaceId): void
    {
        foreach ([['How much is the Japan eSIM?', 'answer', ['12 USD']], ['Do you have a shop in Paris?', 'decline', null]] as [$question, $expected, $facts]) {
            AiEvalCase::create([
                'workspace_id' => $workspaceId, 'chatbot_id' => $bot->id, 'question' => $question, 'expected' => $expected,
                'expected_facts' => $facts, 'source' => 'knowledge', 'fingerprint' => AiEvalCase::fingerprint($question),
            ]);
        }
    }

    /**
     * Chat replies in order; embeddings point at the passage except for
     * questions about Paris or Dhaka, which match nothing.
     *
     * @param  list<array<string,mixed>>  $chats
     */
    private function fakeOpenAi(array $chats): void
    {
        $sequence = Http::sequence();
        foreach ($chats as $chat) {
            $sequence->push([
                'choices' => [['message' => ['content' => json_encode($chat)], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 50, 'completion_tokens' => 20],
                'model' => 'gpt-4o-mini',
            ]);
        }
        Http::fake([
            'api.openai.com/v1/embeddings' => fn (Request $request) => Http::response(['data' => [[
                'embedding' => preg_match('/Paris|Dhaka/', (string) json_encode($request['input'])) ? [0.0, 1.0, 0.0] : [1.0, 0.0, 0.0],
            ]]]),
            'api.openai.com/v1/chat/completions' => $sequence,
        ]);
    }
}
