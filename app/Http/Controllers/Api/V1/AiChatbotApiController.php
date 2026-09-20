<?php

namespace App\Http\Controllers\Api\V1;

use App\Modules\AI\Models\AiChatbot;
use App\Modules\AI\Services\ChatbotRunner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiChatbotApiController extends WorkspaceScopedController
{
    public function __construct(private readonly ChatbotRunner $runner) {}

    /**
     * GET /api/v1/ai/chatbots
     */
    public function index(Request $request): JsonResponse
    {
        $chatbots = AiChatbot::where('workspace_id', $this->workspaceId($request))
            ->latest('id')
            ->get()
            ->map(fn ($b) => [
                'id' => $b->id,
                'name' => $b->name,
                // Kept for older clients; selection on a channel/widget is now
                // the only activation state for a Smart Bot.
                'enabled' => true,
                'kb_id' => $b->ai_kb_id,
                'channels' => $b->channels ?? [],
                'answer_outside_knowledge_base' => ($b->answer_scope ?? null) === 'general' || $b->unsupported_answer_action === 'general',
                'answer_scope' => $b->unsupported_answer_action === 'general' ? 'general' : ($b->answer_scope ?? 'business_only'),
                'trusted_research_enabled' => (bool) $b->trusted_research_enabled,
                'kb_exact_wording' => (bool) $b->kb_exact_wording,
                'starter_questions_enabled' => (bool) $b->starter_questions_enabled,
                'starter_questions' => array_values($b->starter_questions ?? []),
                'live_product_facts_enabled' => (bool) $b->live_product_facts_enabled,
                'unsupported_fallback_action' => $b->unsupported_fallback_action ?? ($b->unsupported_answer_action === 'handoff' ? 'handoff' : 'clarify_then_handoff'),
                'unsupported_answer_action' => $b->unsupported_answer_action ?? 'clarify_then_handoff',
                'video_match_threshold' => $b->video_match_threshold ?? 0.72,
                'created_at' => $b->created_at->toIso8601String(),
            ]);

        return response()->json(['data' => $chatbots]);
    }

    /**
     * POST /api/v1/ai/chatbots/{id}/chat
     */
    public function chat(Request $request, int $id): JsonResponse
    {
        $chatbot = AiChatbot::where('workspace_id', $this->workspaceId($request))->find($id);

        if (! $chatbot) {
            return response()->json(['error' => 'Chatbot not found.'], 404);
        }

        $validated = $request->validate([
            'message' => ['required', 'string', 'max:4000'],
            'contact_id' => ['nullable', 'integer'],
            'history' => ['nullable', 'array'],
            'history.*.role' => ['required_with:history', 'string', 'in:user,assistant'],
            'history.*.content' => ['required_with:history', 'string'],
        ]);

        $result = $this->runner->runForApi(
            $chatbot,
            $validated['message'],
            $this->workspaceId($request),
            $validated['history'] ?? [],
            $request->header('Idempotency-Key'),
            true,
        );

        return response()->json($result);
    }
}
