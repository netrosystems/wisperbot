<?php

namespace App\Http\Controllers\Api\V1;

use App\Modules\AI\Jobs\IndexDocumentJob;
use App\Modules\AI\Models\AiKbDocument;
use App\Modules\AI\Models\AiKnowledgeBase;
use App\Modules\AI\Services\KnowledgeBaseTestService;
use App\Modules\AI\Services\KnowledgeBaseWorkflowService;
use App\Modules\AI\Services\KnowledgeUrlGuard;
use App\Modules\AI\Services\VideoResourceService;
use App\Services\StorageManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiKnowledgeBaseApiController extends WorkspaceScopedController
{
    public function __construct(
        private StorageManager $storage,
        private VideoResourceService $videos,
        private KnowledgeBaseWorkflowService $workflow,
        private KnowledgeBaseTestService $tests,
        private KnowledgeUrlGuard $urls,
    ) {}

    /**
     * GET /api/v1/ai/knowledge-bases
     */
    public function index(Request $request): JsonResponse
    {
        $kbs = AiKnowledgeBase::where('workspace_id', $this->workspaceId($request))
            ->withCount('documents')
            ->latest('id')
            ->get()
            ->map(fn ($kb) => $this->formatKb($kb));

        return response()->json(['data' => $kbs]);
    }

    /**
     * POST /api/v1/ai/knowledge-bases
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:128'],
            'purpose' => ['nullable', 'string', 'max:2000'],
            'language' => ['nullable', 'string', 'max:16'],
            'brand' => ['nullable', 'string', 'max:128'],
            'audience' => ['nullable', 'string', 'max:256'],
            'embedding_model' => ['nullable', 'string'],
        ]);

        $kb = AiKnowledgeBase::create(array_merge($validated, [
            'workspace_id' => $this->workspaceId($request),
        ]));
        $this->workflow->createInitialDraft($kb, $request->user()->id);

        return response()->json($this->formatKb($kb), 201);
    }

    /**
     * GET /api/v1/ai/knowledge-bases/{id}
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $kb = AiKnowledgeBase::where('workspace_id', $this->workspaceId($request))
            ->with('documents')
            ->find($id);

        if (! $kb) {
            return response()->json(['error' => 'Knowledge base not found.'], 404);
        }

        return response()->json(array_merge($this->formatKb($kb), [
            'documents' => $kb->documents->map(fn ($d) => $this->formatDoc($d)),
        ]));
    }

    /**
     * POST /api/v1/ai/knowledge-bases/{id}/documents
     */
    public function addDocument(Request $request, int $id): JsonResponse
    {
        $kb = AiKnowledgeBase::where('workspace_id', $this->workspaceId($request))->find($id);

        if (! $kb) {
            return response()->json(['error' => 'Knowledge base not found.'], 404);
        }

        $sourceType = (string) $request->input('source_type');
        if (in_array($sourceType, ['url', 'sitemap'], true)) {
            $request->merge([
                'source_ref' => $this->normaliseSourceUrl((string) $request->input('source_ref')),
            ]);
            if (config('knowledge_base.guarded_publishing')) {
                try {
                    $request->merge(['source_ref' => $this->urls->assertSafe((string) $request->input('source_ref'))]);
                } catch (\InvalidArgumentException $exception) {
                    return response()->json(['error' => $exception->getMessage()], 422);
                }
            }
        }

        $validated = $request->validate([
            'source_type' => ['required', 'string', 'in:file,url,text,sitemap,faq,video'],
            'source_ref' => match ((string) $request->input('source_type')) {
                'url', 'sitemap' => ['nullable', 'url', 'max:2048'],
                'text', 'faq' => ['required', 'string', 'max:200000'],
                'video' => ['nullable', 'string', 'max:200000'],
                default => ['nullable', 'string', 'max:512'],
            },
            'title' => [$sourceType === 'video' ? 'required' : 'nullable', 'string', 'max:256'],
            'video_url' => [$sourceType === 'video' ? 'required' : 'nullable', 'string', 'max:2048'],
            'video_transcript' => [$sourceType === 'video' ? 'required' : 'nullable', 'string', 'max:200000'],
            'thumbnail_url' => ['nullable', 'string', 'max:2048'],
            'trigger_phrases' => ['nullable', 'string', 'max:4000'],
            'authoritative' => ['nullable', 'boolean'],
            'priority' => ['nullable', 'integer', 'min:0', 'max:100'],
        ]);

        if ($sourceType === 'video') {
            $validated['resource_json'] = $this->videos->normalise($validated['video_url'], $validated['title'], $validated['thumbnail_url'] ?? null);
            $validated['resource_json']['transcript'] = $validated['video_transcript'];
            $validated['resource_json']['trigger_phrases'] = $validated['trigger_phrases'] ?? '';
            $validated['source_ref'] = trim("Title: {$validated['title']}\nDescription or transcript:\n{$validated['video_transcript']}\nTrigger phrases:\n".($validated['trigger_phrases'] ?? ''));
        }
        unset($validated['video_url'], $validated['video_transcript'], $validated['thumbnail_url'], $validated['trigger_phrases']);

        if ($request->hasFile('file')) {
            $request->validate(['file' => ['file', 'max:20480', 'mimes:pdf,txt,md,csv,docx,xlsx,json']]);
            $file = $request->file('file');
            $path = $this->storage->prefixedPath('kb-docs/'.$file->hashName());
            $this->storage->disk()->putFileAs(dirname($path), $file, basename($path));
            $validated['source_ref'] = $path;
            $validated['title'] = $validated['title'] ?? $file->getClientOriginalName();
        }

        if (empty($validated['source_ref']) && $validated['source_type'] !== 'text') {
            return response()->json(['error' => 'source_ref is required for source_type '.$validated['source_type'].'.'], 422);
        }

        $doc = AiKbDocument::create(array_merge($validated, [
            'kb_id' => $kb->id,
            'status' => 'pending',
            'review_status' => 'needs_review',
            'publication_status' => config('knowledge_base.guarded_publishing') ? 'draft' : 'published',
        ]));
        $this->workflow->attachToDraft($doc, $request->user()->id);
        IndexDocumentJob::dispatch($doc->id)->onQueue('ai');

        return response()->json($this->formatDoc($doc), 201);
    }

    /**
     * DELETE /api/v1/ai/knowledge-bases/{kbId}/documents/{docId}
     */
    public function destroyDocument(Request $request, int $kbId, int $docId): JsonResponse
    {
        $kb = AiKnowledgeBase::where('workspace_id', $this->workspaceId($request))->find($kbId);

        if (! $kb) {
            return response()->json(['error' => 'Knowledge base not found.'], 404);
        }

        $doc = AiKbDocument::where('kb_id', $kb->id)->find($docId);
        if (! $doc) {
            return response()->json(['error' => 'Document not found.'], 404);
        }

        if (config('knowledge_base.guarded_publishing')) {
            $this->workflow->removeFromDraft($doc, $request->user()->id);
        } else {
            $doc->chunks()->delete();
            $doc->delete();
        }

        return response()->json(['ok' => true]);
    }

    public function approveDocument(Request $request, int $kbId, int $docId): JsonResponse
    {
        $kb = $this->findKb($request, $kbId);
        $doc = AiKbDocument::where('kb_id', $kb->id)->findOrFail($docId);
        $this->workflow->approve($doc, $request->user()->id);

        return response()->json($this->formatDoc($doc->fresh()));
    }

    public function toggleDocument(Request $request, int $kbId, int $docId): JsonResponse
    {
        $kb = $this->findKb($request, $kbId);
        $doc = AiKbDocument::where('kb_id', $kb->id)->findOrFail($docId);
        $doc->update(['enabled' => ! $doc->enabled]);

        return response()->json($this->formatDoc($doc->fresh()));
    }

    public function publish(Request $request, int $id): JsonResponse
    {
        $revision = $this->workflow->publish($this->findKb($request, $id));

        return response()->json(['published_revision' => $revision->version]);
    }

    public function testQuery(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate(['question' => ['required', 'string', 'max:1000']]);

        return response()->json($this->tests->test($this->findKb($request, $id), $validated['question']));
    }

    private function findKb(Request $request, int $id): AiKnowledgeBase
    {
        return AiKnowledgeBase::where('workspace_id', $this->workspaceId($request))->findOrFail($id);
    }

    private function formatKb(AiKnowledgeBase $kb): array
    {
        return [
            'id' => $kb->id,
            'name' => $kb->name,
            'purpose' => $kb->purpose,
            'language' => $kb->language,
            'brand' => $kb->brand,
            'audience' => $kb->audience,
            'embedding_model' => $kb->embedding_model,
            'status' => $kb->status,
            'workspace_id' => $kb->workspace_id,
            'documents_count' => $kb->documents_count ?? null,
            'readiness' => $kb->readiness_score,
            'draft_revision_id' => $kb->draft_revision_id,
            'published_revision_id' => $kb->published_revision_id,
            'regression_status' => $kb->regression_status,
            'created_at' => $kb->created_at->toIso8601String(),
        ];
    }

    private function formatDoc(AiKbDocument $doc): array
    {
        return [
            'id' => $doc->id,
            'kb_id' => $doc->kb_id,
            'source_type' => $doc->source_type,
            'source_ref' => $doc->source_ref,
            'resource' => $doc->resource_json,
            'title' => $doc->title,
            'status' => $doc->status,
            'enabled' => $doc->enabled,
            'authoritative' => $doc->authoritative,
            'priority' => $doc->priority,
            'review_status' => $doc->review_status,
            'publication_status' => $doc->publication_status,
            'quality_score' => $doc->quality_score,
            'quality_findings' => $doc->quality_findings,
            'detected_language' => $doc->detected_language,
            'extracted_content' => $doc->extracted_content,
            'created_at' => $doc->created_at->toIso8601String(),
        ];
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
