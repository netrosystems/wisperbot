<?php

namespace Tests\Feature\AI;

use App\Modules\AI\Models\AiChatbot;
use App\Modules\AI\Models\AiKbChunk;
use App\Modules\AI\Models\AiKbDocument;
use App\Modules\AI\Models\AiKnowledgeBase;
use App\Modules\AI\Models\AiProviderConfig;
use App\Modules\AI\Services\ChatbotRunner;
use App\Modules\AI\Services\EmbeddingStore;
use App\Modules\AI\Services\KnowledgeRetrievalService;
use App\Modules\AI\Services\SmartBotRetrievalPolicy;
use App\Modules\AI\Services\TrustedKnowledgeResearchService;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A client's own authoritative source must settle conflicts with other
 * sources (such as outdated website marketing) in every retrieval path.
 */
class KnowledgeSourceAuthorityTest extends TestCase
{
    use RefreshDatabase;

    private const WEBSITE = 'How does Northwind work? Northwind works only with eSIM technology, so no physical SIM is needed.';

    private const OFFICIAL = 'Northwind is a roaming data provider. Northwind works with eSIM for supported phones and delivers physical SIM cards to your door.';

    private int $workspaceId;

    private AiKnowledgeBase $kb;

    private ?string $systemPrompt = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workspaceId = $this->createWorkspaceContext()['workspace']->id;
        $this->kb = AiKnowledgeBase::create([
            'workspace_id' => $this->workspaceId,
            'name' => 'Support KB',
            'brand' => 'Northwind',
            'purpose' => 'Sell roaming mobile data with eSIM and physical SIM cards.',
            'audience' => 'Travellers',
            'embedding_model' => 'text-embedding-3-small',
            'dimensions' => 3,
            'status' => 'active',
        ]);
        AiProviderConfig::create([
            'workspace_id' => $this->workspaceId,
            'provider' => 'openai',
            'credentials' => ['api_key' => 'sk-test'],
            'default_model_chat' => 'gpt-4o-mini',
            'default_model_embed' => 'text-embedding-3-small',
            'enabled' => true,
            'last_tested_at' => now(),
            'last_test_succeeded_at' => now(),
        ]);
        Http::fake([
            'api.openai.com/v1/embeddings' => Http::response(['data' => [['embedding' => [1.0, 0.0, 0.0]]]], 200),
            'api.openai.com/v1/chat/completions' => function ($request) {
                $this->systemPrompt = collect(json_decode($request->body(), true)['messages'] ?? [])->firstWhere('role', 'system')['content'] ?? '';

                return Http::response([
                    'choices' => [['message' => ['content' => json_encode(['reply' => 'Northwind offers eSIM and physical SIM cards.', 'quick_replies' => [], 'grounded' => true])]]],
                    'usage' => ['prompt_tokens' => 50, 'completion_tokens' => 20],
                    'model' => 'gpt-4o-mini',
                ], 200);
            },
        ]);
    }

    public function test_authoritative_source_leads_the_evidence_even_when_other_wording_matches_better(): void
    {
        $this->source('Website', 'https://example.com/', self::WEBSITE, [1.0, 0.0, 0.0]);
        $this->source('Operations guide.docx', 'kb-docs/guide.docx', self::OFFICIAL, [0.9, 0.3, 0.0], authoritative: true, priority: 75);

        $context = $this->retrieve('how northwind works')['context'];

        $this->assertStringStartsWith('[Authoritative source: Operations guide.docx]', $context);
        $this->assertStringContainsString(self::WEBSITE, $context);
    }

    public function test_the_same_passage_uploaded_twice_uses_one_evidence_slot(): void
    {
        $this->source('Website', 'https://example.com/', self::WEBSITE, [1.0, 0.0, 0.0]);
        $this->source('Operations guide.docx', 'kb-docs/guide.docx', self::OFFICIAL, [0.9, 0.3, 0.0], authoritative: true, priority: 75);
        $this->source('Operations guide (copy).docx', 'kb-docs/guide-copy.docx', self::OFFICIAL, [0.9, 0.3, 0.0], authoritative: true);

        $result = $this->retrieve('how northwind works');

        $this->assertSame(1, substr_count($result['context'], self::OFFICIAL));
        $this->assertSame(2, $result['passages_used']);
    }

    public function test_a_revised_copy_of_a_passage_is_a_duplicate_and_the_stronger_source_is_kept(): void
    {
        $this->source('Operations guide (old upload).docx', 'kb-docs/guide-old.docx', 'Northwind is a roaming data provider. Northwind works with eSIM for supported phones and delivers physical SIM cards to your door quickly.', [0.9, 0.3, 0.0], authoritative: true);
        $this->source('Operations guide.docx', 'kb-docs/guide.docx', self::OFFICIAL, [0.9, 0.3, 0.0], authoritative: true, priority: 75);

        $result = $this->retrieve('how northwind works');

        $this->assertSame(1, $result['passages_used']);
        $this->assertStringStartsWith('[Authoritative source: Operations guide.docx]', $result['context']);
    }

    public function test_keyword_match_outside_the_vector_window_is_scored_by_meaning(): void
    {
        // More than the vector window of closer-but-unrelated passages.
        for ($i = 0; $i < 22; $i++) {
            $this->source('Note '.$i, 'kb-docs/note-'.$i.'.md', 'Shipping note '.$i.' about parcel packaging.', [0.95, 0.312, 0.0]);
        }
        $this->source('Operations guide.docx', 'kb-docs/guide.docx', self::OFFICIAL, [0.8, 0.6, 0.0], authoritative: true, priority: 75);

        $result = $this->retrieve('how northwind works');
        $official = collect($result['candidates'])->first(fn (array $candidate): bool => $candidate['chunk']->content === self::OFFICIAL);

        $this->assertNotNull($official);
        $this->assertEqualsWithDelta(0.8, $official['semantic_score'], 0.001);
        $this->assertStringStartsWith('[Authoritative source: Operations guide.docx]', $result['context']);
    }

    public function test_answer_prompt_carries_the_business_profile_and_conflict_rule(): void
    {
        config()->set('knowledge_base.hybrid_retrieval_enabled', true);
        $this->source('Website', 'https://example.com/', self::WEBSITE, [1.0, 0.0, 0.0]);
        $this->source('Operations guide.docx', 'kb-docs/guide.docx', self::OFFICIAL, [0.9, 0.3, 0.0], authoritative: true, priority: 75);

        $this->runBot('how northwind works');

        $prompt = (string) $this->systemPrompt;
        $this->assertStringContainsString("Business profile (written by the business; authoritative):\nBusiness: Northwind\nPurpose: Sell roaming mobile data with eSIM and physical SIM cards.", $prompt);
        $this->assertStringContainsString('When sources disagree, follow them and never repeat the conflicting claim', $prompt);
        $this->assertLessThan(strpos($prompt, 'Verified business context'), strpos($prompt, 'Business profile'));
        $this->assertStringNotContainsString('Prefer the highest-ranked passage', $prompt);
    }

    public function test_legacy_retrieval_also_presents_the_authoritative_source_first(): void
    {
        config()->set('knowledge_base.hybrid_retrieval_enabled', false);
        $this->source('Website', 'https://example.com/', self::WEBSITE, [1.0, 0.0, 0.0]);
        $this->source('Operations guide.docx', 'kb-docs/guide.docx', self::OFFICIAL, [0.9, 0.3, 0.0], authoritative: true, priority: 75);

        $this->runBot('how northwind works');

        $prompt = (string) $this->systemPrompt;
        $this->assertStringContainsString('[Authoritative source: Operations guide.docx]', $prompt);
        $this->assertLessThan(strpos($prompt, self::WEBSITE), strpos($prompt, self::OFFICIAL));
    }

    public function test_availability_question_with_clear_keyword_evidence_is_answered_not_clarified(): void
    {
        $this->source('Operations guide.docx', 'kb-docs/guide.docx', 'Customer: How can I get a physical SIM? Answer: Order it in our app and we deliver it to your address.', [0.51, 0.86, 0.0], authoritative: true);

        $this->assertSame('answer', $this->retrieve('do you sell physical sim')['response_mode']);
        $this->assertSame('answer', $this->retrieve('physical sim ache?')['response_mode']);
    }

    public function test_answer_from_website_research_reaches_the_customer_without_source_links(): void
    {
        config()->set('knowledge_base.hybrid_retrieval_enabled', true);
        config()->set('chatbot.business_aware_routing_enabled', true);
        $this->source('Operations guide.docx', 'kb-docs/guide.docx', self::OFFICIAL, [1.0, 0.0, 0.0], authoritative: true);
        $this->mock(TrustedKnowledgeResearchService::class, fn ($mock) => $mock->shouldReceive('research')->andReturn([
            'context' => "[Source: Plans (https://example.com/plans)]\nNorthwind plans start at 5 USD.",
            'citations' => [['title' => 'Plans', 'url' => 'https://example.com/plans']],
            'outcome' => 'answered',
            'latency_ms' => 5,
        ]));

        $result = $this->runBot('How do I buy a Northwind plan?', trustedResearch: true);

        $this->assertSame('trusted_research', $result['answer_origin']);
        $this->assertStringNotContainsString('example.com', $result['reply']);
        $this->assertStringNotContainsString('Sources', $result['display_body']);
        $this->assertSame('https://example.com/plans', $result['citations'][0]['url']);
    }

    public function test_no_after_an_offer_of_more_help_says_goodbye_without_generation_or_handoff(): void
    {
        config()->set('chatbot.business_aware_routing_enabled', false);
        $this->source('Operations guide.docx', 'kb-docs/guide.docx', self::OFFICIAL, [1.0, 0.0, 0.0], authoritative: true);
        $contact = Contact::factory()->create(['workspace_id' => $this->workspaceId]);
        $conversation = Conversation::create(['workspace_id' => $this->workspaceId, 'contact_id' => $contact->id, 'status' => 'open']);
        Message::create([
            'conversation_id' => $conversation->id, 'direction' => 'out', 'channel' => 'webchat', 'type' => 'text', 'status' => 'sent', 'sent_by' => 'bot',
            'body' => "Plans vary by country. Would you like assistance with anything else?\n\n1. Yes\n2. No",
            'sent_at' => now()->subMinute(),
        ]);

        $result = $this->runBot('No', conversation: $conversation);

        $this->assertSame('Thanks for chatting with Northwind! Have a great day.', $result['reply']);
        $this->assertSame('conversation', $result['answer_origin']);
        $this->assertNull($this->systemPrompt, 'No model call for a polite closing.');
    }

    public function test_private_answering_uses_a_five_passage_window_and_comments_stay_strict(): void
    {
        $policy = app(SmartBotRetrievalPolicy::class);

        $this->assertSame(5, $policy->privateAnswering()['max_context_chunks']);
        $this->assertSame(1600, $policy->privateAnswering()['max_context_tokens']);
        $this->assertSame(3, $policy->publicComments()['max_context_chunks']);
    }

    /** @param  array<int,float>  $embedding */
    private function source(string $title, string $ref, string $content, array $embedding, bool $authoritative = false, int $priority = 50): void
    {
        $document = AiKbDocument::create([
            'kb_id' => $this->kb->id,
            'title' => $title,
            'source_type' => str_starts_with($ref, 'https://') ? 'url' : 'file',
            'source_ref' => $ref,
            'status' => 'indexed',
            'review_status' => 'approved',
            'publication_status' => 'published',
            'authoritative' => $authoritative,
            'priority' => $priority,
        ]);
        $chunk = AiKbChunk::create([
            'kb_id' => $this->kb->id,
            'document_id' => $document->id,
            'ord' => 0,
            'content' => $content,
            'tokens' => 20,
            'index_generation' => 'legacy',
            'embedding_status' => 'ready',
        ]);
        app(EmbeddingStore::class)->storeEmbedding($chunk, $embedding);
    }

    private function retrieve(string $question): array
    {
        $policy = app(SmartBotRetrievalPolicy::class)->privateAnswering();

        return app(KnowledgeRetrievalService::class)->retrieve(
            $this->kb, $this->workspaceId, $question, [], $policy['max_context_chunks'], null, $policy['answer_threshold'], $policy['max_context_tokens'],
        );
    }

    private function runBot(string $question, bool $trustedResearch = false, ?Conversation $conversation = null): array
    {
        $bot = AiChatbot::create([
            'workspace_id' => $this->workspaceId,
            'name' => 'Support Bot',
            'ai_kb_id' => $this->kb->id,
            'enabled' => true,
            'channels' => ['webchat'],
            'trusted_research_enabled' => $trustedResearch,
        ]);
        $conversation ??= Conversation::create(['workspace_id' => $this->workspaceId, 'contact_id' => Contact::factory()->create(['workspace_id' => $this->workspaceId])->id, 'status' => 'open']);
        $message = new Message;
        $message->body = $question;
        $message->direction = 'in';
        $message->channel = 'playground';
        $message->setRelation('conversation', $conversation);

        return app(ChatbotRunner::class)->run($bot, $message);
    }
}
