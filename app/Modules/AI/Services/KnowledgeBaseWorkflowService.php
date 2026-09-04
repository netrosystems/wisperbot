<?php

namespace App\Modules\AI\Services;

use App\Modules\AI\Models\AiKbAnswerCache;
use App\Modules\AI\Models\AiKbDocument;
use App\Modules\AI\Models\AiKbRevision;
use App\Modules\AI\Models\AiKnowledgeBase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class KnowledgeBaseWorkflowService
{
    public function __construct(private readonly KnowledgeBaseTestService $tests) {}

    public function createInitialDraft(AiKnowledgeBase $kb, ?int $actorId = null): AiKbRevision
    {
        $revision = $kb->revisions()->create([
            'version' => 1,
            'status' => 'draft',
            'created_by' => $actorId,
        ]);
        $kb->update(['draft_revision_id' => $revision->id]);

        return $revision;
    }

    public function draft(AiKnowledgeBase $kb, ?int $actorId = null): AiKbRevision
    {
        $kb->refresh();
        if ($kb->draft_revision_id) {
            return AiKbRevision::where('kb_id', $kb->id)->findOrFail($kb->draft_revision_id);
        }

        return DB::transaction(function () use ($kb, $actorId): AiKbRevision {
            $version = ((int) $kb->revisions()->max('version')) + 1;
            $revision = $kb->revisions()->create([
                'version' => max(1, $version),
                'status' => 'draft',
                'created_by' => $actorId,
            ]);
            if ($kb->published_revision_id) {
                $published = AiKbRevision::where('kb_id', $kb->id)->find($kb->published_revision_id);
                if ($published) {
                    $revision->documents()->sync($published->documents()->pluck('ai_kb_documents.id'));
                }
            }
            $kb->update(['draft_revision_id' => $revision->id]);

            return $revision;
        });
    }

    public function attachToDraft(AiKbDocument $document, ?int $actorId = null): AiKbRevision
    {
        $revision = $this->draft($document->knowledgeBase, $actorId);
        $revision->documents()->syncWithoutDetaching([$document->id]);

        return $revision;
    }

    /**
     * Return a document that can be safely changed in the draft revision.
     *
     * Published revisions are immutable. A source inherited by a new draft is
     * therefore copied before reindexing, toggling, or editing it. The copy is
     * deliberately left without chunks: the caller must reindex it when the
     * source remains enabled.
     */
    public function editableDocument(AiKbDocument $document, ?int $actorId = null): AiKbDocument
    {
        if (! config('knowledge_base.guarded_publishing')) {
            return $document;
        }

        if ($document->publication_status !== 'published') {
            $this->attachToDraft($document, $actorId);

            return $document;
        }

        return DB::transaction(function () use ($document, $actorId): AiKbDocument {
            $revision = $this->draft($document->knowledgeBase, $actorId);
            $copy = $document->replicate();
            $copy->uuid = (string) Str::uuid();
            $copy->publication_status = 'draft';
            $copy->status = 'pending';
            $copy->review_status = 'needs_review';
            $copy->quality_score = 0;
            $copy->quality_findings = [];
            $copy->reviewed_by = null;
            $copy->reviewed_at = null;
            $copy->last_indexed_at = null;
            $copy->error_message = null;
            $copy->tokens = 0;
            $copy->save();

            $revision->documents()->detach($document->id);
            $revision->documents()->syncWithoutDetaching([$copy->id]);

            return $copy;
        });
    }

    public function approve(AiKbDocument $document, int $actorId): void
    {
        abort_if($document->review_status === 'blocked', 422, 'Resolve all blocking findings before approval.');
        $document->update([
            'review_status' => 'approved',
            'reviewed_by' => $actorId,
            'reviewed_at' => now(),
        ]);
    }

    public function reject(AiKbDocument $document, int $actorId): void
    {
        $document->update([
            'review_status' => 'rejected',
            'enabled' => false,
            'reviewed_by' => $actorId,
            'reviewed_at' => now(),
        ]);
    }

    public function removeFromDraft(AiKbDocument $document, ?int $actorId = null): void
    {
        $revision = $this->draft($document->knowledgeBase, $actorId);
        $revision->documents()->detach($document->id);
        if ($document->publication_status !== 'published') {
            $document->chunks()->delete();
            $document->delete();
        }
    }

    public function canPublish(AiKbRevision $revision): array
    {
        $documents = $revision->documents()->get();
        $eligible = $documents->filter(fn (AiKbDocument $document) => $document->enabled && in_array($document->review_status, ['auto_approved', 'approved'], true));
        $blocked = $documents->filter(fn (AiKbDocument $document) => in_array($document->review_status, ['blocked', 'needs_review'], true));
        $notReady = $eligible->filter(fn (AiKbDocument $document) => $document->status !== 'indexed' || ! $document->chunks()->where('embedding_status', 'ready')->exists());

        return [
            'allowed' => $eligible->isNotEmpty() && $blocked->isEmpty() && $notReady->isEmpty(),
            'eligible' => $eligible->count(),
            'blocked' => $blocked->count(),
            'not_ready' => $notReady->count(),
        ];
    }

    public function publish(AiKnowledgeBase $kb, bool $automatic = false): AiKbRevision
    {
        $revision = $kb->draftRevision()->with('documents')->firstOrFail();
        $readiness = $this->canPublish($revision);
        abort_unless($readiness['allowed'], 422, 'Resolve source warnings and indexing problems before publishing.');

        $testSummary = $this->tests->runRevision($kb, $revision);
        abort_unless($testSummary['passed'], 422, 'Knowledge Base regression tests must pass before publishing.');

        return DB::transaction(function () use ($kb, $revision, $testSummary): AiKbRevision {
            if ($kb->published_revision_id) {
                AiKbRevision::whereKey($kb->published_revision_id)->update(['status' => 'superseded']);
            }
            $revision->update([
                'status' => 'published',
                'readiness_score' => 100,
                'regression_status' => $testSummary['status'],
                'published_at' => now(),
            ]);
            $revision->documents()->update(['publication_status' => 'published']);
            $kb->update([
                'published_revision_id' => $revision->id,
                'draft_revision_id' => null,
                'readiness_score' => 100,
                'regression_status' => $testSummary['status'],
                'last_published_at' => now(),
                'status' => 'active',
            ]);
            AiKbAnswerCache::where('workspace_id', $kb->workspace_id)->where('expires_at', '>', now())->delete();

            return $revision->fresh();
        });
    }

    public function attemptAutoPublish(AiKnowledgeBase $kb): bool
    {
        if (! config('knowledge_base.guarded_publishing')) {
            return false;
        }
        // A clean source is not the same as a client-approved answer. Automatic
        // publishing is allowed only when the client has saved regression tests;
        // first-time setup and untested updates stay in the guided review flow.
        if (! $kb->testCases()->exists()) {
            return false;
        }
        $revision = $kb->draftRevision()->first();
        if (! $revision || ! $this->canPublish($revision)['allowed']) {
            return false;
        }

        try {
            $this->publish($kb, true);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function rollback(AiKnowledgeBase $kb, AiKbRevision $revision): void
    {
        abort_unless((int) $revision->kb_id === (int) $kb->id && $revision->status === 'superseded', 422);
        DB::transaction(function () use ($kb, $revision): void {
            if ($kb->published_revision_id) {
                AiKbRevision::whereKey($kb->published_revision_id)->update(['status' => 'superseded']);
            }
            $revision->update(['status' => 'published', 'published_at' => now()]);
            $kb->update([
                'published_revision_id' => $revision->id,
                'draft_revision_id' => null,
                'readiness_score' => $revision->readiness_score,
                'regression_status' => $revision->regression_status,
                'last_published_at' => now(),
            ]);
            AiKbAnswerCache::where('workspace_id', $kb->workspace_id)->delete();
        });
    }

    public function health(AiKnowledgeBase $kb): array
    {
        $revision = $kb->draftRevision()->with('documents')->first()
            ?? $kb->publishedRevision()->with('documents')->first();
        $documents = $revision?->documents ?? $kb->documents;
        $ready = $documents->where('status', 'indexed')->whereIn('review_status', ['approved', 'auto_approved'])->count();
        $warning = $documents->where('review_status', 'needs_review')->count();
        $blocked = $documents->where('review_status', 'blocked')->count();
        $failed = $documents->whereIn('status', ['degraded', 'error'])->count();
        $total = max(1, $documents->count());
        $readiness = $documents->isEmpty() ? 0 : (int) max(0, min(100, round((($ready / $total) * 100) - ($blocked * 25) - ($failed * 15))));

        return [
            'ready' => $ready,
            'warning' => $warning,
            'blocked' => $blocked,
            'failed' => $failed,
            'readiness' => $readiness,
        ];
    }
}
