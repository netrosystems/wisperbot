<?php

namespace App\Modules\Social\Jobs;

use App\Modules\Social\Exceptions\CommentProviderException;
use App\Modules\Social\Models\SocialAccount;
use App\Modules\Social\Models\SocialComment;
use App\Modules\Social\Models\SocialCommentOperation;
use App\Modules\Social\Models\SocialCommentPost;
use App\Modules\Social\Services\MetaCommentProvider;
use App\Modules\Social\Services\SocialCommentService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class SyncSocialComments implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public int $timeout = 120;

    public function __construct(public int $accountId, public int $workspaceId, public bool $checkConnection = false)
    {
        $this->onQueue('social');
    }

    public function backoff(): array
    {
        return [60, 180, 600];
    }

    public function handle(MetaCommentProvider $provider, SocialCommentService $service): void
    {
        if (! config('social_comments.enabled')) {
            return;
        }
        Cache::lock('social-comment-sync:'.$this->accountId, 140)->block(2, function () use ($provider, $service) {
            $account = SocialAccount::where('workspace_id', $this->workspaceId)->where('active', true)->find($this->accountId);
            if (! $account) {
                return;
            }
            $settings = $service->settings($account);
            $fingerprint = $provider->fingerprint($account);
            try {
                if ($this->checkConnection || $settings->connection_status !== 'ready') {
                    $caps = $provider->verifyAndSubscribe($account);
                    if (! $account->fresh() || $provider->fingerprint($account->fresh()) !== $fingerprint) {
                        return;
                    }
                    $settings->update(['capabilities' => $caps, 'connection_status' => 'ready']);
                }
                $ig = $account->network === 'instagram';
                $state = $settings->sync_cursor;
                if (! $state) {
                    $tasks = [['kind' => 'posts', 'id' => $account->account_id]];
                    // Previously observed posts may be older than the initial discovery window.
                    foreach (SocialCommentPost::where('workspace_id', $account->workspace_id)->where('social_account_id', $account->id)
                        ->where('updated_at', '>=', now()->subDays(30))->orderByDesc('id')->limit(100)->get() as $post) {
                        $tasks[] = ['kind' => 'comments', 'id' => $post->remote_id, 'post' => $post->remote_id];
                    }
                    $state = ['tasks' => $tasks, 'pages' => 0];
                }
                $settings->update(['sync_started_at' => now()]);
                for ($batch = 0; $batch < 3 && $state['tasks'] !== [] && $state['pages'] < 500; $batch++) {
                    $task = $state['tasks'][0];
                    if ($task['kind'] === 'posts') {
                        $data = $provider->request($account, 'GET', $account->account_id.'/'.($ig ? 'media' : 'posts'), array_filter([
                            'fields' => $ig ? 'id,caption,permalink,timestamp' : 'id,message,permalink_url,created_time', 'limit' => 25, 'after' => $task['after'] ?? null,
                        ]));
                    } else {
                        $data = $provider->comments($account, $task['id'], $task['after'] ?? null, $task['kind'] === 'replies');
                    }
                    $fresh = $account->fresh();
                    if (! $fresh || ! $fresh->active || $provider->fingerprint($fresh) !== $fingerprint) {
                        return;
                    }
                    array_shift($state['tasks']);
                    $older = false;
                    foreach ($data['data'] ?? [] as $item) {
                        if ($task['kind'] === 'posts') {
                            $date = $item['timestamp'] ?? $item['created_time'] ?? null;
                            if ($date && Carbon::parse($date)->lt(now()->subDays(config('social_comments.sync_days')))) {
                                $older = true;

                                continue;
                            }
                            SocialCommentPost::updateOrCreate(['social_account_id' => $account->id, 'remote_id' => $item['id']], [
                                'workspace_id' => $account->workspace_id, 'body' => $item['caption'] ?? $item['message'] ?? null,
                                'permalink' => $item['permalink'] ?? $item['permalink_url'] ?? null, 'posted_at' => $date,
                            ]);
                            $state['tasks'][] = ['kind' => 'comments', 'id' => $item['id'], 'post' => $item['id']];
                        } else {
                            $comment = $service->ingest($account, $task['post'], $item, true, $task['kind'] === 'replies' ? $task['id'] : null);
                            if ($task['kind'] === 'comments') {
                                $state['tasks'][] = ['kind' => 'replies', 'id' => $item['id'], 'post' => $task['post']];
                            }
                            if ($comment && $comment->is_own && $comment->parent_remote_id) {
                                // Read-back confirmation only. No unmatched unknown request is resent.
                                $ops = SocialCommentOperation::where('workspace_id', $this->workspaceId)->whereIn('status', ['sending', 'delivery_unknown'])
                                    ->where('kind', 'reply')->where('body', $comment->body)->where('created_at', '<=', $comment->posted_at)
                                    ->where('created_at', '>=', $comment->posted_at->copy()->subMinutes(10))
                                    ->whereIn('comment_id', SocialComment::where('social_account_id', $account->id)->where('remote_id', $comment->parent_remote_id)->select('id'))->get();
                                if ($ops->count() === 1) {
                                    $op = $ops->first();
                                    $op->update(['status' => 'sent', 'remote_id' => $comment->remote_id, 'reason' => null]);
                                    $comment->update(['reply_source' => $op->source === 'automatic' ? 'ai' : 'agent']);
                                    SocialComment::where('workspace_id', $this->workspaceId)->whereKey($op->comment_id)
                                        ->update(['status' => $op->source === 'automatic' ? 'ai_handled' : 'resolved']);
                                }
                            }
                        }
                    }
                    // Reconstruct cursor requests at the fixed origin; never follow provider-supplied next URLs.
                    if (! $older && isset($data['paging']['next'], $data['paging']['cursors']['after'])) {
                        $task['after'] = $data['paging']['cursors']['after'];
                        $state['tasks'][] = $task;
                    }
                    $state['pages']++;
                    $settings->update(['sync_cursor' => $state]);
                }
                $done = $state['tasks'] === [];
                $settings->update(['sync_cursor' => $done ? null : $state, 'last_synced_at' => $done ? now() : $settings->last_synced_at,
                    'connection_status' => $state['pages'] >= 500 && ! $done ? 'sync_limit_reached' : 'ready']);
                if (! $done && $state['pages'] < 500) {
                    self::dispatch($account->id, $account->workspace_id)->delay(now()->addSeconds(10));
                }
            } catch (CommentProviderException $e) {
                $settings->update(['connection_status' => $e->reason]);
                if (in_array($e->reason, ['rate_limited', 'temporarily_unavailable'], true) && $this->attempts() < $this->tries) {
                    $this->release($e->retryAfter);
                }
            }
        });
    }
}
