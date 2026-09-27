<?php

namespace Tests\Feature\Social;

use App\Models\User;
use App\Models\Workspace;
use App\Modules\Integrations\Models\IntegrationConfig;
use App\Modules\Social\Jobs\RefreshSocialTokensJob;
use App\Modules\Social\Models\SocialAccount;
use App\Modules\Social\Services\Drivers\LinkedInDriver;
use App\Modules\Social\Services\OAuth\OAuthManager;
use App\Modules\Social\Services\SocialTokenRefresher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Session;
use Tests\TestCase;

class LinkedInCompanyPageTest extends TestCase
{
    use RefreshDatabase;

    private const ORGANIZATIONS = [
        ['id' => '5566', 'name' => 'Telzen', 'vanity_name' => 'telzen', 'picture_url' => 'https://cdn.test/telzen.png'],
        ['id' => '7788', 'name' => 'Telzen Travel', 'vanity_name' => null, 'picture_url' => null],
    ];

    public function test_each_linkedin_app_authorizes_with_its_own_key_and_scopes(): void
    {
        [$user] = $this->clientWorkspace();
        $this->linkedInCredentials(pagesApp: true);

        // Sign-in app: identity and member posting only.
        $member = $this->authorizationUrl($user);
        $this->assertStringContainsString('client_id=client-id', $member);
        $this->assertStringContainsString('w_member_social', $member);
        $this->assertStringNotContainsString('w_organization_social', $member);

        // Community Management app: organization scopes, and no sign-in scopes,
        // because LinkedIn will not put Sign In on that app.
        $pages = $this->authorizationUrl($user, target: 'pages');
        $this->assertStringContainsString('client_id=pages-client-id', $pages);
        $this->assertStringContainsString('r_organization_admin', $pages);
        $this->assertStringContainsString('w_organization_social', $pages);
        $this->assertStringNotContainsString('openid', $pages);
    }

    public function test_company_page_connect_is_refused_until_the_second_app_is_configured(): void
    {
        [$user] = $this->clientWorkspace();
        $this->linkedInCredentials(pagesApp: false);

        $this->actingAs($user)
            ->get(route('client.social.accounts.connect', ['network' => 'linkedin', 'target' => 'pages']))
            ->assertRedirect(route('client.social.automation.index'))
            ->assertSessionHas('error');
    }

