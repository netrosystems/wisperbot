<?php

namespace App\Http\Controllers\Api\V1;

use App\Modules\AI\Services\AnswerFeedbackService;
use App\Modules\AI\Services\UnansweredQuestionService;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 👍/👎 and "Improve" on Smart Bot replies for the mobile inbox (Smart Bot
 * 2.0, Phase 1.5). "Why this answer" is already in each bot message's
 * `payload.ai_review`; the rating is in `payload.ai_feedback`.
 */
class MobileAiFeedbackController extends WorkspaceScopedController
{
    public function __construct(private readonly AnswerFeedbackService $feedback) {}

    public function rate(Request $request, string $uuid, Message $message): JsonResponse
    {
        $this->resolve($request, $uuid, $message);
        $validated = $request->validate(['rating' => ['required', 'in:'.implode(',', AnswerFeedbackService::RATINGS)]]);

        return response()->json(['data' => ['ai_feedback' => $this->feedback->rate($message, $request->user(), $validated['rating'])]]);
    }

    public function question(Request $request, string $uuid, Message $message): JsonResponse
    {
        $this->resolve($request, $uuid, $message);

        return response()->json(['data' => ['question' => $this->feedback->question($message)]]);
    }

    public function improve(Request $request, string $uuid, Message $message): JsonResponse
    {
        $this->resolve($request, $uuid, $message);
        $validated = $request->validate([
            'question' => ['required', 'string', 'max:500'],
            'answer' => ['required', 'string', 'max:'.UnansweredQuestionService::MAX_ANSWER_CHARS],
        ]);

        return response()->json(['data' => ['ai_feedback' => $this->feedback->improve($message, $request->user(), $validated['question'], $validated['answer'])]]);
    }

    private function resolve(Request $request, string $uuid, Message $message): void
    {
        $conversation = Conversation::where('workspace_id', $this->workspaceId($request))->where('uuid', $uuid)->firstOrFail();
        abort_unless((int) $message->conversation_id === (int) $conversation->id && $this->feedback->isReviewable($message), 404);
        $message->setRelation('conversation', $conversation);
    }
}
