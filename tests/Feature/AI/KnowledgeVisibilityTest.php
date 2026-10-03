<?php

namespace Tests\Feature\AI;

use App\Modules\AI\Models\AiKbChunk;
use App\Modules\AI\Models\AiKbDocument;
use App\Modules\AI\Models\AiKnowledgeBase;
use App\Modules\AI\Services\EmbeddingStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Without guarded publishing a source is live once read (2026-10-04). In
 * production a re-read left "Latest" indexed but "needs_review", and the
 * search silently skipped all of its passages.
 */
class KnowledgeVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_read_source_is_searched_whatever_its_review_state_without_guarded_publishing(): void
    {
        config()->set('knowledge_base.guarded_publishing', false);
        [$kb] = $this->source('needs_review', 'draft');

        $this->assertCount(1, app(EmbeddingStore::class)->search($kb->id, [1.0, 0.0, 0.0], 3));
        $this->assertSame(1, app(EmbeddingStore::class)->liveChunks($kb->id)->count());
    }

    public function test_guarded_publishing_still_waits_for_approval_and_a_disabled_source_is_never_searched(): void
    {
        config()->set('knowledge_base.guarded_publishing', true);
        [$kb] = $this->source('needs_review', 'published');
        $this->assertSame([], app(EmbeddingStore::class)->search($kb->id, [1.0, 0.0, 0.0], 3));

        config()->set('knowledge_base.guarded_publishing', false);
        [$kb2, $document] = $this->source('auto_approved', 'published');
        $document->update(['enabled' => false]);
        $this->assertSame([], app(EmbeddingStore::class)->search($kb2->id, [1.0, 0.0, 0.0], 3));
    }

    public function test_re_reading_keeps_the_source_live_without_guarded_publishing(): void
    {
        config()->set('knowledge_base.guarded_publishing', false);
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();
        [$kb, $document] = $this->source('auto_approved', 'published', $workspace->id);
        Queue::fake();

        $this->actingAs($user)->post(route('client.ai.documents.reindex', $document->uuid))->assertRedirect();

        $this->assertSame('auto_approved', $document->fresh()->review_status);
        $this->assertCount(1, app(EmbeddingStore::class)->search($kb->id, [1.0, 0.0, 0.0], 3));
    }

    /** @return array{0: AiKnowledgeBase, 1: AiKbDocument} */
    private function source(string $review, string $publication, ?int $workspaceId = null): array
    {
        $workspaceId ??= $this->createWorkspaceContext()['workspace']->id;
        $kb = AiKnowledgeBase::create(['workspace_id' => $workspaceId, 'name' => 'Telzen', 'embedding_model' => 'text-embedding-3-small', 'dimensions' => 3, 'status' => 'active']);
        $document = AiKbDocument::create([
            'kb_id' => $kb->id, 'title' => 'Latest', 'source_type' => 'text', 'source_ref' => 'Download the Telzen app to buy an eSIM.',
            'status' => 'indexed', 'enabled' => true, 'review_status' => $review, 'publication_status' => $publication, 'active_index_generation' => 'gen-1',
        ]);
        $chunk = AiKbChunk::create([
            'kb_id' => $kb->id, 'document_id' => $document->id, 'ord' => 0, 'content' => 'Download the Telzen app to buy an eSIM.',
            'tokens' => 10, 'index_generation' => 'gen-1', 'embedding_status' => 'ready',
        ]);
        app(EmbeddingStore::class)->storeEmbedding($chunk, [1.0, 0.0, 0.0]);

        return [$kb, $document];
    }
}
