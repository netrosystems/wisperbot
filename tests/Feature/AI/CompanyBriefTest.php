<?php

namespace Tests\Feature\AI;

use App\Modules\AI\Jobs\DraftCompanyBriefJob;
use App\Modules\AI\Models\AiChatbot;
use App\Modules\AI\Models\AiCreditLedger;
use App\Modules\AI\Models\AiKbChunk;
use App\Modules\AI\Models\AiKbDocument;
use App\Modules\AI\Models\AiKnowledgeBase;
use App\Modules\AI\Models\AiProviderConfig;
use App\Modules\AI\Services\Agent\PromptBuilder;
use App\Modules\AI\Services\BusinessAwareTurnRouter;
use App\Modules\AI\Services\CompanyBriefService;
use App\Modules\AI\Services\EmbeddingStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The company brief beside a Knowledge Base's business profile (Smart Bot
 * 2.0, Phase 1.3): drafted from live sources, checked sentence by sentence,
 * used by the Smart Bot only once the client approves it.
 */
class CompanyBriefTest extends TestCase
{
    use RefreshDatabase;

    private const ABOUT = 'Telzen sells travel eSIM data plans for more than 150 countries. Plans start at 4 USD. Support is available by chat at https://telzen.example/help.';

    public function test_a_draft_keeps_sentences_with_real_sources_and_flags_invented_details(): void
    {
        [$kb] = $this->knowledgeBase();
        $this->fakeChat(['sentences' => [
            ['text' => 'Telzen sells travel eSIM data plans for more than 150 countries.', 'source' => 1],
            ['text' => 'Plans start at 2 USD.', 'source' => 1],
            ['text' => 'Write to sales@telzen.example for discounts.', 'source' => 1],
            ['text' => 'This sentence cites a source that does not exist.', 'source' => 9],
        ]]);

        app(CompanyBriefService::class)->draft($kb, null);

        $kb->refresh();
        $this->assertSame(AiKnowledgeBase::BRIEF_DRAFT, $kb->company_brief_status);
        $this->assertSame([true, false, false], array_column($kb->company_brief_draft, 'supported'));
        $this->assertSame('About Telzen', $kb->company_brief_draft[0]['source_title']);
        $this->assertNull($kb->approvedBrief());
        $this->assertSame('kb_company_brief', AiCreditLedger::sole()->feature);
    }

    public function test_the_client_drafts_approves_redrafts_and_removes_through_the_page(): void
    {
        [$kb, $user] = $this->knowledgeBase();
        Queue::fake();

        $this->actingAs($user)->post(route('client.ai.knowledge-bases.company-brief.draft', $kb->uuid))->assertRedirect();
        Queue::assertPushedOn('ai', DraftCompanyBriefJob::class);
        $this->assertSame(AiKnowledgeBase::BRIEF_DRAFTING, $kb->fresh()->company_brief_status);

        $this->actingAs($user)->post(route('client.ai.knowledge-bases.company-brief.approve', $kb->uuid), [
            'sentences' => ['Telzen sells travel eSIM data plans.', '  ', 'Plans start at 4 USD.'],
        ])->assertRedirect();
        $this->assertSame('Telzen sells travel eSIM data plans. Plans start at 4 USD.', $kb->fresh()->approvedBrief());

        // A new draft waiting for review never switches the approved brief off.
        $kb->forceFill(['company_brief_status' => AiKnowledgeBase::BRIEF_DRAFT, 'company_brief_draft' => [['text' => 'New', 'document_id' => 1, 'source_title' => 'x', 'supported' => true]]])->save();
        $this->assertSame('Telzen sells travel eSIM data plans. Plans start at 4 USD.', $kb->fresh()->approvedBrief());
        $this->actingAs($user)->delete(route('client.ai.knowledge-bases.company-brief.destroy', ['kb' => $kb->uuid, 'draft' => 1]))->assertRedirect();
        $this->assertNull($kb->fresh()->company_brief_draft);
        $this->assertNotNull($kb->fresh()->approvedBrief());

        $this->actingAs($user)->post(route('client.ai.knowledge-bases.company-brief.approve', $kb->uuid), ['text' => 'Telzen sells eSIMs.'])->assertRedirect();
        $this->assertSame('Telzen sells eSIMs.', $kb->fresh()->approvedBrief());

        $this->actingAs($user)->delete(route('client.ai.knowledge-bases.company-brief.destroy', $kb->uuid))->assertRedirect();
        $this->assertNull($kb->fresh()->approvedBrief());
    }

