<?php

namespace App\Modules\AI\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\AI\Exceptions\AiCreditsException;
use App\Modules\AI\Models\AiChatbot;
use App\Modules\AI\Models\AiKnowledgeBase;
use App\Modules\AI\Services\AiCreditService;
use App\Modules\AI\Services\ChatbotRunner;
use App\Modules\AI\Services\ProviderErrorPresenter;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
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
        $knowledgeBases = AiKnowledgeBase::where('workspace_id', $wid)->get(['id', 'name']);

        return Inertia::render('AI/Chatbots/Index', [
            'chatbots' => $chatbots,
            'knowledgeBases' => $knowledgeBases,
            'aiCredits' => app(AiCreditService::class)->usage($wid),
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
            'max_context_chunks' => 3,
            'retrieval_match_threshold' => 0.60,
            'max_context_tokens' => 1200,
            'video_match_threshold' => 0.72,
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
            'max_context_chunks' => ['nullable', 'integer', 'min:1', 'max:20'],
            'retrieval_match_threshold' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'max_context_tokens' => ['nullable', 'integer', 'min:200', 'max:4000'],
            'video_match_threshold' => ['nullable', 'numeric', 'min:0', 'max:1'],
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
        $request->validate([
            'message' => ['required', 'string', 'max:1000'],
            'history' => ['nullable', 'array'],
        ]);

        if (! $chatbot->enabled) {
            return response()->json([
                'error' => 'Enable this chatbot before testing it.',
                'error_code' => 'chatbot_disabled',
            ], 422);
        }

        try {
            // Build a synthetic inbound Message model (unsaved) for ChatbotRunner
            $fakeMessage = new Message;
            $fakeMessage->body = $request->message;
            $fakeMessage->direction = 'in';
            $fakeMessage->channel = 'playground';

            // Attach a minimal conversation with workspace context
            $fakeConversation = new Conversation;
            $fakeConversation->workspace_id = $this->workspaceId($request);
            $fakeConversation->id = 0;
            $fakeMessage->setRelation('conversation', $fakeConversation);

            $result = app(ChatbotRunner::class)->run($chatbot, $fakeMessage, throwProviderErrors: true);

            if (blank($result['reply'] ?? null)) {
                return response()->json([
                    'error' => 'The AI provider returned an empty response. Check the selected model and try again.',
                    'error_code' => 'provider_empty_response',
                ], 422);
            }

            return response()->json([
                'reply' => $result['reply'],
                'resources' => $result['resources'] ?? [],
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
