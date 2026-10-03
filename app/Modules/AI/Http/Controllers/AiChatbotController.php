<?php

namespace App\Modules\AI\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\AI\Exceptions\AiCreditsException;
use App\Modules\AI\Models\AiChatbot;
use App\Modules\AI\Models\AiKbDocument;
use App\Modules\AI\Models\AiKbRevision;
use App\Modules\AI\Models\AiKnowledgeBase;
use App\Modules\AI\Services\AiCreditService;
use App\Modules\AI\Services\ChatbotRunner;
use App\Modules\AI\Services\CompanyBriefService;
use App\Modules\AI\Services\KnowledgeBaseWorkflowService;
use App\Modules\AI\Services\KnowledgeUploadLimit;
use App\Modules\AI\Services\ProviderErrorPresenter;
use App\Modules\AI\Services\SmartBotRetrievalPolicy;
use App\Modules\Inbox\Services\SmartBotPlacements;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class AiChatbotController extends Controller
{
    private function workspaceId(Request $request): int
    {
        return (int) ($request->user()->current_workspace_id ?? $request->user()->workspace_id);
    }

    /** The setup steps' tones; the prompts accept any of them. */
    public const TONES = ['friendly', 'professional', 'formal', 'casual'];

    public function index(Request $request): Response
    {
        $wid = $this->workspaceId($request);
        $places = app(SmartBotPlacements::class)->summary($wid);
        $chatbots = AiChatbot::where('workspace_id', $wid)->latest()->get()
            ->map(fn (AiChatbot $bot): array => [
                'places' => $places[$bot->id] ?? [],
                'id' => $bot->id, 'uuid' => $bot->uuid, 'name' => $bot->name, 'tone' => $bot->tone,
                'engine' => $bot->engine, 'system_prompt' => $bot->system_prompt,
                'knowledge' => $this->knowledgeStatus($bot->ai_kb_id),
            ]);
        // Knowledge left by a deleted bot, or made before knowledge belonged to a bot.
        $unusedKnowledge = AiKnowledgeBase::where('workspace_id', $wid)->whereDoesntHave('chatbots')
            ->withCount('documents')->latest()->get(['id', 'uuid', 'name']);

        return Inertia::render('AI/Chatbots/Index', [
            'chatbots' => $chatbots,
            'unusedKnowledge' => $unusedKnowledge,
            'aiCredits' => app(AiCreditService::class)->usage($wid),
            'engineV2Enabled' => (bool) config('chatbot.engine_v2_enabled'),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('AI/Chatbots/Create', ['tones' => self::TONES]);
    }

    /**
     * A new bot and its own knowledge, created together; or a bot for existing
     * knowledge that no bot uses (`from_kb`).
     */
    public function store(Request $request, KnowledgeBaseWorkflowService $workflow): RedirectResponse
    {
        $wid = $this->workspaceId($request);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:128'],
            'tone' => ['nullable', 'in:'.implode(',', self::TONES)],
            'system_prompt' => ['nullable', 'string', 'max:8192'],
            'from_kb' => ['nullable', 'string', 'max:64'],
        ]);
        $existing = null;
        if (! empty($validated['from_kb'])) {
            $existing = AiKnowledgeBase::where('workspace_id', $wid)->where('uuid', $validated['from_kb'])->first();
            abort_unless($existing, 422);
        }

        $bot = DB::transaction(function () use ($validated, $wid, $existing, $workflow, $request): AiChatbot {
            $kb = $existing;
            if (! $kb) {
                $kb = AiKnowledgeBase::create(['workspace_id' => $wid, 'name' => $validated['name']]);
                $workflow->createInitialDraft($kb, $request->user()->id);
            }

            return AiChatbot::create(array_merge([
                'workspace_id' => $wid,
            ], app(SmartBotRetrievalPolicy::class)->compatibilityDefaults(), [
                'answer_scope' => 'business_only',
                'unsupported_fallback_action' => 'clarify_then_handoff',
                'trusted_research_enabled' => false,
                'live_product_facts_enabled' => false,
                'kb_exact_wording' => false,
                'unsupported_answer_action' => 'clarify_then_handoff',
                'name' => $validated['name'],
                'tone' => $validated['tone'] ?? 'friendly',
                'system_prompt' => $validated['system_prompt'] ?? null,
                'ai_kb_id' => $kb->id,
            ]));
        });

        return to_route('client.ai.chatbots.show', $bot)->with('success', 'Smart Bot created. Add your business details next.');
    }

    /** Knowledge for a bot made before every bot had its own. */
    public function createKnowledge(Request $request, AiChatbot $chatbot, KnowledgeBaseWorkflowService $workflow): RedirectResponse
    {
        $this->authorise($request, $chatbot);
        if (! $chatbot->ai_kb_id) {
            DB::transaction(function () use ($chatbot, $workflow, $request): void {
                $kb = AiKnowledgeBase::create(['workspace_id' => $chatbot->workspace_id, 'name' => $chatbot->name]);
                $workflow->createInitialDraft($kb, $request->user()->id);
                $chatbot->update(['ai_kb_id' => $kb->id]);
            });
        }

        return back();
    }

    /** The bot's setup page: identity, business, knowledge, how it answers. */
    public function show(Request $request, AiChatbot $chatbot): Response
    {
        $this->authorise($request, $chatbot);
        $kb = $chatbot->ai_kb_id ? AiKnowledgeBase::where('workspace_id', $chatbot->workspace_id)->find($chatbot->ai_kb_id) : null;
        if ($kb) {
            $revisionId = $kb->draft_revision_id ?: $kb->published_revision_id;
            $visibleDocumentIds = $revisionId
                ? AiKbRevision::where('kb_id', $kb->id)->whereKey($revisionId)->first()?->documents()->pluck('ai_kb_documents.id')->all()
                : $kb->documents()->pluck('id')->all();
            $kb->load([
                'documents' => fn ($query) => $query->whereIn('id', $visibleDocumentIds ?? [])->withCount('chunks')->latest(),
                // Most asked first: the unanswered questions worth answering.
                'knowledgeGaps' => fn ($query) => $query->where('status', 'open')->orderByDesc('occurrences')->latest('last_seen_at')->limit(25),
            ]);
        }
        $uploadMaxKb = app(KnowledgeUploadLimit::class)->maxKb();

        return Inertia::render('AI/Chatbots/Show', [
            'chatbot' => $chatbot,
            'kb' => $kb,
            // Other bots answering from the same knowledge: edits here change them too.
            'sharedWith' => $kb ? AiChatbot::where('ai_kb_id', $kb->id)->whereKeyNot($chatbot->id)->pluck('name') : [],
            'hasBriefSources' => $kb ? app(CompanyBriefService::class)->hasSources($kb) : false,
            'kbUploadMaxKb' => $uploadMaxKb,
            'kbUploadMaxMb' => round($uploadMaxKb / 1024, 1),
            'tones' => self::TONES,
            'aiCredits' => app(AiCreditService::class)->usage((int) $chatbot->workspace_id),
            'engineV2Enabled' => (bool) config('chatbot.engine_v2_enabled'),
            // Settings that only act while their rollout flag is on are shown only then.
            'researchAvailable' => (bool) config('chatbot.business_aware_routing_enabled'),
            'liveProductFactsAvailable' => (bool) config('knowledge_base.live_product_facts_enabled'),
            'placements' => app(SmartBotPlacements::class)->forBot($chatbot),
            'canManagePlacements' => app(SmartBotPlacements::class)->canManage($request->user()),
        ]);
    }

    public function update(Request $request, AiChatbot $chatbot): RedirectResponse
    {
        $this->authorise($request, $chatbot);
        $wid = $this->workspaceId($request);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:128'],
            'ai_kb_id' => ['nullable', 'integer'],
            'system_prompt' => ['nullable', 'string', 'max:8192'],
            'tone' => ['nullable', 'string', 'max:64'],
            'answer_scope' => ['nullable', 'in:business_only,verified_only,general'],
            'unsupported_fallback_action' => ['nullable', 'in:clarify_then_handoff,handoff'],
            'trusted_research_enabled' => ['boolean'],
            'live_product_facts_enabled' => ['boolean'],
            'kb_exact_wording' => ['boolean'],
            'reply_length' => ['nullable', 'in:'.implode(',', AiChatbot::REPLY_LENGTHS)],
            'unsupported_answer_action' => ['nullable', 'in:clarify_then_handoff,handoff,general'],
            'fallback_reply' => ['nullable', 'string', 'max:512'],
            // Sent by the "How it answers" step, which ticks it as done.
            'answers_configured' => ['boolean'],
        ]);
        // Verify the knowledge base belongs to this workspace
        if (! empty($validated['ai_kb_id'])) {
            $kbExists = AiKnowledgeBase::where('workspace_id', $wid)
                ->where('id', $validated['ai_kb_id'])
                ->exists();
            abort_unless($kbExists, 422);
        }
        if ($request->boolean('answers_configured')) {
            $validated['answers_configured_at'] = now();
        }
        unset($validated['answers_configured']);

        // Maintain the legacy field for older clients and rollback safety while
        // keeping answer scope separate from the fallback decision.
        if (! isset($validated['answer_scope']) && isset($validated['unsupported_answer_action'])) {
            $validated['answer_scope'] = $validated['unsupported_answer_action'] === 'general'
                ? 'general' : ($chatbot->answer_scope ?? 'business_only');
        }
        if (! isset($validated['unsupported_fallback_action']) && isset($validated['unsupported_answer_action'])) {
            $validated['unsupported_fallback_action'] = $validated['unsupported_answer_action'] === 'handoff'
                ? 'handoff' : ($chatbot->unsupported_fallback_action ?? 'clarify_then_handoff');
        }
        $scope = $validated['answer_scope'] ?? $chatbot->answer_scope ?? 'business_only';
        $fallback = $validated['unsupported_fallback_action'] ?? $chatbot->unsupported_fallback_action ?? 'clarify_then_handoff';
        $validated['unsupported_answer_action'] = $scope === 'general' ? 'general' : $fallback;

        $chatbot->update($validated);

        return back()->with('success', 'Smart Bot saved.');
    }

    public function destroy(Request $request, AiChatbot $chatbot): RedirectResponse
    {
        $this->authorise($request, $chatbot);
        // Its knowledge stays, listed under "Knowledge not used by a bot".
        $chatbot->delete();

        return to_route('client.ai.chatbots.index')->with('success', 'Smart Bot deleted. Its knowledge is kept.');
    }

    public function playground(Request $request, AiChatbot $chatbot): JsonResponse
    {
        $this->authorise($request, $chatbot);
        $validated = $request->validate([
            'message' => ['required', 'string', 'max:1000'],
            'history' => ['nullable', 'array', 'max:20'],
            'history.*.role' => ['required', 'in:user,assistant'],
            'history.*.content' => ['required', 'string', 'max:4000'],
        ]);

        try {
            // Tests in the playground are not customers' questions.
            $result = app(ChatbotRunner::class)->withoutKnowledgeGaps()->runForApi(
                $chatbot, $validated['message'], $this->workspaceId($request),
                $validated['history'] ?? [], $request->header('Idempotency-Key'), true,
            );

            if (blank($result['reply'] ?? null)) {
                return response()->json([
                    'error' => 'The AI provider returned an empty response. Check the selected model and try again.',
                    'error_code' => 'provider_empty_response',
                ], 422);
            }

            return response()->json([
                'reply' => $result['reply'],
                'display_body' => $result['display_body'] ?? $result['reply'],
                'quick_replies' => $result['quick_replies'] ?? [],
                'resources' => $result['resources'],
                'answer_origin' => $result['answer_origin'] ?? null,
                'response_mode' => $result['response_mode'] ?? null,
                'citations' => $result['citations'] ?? [],
                'product_facts' => $result['product_facts'] ?? [],
                'ai_credits' => app(AiCreditService::class)->usage($this->workspaceId($request)),
            ]);
        } catch (AiCreditsException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $error = ProviderErrorPresenter::present($e);

            return response()->json([
                'error' => $error['message'],
                'error_code' => $error['code'],
            ], 422);
        }
    }

    /**
     * How far a bot's knowledge has been read, for the list.
     *
     * @return array{total:int,ready:int,reading:int,failed:int}|null
     */
    private function knowledgeStatus(?int $kbId): ?array
    {
        if (! $kbId) {
            return null;
        }
        $statuses = AiKbDocument::where('kb_id', $kbId)->pluck('status');

        return [
            'total' => $statuses->count(),
            'ready' => $statuses->filter(fn ($status) => $status === 'indexed')->count(),
            'reading' => $statuses->filter(fn ($status) => in_array($status, ['pending', 'extracting', 'validating', 'indexing'], true))->count(),
            'failed' => $statuses->filter(fn ($status) => in_array($status, ['error', 'degraded'], true))->count(),
        ];
    }

    private function authorise(Request $request, AiChatbot $chatbot): void
    {
        abort_unless((int) $chatbot->workspace_id === $this->workspaceId($request), 403);
    }
}
