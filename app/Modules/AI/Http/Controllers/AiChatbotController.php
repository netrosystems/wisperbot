<?php

namespace App\Modules\AI\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\AI\Exceptions\AiCreditsException;
use App\Modules\AI\Models\AiChatbot;
use App\Modules\AI\Models\AiKnowledgeBase;
use App\Modules\AI\Services\AiCreditService;
use App\Modules\AI\Services\ChatbotRunner;
use App\Modules\AI\Services\ProviderErrorPresenter;
use App\Modules\AI\Services\SmartBotRetrievalPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AiChatbotController extends Controller
{
    private function workspaceId(Request $request): int
    {
        return (int) ($request->user()->current_workspace_id ?? $request->user()->workspace_id);
    }

    public function index(Request $request): Response
    {
        $wid = $this->workspaceId($request);
        $chatbots = AiChatbot::where('workspace_id', $wid)->with('knowledgeBase')->latest()->get();
        $knowledgeBases = AiKnowledgeBase::where('workspace_id', $wid)
            ->withCount([
                'products as live_product_count' => fn ($query) => $query->where('status', 'active'),
                'documents as live_product_attention_count' => fn ($query) => $query->whereIn('product_detection_status', ['blocked', 'unsupported', 'error']),
            ])
            ->withMax('products as live_products_verified_at', 'verified_at')
            ->get(['id', 'name', 'purpose', 'brand', 'audience']);

        return Inertia::render('AI/Chatbots/Index', [
            'chatbots' => $chatbots,
            'knowledgeBases' => $knowledgeBases,
            'aiCredits' => app(AiCreditService::class)->usage($wid),
            'businessAwareRoutingEnabled' => (bool) config('chatbot.business_aware_routing_enabled'),
            'liveProductFactsAvailable' => (bool) config('knowledge_base.live_product_facts_enabled'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $wid = $this->workspaceId($request);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:128'],
        ]);

        AiChatbot::create(array_merge([
            'workspace_id' => $wid,
        ], app(SmartBotRetrievalPolicy::class)->compatibilityDefaults(), [
            'answer_scope' => 'business_only',
            'unsupported_fallback_action' => 'clarify_then_handoff',
            'trusted_research_enabled' => false,
            'live_product_facts_enabled' => false,
            'kb_exact_wording' => false,
            'unsupported_answer_action' => 'clarify_then_handoff',
        ], $validated));

        return back()->with('success', 'Chatbot created.');
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
            'unsupported_answer_action' => ['nullable', 'in:clarify_then_handoff,handoff,general'],
            'fallback_reply' => ['nullable', 'string', 'max:512'],
            'channels' => ['nullable', 'array'],
            'enabled' => ['boolean'],
        ]);
        // Verify the knowledge base belongs to this workspace
        if (! empty($validated['ai_kb_id'])) {
            $kbExists = AiKnowledgeBase::where('workspace_id', $wid)
                ->where('id', $validated['ai_kb_id'])
                ->exists();
            abort_unless($kbExists, 422);
        }

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

        return back()->with('success', 'Chatbot updated.');
    }

    public function destroy(Request $request, AiChatbot $chatbot): RedirectResponse
    {
        $this->authorise($request, $chatbot);
        $chatbot->delete();

        return back()->with('success', 'Chatbot deleted.');
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
            $result = app(ChatbotRunner::class)->runForApi(
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

    private function authorise(Request $request, AiChatbot $chatbot): void
    {
        abort_unless((int) $chatbot->workspace_id === $this->workspaceId($request), 403);
    }
}
