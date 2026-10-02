<?php

namespace App\Modules\Inbox\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\AI\Services\AnswerFeedbackService;
use App\Modules\AI\Services\UnansweredQuestionService;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 👍/👎 and "Improve" on Smart Bot replies in the inbox (Smart Bot 2.0,
 * Phase 1.5). JSON, so the open conversation updates in place.
 */
class AiAnswerFeedbackController extends Controller
{
    public function __construct(private readonly AnswerFeedbackService $feedback) {}

    public function rate(Request $request, Conversation $conversation, Message $message): JsonResponse
    {
        $this->authorise($request, $conversation, $message);
        $validated = $request->validate(['rating' => ['required', 'in:'.implode(',', AnswerFeedbackService::RATINGS)]]);

        return response()->json(['ai_feedback' => $this->feedback->rate($message, $request->user(), $validated['rating'])]);
    }

    public function question(Request $request, Conversation $conversation, Message $message): JsonResponse
    {
        $this->authorise($request, $conversation, $message);

        return response()->json(['question' => $this->feedback->question($message)]);
    }

    public function improve(Request $request, Conversation $conversation, Message $message): JsonResponse
    {
        $this->authorise($request, $conversation, $message);
        $validated = $request->validate([
            'question' => ['required', 'string', 'max:500'],
            'answer' => ['required', 'string', 'max:'.UnansweredQuestionService::MAX_ANSWER_CHARS],
        ]);

        return response()->json(['ai_feedback' => $this->feedback->improve($message, $request->user(), $validated['question'], $validated['answer'])]);
    }

    private function authorise(Request $request, Conversation $conversation, Message $message): void
    {
        $workspaceId = $request->user()->current_workspace_id ?? $request->user()->workspace_id;
        abort_unless((int) $conversation->workspace_id === (int) $workspaceId, 403);
        abort_unless((int) $message->conversation_id === (int) $conversation->id && $this->feedback->isReviewable($message), 404);
        $message->setRelation('conversation', $conversation);
    }
}