    public function test_company_pages_the_member_administers_are_listed(): void
    {
        Http::fake(['api.linkedin.com/v2/organizationAcls*' => Http::response([
            'elements' => [
                ['organization~' => [
                    'id' => 5566,
                    'localizedName' => 'Telzen',
                    'vanityName' => 'telzen',
                    'logoV2' => ['original~' => ['elements' => [['identifiers' => [['identifier' => 'https://cdn.test/telzen.png']]]]]],
                ]],
                ['organization~' => ['id' => 7788, 'localizedName' => 'Telzen Travel']],
                ['organization~' => null,
                ],
            ],
        ])]);

        $organizations = (new LinkedInDriver)->fetchOrganizations('member-token');

        $this->assertSame(self::ORGANIZATIONS, $organizations);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/v2/organizationAcls')
            && $request['q'] === 'roleAssignee'
            && $request['role'] === 'ADMINISTRATOR'
            && $request['state'] === 'APPROVED');
    }

    public function test_a_member_without_organization_access_still_connects(): void
    {
        Http::fake(['api.linkedin.com/v2/organizationAcls*' => Http::response(['serviceErrorCode' => 100], 403)]);

        $this->assertSame([], (new LinkedInDriver)->fetchOrganizations('member-token'));
    }

    public function test_a_page_connection_publishes_as_the_organization(): void
    {
        Http::fake(['api.linkedin.com/v2/ugcPosts' => Http::response([], 201, ['X-RestLi-Id' => 'urn:li:share:9'])]);
        $driver = new LinkedInDriver;

        $page = new SocialAccount(['account_id' => '5566', 'access_token' => 'token', 'meta' => ['actor_type' => 'organization']]);
        $driver->publish($page, ['body' => 'From the company']);
        Http::assertSent(fn (Request $request): bool => $request['author'] === 'urn:li:organization:5566');

        $member = new SocialAccount(['account_id' => 'member-1', 'access_token' => 'token', 'meta' => ['actor_type' => 'member']]);
        $driver->publish($member, ['body' => 'From me']);
        Http::assertSent(fn (Request $request): bool => $request['author'] === 'urn:li:person:member-1');
    }

    public function test_client_chooses_which_profile_and_pages_to_connect(): void
    {
        [$user, $workspace] = $this->clientWorkspace();
        $this->pendingConnection($workspace->id);

        $this->actingAs($user)
            ->get(route('client.social.accounts.linkedin.select'))
            ->assertOk();

        $this->actingAs($user)->post(route('client.social.accounts.linkedin.store'), [
            'connect_member' => false,
            // The second id is not in this authorization and must be ignored.
            'organization_ids' => ['5566', '9999'],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $accounts = SocialAccount::where('workspace_id', $workspace->id)->get();
        $this->assertCount(1, $accounts);
        $this->assertSame('5566', $accounts[0]->account_id);
        $this->assertSame('Telzen', $accounts[0]->name);
        $this->assertSame('organization', $accounts[0]->meta['actor_type']);
        $this->assertSame('urn:li:organization:5566', $accounts[0]->meta['organization_urn']);
        $this->assertSame('member-token', $accounts[0]->access_token);
        $this->assertNull(Session::get('linkedin_pending_connection'));
    }

    public function test_connecting_nothing_is_rejected_and_another_workspace_cannot_claim_the_authorization(): void
    {
        [$user, $workspace] = $this->clientWorkspace();
        $this->pendingConnection($workspace->id);

        $this->actingAs($user)->post(route('client.social.accounts.linkedin.store'), [
            'connect_member' => false,
            'organization_ids' => [],
        ])->assertSessionHas('error');
        $this->assertSame(0, SocialAccount::count());

        $this->pendingConnection($workspace->id + 999);
        $this->actingAs($user)->get(route('client.social.accounts.linkedin.select'))
            ->assertRedirect(route('client.social.automation.index'));
    }

    public function test_the_accounts_list_can_tell_a_page_from_a_profile(): void
    {
        [$user, $workspace] = $this->clientWorkspace();
        $this->linkedInCredentials(pagesApp: true);
        foreach ([['member-1', 'member'], ['5566', 'organization']] as [$accountId, $actor]) {
            SocialAccount::create([
                'workspace_id' => $workspace->id,
                'network' => 'linkedin',
                'account_id' => $accountId,
                'name' => 'Account '.$accountId,
                'access_token' => 'token',
                'meta' => ['actor_type' => $actor],
                'active' => true,
            ]);
        }

        $props = $this->actingAs($user)
            ->get(route('client.social.automation.index'))
            ->assertOk()
            ->viewData('page')['props'];

        // Without meta the page cannot label a Page, or reconnect it through
        // the Community Management app.
        $actors = collect($props['accounts'])->pluck('meta.actor_type')->sort()->values()->all();
        $this->assertSame(['member', 'organization'], $actors);
        $this->assertTrue($props['linkedinPagesEnabled']);
    }

    public function test_a_rotated_token_reaches_every_row_from_the_same_authorization(): void
    {
        [, $workspace] = $this->clientWorkspace();
        // One Company Page authorization backs both Page rows and shares a token.
        foreach ([['5566', 'organization'], ['7788', 'organization']] as [$accountId, $actor]) {
            SocialAccount::create([
                'workspace_id' => $workspace->id,
                'network' => 'linkedin',
                'account_id' => $accountId,
                'name' => $accountId,
                'access_token' => 'old-access',
                'refresh_token' => 'shared-refresh',
                // Inside the job's two-hour renewal window.
                'token_expires_at' => now()->addHour(),
                'meta' => ['actor_type' => $actor],
                'active' => true,
            ]);
        }
        $this->linkedInCredentials(pagesApp: true);
        Http::fake(['www.linkedin.com/oauth/v2/accessToken' => Http::response([
            'access_token' => 'new-access', 'refresh_token' => 'new-refresh', 'expires_in' => 5184000,
        ])]);

        app(RefreshSocialTokensJob::class)->handle(app(SocialTokenRefresher::class));

        $accounts = SocialAccount::where('workspace_id', $workspace->id)->get();
        $this->assertCount(2, $accounts);
        foreach ($accounts as $account) {
            $this->assertSame('new-access', $account->access_token);
            $this->assertSame('new-refresh', $account->refresh_token);
            $this->assertTrue($account->active);
        }
        // One token exchange for the whole authorization, not one per row.
        Http::assertSentCount(1);
        // Page rows belong to the Community Management app, so its keys are used.
        Http::assertSent(fn (Request $request): bool => $request['client_id'] === 'pages-client-id');
    }

    private function authorizationUrl($user, ?string $target = null): string
    {
        return $this->actingAs($user)
            ->get(route('client.social.accounts.connect', array_filter(['network' => 'linkedin', 'target' => $target])))
            ->headers->get('Location');
    }

    private function pendingConnection(int $workspaceId): void
    {
        Session::put('linkedin_pending_connection', [
            'workspace_id' => $workspaceId,
            'tokens' => ['access_token' => 'member-token', 'refresh_token' => 'refresh', 'expires_in' => 5184000, 'scope' => 'openid profile w_member_social w_organization_social'],
            'member' => ['account_id' => 'member-1', 'name' => 'Ada Lovelace', 'picture_url' => null],
            'organizations' => self::ORGANIZATIONS,
        ]);
    }

    private function linkedInCredentials(bool $pagesApp): void
    {
        IntegrationConfig::updateOrCreate(['provider' => 'oauth_linkedin'], [
            'label' => 'LinkedIn OAuth',
            'credentials' => array_filter([
                'client_id' => 'client-id',
                'client_secret' => 'client-secret',
                'pages_client_id' => $pagesApp ? 'pages-client-id' : '',
                'pages_client_secret' => $pagesApp ? 'pages-client-secret' : '',
            ]),
            'enabled' => true,
        ]);
    }

    /** @return array{User,Workspace} */
    private function clientWorkspace(): array
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();

        return [$user, $workspace];
    }
}
