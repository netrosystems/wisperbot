<?php

namespace App\Modules\Social\Jobs;

use App\Modules\AI\Models\AiChatbot;
use App\Modules\AI\Services\ChatbotRunner;
use App\Modules\Social\Events\SocialCommentsChanged;
use App\Modules\Social\Exceptions\CommentProviderException;
use App\Modules\Social\Models\SocialComment;
use App\Modules\Social\Models\SocialCommentOperation;
use App\Modules\Social\Services\MetaCommentProvider;
use App\Modules\Social\Services\SocialCommentService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

class ProcessSocialCommentOperation implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public int $timeout = 75;

    public function __construct(public string $operationId, public int $workspaceId) {}

    public function backoff(): array
    {
        return [30, 120, 600];
    }

    public function failed(?\Throwable $exception): void
    {
        $op = SocialCommentOperation::where('workspace_id', $this->workspaceId)->find($this->operationId);
        if ($op && in_array($op->status, ['queued', 'sending'], true)) {
            $op->update(['status' => $op->status === 'sending' ? 'delivery_unknown' : 'failed', 'reason' => $op->status === 'sending' ? 'verify_on_platform' : 'processing_failed']);
        }
    }

    public function handle(MetaCommentProvider $provider, SocialCommentService $service, ChatbotRunner $runner): void
    {
        if (! config('social_comments.enabled')) {
            return;
        }
        $op = SocialCommentOperation::where('workspace_id', $this->workspaceId)->find($this->operationId);
        if (! $op || ! in_array($op->status, ['queued', 'sending', 'delivery_unknown'], true)) {
            return;
        }
        Cache::lock('social-comment:'.$op->comment_id, 90)->block(5, function () use ($op, $provider, $service, $runner) {
            $op->refresh();
            // Another worker may have completed this operation while we waited for the lock.
            if (! in_array($op->status, ['queued', 'sending', 'delivery_unknown'], true)) {
                return;
            }
            if (in_array($op->status, ['sending', 'delivery_unknown'], true)) {
                $op->update(['status' => 'delivery_unknown', 'reason' => 'verify_on_platform']);

                return;
            }
            $comment = SocialComment::with('account')->where('workspace_id', $this->workspaceId)->find($op->comment_id);
            if (! $comment || ! $comment->account || ! $comment->account->active || $comment->deleted
                || ! hash_equals($op->credential_fingerprint, $provider->fingerprint($comment->account))) {
                $op->update(['status' => 'canceled', 'reason' => 'connection_changed']);

                return;
            }
            $settings = $service->settings($comment->account);
            if ($op->kind === 'suggest' || $op->source === 'automatic') {
                $bot = AiChatbot::with('knowledgeBase')->where('workspace_id', $this->workspaceId)->find($settings->chatbot_id);
                if (! $bot || ! $settings->public_kb_confirmed_at || ! $bot->knowledgeBase
                    || (int) $settings->public_kb_revision_id !== (int) $bot->knowledgeBase->published_revision_id
                    || ($op->kind === 'reply' && (int) ($op->diagnostics['revision_id'] ?? 0) !== (int) $bot->knowledgeBase->published_revision_id)) {
                    $op->update(['status' => 'needs_attention', 'reason' => 'choose_public_knowledge_base']);

                    return;
                }
            }
            if ($op->source === 'automatic' && ($comment->ai_paused || $comment->status !== 'needs_attention' || $settings->mode !== 'automatic')) {
                $op->update(['status' => 'canceled', 'reason' => 'agent_took_over']);

                return;
            }
            if ($op->source === 'automatic' && $service->ancestorPaused($comment)) {
                $op->update(['status' => 'canceled', 'reason' => 'agent_took_over']);

                return;
            }
            if ($op->source === 'automatic' && SocialCommentOperation::where('comment_id', $comment->id)->where('id', '!=', $op->id)
                ->where('kind', 'reply')->whereIn('status', ['queued', 'sending', 'sent', 'delivery_unknown'])->exists()) {
                $op->update(['status' => 'canceled', 'reason' => 'agent_took_over']);

                return;
            }
            if ((int) $comment->revision !== (int) $op->comment_revision) {
                $op->update(['status' => 'canceled', 'reason' => 'comment_changed']);

                return;
            }
            try {
                if ($op->kind === 'suggest') {
                    $bot = AiChatbot::where('workspace_id', $this->workspaceId)->find($settings->chatbot_id);
                    if (! $bot || ! $settings->public_kb_confirmed_at) {
                        $op->update(['status' => 'needs_attention', 'reason' => 'choose_public_knowledge_base']);

                        return;
                    }
                    $result = $runner->runForPublicComment($bot, (string) $comment->body, $this->workspaceId, 'social-comment:'.$op->id);
                    if (($result['decision'] ?? '') !== 'answer' || empty($result['reply'])) {
                        $op->update(['status' => 'needs_attention', 'reason' => 'human_review_required']);

                        return;
                    }
                    $op->update(['body' => $result['reply'], 'diagnostics' => ['revision_id' => $result['revision_id'] ?? null, 'tokens_used' => $result['tokens_used'] ?? 0], 'status' => 'suggested']);
                    if ($op->source === 'automatic') {
                        // Same operation changes phase; generation will not run again on delivery retry.
                        $op->update(['kind' => 'reply', 'status' => 'queued']);
                        self::dispatch($op->id, $this->workspaceId)->onQueue('social')->delay(now()->addSeconds(2));
                    }
                } elseif ($op->kind === 'reply') {
                    if (! ($settings->capabilities['reply'] ?? false) || $settings->connection_status !== 'ready') {
                        throw new CommentProviderException('permission_required');
                    }
                    $op->update(['status' => 'sending']);
                    $remoteId = $provider->reply($comment->account, $comment->remote_id, (string) $op->body);
                    $op->update(['status' => 'sent', 'remote_id' => $remoteId]);
                    SocialComment::updateOrCreate(['social_account_id' => $comment->social_account_id, 'remote_id' => $remoteId], [
                        'workspace_id' => $this->workspaceId, 'post_id' => $comment->post_id, 'parent_remote_id' => $comment->remote_id,
                        'author_id' => $comment->account->account_id, 'author_name' => $comment->account->name,
                        'body' => $op->body, 'is_own' => true, 'reply_source' => $op->source === 'agent' ? 'agent' : 'ai',
                        'posted_at' => now(), 'remote_updated_at' => now(), 'status' => 'resolved',
                    ]);
                    $comment->update(['status' => $op->source === 'automatic' ? 'ai_handled' : 'resolved']);
                } else {
                    $capability = $op->kind === 'delete' ? 'delete' : 'hide';
                    if (! ($settings->capabilities[$capability] ?? false) || $settings->connection_status !== 'ready') {
                        throw new CommentProviderException('permission_required');
                    }
                    $op->update(['status' => 'sending']);
                    $provider->moderate($comment->account, $comment->remote_id, $op->kind);
                    $comment->update($op->kind === 'delete' ? ['deleted' => true, 'body' => null, 'status' => 'resolved'] : ['hidden' => $op->kind === 'hide']);
                    $op->update(['status' => 'sent']);
                }
            } catch (CommentProviderException $e) {
                $op->update(['status' => $e->reason === 'delivery_unknown' ? 'delivery_unknown' : 'failed', 'reason' => $e->reason]);
                if (in_array($e->reason, ['reconnect_required', 'permission_required'], true)) {
                    $settings->update(['connection_status' => $e->reason]);
                }
                if ($e->reason === 'rate_limited' && $this->attempts() < $this->tries) {
                    $op->update(['status' => 'queued']);
                    $this->release($e->retryAfter);
                }
            } catch (\Throwable) {
                // A validated AI result is charged independently; provider delivery is never inferred.
                $inFlight = $op->fresh()?->status === 'sending';
                $op->update(['status' => $inFlight ? 'delivery_unknown' : 'needs_attention', 'reason' => $inFlight ? 'verify_on_platform' : 'human_review_required']);
            } finally {
                SocialCommentsChanged::dispatch($this->workspaceId, $comment->id);
            }
        });
    }
}
