<?php

namespace App\Modules\Social\Http\Controllers;

use App\Modules\Social\Services\XContentRules;
use App\Modules\Social\Services\SocialMediaRules;
use App\Modules\Social\Services\XPostContent;
use App\Http\Controllers\Controller;
use App\Modules\AI\Exceptions\AiCreditsException;
use App\Modules\AI\Services\LlmGateway;
use App\Modules\AI\Services\ProviderErrorPresenter;
use App\Modules\Social\Exceptions\PublishedPostLifecycleException;
use App\Modules\Social\Jobs\PublishSocialPostJob;
use App\Modules\Social\Models\SocialAccount;
use App\Modules\Social\Models\SocialPost;
use App\Modules\Social\Services\PublishedPostLifecycle;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class SocialPostController extends Controller
{
    public function __construct(private readonly PublishedPostLifecycle $publishedPosts) {}

    private function workspaceId(Request $request): int
    {
        return (int) ($request->user()->current_workspace_id ?? $request->user()->workspace_id);
    }

    public function index(Request $request): Response
    {
        $wid = $this->workspaceId($request);

        $status = $request->query('status');
        $network = $request->query('network');

        $accounts = SocialAccount::where('workspace_id', $wid)
            ->where('active', true)
            ->get(['id', 'network', 'name', 'picture_url']);

        // Collect account IDs for the requested network filter
        $networkAccountIds = $network
            ? $accounts->where('network', $network)->pluck('id')->map(fn ($id) => (string) $id)
            : collect();

        $query = SocialPost::where('workspace_id', $wid)
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($network && $networkAccountIds->isNotEmpty(), function ($q) use ($networkAccountIds) {
                $q->where(function ($inner) use ($networkAccountIds) {
                    foreach ($networkAccountIds as $aid) {
                        $inner->orWhereJsonContains('target_accounts', $aid)
                            ->orWhereJsonContains('target_accounts', (int) $aid);
                    }
                });
            })
            ->orderByDesc('created_at');

        $posts = $query->paginate(20)->withQueryString();
        $posts->getCollection()->each(function (SocialPost $post): void {
            $post->setAttribute('remote_lifecycle', $this->publishedPosts->capabilities($post));
        });

        return Inertia::render('Social/Posts/Index', [
            'posts' => $posts,
            'accounts' => $accounts,
            'filters' => ['status' => $status, 'network' => $network],
        ]);
    }

    public function composer(Request $request): Response
    {
        $wid = $this->workspaceId($request);
        $accounts = SocialAccount::where('workspace_id', $wid)
            ->where('active', true)
            ->where(fn ($query) => $query->whereNull('token_expires_at')->orWhere('token_expires_at', '>', now())
                // X tokens are refreshed right before publishing.
                ->orWhere(fn ($x) => $x->where('network', 'twitter')->whereNotNull('refresh_token')))
            ->get(['id', 'network', 'name', 'picture_url']);

        return Inertia::render('Social/Composer', ['accounts' => $accounts]);
    }

    public function calendar(Request $request): Response
    {
        $wid = $this->workspaceId($request);
        $month = $request->query('month', now()->format('Y-m'));
        abort_unless(preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month), 422, 'Invalid month format.');

        $filterStatus = $request->query('status');
        $filterAccountId = $request->query('account_id');
        $filterNetwork = $request->query('network');

        $userTz = $request->user()?->timezone ?? 'Asia/Dhaka';
        try {
            $tz = new \DateTimeZone($userTz);
        } catch (\Exception) {
            $tz = new \DateTimeZone('Asia/Dhaka');
        }

        [$year, $mon] = explode('-', $month);
        $start = Carbon::createFromDate((int) $year, (int) $mon, 1, $tz)->startOfMonth()->utc();
        $end = Carbon::createFromDate((int) $year, (int) $mon, 1, $tz)->endOfMonth()->utc();

        $accounts = SocialAccount::where('workspace_id', $wid)
            ->where('active', true)
            ->get(['id', 'network', 'name', 'picture_url']);

        // Resolve account IDs for a network filter
        $networkAccountIds = $filterNetwork
            ? $accounts->where('network', $filterNetwork)->pluck('id')->map(fn ($id) => (string) $id)
            : collect();

        $posts = SocialPost::where('workspace_id', $wid)
            ->whereNotNull('scheduled_at')
            ->whereBetween('scheduled_at', [$start, $end])
            ->when($filterStatus, fn ($q) => $q->where('status', $filterStatus))
            ->when($filterAccountId, function ($q) use ($filterAccountId) {
                $q->where(function ($inner) use ($filterAccountId) {
                    $inner->orWhereJsonContains('target_accounts', $filterAccountId)
                        ->orWhereJsonContains('target_accounts', (int) $filterAccountId);
                });
            })
            ->when($filterNetwork && $networkAccountIds->isNotEmpty(), function ($q) use ($networkAccountIds) {
                $q->where(function ($inner) use ($networkAccountIds) {
                    foreach ($networkAccountIds as $aid) {
                        $inner->orWhereJsonContains('target_accounts', $aid)
                            ->orWhereJsonContains('target_accounts', (int) $aid);
                    }
                });
            })
            ->get(['id', 'title', 'status', 'scheduled_at', 'timezone', 'target_accounts']);

        return Inertia::render('Social/Calendar', [
            'posts' => $posts,
            'month' => $month,
            'accounts' => $accounts,
            'filters' => [
                'status' => $filterStatus,
                'account_id' => $filterAccountId,
                'network' => $filterNetwork,
            ],
        ]);
    }

    public function store(Request $request): JsonResponse|RedirectResponse
    {
        $wid = $this->workspaceId($request);
        $this->normalizeSameOriginMediaUrls($request);
        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:256'],
            'body' => ['required', 'string', 'max:5000'],
            'media_urls' => ['nullable', 'array'],
            'media_urls.*' => ['nullable', 'url', 'regex:/^https:\/\//i', 'max:2048'],
            'target_accounts' => ['required', 'array', 'min:1'],
            'target_accounts.*' => ['integer'],
            'scheduled_at' => ['nullable', 'date'],
            'timezone' => ['nullable', 'string', 'max:64'],
            'delivery_mode' => ['nullable', 'in:schedule,publish_now'],
            ...XPostContent::RULES,
        ]);

        if (($validated['delivery_mode'] ?? null) === 'schedule' && empty($validated['scheduled_at'])) {
            throw ValidationException::withMessages([
                'scheduled_at' => ['Choose a future date and time for this scheduled post.'],
            ]);
        }
        if (($validated['delivery_mode'] ?? null) === 'publish_now') {
            $validated['scheduled_at'] = null;
        }
        unset($validated['delivery_mode']);

        // Ensure every requested account belongs to this workspace (cross-workspace IDOR guard).
        $requestedIds = collect($validated['target_accounts'])->map(fn ($id) => (int) $id);
        $ownedCount = SocialAccount::where('workspace_id', $wid)
            ->whereIn('id', $requestedIds)
            ->count();
        if ($ownedCount !== $requestedIds->count()) {
            throw ValidationException::withMessages([
                'target_accounts' => ['One or more selected accounts do not belong to your workspace.'],
            ]);
        }

        $selectedNetworks = SocialAccount::where('workspace_id', $wid)
            ->whereIn('id', $requestedIds)
            ->pluck('network')
            ->unique();
        $mediaUrls = $validated['media_urls'] ?? [];
        // Each network's own media limits (X is checked separately below).
        if (($mediaErrors = app(SocialMediaRules::class)->errors($selectedNetworks, $mediaUrls)) !== []) {
            throw ValidationException::withMessages(['media_urls' => $mediaErrors]);
        }
        $validated['network_content'] = app(XPostContent::class)
            ->validate($selectedNetworks, $validated['body'] ?? '', array_filter($mediaUrls), $validated['network_content'] ?? null);

        // scheduled_at arrives as UTC ISO from the frontend (already converted).
        // Allow a 30-second buffer to account for form submission latency.
        if (! empty($validated['scheduled_at']) && now()->subSeconds(30)->gt($validated['scheduled_at'])) {
            throw ValidationException::withMessages([
                'scheduled_at' => ['The scheduled time must be in the future.'],
            ]);
        }

        // Strip empty media URL entries before persisting.
        $validated['media_urls'] = array_values(array_filter($validated['media_urls'] ?? [], fn ($v) => $v !== null && $v !== ''));

        $post = SocialPost::create(array_merge($validated, [
            'workspace_id' => $wid,
            'status' => $validated['scheduled_at'] ? 'scheduled' : 'draft',
        ]));

        if ($post->scheduled_at) {
            $this->dispatchAtScheduledTime($post);
        } else {
            // Set the state before dispatching so a fast queue worker cannot
            // observe a draft while an immediate publish is already running.
            $post->update(['status' => 'publishing']);
            PublishSocialPostJob::dispatch($post->id)->onQueue('social');
        }

        if ($request->expectsJson()) {
            return response()->json(['success' => true, 'post_id' => $post->id]);
        }

        return redirect()->route('client.social.automation.index', [
            'tab' => $validated['scheduled_at'] ? 'upcoming' : 'all',
        ])
            ->with('success', 'Post '.($validated['scheduled_at'] ? 'scheduled' : 'queued for publishing').'.');
    }

    public function edit(Request $request, SocialPost $post): Response|RedirectResponse
    {
        abort_unless((int) $post->workspace_id === $this->workspaceId($request), 403);

        $capabilities = $this->publishedPosts->capabilities($post);
        if (! $capabilities['can_update']) {
            return redirect()->route('client.social.automation.index')
                ->with('error', $capabilities['reason'] ?? 'This post cannot be edited safely.');
        }

        $wid = $this->workspaceId($request);
        $accounts = SocialAccount::where('workspace_id', $wid)->where('active', true)->get(['id', 'network', 'name', 'picture_url']);

        return Inertia::render('Social/Posts/Edit', [
            'post' => $post,
            'accounts' => $accounts,
            'remoteLifecycle' => $capabilities,
        ]);
    }

    public function update(Request $request, SocialPost $post): RedirectResponse
    {
        abort_unless((int) $post->workspace_id === $this->workspaceId($request), 403);

        $capabilities = $this->publishedPosts->capabilities($post);
        if (! $capabilities['can_update']) {
            return back()->with('error', $capabilities['reason'] ?? 'This post cannot be edited safely.');
        }

        $this->normalizeSameOriginMediaUrls($request);
        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:256'],
            'body' => ['required', 'string', 'max:5000'],
            'media_urls' => ['nullable', 'array'],
            'media_urls.*' => ['nullable', 'url', 'regex:/^https:\/\//i', 'max:2048'],
            'target_accounts' => ['required', 'array', 'min:1'],
            'target_accounts.*' => ['integer'],
            'scheduled_at' => ['nullable', 'date'],
            'timezone' => ['nullable', 'string', 'max:64'],
            ...XPostContent::RULES,
        ]);

        $requestedIds = collect($validated['target_accounts'])->map(fn ($id) => (int) $id);
        $ownedCount = SocialAccount::where('workspace_id', $this->workspaceId($request))
            ->whereIn('id', $requestedIds)
            ->count();
        if ($ownedCount !== $requestedIds->count()) {
            throw ValidationException::withMessages([
                'target_accounts' => ['One or more selected accounts do not belong to your workspace.'],
            ]);
        }

        $selectedNetworks = SocialAccount::where('workspace_id', $this->workspaceId($request))
            ->whereIn('id', $requestedIds)
            ->pluck('network')
            ->unique();
        $mediaUrls = $validated['media_urls'] ?? [];
        // Each network's own media limits (X is checked separately below).
        if (($mediaErrors = app(SocialMediaRules::class)->errors($selectedNetworks, $mediaUrls)) !== []) {
            throw ValidationException::withMessages(['media_urls' => $mediaErrors]);
        }
        $validated['network_content'] = app(XPostContent::class)
            ->validate($selectedNetworks, $validated['body'] ?? '', array_filter($mediaUrls), $validated['network_content'] ?? null);

        // scheduled_at is historical metadata once a remote post is live. An
        // older client may still submit it even though scheduling is hidden,
        // so ignore it instead of rejecting an otherwise valid text update.
        if ($capabilities['has_remote_posts']) {
            $validated['scheduled_at'] = null;
            unset($validated['network_content']);
        } elseif (! empty($validated['scheduled_at']) && now()->subSeconds(30)->gt($validated['scheduled_at'])) {
            throw ValidationException::withMessages([
                'scheduled_at' => ['The scheduled time must be in the future.'],
            ]);
        }

        $validated['media_urls'] = array_values(array_filter($validated['media_urls'] ?? [], fn ($v) => $v !== null && $v !== ''));
        if (! $capabilities['has_remote_posts']) {
            $validated['status'] = $validated['scheduled_at'] ? 'scheduled' : 'draft';
        }

        try {
            $this->publishedPosts->update($post, $validated);
        } catch (PublishedPostLifecycleException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        $post->refresh();
        if (! $capabilities['has_remote_posts'] && $post->status === 'scheduled' && $post->scheduled_at) {
            $this->dispatchAtScheduledTime($post);
        }

        $message = $capabilities['has_remote_posts']
            ? 'Facebook Page post updated successfully.'
            : 'Post updated successfully.';

        return redirect()->route('client.social.automation.index')->with('success', $message);
    }

    /**
     * Queue the post as soon as it is scheduled so a continuously running
     * worker can publish at the requested second. The every-minute scheduler
     * remains a recovery path if the queue was unavailable at creation time.
     */
    private function dispatchAtScheduledTime(SocialPost $post): void
    {
        PublishSocialPostJob::dispatch($post->id)
            ->delay($post->scheduled_at)
            ->onQueue('social');
    }

    /**
     * Storage URLs created before APP_URL was configured as HTTPS may still be
     * submitted as http://. Upgrade only URLs hosted by this application; the
     * existing HTTPS-only validation remains in force for every external URL.
     */
    private function normalizeSameOriginMediaUrls(Request $request): void
    {
        $requestHost = strtolower($request->getHost());
        $upgrade = function ($url) use ($requestHost) {
            if (! is_string($url) || trim($url) === '') {
                return $url;
            }

            $host = strtolower((string) parse_url($url, PHP_URL_HOST));
            $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

            if ($scheme === 'http' && $host !== '' && hash_equals($requestHost, $host)) {
                return preg_replace('/^http:\/\//i', 'https://', $url, 1);
            }

            return $url;
        };

        $request->merge(['media_urls' => collect($request->input('media_urls', []))->map($upgrade)->all()]);

        $xMedia = $request->input('network_content.twitter.media_urls');
        if (is_array($xMedia)) {
            $request->merge(['network_content' => array_replace_recursive((array) $request->input('network_content'), [
                'twitter' => ['media_urls' => array_map(fn ($url) => $upgrade($url), $xMedia)],
            ])]);
        }
    }

    public function publishNow(Request $request, SocialPost $post): RedirectResponse
    {
        abort_unless((int) $post->workspace_id === $this->workspaceId($request), 403);
        abort_if($post->status === 'publishing', 422, 'Post is already being published.');
        abort_if($post->status === 'published', 422, 'Post is already published.');

        // Claim the post atomically: a double click or a second tab must not
        // queue a second publish job for the same post.
        $claimed = SocialPost::whereKey($post->id)
            ->whereNotIn('status', ['publishing', 'published'])
            ->update(['scheduled_at' => null, 'status' => 'publishing']);
        abort_unless($claimed === 1, 422, 'Post is already being published.');

        // Publishing again is the client's decision after an unconfirmed
        // attempt (the error told them to check the network first), so clear
        // that mark on the failed links.
        $post->accountLinks()->where('status', 'failed')->whereNotNull('provider_attempted_at')
            ->update(['provider_attempted_at' => null]);

        PublishSocialPostJob::dispatch($post->id)->onQueue('social');

        return back()->with('success', 'Post queued for immediate publishing.');
    }

    public function cancel(Request $request, SocialPost $post): RedirectResponse
    {
        abort_unless((int) $post->workspace_id === $this->workspaceId($request), 403);
        abort_unless($post->status === 'scheduled', 422, 'Only scheduled posts can be cancelled.');

        $post->update(['status' => 'draft', 'scheduled_at' => null]);

        return back()->with('success', 'Scheduled post cancelled and moved to drafts.');
    }

    public function destroy(Request $request, SocialPost $post): RedirectResponse
    {
        abort_unless((int) $post->workspace_id === $this->workspaceId($request), 403);

        $capabilities = $this->publishedPosts->capabilities($post);
        if (! $capabilities['can_delete']) {
            return back()->with('error', $capabilities['reason'] ?? 'This post cannot be deleted safely.');
        }

        $remoteNetworks = $post->accountLinks()
            ->where('status', 'published')
            ->whereNotNull('platform_post_id')
            ->whereNull('deleted_at')
            ->with('account:id,network')
            ->get()
            ->pluck('account.network')
            ->filter()
            ->unique()
            ->values();

        try {
            $this->publishedPosts->delete($post);
        } catch (PublishedPostLifecycleException $e) {
            return back()->with('error', $e->getMessage());
        }

        $keptOnX = $remoteNetworks->intersect(PublishedPostLifecycle::LOCAL_ONLY_NETWORKS)->isNotEmpty();
        $remoteNetworks = $remoteNetworks->diff(PublishedPostLifecycle::LOCAL_ONLY_NETWORKS)->values();

        return back()->with(
            'success',
            ($keptOnX ? 'The X copy stays on X. ' : '').($capabilities['has_remote_posts']
                ? ($remoteNetworks->all() === ['facebook']
                    ? 'Post deleted from Facebook and WisperBot.'
                    : ($remoteNetworks->all() === ['instagram']
                        ? 'Post deleted from Instagram and WisperBot.'
                        : 'Post deleted from the connected social accounts and WisperBot.'))
                : 'Post deleted.')
        );
    }

    public function removeLocal(Request $request, SocialPost $post): RedirectResponse
    {
        abort_unless((int) $post->workspace_id === $this->workspaceId($request), 403);

        try {
            $keptOnX = $this->publishedPosts->removeLocal($post);
        } catch (PublishedPostLifecycleException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with(
            'success',
            $keptOnX
                ? 'Post removed from WisperBot. It stays on X.'
                : 'Post record removed from WisperBot. The remote post was not deleted because its connected account is unavailable.'
        );
    }

    public function aiPlan(Request $request): JsonResponse
    {
        $wid = $this->workspaceId($request);

        $validated = $request->validate([
            'topic' => ['required', 'string', 'max:500'],
            'campaign_goal' => ['nullable', 'string', 'max:200'],
            'tone' => ['nullable', 'string', 'in:professional,casual,humorous,inspirational,educational'],
            'post_count' => ['nullable', 'integer', 'min:3', 'max:14'],
            'start_date' => ['required', 'date', 'after_or_equal:today'],
            'end_date' => ['required', 'date', 'after:start_date'],
            'target_accounts' => ['required', 'array', 'min:1'],
            'target_accounts.*' => ['integer'],
            'timezone' => ['nullable', 'string', 'max:64'],
        ]);

        $requestedIds = collect($validated['target_accounts'])->map(fn ($id) => (int) $id);
        $accounts = SocialAccount::where('workspace_id', $wid)
            ->whereIn('id', $requestedIds)
            ->where('active', true)
            ->get(['id', 'network', 'name']);

        if ($accounts->count() !== $requestedIds->count()) {
            return response()->json(['errors' => ['target_accounts' => ['One or more selected accounts are invalid.']]], 403);
        }

        $networks = $accounts->pluck('network')->unique()->values()->all();
        $postCount = $validated['post_count'] ?? 7;
        $tone = $validated['tone'] ?? 'professional';
        $goal = $validated['campaign_goal'] ?? 'increase engagement and brand awareness';

        try {
            $gateway = app(LlmGateway::class);
            $messages = $this->buildPlanMessages(
                $validated['topic'], $networks, $postCount, $tone, $goal,
                $validated['start_date'], $validated['end_date'], $validated['timezone'] ?? 'UTC'
            );
            $response = $gateway->chat($wid, $messages, [
                'temperature' => 0.7,
                'max_tokens' => 4096,
                'feature' => 'social_plan',
                'idempotency_key' => $request->header('Idempotency-Key') ?? 'social-plan:'.(string) Str::uuid(),
            ]);
            $posts = $this->parsePlanResponse($response->content, $postCount);

            return response()->json(['posts' => $posts, 'accounts' => $accounts]);
        } catch (AiCreditsException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $error = ProviderErrorPresenter::present($e);

            return response()->json(['error' => $error['message'], 'error_code' => $error['code']], 422);
        }
    }

    private function buildPlanMessages(
        string $topic,
        array $networks,
        int $count,
        string $tone,
        string $goal,
        string $startDate,
        string $endDate,
        string $timezone
    ): array {
        $networksStr = implode(', ', $networks);
        $limits = ['tiktok' => 2200, 'linkedin' => 3000, 'facebook' => 63206, 'instagram' => 2200, 'youtube' => 5000, 'twitter' => 280];
        $limitLines = collect($networks)->map(fn ($n) => "- {$n}: ".($limits[$n] ?? 5000).' characters')->implode("\n");
        // X posts are rejected if they contain any link, so the plan must not include one.
        $xRule = in_array('twitter', $networks, true)
            ? "\n9. X (twitter) posts must not contain links: never include a URL, web address, domain name or e-mail address anywhere in \"body\"."
            : '';

        $system = <<<SYSTEM
You are an expert social media strategist. Generate a content calendar as JSON.

RULES:
1. Output ONLY valid JSON — no markdown, no prose, no code fences.
2. Top-level object must be: {"posts": [...]}
3. Generate exactly {$count} posts spread evenly between {$startDate} and {$endDate}.
4. Each post must have EXACTLY these fields:
   - "title": short title (string, max 100 chars)
   - "body": post content (string)
   - "suggested_time": UTC ISO 8601 datetime (e.g. "2026-06-01T10:00:00Z")
   - "rationale": one sentence explaining timing/approach (string)
   - "platform_notes": object keyed by network with tailored copy variants, or null
5. Character limits per network:
{$limitLines}
6. Primary "body" must fit the SHORTEST character limit among: {$networksStr}
7. Tone: {$tone}. Campaign goal: {$goal}.
8. If you cannot produce valid JSON, return exactly: {"error": "generation_failed"}{$xRule}
SYSTEM;

        return [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user',   'content' => "Create a {$count}-post campaign calendar for: {$topic}\nPlatforms: {$networksStr}\nSchedule: {$startDate} to {$endDate} ({$timezone})."],
        ];
    }

    private function parsePlanResponse(string $content, int $expectedCount): array
    {
        $cleaned = preg_replace('/^```(?:json)?\s*/i', '', trim($content));
        $cleaned = preg_replace('/\s*```$/', '', $cleaned);

        $decoded = json_decode($cleaned, true);

        if (json_last_error() !== JSON_ERROR_NONE || ! isset($decoded['posts'])) {
            throw new \RuntimeException('AI returned malformed JSON. Please try again.');
        }
        if (isset($decoded['error'])) {
            throw new \RuntimeException('AI failed to generate the plan. Please refine your brief.');
        }

        return collect($decoded['posts'])->map(function ($post, $i) {
            if (empty($post['body'])) {
                throw new \RuntimeException("Post #{$i} is missing body content.");
            }

            return [
                'title' => $post['title'] ?? '',
                'body' => $post['body'],
                'suggested_time' => $post['suggested_time'] ?? null,
                'rationale' => $post['rationale'] ?? '',
                'platform_notes' => $post['platform_notes'] ?? null,
            ];
        })->all();
    }

    public function bulkStore(Request $request): JsonResponse
    {
        $wid = $this->workspaceId($request);

        $validated = $request->validate([
            'posts' => ['required', 'array', 'min:1', 'max:14'],
            'posts.*.title' => ['nullable', 'string', 'max:256'],
            'posts.*.body' => ['required', 'string', 'max:5000'],
            'posts.*.scheduled_at' => ['nullable', 'date'],
            'posts.*.timezone' => ['nullable', 'string', 'max:64'],
            'posts.*.target_accounts' => ['required', 'array', 'min:1'],
            'posts.*.target_accounts.*' => ['integer'],
            'posts.*.ai_prompt' => ['nullable', 'string', 'max:1000'],
        ]);

        $allIds = collect($validated['posts'])
            ->flatMap(fn ($p) => $p['target_accounts'])
            ->map(fn ($id) => (int) $id)
            ->unique();

        $ownedCount = SocialAccount::where('workspace_id', $wid)->whereIn('id', $allIds)->count();
        if ($ownedCount !== $allIds->count()) {
            return response()->json(['errors' => ['posts' => ['One or more accounts do not belong to your workspace.']]], 403);
        }

        $now = now();
        $xAccountIds = SocialAccount::where('workspace_id', $wid)->whereIn('id', $allIds)->where('network', 'twitter')->pluck('id')->all();
        foreach ($validated['posts'] as $i => $postData) {
            if (! empty($postData['scheduled_at']) && $now->copy()->addMinute()->gt($postData['scheduled_at'])) {
                return response()->json(['errors' => ["posts.{$i}.scheduled_at" => ['Must be at least 1 minute in the future.']]], 422);
            }
            // Planner posts reach the publisher without the composer, so X rules apply here too.
            if (array_intersect(array_map('intval', $postData['target_accounts']), $xAccountIds) !== []
                && ($xErrors = app(XContentRules::class)->errors($postData['body'])) !== []) {
                return response()->json(['errors' => ["posts.{$i}.body" => $xErrors]], 422);
            }
        }

        $created = [];
        \DB::transaction(function () use ($validated, $wid, &$created) {
            foreach ($validated['posts'] as $postData) {
                $scheduledAt = $postData['scheduled_at'] ?? null;
                $post = SocialPost::create([
                    'workspace_id' => $wid,
                    'title' => $postData['title'] ?? null,
                    'body' => $postData['body'],
                    'media_urls' => [],
                    'target_accounts' => array_map('intval', $postData['target_accounts']),
                    'scheduled_at' => $scheduledAt,
                    'timezone' => $postData['timezone'] ?? 'UTC',
                    'status' => $scheduledAt ? 'scheduled' : 'draft',
                    'ai_generated' => true,
                    'ai_prompt' => $postData['ai_prompt'] ?? null,
                ]);
                $created[] = $post->id;
            }
        });

        return response()->json(['success' => true, 'created' => count($created), 'post_ids' => $created]);
    }

    /** AI Post Planner – generate body copy from a prompt. */
    public function aiGenerate(Request $request): JsonResponse
    {
        $wid = $this->workspaceId($request);
        $request->validate([
            'prompt' => ['required', 'string', 'max:500'],
            'network' => ['nullable', 'string'],
        ]);

        try {
            $gateway = app(LlmGateway::class);
            $network = $request->network ?? 'any social network';
            $messages = [
                ['role' => 'system', 'content' => "You are a social media copywriter. Write engaging, concise posts optimized for {$network}. Return ONLY the post text, no explanations."],
                ['role' => 'user',   'content' => $request->prompt],
            ];
            $response = $gateway->chat($wid, $messages, [
                'feature' => 'social_post',
                'idempotency_key' => $request->header('Idempotency-Key') ?? 'social-post:'.(string) Str::uuid(),
            ]);

            return response()->json(['body' => $response->content]);
        } catch (AiCreditsException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $error = ProviderErrorPresenter::present($e);

            return response()->json(['error' => $error['message'], 'error_code' => $error['code']], 422);
        }
    }
}
