<?php

namespace App\Modules\Inbox\Jobs;

use App\Events\MessageSent;
use App\Modules\AI\Models\AiChatbot;
use App\Modules\AI\Services\ChatbotRunner;
use App\Modules\Shared\Models\Message;
use App\Modules\Shared\Services\ChannelManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Website chat Smart Bot replies run on the `ai` queue like other channels, so
 * a slow model or approved-source fetch can never exceed the visitor's HTTP
 * request limit and lose the reply. The widget receives it by poll/realtime.
 */
class ProcessWebchatAiReplyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // One attempt: a retry after a partial run could send the customer a duplicate.
    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(
        public readonly int $messageId,
        public readonly int $chatbotId,
    ) {
        $this->onQueue('ai');
    }

    public function handle(ChatbotRunner $runner, ChannelManager $channels): void
    {
        $message = Message::with('conversation')->find($this->messageId);
        $conversation = $message?->conversation;
        $chatbot = AiChatbot::find($this->chatbotId);
        if (! $message || ! $conversation || ! $chatbot || $chatbot->workspace_id !== $conversation->workspace_id) {
            return;
        }

        try {
            $result = $runner->run($chatbot, $message);
            $reply = $result['reply'] ?? null;
            if ($reply === null) {
                return;
            }

            $botMessage = Message::create([
                'conversation_id' => $conversation->id,
                'direction' => 'out',
                'channel' => $message->channel,
                'type' => 'text',
                'body' => $reply,
                'payload' => [
                    'resources' => $result['resources'],
                    'quick_replies' => $result['quick_replies'] ?? [],
                    'display_body' => $result['display_body'] ?? $reply,
                    'answer_origin' => $result['answer_origin'] ?? null,
                    'response_mode' => $result['response_mode'] ?? null,
                    'citations' => $result['citations'] ?? [],
                    'product_facts' => $result['product_facts'] ?? [],
                ],
                'status' => 'queued',
                'sent_by' => 'bot',
                'sent_at' => now(),
            ]);

            try {
                $providerId = $channels->driver($message->channel)->send($botMessage);
                $botMessage->update(['status' => 'sent', 'provider_message_id' => $providerId]);
            } catch (\Throwable $sendErr) {
                $botMessage->update(['status' => 'failed', 'error_json' => ['message' => $sendErr->getMessage()]]);
                Log::warning('Webchat AI reply send failed', [
                    'message_id' => $botMessage->id,
                    'error' => $sendErr->getMessage(),
                ]);
            }

            $conversation->update(['last_message_at' => now()]);
            $botMessage->load('conversation');
            MessageSent::dispatch($botMessage);
        } catch (\Throwable $e) {
            Log::error('Webchat AI reply failed', [
                'message_id' => $message->id,
                'chatbot_id' => $chatbot->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
