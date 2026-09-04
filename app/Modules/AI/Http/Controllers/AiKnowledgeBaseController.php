<?php

namespace App\Modules\AI\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\AI\Jobs\IndexDocumentJob;
use App\Modules\AI\Models\AiKbChunk;
use App\Modules\AI\Models\AiKbDocument;
use App\Modules\AI\Models\AiKbRevision;
use App\Modules\AI\Models\AiKbTestCase;
use App\Modules\AI\Models\AiKnowledgeBase;
use App\Modules\AI\Services\EmbeddingStore;
use App\Modules\AI\Services\KnowledgeBaseTestService;
use App\Modules\AI\Services\KnowledgeBaseWorkflowService;
use App\Modules\AI\Services\KnowledgeUrlGuard;
use App\Modules\AI\Services\LlmGateway;
use App\Modules\AI\Services\VideoResourceService;
use App\Services\StorageManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class AiKnowledgeBaseController extends Controller
{
    public function __construct(
        private StorageManager $storage,
        private EmbeddingStore $embeddings,
        private VideoResourceService $videos,
        private KnowledgeBaseWorkflowService $workflow,
        private KnowledgeBaseTestService $tests,
        private KnowledgeUrlGuard $urls,
        private LlmGateway $llm,
    ) {}

    public function index(Request $request): Response
    {
        $workspaceId = $request->user()->current_workspace_id ?? $request->user()->workspace_id;
        $kbs = AiKnowledgeBase::where('workspace_id', $workspaceId)
            ->with(['documents', 'publishedRevision', 'chatbots:id,name,ai_kb_id'])
            ->withCount('documents')->latest()->get()
            ->each(fn (AiKnowledgeBase $kb) => $kb->setAttribute('health', $this->workflow->health($kb)));

        return Inertia::render('AI/KnowledgeBases/Index', ['knowledgeBases' => $kbs]);
    }

    public function show(Request $request, AiKnowledgeBase $kb): Response
    {
        $this->authorise($request, $kb);
        $revisionId = $kb->draft_revision_id ?: $kb->published_revision_id;
        $visibleDocumentIds = $revisionId
            ? AiKbRevision::where('kb_id', $kb->id)->whereKey($revisionId)->first()?->documents()->pluck('ai_kb_documents.id')->all()
            : $kb->documents()->pluck('id')->all();
        $kb->load([
            'documents' => fn ($query) => $query->whereIn('id', $visibleDocumentIds)->withCount('chunks')->latest(),
            'revisions' => fn ($query) => $query->latest('version'),
            'testCases',
            'chatbots:id,name,ai_kb_id,enabled',
            'publishedRevision',
            'draftRevision',
            'knowledgeGaps' => fn ($query) => $query->where('status', 'open')->latest('last_seen_at')->limit(25),
        ]);

        $kbUploadMaxKb = $this->kbUploadMaxKb();

        return Inertia::render('AI/KnowledgeBases/Show', [
            'kb' => $kb,
            'kbUploadMaxKb' => $kbUploadMaxKb,
            'kbUploadMaxMb' => round($kbUploadMaxKb / 1024, 1),
            'health' => $this->workflow->health($kb),
            'guardedPublishing' => (bool) config('knowledge_base.guarded_publishing'),
            'efficiency' => $this->efficiency($kb),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $workspaceId = $request->user()->current_workspace_id ?? $request->user()->workspace_id;
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:128'],
            'purpose' => ['nullable', 'string', 'max:2000'],
            'language' => ['nullable', 'string', 'max:16'],
            'brand' => ['nullable', 'string', 'max:128'],
            'audience' => ['nullable', 'string', 'max:256'],
        ]);
        $kb = AiKnowledgeBase::create(array_merge($validated, ['workspace_id' => $workspaceId]));
        $this->workflow->createInitialDraft($kb, $request->user()->id);

        return to_route('client.ai.knowledge-bases.show', $kb)->with('success', 'Knowledge Base created. Add your first trusted source.');
    }

    public function update(Request $request, AiKnowledgeBase $kb): RedirectResponse
    {
        $this->authorise($request, $kb);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:128'],
            'purpose' => ['nullable', 'string', 'max:2000'],
            'language' => ['nullable', 'string', 'max:16'],
            'brand' => ['nullable', 'string', 'max:128'],
            'audience' => ['nullable', 'string', 'max:256'],
        ]);
        $kb->update($validated);

        return back()->with('success', 'Knowledge base updated.');
    }

    public function destroy(Request $request, AiKnowledgeBase $kb): RedirectResponse
    {
        $this->authorise($request, $kb);

        $documents = $kb->documents()->get(['id', 'source_type', 'source_ref']);
        $documentIds = $documents->pluck('id')->all();
        $filePaths = $documents
            ->where('source_type', 'file')
            ->pluck('source_ref')
            ->filter()
            ->values()
            ->all();

        // Qdrant is external to the database, so remove its vectors before the
        // relational records. A failed vector cleanup leaves the KB intact and
        // allows the client to safely retry the deletion.
        foreach ($documentIds as $documentId) {
            $this->embeddings->deleteDocumentEmbeddings($documentId);
        }

        DB::transaction(function () use ($kb, $documentIds): void {
            // Existing chatbots continue in prompt-only mode after their KB is gone.
            $kb->chatbots()->update(['ai_kb_id' => null]);

            $chunks = AiKbChunk::query()->where('kb_id', $kb->id);
            if ($documentIds !== []) {
                $chunks->orWhereIn('document_id', $documentIds);
            }
            $chunks->delete();

            $kb->documents()->delete();
            $kb->delete();
        });

        $this->deleteStoredFiles($filePaths);

        return to_route('client.ai.knowledge-bases.index')
            ->with('success', 'Knowledge base deleted.');
    }

    public function addDocument(Request $request, AiKnowledgeBase $kb): RedirectResponse
    {
        $this->authorise($request, $kb);

        $sourceType = (string) $request->input('source_type');
        if (in_array($sourceType, ['url', 'sitemap'], true)) {
            $request->merge([
                'source_ref' => $this->normaliseSourceUrl((string) $request->input('source_ref')),
            ]);
        }
        if (config('knowledge_base.guarded_publishing') && in_array($sourceType, ['url', 'sitemap'], true)) {
            try {
                $request->merge(['source_ref' => $this->urls->assertSafe((string) $request->input('source_ref'))]);
            } catch (\InvalidArgumentException $exception) {
                return back()->withErrors(['source_ref' => $exception->getMessage()]);
            }
        }

        $validated = $request->validate([
            'source_type' => ['required', 'in:file,url,text,sitemap,faq,video'],
            'source_ref' => $this->sourceRefRules((string) $request->input('source_type')),
            'title' => [$sourceType === 'video' ? 'required' : 'nullable', 'string', 'max:256'],
            'video_url' => [$sourceType === 'video' ? 'required' : 'nullable', 'string', 'max:2048'],
            'video_transcript' => [$sourceType === 'video' ? 'required' : 'nullable', 'string', 'max:200000'],
            'thumbnail_url' => ['nullable', 'string', 'max:2048'],
            'trigger_phrases' => ['nullable', 'string', 'max:4000'],
            'authoritative' => ['nullable', 'boolean'],
            'priority' => ['nullable', 'integer', 'min:0', 'max:100'],
        ]);

        if ($sourceType === 'video') {
            $validated['resource_json'] = $this->videos->normalise(
                $validated['video_url'],
                $validated['title'],
                $validated['thumbnail_url'] ?? null,
            );
            $validated['resource_json']['transcript'] = $validated['video_transcript'];
            $validated['resource_json']['trigger_phrases'] = $validated['trigger_phrases'] ?? '';
            $validated['source_ref'] = $this->videoIndexText($validated);
        }
        unset($validated['video_url'], $validated['video_transcript'], $validated['thumbnail_url'], $validated['trigger_phrases']);

        // Handle file upload
        if ($request->hasFile('file')) {
            $request->validate([
                'file' => [
                    'file',
                    'max:'.$this->kbUploadMaxKb(),
                    'mimes:pdf,docx,txt,md',
                ],
            ]);
            $file = $request->file('file');
            $diskName = $this->storage->diskName();
            $path = $this->storage->prefixedPath('kb-docs/'.$file->hashName());
            $this->storage->disk()->putFileAs(dirname($path), $file, basename($path));
            $validated['source_ref'] = $path;
            $validated['title'] = $validated['title'] ?? $file->getClientOriginalName();
        }

        $activeRevision = $kb->draftRevision()->first() ?? $kb->publishedRevision()->first();
        $activeDocumentIds = $activeRevision?->documents()->pluck('ai_kb_documents.id') ?? collect();
        $duplicate = AiKbDocument::where('kb_id', $kb->id)->whereIn('id', $activeDocumentIds)
            ->where('source_type', $sourceType)
            ->where('source_ref', $validated['source_ref'] ?? null)->exists();
        if ($duplicate && $sourceType !== 'text' && $sourceType !== 'faq') {
            return back()->withErrors(['source_ref' => 'This source is already in the Knowledge Base. Refresh the existing source instead.']);
        }

        $doc = AiKbDocument::create(array_merge($validated, [
            'kb_id' => $kb->id,
            'status' => 'pending',
            'review_status' => 'needs_review',
            'publication_status' => config('knowledge_base.guarded_publishing') ? 'draft' : 'published',
        ]));
        $this->workflow->attachToDraft($doc, $request->user()->id);

        try {
            IndexDocumentJob::dispatch($doc->id)->onQueue('ai');
        } catch (\RuntimeException $e) {
            return back()->withErrors(['source_type' => $e->getMessage()]);
        }

        return back()->with('success', 'Document queued for indexing.');
    }

    public function updateDocument(Request $request, AiKbDocument $document): RedirectResponse
    {
        $kb = $document->load('knowledgeBase')->knowledgeBase;
        $this->authorise($request, $kb);
        abort_unless(in_array($document->source_type, ['video', 'text', 'faq'], true), 422);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:256'],
            'source_ref' => [$document->source_type === 'video' ? 'nullable' : 'required', 'string', 'max:200000'],
            'video_url' => [$document->source_type === 'video' ? 'required' : 'nullable', 'string', 'max:2048'],
            'video_transcript' => [$document->source_type === 'video' ? 'required' : 'nullable', 'string', 'max:200000'],
            'thumbnail_url' => ['nullable', 'string', 'max:2048'],
            'trigger_phrases' => ['nullable', 'string', 'max:4000'],
        ]);
        $sourceRef = $validated['source_ref'] ?? '';
        $resource = $document->resource_json;
        if ($document->source_type === 'video') {
            $resource = $this->videos->normalise($validated['video_url'], $validated['title'], $validated['thumbnail_url'] ?? null);
            $resource['transcript'] = $validated['video_transcript'];
            $resource['trigger_phrases'] = $validated['trigger_phrases'] ?? '';
            $sourceRef = $this->videoIndexText($validated);
        }

        $target = $this->workflow->editableDocument($document, $request->user()->id);
        $target->update([
            'title' => $validated['title'],
            'source_ref' => $sourceRef,
            'resource_json' => $resource,
            'status' => 'pending',
            'review_status' => 'needs_review',
            'publication_status' => config('knowledge_base.guarded_publishing') ? 'draft' : 'published',
            'error_message' => null,
        ]);
        IndexDocumentJob::dispatch($target->id)->onQueue('ai');

        return back()->with('success', 'Video updated and queued for indexing.');
    }

    public function reindex(Request $request, AiKbDocument $document): RedirectResponse
    {
        $kb = $document->load('knowledgeBase')->knowledgeBase;
        $this->authorise($request, $kb);
        $target = $this->workflow->editableDocument($document, $request->user()->id);
        $target->update(['status' => 'pending', 'error_message' => null, 'review_status' => 'needs_review']);

        try {
            IndexDocumentJob::dispatch($target->id)->onQueue('ai');
        } catch (\RuntimeException $e) {
            return back()->withErrors(['document' => $e->getMessage()]);
        }

        return back()->with('success', 'Re-indexing queued.');
    }

    public function destroyDocument(Request $request, AiKbDocument $document): RedirectResponse
    {
        $kb = $document->load('knowledgeBase')->knowledgeBase;
        $this->authorise($request, $kb);
        $filePath = $document->source_type === 'file' ? $document->source_ref : null;

        if (config('knowledge_base.guarded_publishing')) {
            $this->workflow->removeFromDraft($document, $request->user()->id);

            return back()->with('success', 'Source removed from the next Knowledge Base revision.');
        }

        $this->embeddings->deleteDocumentEmbeddings($document->id);
        DB::transaction(function () use ($document): void {
            $document->chunks()->delete();
            $document->delete();
        });

        $this->deleteStoredFiles(array_filter([$filePath]));

        return back()->with('success', 'Document removed.');
    }

    public function approveDocument(Request $request, AiKbDocument $document): RedirectResponse
    {
        $this->authorise($request, $document->load('knowledgeBase')->knowledgeBase);
        $this->workflow->approve($document, $request->user()->id);
        $this->workflow->attemptAutoPublish($document->knowledgeBase);

        return back()->with('success', 'Source approved.');
    }

    public function rejectDocument(Request $request, AiKbDocument $document): RedirectResponse
    {
        $this->authorise($request, $document->load('knowledgeBase')->knowledgeBase);
        $this->workflow->reject($document, $request->user()->id);

        return back()->with('success', 'Source rejected and disabled.');
    }

    public function toggleDocument(Request $request, AiKbDocument $document): RedirectResponse
    {
        $this->authorise($request, $document->load('knowledgeBase')->knowledgeBase);
        $willEnable = ! $document->enabled;
        $target = $this->workflow->editableDocument($document, $request->user()->id);
        $target->update($willEnable ? [
            'enabled' => true,
            'status' => 'pending',
            'review_status' => 'needs_review',
            'error_message' => null,
        ] : [
            'enabled' => false,
            // Disabled sources are intentionally omitted from retrieval and do
            // not need a fresh embedding to be safely published.
            'status' => 'indexed',
            'review_status' => 'approved',
            'error_message' => null,
        ]);

        if ($willEnable) {
            IndexDocumentJob::dispatch($target->id)->onQueue('ai');
        }

        return back()->with('success', $target->enabled ? 'Source enabled in the next revision.' : 'Source disabled in the next revision.');
    }

    public function publish(Request $request, AiKnowledgeBase $kb): RedirectResponse
    {
        $this->authorise($request, $kb);
        $revision = $this->workflow->publish($kb);

        return back()->with('success', 'Knowledge Base revision '.$revision->version.' published.');
    }

    public function rollback(Request $request, AiKnowledgeBase $kb, AiKbRevision $revision): RedirectResponse
    {
        $this->authorise($request, $kb);
        $this->workflow->rollback($kb, $revision);

        return back()->with('success', 'Knowledge Base rolled back to revision '.$revision->version.'.');
    }

    public function testQuery(Request $request, AiKnowledgeBase $kb): JsonResponse
    {
        $this->authorise($request, $kb);
        $validated = $request->validate(['question' => ['required', 'string', 'max:1000']]);

        return response()->json($this->tests->test($kb, $validated['question']));
    }

    public function storeTestCase(Request $request, AiKnowledgeBase $kb): RedirectResponse
    {
        $this->authorise($request, $kb);
        $validated = $request->validate([
            'question' => ['required', 'string', 'max:1000'],
            'expected_facts' => ['nullable', 'string', 'max:4000'],
            'expected_document_id' => ['nullable', 'integer'],
            'critical' => ['nullable', 'boolean'],
        ]);
        if (! empty($validated['expected_document_id'])) {
            abort_unless(AiKbDocument::where('kb_id', $kb->id)->whereKey($validated['expected_document_id'])->exists(), 422);
        }
        $kb->testCases()->create($validated);

        return back()->with('success', 'Test question added.');
    }

    public function destroyTestCase(Request $request, AiKnowledgeBase $kb, AiKbTestCase $testCase): RedirectResponse
    {
        $this->authorise($request, $kb);
        abort_unless((int) $testCase->kb_id === (int) $kb->id, 403);
        $testCase->delete();

        return back()->with('success', 'Test question removed.');
    }

    public function suggestCorrection(Request $request, AiKbDocument $document): JsonResponse
    {
        $kb = $document->load('knowledgeBase')->knowledgeBase;
        $this->authorise($request, $kb);
        abort_if(trim((string) $document->extracted_content) === '', 422, 'Extract this source before requesting a correction.');
        $response = $this->llm->chat((int) $kb->workspace_id, [[
            'role' => 'system',
            'content' => 'Improve grammar, clarity, headings, and structure. Preserve every factual value exactly. Never add facts. Return only the corrected document.',
        ], [
            'role' => 'user',
            'content' => mb_substr((string) $document->extracted_content, 0, 20_000),
        ]], [
            'feature' => 'short_rewrite',
            'idempotency_key' => (string) ($request->header('Idempotency-Key') ?: 'kb-correction:'.$document->id.':'.hash('sha256', (string) $document->content_hash)),
            'max_tokens' => 2000,
        ]);

        return response()->json(['suggestion' => $response->content, 'original' => $document->extracted_content]);
    }

    private function deleteStoredFiles(array $paths): void
    {
        if ($paths === []) {
            return;
        }

        try {
            $this->storage->disk()->delete($paths);
        } catch (\Throwable $exception) {
            Log::warning('Knowledge base file cleanup failed after database deletion.', [
                'file_count' => count($paths),
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function efficiency(AiKnowledgeBase $kb): array
    {
        $diagnostics = $kb->retrievalDiagnostics()->where('created_at', '>=', now()->subDays(30));
        $total = (clone $diagnostics)->count();
        $cached = (clone $diagnostics)->whereNotNull('cache_source')->count();

        return [
            'queries' => $total,
            'cache_hit_rate' => $total > 0 ? (int) round(($cached / $total) * 100) : 0,
            'context_tokens' => (int) (clone $diagnostics)->sum('context_tokens'),
            'handoffs' => (int) (clone $diagnostics)->where('decision', 'handoff')->count(),
        ];
    }

    private function videoIndexText(array $data): string
    {
        return trim("Title: {$data['title']}\nDescription or transcript:\n{$data['video_transcript']}\nTrigger phrases:\n".($data['trigger_phrases'] ?? ''));
    }

    private function authorise(Request $request, AiKnowledgeBase $kb): void
    {
        $workspaceId = $request->user()->current_workspace_id ?? $request->user()->workspace_id;
        abort_unless((int) $kb->workspace_id === (int) $workspaceId, 403);
    }

    private function kbUploadMaxKb(): int
    {
        $appMaxKb = 20 * 1024;
        $serverMaxKb = min(
            $this->iniSizeToKb(ini_get('upload_max_filesize')),
            $this->iniSizeToKb(ini_get('post_max_size')),
        );

        // Leave room for multipart form overhead so a file at the exact PHP
        // limit does not get rejected by the web server before Laravel sees it.
        $serverMaxKb = max(1024, $serverMaxKb - 512);

        return min($appMaxKb, $serverMaxKb);
    }

    private function iniSizeToKb(string|false $value): int
    {
        if ($value === false || trim($value) === '') {
            return PHP_INT_MAX;
        }

        $value = trim($value);
        $unit = strtolower(substr($value, -1));
        $number = (float) $value;

        return match ($unit) {
            'g' => (int) ($number * 1024 * 1024),
            'm' => (int) ($number * 1024),
            'k' => (int) $number,
            default => (int) ceil($number / 1024),
        };
    }

    private function sourceRefRules(string $sourceType): array
    {
        return match ($sourceType) {
            'url', 'sitemap' => ['required', 'url', 'max:2048'],
            'text' => ['required', 'string', 'max:200000'],
            'faq' => ['required', 'string', 'max:200000'],
            'file' => ['nullable', 'string', 'max:512'],
            default => ['nullable', 'string', 'max:512'],
        };
    }

    private function normaliseSourceUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '' || preg_match('/^[a-z][a-z0-9+.-]*:\/\//i', $url)) {
            return $url;
        }

        return 'https://'.$url;
    }
}
