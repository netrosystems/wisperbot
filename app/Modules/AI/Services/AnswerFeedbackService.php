<?php

namespace App\Modules\AI\Services;

use App\Models\User;
use App\Modules\AI\Models\AiAnswerFeedback;
use App\Modules\AI\Models\AiKnowledgeBase;
use App\Modules\Shared\Models\Message;
use Illuminate\Validation\ValidationException;

/**
 * The team's 👍/👎 on a Smart Bot reply and "Improve", which writes the right
 * answer into the Knowledge Base (Smart Bot 2.0, Phase 1.5). Shared by the
 * inbox and the mobile app. One rating per reply, mirrored into the message
 * payload (`ai_feedback`) so every agent sees it.
 */
class AnswerFeedbackService
{
    public const RATINGS = ['up', 'down'];

    public function __construct(private readonly UnansweredQuestionService $questions) {}

    /** A Smart Bot reply that carries "Why this answer". */
    public function isReviewable(Message $message): bool
    {
        return $message->direction === 'out' && $message->sent_by === 'bot' && is_array($message->payload['ai_review'] ?? null);
    }

    /**
     * Sets the reply's rating; the same rating again clears it.
     *
     * @return array<string,mixed> The feedback as the payload now shows it
     */
    public function rate(Message $message, User $user, string $rating): array
    {
        $feedback = $this->feedback($message);
        $feedback->fill([
            'rating' => $feedback->rating === $rating ? null : $rating,
            'user_id' => $user->id,
        ])->save();

        return $this->mirror($message, $feedback);
    }

    /**
     * Writes a better answer for the question this reply answered into the
     * bot's Knowledge Base, and marks the reply as improved.
     *
     * @return array<string,mixed>
     */
    public function improve(Message $message, User $user, string $question, string $answer): array
    {
        $review = (array) $message->payload['ai_review'];
        $kb = AiKnowledgeBase::where('workspace_id', $message->conversation->workspace_id)->find($review['kb_id'] ?? null);
        if (! $kb) {
            throw ValidationException::withMessages(['answer' => 'This Smart Bot has no Knowledge Base to add the answer to.']);
        }
        $this->questions->addAnswer($kb, $question, $answer, $user);

        $feedback = $this->feedback($message);
        $feedback->fill(['improved' => true, 'user_id' => $user->id, 'rating' => $feedback->rating ?? 'down', 'kb_id' => $kb->id])->save();

        return $this->mirror($message, $feedback);
    }

    /** The customer's question this reply answered, for prefilling "Improve". */
    public function question(Message $message): ?string
    {
        $id = $message->payload['ai_review']['question_message_id'] ?? null;

        return $id ? Message::where('conversation_id', $message->conversation_id)->whereKey($id)->value('body') : null;
    }

    private function feedback(Message $message): AiAnswerFeedback
    {
        $review = (array) $message->payload['ai_review'];

        return AiAnswerFeedback::firstOrNew(['message_id' => $message->id], [
            'workspace_id' => $message->conversation->workspace_id,
            'chatbot_id' => $review['chatbot_id'] ?? null,
            'kb_id' => $review['kb_id'] ?? null,
        ]);
    }

    /** @return array<string,mixed> */
    private function mirror(Message $message, AiAnswerFeedback $feedback): array
    {
        $summary = ['rating' => $feedback->rating, 'improved' => $feedback->improved, 'user_id' => $feedback->user_id, 'at' => now()->toIso8601String()];
        $payload = $message->payload ?? [];
        $payload['ai_feedback'] = $summary;
        $message->forceFill(['payload' => $payload])->save();

        return $summary;
    }
}
