<?php

namespace App\Modules\Inbox\Jobs;

use App\Events\MessageSent;
use App\Modules\AI\Models\AiChatbot;
use App\Modules\AI\Services\AiCreditService;
use App\Modules\AI\Services\ChatbotRunner;
use App\Modules\Inbox\Services\EmailAiMessageGuard;
use App\Modules\Inbox\Services\SegmentAiPolicyService;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use App\Modules\Shared\Services\ChannelManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ProcessChannelAiReplyJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public int $uniqueFor = 30;

    public function __construct(public readonly int $conversationId) {}

    public function uniqueId(): string
    {
        return (string) $this->conversationId;
    }

    public function handle(
        SegmentAiPolicyService $policy,
        EmailAiMessageGuard $emailGuard,
        ChatbotRunner $runner,
        ChannelManager $channels,
        AiCreditService $credits,
    ): void {
        $lock = Cache::lock('conversation-ai-generation:'.$this->conversationId, 150);
        if (! $lock->get()) {
            $this->release(3);

            return;
        }

        $account = null;
        try {
            $conversation = Conversation::with(['channelAccount', 'contact'])->find($this->conversationId);
            if (! $conversation || ! $conversation->channelAccount) {
                return;
            }
            $account = $conversation->channelAccount;

            $message = $conversation->messages()->where('direction', 'in')->latest('id')->first();
            if (! $message || Message::where('ai_source_message_id', $message->id)->exists()) {
                return;
            }

            $reason = $this->conversationBlockReason($conversation) ?: $policy->decision($conversation->channelAccount, $message->sent_at);
            if ($reason === 'eligible' && $message->channel === 'email') {
                $reason = $emailGuard->reason($message, $conversation->channelAccount) ?: 'eligible';
            }
            if ($reason && $reason !== 'eligible') {
                $this->logDecision($reason, $conversation, $message);

                return;
            }
            if (! $this->supportedMessage($message)) {
                $this->logDecision('unsupported_message', $conversation, $message);

                return;
            }

            $chatbot = AiChatbot::whereKey($policy->chatbotId($conversation->channelAccount))
                ->where('workspace_id', $conversation->workspace_id)->first();
            if (! $chatbot) {
                $this->logDecision('missing_bot', $conversation, $message);

                return;
            }

            if ($message->channel === 'email') {
                $message->body = $emailGuard->promptBody($message);
            }
            $message->setRelation('conversation', $conversation);
            $result = $runner->run($chatbot, $message, true);
            $reply = trim((string) ($result['reply'] ?? ''));
            if ($reply === '') {
                $this->logDecision('empty_reply', $conversation, $message);

                return;
            }

            Cache::lock('conversation-ai-reply:'.$this->conversationId, 150)->block(10, function () use ($channels, $conversation, $credits, $message, $policy, $reply, $result): void {
                $segment = $policy->segmentFor($conversation->channelAccount);
                Cache::lock('workspace-ai-policy:'.$conversation->workspace_id.':'.$segment, 150)->block(10, function () use ($channels, $conversation, $credits, $message, $policy, $reply, $result): void {
                    $conversation->refresh()->load('channelAccount');
                    $latestInboundId = $conversation->messages()->where('direction', 'in')->max('id');
                    $reason = $this->conversationBlockReason($conversation) ?: $policy->decision($conversation->channelAccount);
                    if ((int) $latestInboundId !== (int) $message->id || ($reason && $reason !== 'eligible')) {
                        $decision = (int) $latestInboundId !== (int) $message->id ? 'superseded' : $reason;
                        if ($decision === 'superseded') {
                            self::dispatch($conversation->id)->onQueue('ai')->delay(now()->addSeconds(2));
                        }
                        $credits->refundCompleted(
                            (int) $conversation->workspace_id,
                            'chatbot_reply',
                            'chatbot:message:'.$message->id,
                            'delivery_'.$decision,
                        );
                        $this->logDecision($decision, $conversation, $message);

                        return;
                    }

                    $resources = $result['resources'];
                    $providerBody = $this->providerBody($reply, $resources, $message->channel);
                    try {
                        $botMessage = Message::create([
                            'conversation_id' => $conversation->id,
                            'direction' => 'out',
                            'channel' => $message->channel,
                            'type' => 'text',
                            'body' => $providerBody,
                            'payload' => [
                                'resources' => $resources,
                                'quick_replies' => $result['quick_replies'] ?? [],
                                'display_body' => $result['display_body'] ?? $reply,
                            ],
                            'status' => 'queued',
                            'sent_by' => 'bot',
                            'ai_source_message_id' => $message->id,
                            'sent_at' => now(),
                        ]);
                    } catch (QueryException $e) {
                        if (Message::where('ai_source_message_id', $message->id)->exists()) {
                            return;
                        }
                        throw $e;
                    }

                    try {
                        $providerId = $channels->driver($message->channel)->send($botMessage);
                        $botMessage->update(['status' => 'sent', 'provider_message_id' => $providerId]);
                        $this->clearError($conversation->channelAccount);
                        $this->logDecision('sent', $conversation, $message);
                    } catch (\Throwable $e) {
                        $botMessage->update(['status' => 'failed', 'error_json' => ['message' => $e->getMessage()]]);
                        $this->storeError($conversation->channelAccount, 'send_failed');
                        Log::warning('inbox.ai_reply.send_failed', [
                            'workspace_id' => $conversation->workspace_id,
                            'channel_account_id' => $conversation->channel_account_id,
                            'conversation_id' => $conversation->id,
                            'message_id' => $message->id,
                            'channel' => $message->channel,
                            'error' => $e->getMessage(),
                        ]);
                    }

                    $conversation->update(['last_message_at' => now()]);
                    MessageSent::dispatch($botMessage->load('conversation'));
                });
            });
        } catch (LockTimeoutException) {
            $this->release(3);
        } catch (\Throwable $e) {
            if ($account) {
                $this->storeError($account, 'generation_failed');
            }
            Log::error('inbox.ai_reply.failed', [
                'conversation_id' => $this->conversationId,
                'error' => $e->getMessage(),
            ]);
        } finally {
            $lock->release();
        }
    }

    private function conversationBlockReason(Conversation $conversation): ?string
    {
        if ($conversation->status !== 'open') {
            return $conversation->status === 'resolved' ? 'resolved' : 'inactive_conversation';
        }
        if ($conversation->ai_paused_at || $conversation->assigned_user_id || $conversation->joined_user_id) {
            return 'human_owned';
        }
        if ($conversation->handover_at) {
            return 'human_handoff';
        }
        if ($conversation->channelAccount?->channel === 'whatsapp' && ! $conversation->isWhatsappWindowOpen()) {
            return 'whatsapp_window_closed';
        }
        if ($conversation->messages()->where('direction', 'out')->where('sent_by', 'human')
            ->where('id', '>', (int) $conversation->messages()->where('direction', 'in')->max('id'))->exists()) {
            return 'human_replied';
        }

        return null;
    }

    private function supportedMessage(Message $message): bool
    {
        return $message->type === 'text' && trim((string) $message->body) !== '';
    }

    /** @param array<int, array<string, mixed>> $resources */
    private function providerBody(string $reply, array $resources, string $channel): string
    {
        $limits = ['instagram' => 1000, 'messenger' => 2000, 'ebay' => 2000, 'whatsapp' => 4096, 'telegram' => 4096, 'email' => 12000];
        $limit = $limits[$channel] ?? 4000;
        $url = $channel !== 'webchat' ? ($resources[0]['canonical_url'] ?? null) : null;
        $suffix = $url && ! str_contains($reply, $url) ? "\n\nWatch video: {$url}" : '';
        if (mb_strlen($suffix) >= $limit) {
            $suffix = '';
        }
        $available = max(1, $limit - mb_strlen($suffix));
        $body = $this->truncateWithoutBreakingUrls($reply, $available);

        return $body.$suffix;
    }

    private function truncateWithoutBreakingUrls(string $text, int $limit): string
    {
        if (mb_strlen($text) <= $limit) {
            return $text;
        }
        if ($limit <= 1) {
            return '…';
        }

        $candidate = rtrim(mb_substr($text, 0, $limit - 1));
        preg_match_all('~https?://\S+~u', $text, $matches, PREG_OFFSET_CAPTURE);
        $byteBoundary = strlen(mb_substr($text, 0, $limit - 1));
        foreach ($matches[0] as [$url, $byteOffset]) {
            $urlEnd = $byteOffset + strlen($url);
            if ($byteOffset < $byteBoundary && $urlEnd > $byteBoundary) {
                $candidate = rtrim(substr($text, 0, $byteOffset));
                break;
            }
        }

        return $candidate.'…';
    }

    private function logDecision(string $reason, Conversation $conversation, Message $message): void
    {
        Log::info('inbox.ai_reply.decision', [
            'reason' => $reason,
            'workspace_id' => $conversation->workspace_id,
            'channel_account_id' => $conversation->channel_account_id,
            'conversation_id' => $conversation->id,
            'message_id' => $message->id,
            'channel' => $message->channel,
        ]);
    }

    private function storeError(ChannelAccount $account, string $code): void
    {
        $account->refresh();
        $meta = $account->meta_json ?? [];
        $meta['ai_last_error'] = ['code' => $code, 'at' => now()->toIso8601String()];
        $account->update(['meta_json' => $meta]);
    }

    private function clearError(ChannelAccount $account): void
    {
        $account->refresh();
        $meta = $account->meta_json ?? [];
        unset($meta['ai_last_error']);
        $account->update(['meta_json' => $meta]);
    }
}