    public function test_another_workspace_cannot_touch_the_brief(): void
    {
        [$kb] = $this->knowledgeBase();
        $other = $this->createWorkspaceContext()['user'];

        $this->actingAs($other)->post(route('client.ai.knowledge-bases.company-brief.draft', $kb->uuid))->assertForbidden();
        $this->actingAs($other)->post(route('client.ai.knowledge-bases.company-brief.approve', $kb->uuid), ['text' => 'Hijacked'])->assertForbidden();
        $this->assertNull($kb->fresh()->approvedBrief());
    }

    public function test_a_failed_draft_says_why_and_keeps_the_approved_brief(): void
    {
        [$kb] = $this->knowledgeBase();
        $kb->forceFill(['company_brief' => 'Telzen sells eSIMs.', 'company_brief_approved_at' => now(), 'company_brief_status' => AiKnowledgeBase::BRIEF_DRAFTING])->save();
        $this->fakeChat(['sentences' => []]);

        (new DraftCompanyBriefJob($kb->id, null))->handle(app(CompanyBriefService::class));

        $kb->refresh();
        $this->assertSame(AiKnowledgeBase::BRIEF_FAILED, $kb->company_brief_status);
        $this->assertStringContainsString('usable brief', $kb->company_brief_error);
        $this->assertSame('Telzen sells eSIMs.', $kb->approvedBrief());
        $this->assertSame('refunded', AiCreditLedger::sole()->status);
    }

    public function test_only_an_approved_brief_reaches_the_smart_bot(): void
    {
        [$kb] = $this->knowledgeBase();
        $bot = AiChatbot::create(['workspace_id' => $kb->workspace_id, 'name' => 'Support Bot', 'ai_kb_id' => $kb->id]);
        $kb->forceFill(['company_brief' => 'Telzen has served travellers since 2019.'])->save();

        $this->assertStringNotContainsString('since 2019', app(BusinessAwareTurnRouter::class)->profileText($kb));
        $this->assertStringNotContainsString('since 2019', app(PromptBuilder::class)->build($bot, $kb, 'balanced', [], null, 70, null)['static']);

        $kb->forceFill(['company_brief_approved_at' => now()])->save();

        $this->assertStringContainsString('since 2019', app(BusinessAwareTurnRouter::class)->profileText($kb));
        $this->assertStringContainsString("Company brief (written and approved by the business):\nTelzen has served travellers since 2019.", app(PromptBuilder::class)->build($bot, $kb, 'balanced', [], null, 70, null)['static']);
    }

    /** @return array{0:AiKnowledgeBase,1:mixed} */
    private function knowledgeBase(): array
    {
        $context = $this->createWorkspaceContext();
        $workspaceId = $context['workspace']->id;
        $kb = AiKnowledgeBase::create([
            'workspace_id' => $workspaceId, 'name' => 'Travel eSIM knowledge', 'brand' => 'Telzen',
            'purpose' => 'Sells travel eSIM data plans for international trips', 'audience' => 'Travellers',
            'embedding_model' => 'text-embedding-3-small', 'dimensions' => 3, 'status' => 'active',
        ]);
        $document = AiKbDocument::create([
            'kb_id' => $kb->id, 'title' => 'About Telzen', 'source_type' => 'url', 'source_ref' => 'https://telzen.example/about',
            'status' => 'indexed', 'review_status' => 'approved', 'publication_status' => 'published',
        ]);
        $chunk = AiKbChunk::create([
            'kb_id' => $kb->id, 'document_id' => $document->id, 'ord' => 0, 'content' => self::ABOUT,
            'tokens' => 30, 'index_generation' => 'legacy', 'embedding_status' => 'ready',
        ]);
        app(EmbeddingStore::class)->storeEmbedding($chunk, [1.0, 0.0, 0.0]);
        AiProviderConfig::create([
            'workspace_id' => $workspaceId, 'provider' => 'openai', 'credentials' => ['api_key' => 'sk-test'],
            'default_model_chat' => 'gpt-4o-mini', 'default_model_embed' => 'text-embedding-3-small',
            'enabled' => true, 'last_tested_at' => now(), 'last_test_succeeded_at' => now(),
        ]);

        return [$kb, $context['user']];
    }

    /** @param array<string,mixed> $reply */
    private function fakeChat(array $reply): void
    {
        Http::fake(['api.openai.com/v1/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => json_encode($reply)], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 400, 'completion_tokens' => 120],
            'model' => 'gpt-4o-mini',
        ])]);
    }
}
