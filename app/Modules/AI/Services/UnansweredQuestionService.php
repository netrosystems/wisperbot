<?php

namespace App\Modules\AI\Services;

use App\Models\User;
use App\Modules\AI\Jobs\IndexDocumentJob;
use App\Modules\AI\Models\AiChatbot;
use App\Modules\AI\Models\AiKbDocument;
use App\Modules\AI\Models\AiKbKnowledgeGap;
use App\Modules\AI\Models\AiKnowledgeBase;
use Illuminate\Support\Facades\DB;

/**
 * Questions customers asked that the Knowledge Base could not answer
 * (Smart Bot 2.0, Phase 1.5), kept in `ai_kb_knowledge_gaps`.
 *
 * Recorded for every Smart Bot turn that ended without an answer from the
 * knowledge (whatever the publishing mode), never from the playground or a
 * test run. The client sees them on the Knowledge Base page, most asked
 * first, and either writes the answer, which joins one FAQ source the
 * Knowledge Base keeps for these answers, or dismisses the question.
 *
 * Questions are customers' words: email addresses, phone numbers and long
 * numbers (orders, accounts) are replaced before anything is stored.
 */
class UnansweredQuestionService
{
    /** Marks the FAQ source that holds answers written from this list. */
    public const ANSWERS_SOURCE = 'wisperbot:answered-questions';

    public const MAX_ANSWER_CHARS = 4000;

    public function __construct(private readonly KnowledgeBaseWorkflowService $workflow) {}

    public function record(AiChatbot $bot, int $workspaceId, string $question, float $score): void
    {
        $sample = $this->scrub($question);
        $normalized = trim((string) preg_replace('/[^\p{L}\p{M}\p{N}]+/u', ' ', mb_strtolower($sample)));
        if (! $bot->ai_kb_id || mb_strlen($normalized) < 3) {
            return;
        }

        $gap = AiKbKnowledgeGap::firstOrNew(['kb_id' => $bot->ai_kb_id, 'question_hash' => hash('sha256', $normalized)]);
        $gap->fill([
            'workspace_id' => $workspaceId,
            'chatbot_id' => $bot->id,
            'question_sample' => mb_substr($sample, 0, 500),
            'occurrences' => $gap->exists ? $gap->occurrences + 1 : 1,
            'best_score' => $score,
            'decision' => 'handoff',
            // Answered or dismissed questions keep their status; the count
            // still shows they are asked.
            'status' => $gap->exists ? $gap->status : 'open',
            'last_seen_at' => now(),
        ])->save();
    }

    /** Answers an unanswered question and closes it. */
    public function answer(AiKnowledgeBase $kb, AiKbKnowledgeGap $gap, string $question, string $answer, User $user): AiKbDocument
    {
        return DB::transaction(function () use ($kb, $gap, $question, $answer, $user): AiKbDocument {
            $document = $this->addAnswer($kb, $question, $answer, $user);
            $gap->update(['status' => 'resolved']);

            return $document;
        });
    }

    /**
     * Adds a question and its answer to the Knowledge Base's answers source
     * (one FAQ source per Knowledge Base, written by the business). The
     * source is reindexed and, with guarded publishing, joins the draft
     * revision to publish. Used by the unanswered list and by "Improve" on a
     * bot reply in the inbox.
     */
    public function addAnswer(AiKnowledgeBase $kb, string $question, string $answer, User $user): AiKbDocument
    {
        return DB::transaction(function () use ($kb, $question, $answer, $user): AiKbDocument {
            $existing = AiKbDocument::where('kb_id', $kb->id)->where('source_type', 'faq')
                ->where('original_source_ref', self::ANSWERS_SOURCE)->latest('id')->lockForUpdate()->first();
            $guarded = (bool) config('knowledge_base.guarded_publishing');
            $document = $existing
                ? $this->workflow->editableDocument($existing, $user->id)
                : AiKbDocument::create([
                    'kb_id' => $kb->id,
                    'source_type' => 'faq',
                    'title' => 'Answers to customer questions',
                    'source_ref' => '[]',
                    'original_source_ref' => self::ANSWERS_SOURCE,
                    // Written by the business itself for exactly these questions.
                    'authoritative' => true,
                    'status' => 'pending',
                    'review_status' => 'needs_review',
                    'publication_status' => $guarded ? 'draft' : 'published',
                ]);
            if (! $existing) {
                $this->workflow->attachToDraft($document, $user->id);
            }

            $pairs = json_decode((string) $document->source_ref, true);
            $pairs = is_array($pairs) ? $pairs : [];
            $pairs[] = ['question' => trim($question), 'answer' => trim($answer)];
            $document->update([
                'source_ref' => json_encode(array_values($pairs), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'status' => 'pending',
                'review_status' => 'needs_review',
                'publication_status' => $guarded ? 'draft' : 'published',
                'error_message' => null,
            ]);
            IndexDocumentJob::dispatch($document->id)->onQueue('ai')->afterCommit();

            return $document;
        });
    }

    public function dismiss(AiKbKnowledgeGap $gap): void
    {
        $gap->update(['status' => 'ignored']);
    }

    /** A customer's question without the personal details it may carry. */
    public function scrub(string $question): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $question));
        $text = (string) preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/iu', '[email]', $text);
        $text = (string) preg_replace('/\+?\d[\d\s\-().]{6,}\d/u', '[phone]', $text);

        return (string) preg_replace('/\b[A-Z]{0,4}\d{6,}\b/iu', '[number]', $text);
    }
}
