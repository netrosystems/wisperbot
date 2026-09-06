<?php

namespace App\Modules\Social\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Workspace;
use App\Modules\AI\Models\AiChatbot;
use App\Modules\Social\Events\SocialCommentsChanged;
use App\Modules\Social\Jobs\ProcessSocialCommentOperation;
use App\Modules\Social\Jobs\SyncSocialComments;
use App\Modules\Social\Models\SocialAccount;
use App\Modules\Social\Models\SocialComment;
use App\Modules\Social\Models\SocialCommentOperation;
use App\Modules\Social\Services\SocialCommentCapabilities;
use App\Modules\Social\Services\SocialCommentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class SocialCommentController extends Controller
{
    public function __construct(private SocialCommentService $service) {}

    private function workspace(Request $request, bool $write = false, bool $admin = false): Workspace
    {
        abort_unless(config('social_comments.enabled'), 404);
        $user = $request->user();
        $workspace = Workspace::findOrFail($request->is('api/*') ? $user->workspace_id : ($user->current_workspace_id ?? $user->workspace_id));
        $member = $workspace->members()->where('user_id', $user->id)->first();
        $owner = (int) $workspace->owner_id === (int) $user->id;
        $primary = (int) $user->workspace_id === (int) $workspace->id;
        abort_unless($owner || $member || $primary, 403);
        $role = $member?->pivot->getAttribute('role') ?? ($primary ? $user->client_role : null);
        if ($write) {
            abort_unless($owner || in_array($role, ['admin', 'owner', 'administrator', 'agent'], true), 403);
        }
        if ($admin) {
            abort_unless($owner || in_array($role, ['admin', 'owner', 'administrator'], true), 403);
        }

        return $workspace;
    }

    private function comment(Request $request, int $id, bool $write = false): SocialComment
    {
        return SocialComment::with('account', 'post')->where('workspace_id', $this->workspace($request, $write)->id)->findOrFail($id);
    }

    public function index(Request $request)
    {
        $workspace = $this->workspace($request);
        $filters = $request->validate(['tab' => ['nullable', Rule::in(['needs_attention', 'all', 'ai_handled', 'resolved'])],
            'network' => ['nullable', Rule::in(['facebook', 'instagram'])], 'account_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:200'], 'selected' => ['nullable', 'integer'], 'cursor' => ['nullable', 'string', 'max:2000']]);
        $filters['tab'] = $filters['tab'] ?? 'needs_attention';
        $query = SocialComment::where('workspace_id', $workspace->id)->where('is_own', false)
            ->when($filters['account_id'] ?? null, fn ($q, $id) => $q->where('social_account_id', $id))
            ->when($filters['network'] ?? null, fn ($q, $network) => $q->whereHas('account', fn ($a) => $a->where('network', $network)))
            ->when($filters['search'] ?? null, fn ($q, $term) => $q->where(fn ($inner) => $inner->where('body', 'like', '%'.$term.'%')->orWhere('author_name', 'like', '%'.$term.'%')));
        $counts = ['all' => (clone $query)->count()];
        foreach (['needs_attention', 'ai_handled', 'resolved'] as $status) {
            $counts[$status] = (clone $query)->where('status', $status)->count();
        }
        $comments = $query->when($filters['tab'] !== 'all', fn ($q) => $q->where('status', $filters['tab']))
            ->with(['account:id,name,network,picture_url,active', 'post:id,body,permalink'])
            ->orderByDesc('id')->cursorPaginate(40)->withQueryString();
        $reads = DB::table('social_comment_reads')->where('user_id', $request->user()->id)->whereIn('comment_id', $comments->pluck('id'))->pluck('read_at', 'comment_id');
        $comments->getCollection()->each(fn ($comment) => $comment->setAttribute('unread', ! isset($reads[$comment->id]) || $comment->updated_at->gt($reads[$comment->id])));
        $accounts = SocialAccount::where('workspace_id', $workspace->id)->whereIn('network', ['facebook', 'instagram'])->get()->map(function ($account) {
            return ['id' => $account->id, 'name' => $account->name, 'network' => $account->network, 'active' => $account->active,
                'settings' => $this->service->settings($account)];
        });
        $user = $request->user();
        $role = $workspace->members()->where('user_id', $user->id)->first()?->pivot->getAttribute('role') ?? $user->client_role;
        $manager = (int) $workspace->owner_id === (int) $user->id || in_array($role, ['owner', 'admin', 'administrator'], true);
        $data = ['commentPlatforms' => SocialCommentCapabilities::catalog(), 'comments' => $comments, 'counts' => $counts, 'filters' => $filters, 'accounts' => $accounts,
            'canManage' => $manager, 'canReply' => $manager || $role === 'agent', 'workspaceId' => $workspace->id,
            'chatbots' => $manager ? AiChatbot::where('workspace_id', $workspace->id)->where('enabled', true)->get(['id', 'name', 'ai_kb_id']) : []];

        return $request->is('api/*') || $request->wantsJson() ? response()->json($data) : Inertia::render('Social/Comments/Index', $data);
    }

    public function show(Request $request, int $comment)
    {
        $record = $this->comment($request, $comment);
        abort_unless($record->account !== null, 404);
        $replies = SocialComment::where('workspace_id', $record->workspace_id)->where('social_account_id', $record->social_account_id)
            ->where('parent_remote_id', $record->remote_id)->orderBy('id')->cursorPaginate(50)->withQueryString();
        $settings = $this->service->settings($record->account);
        $record->account->setVisible(['id', 'name', 'network', 'active']);

        return response()->json(['comment' => $record, 'replies' => $replies,
            'operations' => $record->operations()->latest()->limit(20)->get(), 'settings' => $settings]);
    }

    public function read(Request $request, int $comment)
    {
        $record = $this->comment($request, $comment);
        DB::table('social_comment_reads')->updateOrInsert(['comment_id' => $record->id, 'user_id' => $request->user()->id], ['read_at' => now()]);

        return response()->json(['success' => true]);
    }

    public function reply(Request $request, int $comment)
    {
        $record = $this->comment($request, $comment, true);
        $data = $request->validate(['body' => ['required', 'string', 'max:2000'], 'idempotency_key' => ['required', 'string', 'max:100']]);
        abort_if(trim($data['body']) === '', 422, 'Write a reply first.');

        return response()->json(['operation' => $this->service->enqueue($record, 'reply', trim($data['body']), $data['idempotency_key'], $request->user()->id)], 202);
    }

    public function suggest(Request $request, int $comment)
    {
        $record = $this->comment($request, $comment, true);
        abort_unless($record->account !== null, 404);
        $settings = $this->service->settings($record->account);
        $key = implode(':', ['suggest', $record->revision, $settings->chatbot_id, $settings->public_kb_revision_id]);

        return response()->json(['operation' => $this->service->enqueue($record, 'suggest', '', $key, $request->user()->id)], 202);
    }

    public function reviewSuggestion(Request $request, int $comment, string $operation)
    {
        $workspace = $this->workspace($request, true, true);
        $record = $this->comment($request, $comment);
        $op = SocialCommentOperation::where('workspace_id', $workspace->id)->where('comment_id', $record->id)
            ->where('status', 'suggested')->findOrFail($operation);
        abort_unless($record->account !== null, 404);
        $settings = $this->service->settings($record->account);
        abort_unless((int) ($op->diagnostics['revision_id'] ?? 0) === (int) $settings->public_kb_revision_id
            && (int) $op->comment_revision === (int) $record->revision, 409, 'This suggestion is outdated. Generate a new preview.');
        $settings->update(['previewed_at' => now()]);

        return response()->json(['message' => 'Preview reviewed. Automatic replies can now be enabled in AI reply settings.']);
    }

    public function update(Request $request, int $comment)
    {
        $record = $this->comment($request, $comment, true);
        $data = $request->validate(['status' => ['sometimes', Rule::in(['resolved', 'needs_attention'])], 'ai_paused' => ['sometimes', 'boolean']]);
        Cache::lock('social-comment:'.$record->id, 90)->block(5, function () use ($record, $data) {
            $record->refresh()->update($data + ['revision' => $record->revision + 1]);
        });
        SocialCommentsChanged::dispatch($record->workspace_id, $record->id);

        return response()->json(['comment' => $record->withoutRelations()]);
    }

    public function moderate(Request $request, int $comment)
    {
        $record = $this->comment($request, $comment, true);
        $data = $request->validate(['action' => ['required', Rule::in(['hide', 'unhide', 'delete'])], 'idempotency_key' => ['required', 'string', 'max:100']]);

        return response()->json(['operation' => $this->service->enqueue($record, $data['action'], '', $data['idempotency_key'], $request->user()->id)], 202);
    }

    public function retry(Request $request, string $operation)
    {
        $workspace = $this->workspace($request, true);
        $op = SocialCommentOperation::where('workspace_id', $workspace->id)->findOrFail($operation);
        // Unknown delivery is intentionally not retryable: check provider history first.
        abort_unless($op->status === 'failed' && $op->kind !== 'suggest', 409, 'This action cannot safely be retried. Check its delivery on the platform.');
        Cache::lock('social-comment:'.$op->comment_id, 90)->block(5, function () use ($op) {
            $op->refresh();
            if ($op->status !== 'failed') {
                return;
            }
            $op->update(['status' => 'queued', 'reason' => null]);
            ProcessSocialCommentOperation::dispatch($op->id, $op->workspace_id)->onQueue('social')->afterCommit();
        });

        return response()->json(['operation' => $op], 202);
    }

    public function settings(Request $request, int $account)
    {
        $workspace = $this->workspace($request, true, true);
        $account = SocialAccount::where('workspace_id', $workspace->id)->whereIn('network', ['facebook', 'instagram'])->findOrFail($account);
        $data = $request->validate(['mode' => ['required', Rule::in(['off', 'suggestions', 'automatic'])], 'chatbot_id' => ['nullable', 'integer'], 'public_kb_confirmed' => ['boolean']]);
        $settings = $this->service->settings($account);
        if ($data['mode'] !== 'off') {
            $bot = AiChatbot::with('knowledgeBase')->where('workspace_id', $workspace->id)->where('enabled', true)->whereKey($data['chatbot_id'] ?? 0)->firstOrFail();
            abort_unless($bot->knowledgeBase && (int) $bot->knowledgeBase->workspace_id === $workspace->id && $bot->knowledgeBase->published_revision_id, 422, 'Publish the chatbot Knowledge Base before enabling public replies.');
            abort_unless($request->boolean('public_kb_confirmed'), 422, 'Confirm that this Knowledge Base is appropriate for public replies.');
            if ($data['mode'] === 'automatic') {
                abort_unless($settings->previewed_at && (int) $settings->chatbot_id === $bot->id
                    && (int) $settings->public_kb_revision_id === (int) $bot->knowledgeBase->published_revision_id, 422, 'Preview an AI suggestion with this chatbot before enabling automatic replies.');
            }
        }
        $changedBot = $data['mode'] === 'off' || (int) $settings->chatbot_id !== (int) ($data['chatbot_id'] ?? 0)
            || (int) $settings->public_kb_revision_id !== (int) $bot->knowledgeBase->published_revision_id;
        $settings->update(['mode' => $data['mode'], 'chatbot_id' => $data['mode'] === 'off' ? null : $bot->id,
            'public_kb_confirmed_at' => $request->boolean('public_kb_confirmed') ? now() : null,
            'public_kb_revision_id' => $data['mode'] === 'off' ? null : $bot->knowledgeBase->published_revision_id,
            'previewed_at' => $changedBot ? null : $settings->previewed_at]);

        return response()->json(['settings' => $settings]);
    }

    public function sync(Request $request, int $account)
    {
        $workspace = $this->workspace($request, true, true);
        $account = SocialAccount::where('workspace_id', $workspace->id)->whereIn('network', ['facebook', 'instagram'])->findOrFail($account);
        if (Cache::add('social-comments-manual-sync:'.$account->id, true, 60)) {
            SyncSocialComments::dispatch($account->id, $workspace->id, true);
        }

        return response()->json(['status' => 'queued', 'message' => 'Checking access and synchronizing recent comments.'], 202);
    }
}
