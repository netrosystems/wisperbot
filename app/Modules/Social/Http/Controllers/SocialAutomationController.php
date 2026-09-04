<?php

namespace App\Modules\Social\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Social\Models\SocialAccount;
use App\Modules\Social\Models\SocialPost;
use App\Modules\Social\Services\PublishedPostLifecycle;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SocialAutomationController extends Controller
{
    public function __construct(private readonly PublishedPostLifecycle $publishedPosts) {}

    public function index(Request $request): Response
    {
        $validated = $request->validate([
            'tab' => ['nullable', Rule::in(['upcoming', 'drafts', 'published', 'failed', 'all'])],
            'view' => ['nullable', Rule::in(['list', 'calendar'])],
            'search' => ['nullable', 'string', 'max:200'],
            'network' => ['nullable', Rule::in(['facebook', 'instagram', 'linkedin', 'youtube', 'tiktok'])],
            'account_id' => ['nullable', 'integer'],
            'month' => ['nullable', 'date_format:Y-m'],
        ]);

        $workspaceId = (int) ($request->user()->current_workspace_id ?? $request->user()->workspace_id);
        $tab = $validated['tab'] ?? 'upcoming';
        $view = $validated['view'] ?? 'list';
        $search = trim((string) ($validated['search'] ?? ''));
        $network = $validated['network'] ?? null;
        $accountId = isset($validated['account_id']) ? (int) $validated['account_id'] : null;
        $month = $validated['month'] ?? now()->format('Y-m');

        $accounts = SocialAccount::query()
            ->where('workspace_id', $workspaceId)
            ->orderBy('network')
            ->orderBy('name')
            ->get(['id', 'network', 'name', 'picture_url', 'active', 'token_expires_at']);

        $activeAccounts = $accounts
            ->filter(fn (SocialAccount $account): bool => $account->active && ! $account->isTokenExpired())
            ->values();
        $ownedAccountId = $accountId && $accounts->contains('id', $accountId) ? $accountId : null;
        $invalidAccountFilter = $accountId !== null && $ownedAccountId === null;

        $filtered = SocialPost::query()->where('workspace_id', $workspaceId);
        $this->applyCommonFilters($filtered, $search, $network, $ownedAccountId, $invalidAccountFilter, $accounts);

        $tabCounts = [
            'upcoming' => (clone $filtered)->whereIn('status', ['scheduled', 'publishing'])->count(),
            'drafts' => (clone $filtered)->where('status', 'draft')->count(),
            'published' => (clone $filtered)->where('status', 'published')->count(),
            'failed' => (clone $filtered)->where('status', 'failed')->count(),
            'all' => (clone $filtered)->count(),
        ];

        $posts = null;
        $calendarPosts = [];

        if ($view === 'calendar') {
            $timezone = $this->timezone($request);
            [$year, $monthNumber] = array_map('intval', explode('-', $month));
            $start = Carbon::createFromDate($year, $monthNumber, 1, $timezone)->startOfMonth()->utc();
            $end = Carbon::createFromDate($year, $monthNumber, 1, $timezone)->endOfMonth()->utc();

            $calendarPosts = (clone $filtered)
                ->whereNotNull('scheduled_at')
                ->whereBetween('scheduled_at', [$start, $end])
                ->when($tab !== 'all', fn (Builder $query) => $this->applyTab($query, $tab))
                ->orderBy('scheduled_at')
                ->get(['id', 'title', 'body', 'status', 'scheduled_at', 'published_at', 'timezone', 'target_accounts', 'media_urls', 'publish_results', 'post_url']);
            $calendarPosts->each(function (SocialPost $post): void {
                $post->setAttribute('remote_lifecycle', $this->publishedPosts->capabilities($post));
            });
            $calendarPosts = $calendarPosts->values();
        } else {
            $listQuery = clone $filtered;
            $this->applyTab($listQuery, $tab);
            if ($tab === 'upcoming') {
                $listQuery->orderByRaw('COALESCE(scheduled_at, created_at) ASC');
            } else {
                $listQuery->orderByRaw('COALESCE(published_at, scheduled_at, created_at) DESC');
            }
            $posts = $listQuery->paginate(20)->withQueryString();

            $posts->getCollection()->each(function (SocialPost $post): void {
                $post->setAttribute('remote_lifecycle', $this->publishedPosts->capabilities($post));
            });
        }

        return Inertia::render('Social/Automation/Index', [
            'accounts' => $accounts,
            'activeAccounts' => $activeAccounts,
            'posts' => $posts,
            'calendarPosts' => $calendarPosts,
            'tabCounts' => $tabCounts,
            'filters' => [
                'tab' => $tab,
                'view' => $view,
                'search' => $search,
                'network' => $network,
                'account_id' => $ownedAccountId,
                'month' => $month,
            ],
        ]);
    }

    private function applyCommonFilters(
        Builder $query,
        string $search,
        ?string $network,
        ?int $accountId,
        bool $invalidAccountFilter,
        $accounts,
    ): void {
        $query->when($search !== '', function (Builder $query) use ($search): void {
            $query->where(function (Builder $inner) use ($search): void {
                $inner->where('title', 'like', "%{$search}%")
                    ->orWhere('body', 'like', "%{$search}%");
            });
        });

        if ($invalidAccountFilter) {
            $query->whereRaw('1 = 0');

            return;
        }

        if ($accountId !== null) {
            $query->where(function (Builder $inner) use ($accountId): void {
                $inner->whereJsonContains('target_accounts', (string) $accountId)
                    ->orWhereJsonContains('target_accounts', $accountId);
            });

            return;
        }

        if ($network !== null) {
            $ids = $accounts->where('network', $network)->pluck('id');
            if ($ids->isEmpty()) {
                $query->whereRaw('1 = 0');

                return;
            }

            $query->where(function (Builder $inner) use ($ids): void {
                foreach ($ids as $id) {
                    $inner->orWhereJsonContains('target_accounts', (string) $id)
                        ->orWhereJsonContains('target_accounts', (int) $id);
                }
            });
        }
    }

    private function applyTab(Builder $query, string $tab): void
    {
        match ($tab) {
            'upcoming' => $query->whereIn('status', ['scheduled', 'publishing']),
            'drafts' => $query->where('status', 'draft'),
            'published' => $query->where('status', 'published'),
            'failed' => $query->where('status', 'failed'),
            default => null,
        };
    }

    private function timezone(Request $request): \DateTimeZone
    {
        try {
            return new \DateTimeZone($request->user()?->timezone ?? 'Asia/Dhaka');
        } catch (\Exception) {
            return new \DateTimeZone('Asia/Dhaka');
        }
    }
}
