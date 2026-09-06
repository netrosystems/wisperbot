<?php

namespace App\Modules\Social\Services;

use App\Modules\Social\Events\SocialCommentsChanged;
use App\Modules\Social\Jobs\ProcessSocialCommentOperation;
use App\Modules\Social\Models\SocialAccount;
use App\Modules\Social\Models\SocialComment;
use App\Modules\Social\Models\SocialCommentOperation;
use App\Modules\Social\Models\SocialCommentPost;
use App\Modules\Social\Models\SocialCommentSetting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SocialCommentService
{
    public function __construct(private MetaCommentProvider $provider) {}

    public function settings(SocialAccount $account): SocialCommentSetting
    {
        return SocialCommentSetting::firstOrCreate(['social_account_id' => $account->id], ['workspace_id' => $account->workspace_id]);
    }

    public function ancestorPaused(SocialComment $comment): bool
    {
        $parent = $comment->parent_remote_id;
        $seen = [];
        while ($parent && count($seen) < 20) {
            if (isset($seen[$parent])) {
                return true;
            }
            $seen[$parent] = true;
            $record = SocialComment::where('workspace_id', $comment->workspace_id)->where('social_account_id', $comment->social_account_id)
                ->where('post_id', $comment->post_id)->where('remote_id', $parent)->first();
            // Unknown ancestry cannot establish that automatic replies are safe.
            if (! $record || $record->ai_paused || $record->deleted) {
                return true;
            }
            $parent = $record->parent_remote_id;
        }

        return (bool) $parent;
    }

    public function ingest(SocialAccount $account, string $postId, array $data, bool $imported = false, ?string $parent = null): ?SocialComment
    {
        $id = (string) ($data['id'] ?? $data['comment_id'] ?? '');
        if (! preg_match('/^[a-zA-Z0-9_]+$/D', $id) || ! preg_match('/^[a-zA-Z0-9_]+$/D', $postId)) {
            return null;
        }

        return Cache::lock('social-comment-ingest:'.$account->id.':'.$id, 30)->block(5, function () use ($account, $postId, $data, $imported, $parent, $id) {
            $comment = DB::transaction(function () use ($account, $postId, $data, $imported, $parent, $id) {
                $post = SocialCommentPost::firstOrCreate(['social_account_id' => $account->id, 'remote_id' => $postId], ['workspace_id' => $account->workspace_id]);
                $comment = SocialComment::where('workspace_id', $account->workspace_id)->where('social_account_id', $account->id)->where('remote_id', $id)->lockForUpdate()->first();
                $time = $data['updated_time'] ?? $data['timestamp'] ?? $data['created_time'] ?? null;
                try {
                    $at = $time ? (is_numeric($time) ? Carbon::createFromTimestamp((int) $time) : Carbon::parse($time)) : now();
                } catch (\Throwable) {
                    $at = now();
                }
                if ($comment && $comment->remote_updated_at && $at->lt($comment->remote_updated_at)) {
                    return null;
                }
                $author = (string) ($data['from']['id'] ?? $data['sender_id'] ?? '');
                $ownIds = array_filter([(string) $account->account_id, (string) ($account->meta['page_id'] ?? '')]);
                $own = in_array($author, $ownIds, true);
                $deleted = in_array($data['verb'] ?? '', ['remove', 'delete'], true);
                if ($comment?->deleted && ! $deleted) {
                    return null;
                }
                $parentId = $parent ?? $data['parent_id'] ?? $data['parent']['id'] ?? $comment?->parent_remote_id;
                if ($parentId === $postId) {
                    $parentId = null;
                }
                $body = $deleted ? null : mb_substr((string) ($data['message'] ?? $data['text'] ?? $comment->body ?? ''), 0, 10000);
                if ($comment && $comment->body === $body && $comment->deleted === $deleted
                    && ($author === '' || $comment->author_id === $author) && $comment->parent_remote_id === $parentId
                    && (bool) ($data['is_hidden'] ?? $comment->hidden) === $comment->hidden) {
                    return null;
                }
                $values = [
                    'workspace_id' => $account->workspace_id, 'social_account_id' => $account->id, 'post_id' => $post->id,
                    'remote_id' => $id, 'parent_remote_id' => $parentId,
                    'author_id' => $author ?: $comment?->author_id, 'author_name' => mb_substr((string) ($data['from']['name'] ?? $data['from']['username'] ?? $comment->author_name ?? 'Customer'), 0, 255),
                    'body' => $body, 'is_own' => $own || (bool) $comment?->is_own, 'imported' => $imported || (bool) $comment?->imported,
                    'deleted' => $deleted, 'hidden' => (bool) ($data['is_hidden'] ?? $comment->hidden ?? false),
                    'posted_at' => $comment->posted_at ?? $at, 'remote_updated_at' => $at,
                    'revision' => $comment ? $comment->revision + 1 : 1,
                    'status' => $deleted ? 'resolved' : ($own ? 'resolved' : 'needs_attention'),
                ];
                if ($comment) {
                    $comment->update($values);
                } else {
                    $comment = SocialComment::create($values);
                }
                // A human/business reply outside WisperBot also cancels pending AI.
                if ($own && $comment->parent_remote_id) {
                    SocialComment::where('social_account_id', $account->id)->where('workspace_id', $account->workspace_id)
                        ->where('remote_id', $comment->parent_remote_id)->update(['ai_paused' => true, 'status' => 'resolved', 'revision' => DB::raw('revision + 1')]);
                }

                return $comment;
            });
            if ($comment) {
                SocialCommentsChanged::dispatch($account->workspace_id, $comment->id);
                $settings = $this->settings($account);
                $alreadyAnswered = SocialCommentOperation::where('comment_id', $comment->id)->where('kind', 'reply')->whereIn('status', ['queued', 'sending', 'sent', 'delivery_unknown'])->exists();
                if ($comment->author_id && ! $alreadyAnswered && ! $comment->is_own && ! $comment->imported && ! $comment->deleted && ! $comment->ai_paused && ! $this->ancestorPaused($comment) && $settings->mode !== 'off') {
                    $this->enqueue($comment, 'suggest', '', 'auto:'.$comment->id.':'.$comment->revision, null, $settings->mode === 'automatic' ? 'automatic' : 'suggestion');
                }
            }

            return $comment;
        });
    }

    public function enqueue(SocialComment $comment, string $kind, string $body, string $key, ?int $actorId, string $source = 'agent'): SocialCommentOperation
    {
        return Cache::lock('social-comment:'.$comment->id, 90)->block(5, function () use ($comment, $kind, $body, $key, $actorId, $source) {
            $comment->refresh()->load('account');
            if (! $comment->account || ! SocialCommentCapabilities::implemented($comment->account->network)) {
                throw ValidationException::withMessages(['account' => 'Comments are not available for this platform in WisperBot yet.']);
            }
            $hash = hash('sha256', $comment->workspace_id.':'.$comment->id.':'.$kind.':'.$key);
            if ($existing = SocialCommentOperation::where('workspace_id', $comment->workspace_id)->where('idempotency_key', $hash)->first()) {
                if ($existing->body !== ($body ?: null) && $kind !== 'suggest') {
                    throw ValidationException::withMessages(['body' => 'This request identifier was already used for a different reply.']);
                }

                return $existing;
            }
            if ($comment->deleted || $comment->is_own || ! $comment->account->active) {
                throw ValidationException::withMessages(['comment' => 'This comment is no longer available for replies.']);
            }
            $settings = $this->settings($comment->account);
            $capability = match ($kind) {
                'hide', 'unhide' => 'hide', 'delete' => 'delete', default => 'reply'
            };
            if (! ($settings->capabilities[$capability] ?? false) || $settings->connection_status !== 'ready') {
                throw ValidationException::withMessages(['account' => 'Check the comment connection or reconnect this account first.']);
            }
            if ($kind !== 'suggest' && SocialCommentOperation::where('comment_id', $comment->id)->whereIn('status', ['queued', 'sending', 'delivery_unknown'])->where('kind', '!=', 'suggest')->exists()) {
                throw ValidationException::withMessages(['comment' => 'A reply is already processing or its delivery is being checked.']);
            }
            if ($source === 'agent' && $kind !== 'suggest') {
                $comment->update(['ai_paused' => true, 'revision' => $comment->revision + 1]);
            }
            $operation = SocialCommentOperation::create([
                'id' => (string) Str::uuid(), 'workspace_id' => $comment->workspace_id, 'comment_id' => $comment->id,
                'actor_id' => $actorId, 'kind' => $kind, 'source' => $source, 'body' => $body ?: null,
                'idempotency_key' => $hash, 'credential_fingerprint' => $this->provider->fingerprint($comment->account),
                'comment_revision' => $comment->revision,
            ]);
            ProcessSocialCommentOperation::dispatch($operation->id, $comment->workspace_id)->onQueue($kind === 'suggest' ? 'ai' : 'social')->afterCommit();

            return $operation;
        });
    }
}
