<?php

namespace Tests\Feature\Social;

use App\Modules\Social\Models\SocialAccount;
use App\Modules\Social\Models\SocialPost;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SocialAutomationDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_defaults_to_upcoming_and_never_serializes_credentials(): void
    {
        $context = $this->createWorkspaceContext();
        $account = $this->account($context['workspace']->id, 'facebook', 'Review Page');
        $this->account($context['workspace']->id, 'linkedin', 'Expired profile', ['token_expires_at' => now()->subDay()]);

        $this->createPost($context['workspace']->id, $account->id, 'scheduled', 'Upcoming launch');
        $this->createPost($context['workspace']->id, $account->id, 'draft', 'Draft launch');
        $this->createPost($context['workspace']->id, $account->id, 'published', 'Published launch');

        $this->actingAs($context['user'])
            ->get(route('client.social.automation.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Social/Automation/Index')
                ->where('filters.tab', 'upcoming')
                ->where('filters.view', 'list')
                ->where('tabCounts.upcoming', 1)
                ->where('tabCounts.drafts', 1)
                ->where('tabCounts.published', 1)
                ->has('posts.data', 1)
                ->where('posts.data.0.title', 'Upcoming launch')
                ->has('accounts', 2)
                ->has('activeAccounts', 1)
                ->missing('accounts.0.access_token')
                ->missing('accounts.0.refresh_token'));
    }

    public function test_dashboard_search_and_account_filters_are_workspace_scoped(): void
    {
        $context = $this->createWorkspaceContext();
        $other = $this->createWorkspaceContext();
        $account = $this->account($context['workspace']->id, 'instagram', 'Owned profile');
        $otherAccount = $this->account($other['workspace']->id, 'instagram', 'Other profile');

        $this->createPost($context['workspace']->id, $account->id, 'draft', 'Summer campaign');
        $this->createPost($other['workspace']->id, $otherAccount->id, 'draft', 'Summer secret');

        $this->actingAs($context['user'])
            ->get(route('client.social.automation.index', [
                'tab' => 'all',
                'search' => 'Summer',
                'account_id' => $otherAccount->id,
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('posts.data', 0)
                ->has('accounts', 1));
    }

    public function test_calendar_data_is_loaded_only_for_calendar_view(): void
    {
        $context = $this->createWorkspaceContext([], ['timezone' => 'UTC']);
        $account = $this->account($context['workspace']->id, 'linkedin', 'Company page');
        $post = $this->createPost($context['workspace']->id, $account->id, 'scheduled', 'Calendar post');
        $post->update(['scheduled_at' => now()->addDay()]);

        $this->actingAs($context['user'])
            ->get(route('client.social.automation.index', [
                'view' => 'calendar',
                'month' => now()->format('Y-m'),
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.view', 'calendar')
                ->where('posts', null)
                ->has('calendarPosts', 1)
                ->where('calendarPosts.0.title', 'Calendar post'));
    }

    public function test_legacy_pages_redirect_to_the_canonical_workflow(): void
    {
        $context = $this->createWorkspaceContext();

        $this->actingAs($context['user'])
            ->get('/app/social/posts?status=failed')
            ->assertRedirect(route('client.social.automation.index', ['tab' => 'failed']));

        $this->actingAs($context['user'])
            ->get('/app/social/calendar?month=2026-09')
            ->assertRedirect(route('client.social.automation.index', ['month' => '2026-09', 'view' => 'calendar']));

        $this->actingAs($context['user'])
            ->get('/app/social/composer')
            ->assertRedirect(route('client.social.automation.schedule'));
    }

    public function test_schedule_mode_requires_a_future_delivery_time(): void
    {
        $context = $this->createWorkspaceContext();
        $account = $this->account($context['workspace']->id, 'facebook', 'Review Page');

        $this->actingAs($context['user'])
            ->post(route('client.social.posts.store'), [
                'body' => 'This should not publish accidentally.',
                'media_urls' => [],
                'target_accounts' => [$account->id],
                'delivery_mode' => 'schedule',
                'scheduled_at' => null,
                'timezone' => 'UTC',
            ])
            ->assertSessionHasErrors('scheduled_at');

        $this->assertDatabaseCount('social_media_posts', 0);
    }

    private function account(int $workspaceId, string $network, string $name, array $attributes = []): SocialAccount
    {
        return SocialAccount::create(array_merge([
            'workspace_id' => $workspaceId,
            'network' => $network,
            'account_id' => fake()->uuid(),
            'name' => $name,
            'access_token' => 'encrypted-at-rest',
            'refresh_token' => 'encrypted-at-rest',
            'active' => true,
        ], $attributes));
    }

    private function createPost(int $workspaceId, int $accountId, string $status, string $title): SocialPost
    {
        return SocialPost::create([
            'workspace_id' => $workspaceId,
            'title' => $title,
            'body' => "Body for {$title}",
            'media_urls' => [],
            'target_accounts' => [$accountId],
            'status' => $status,
            'scheduled_at' => $status === 'scheduled' ? now()->addDay() : null,
            'timezone' => 'UTC',
        ]);
    }
}
