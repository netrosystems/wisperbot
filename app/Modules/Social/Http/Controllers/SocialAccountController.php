<?php

namespace App\Modules\Social\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Integrations\Services\CredentialResolver;
use App\Modules\Integrations\Services\MetaPageDiscoveryService;
use App\Modules\Social\Jobs\SyncSocialComments;
use App\Modules\Social\Models\SocialAccount;
use App\Modules\Social\Services\Drivers\FacebookDriver;
use App\Modules\Social\Services\Drivers\InstagramSocialDriver;
use App\Modules\Social\Services\Drivers\LinkedInDriver;
use App\Modules\Social\Services\Drivers\TikTokDriver;
use App\Modules\Social\Services\Drivers\XDriver;
use App\Modules\Social\Services\Drivers\YoutubeDriver;
use App\Modules\Social\Services\OAuth\OAuthManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Inertia\Inertia;
use Inertia\Response;

class SocialAccountController extends Controller
{
    private array $drivers;

    public function __construct(
        private readonly OAuthManager $oauth,
        private readonly MetaPageDiscoveryService $metaPages,
    ) {
        $this->drivers = [
            'facebook' => new FacebookDriver,
            'instagram' => new InstagramSocialDriver,
            'linkedin' => new LinkedInDriver,
            'youtube' => new YoutubeDriver,
            'tiktok' => new TikTokDriver,
            'twitter' => new XDriver,
        ];
    }

    private function workspaceId(Request $request): int
    {
        return (int) ($request->user()->current_workspace_id ?? $request->user()->workspace_id);
    }

    public function index(Request $request): Response
    {
        $wid = $this->workspaceId($request);
        $accounts = SocialAccount::where('workspace_id', $wid)->get();

        return Inertia::render('Social/Accounts/Index', [
            'accounts' => $accounts,
            'linkedinPagesEnabled' => (bool) CredentialResolver::system()->oauth('linkedin')?->allowsOrganizationPosting(),
        ]);
    }

    public function connect(Request $request, string $network): RedirectResponse
    {
        $validNetworks = ['facebook', 'instagram', 'linkedin', 'youtube', 'tiktok', 'twitter'];
        abort_unless(in_array($network, $validNetworks, true), 404);

        Session::put('social_oauth_workspace', $this->workspaceId($request));

        $callbackUrl = $this->callbackUrl($network);
        // LinkedIn Company Pages authorize through a second LinkedIn app, because
        // LinkedIn refuses to put Community Management on an app that also signs
        // members in.
        $variant = $network === 'linkedin' && $request->query('target') === 'pages' ? 'pages' : null;

        try {
            $authUrl = $this->oauth->getAuthUrl($network, $this->workspaceId($request), $callbackUrl, ['variant' => $variant]);
        } catch (\RuntimeException $e) {
            return redirect()->route('client.social.automation.index')
                ->with('error', $variant === 'pages'
                    ? 'LinkedIn Company Page posting is not configured yet. Please contact your administrator.'
                    : "OAuth for {$network} is not configured. Please contact your administrator.");
        }

        return redirect($authUrl);
    }

    public function callback(Request $request, string $network): RedirectResponse
    {
        $code = $request->query('code');
        $state = $request->query('state');
        $error = $request->query('error');
        $wid = Session::get('social_oauth_workspace', $this->workspaceId($request));
        $stored = Session::pull('social_oauth_state', []);

        if ($error || ! $code) {
            return redirect()->route('client.social.automation.index')->with('error', 'OAuth failed: '.($error ?? 'No code received'));
        }

        // Verify state to prevent OAuth CSRF / account-linking hijack
        if (empty($stored['state']) || ! hash_equals($stored['state'], (string) $state)) {
            return redirect()->route('client.social.automation.index')->with('error', 'Invalid OAuth state. Please try connecting again.');
        }

        $callbackUrl = $this->callbackUrl($network);
        try {
            $tokens = $this->oauth->exchangeCode($network, $code, $callbackUrl, $stored);

            if (empty($tokens['access_token'])) {
                return redirect()->route('client.social.automation.index')->with('error', 'Failed to obtain access token.');
            }

            // Meta connections are resolved through Page/Business discovery
            // below. Calling the Instagram Basic Display `/me` endpoint here
            // with a Facebook Login token can fail even when Page discovery is
            // valid, turning a good Instagram connection into a false error.
            // A LinkedIn Company Page authorization carries no sign-in scopes, so
            // there is no member profile to read.
            $driver = $this->drivers[$network] ?? null;
            $accountInfo = in_array($network, ['facebook', 'instagram'], true) || ($stored['variant'] ?? null) === 'pages'
                ? ['account_id' => '', 'name' => '', 'picture_url' => null]
                : ($driver
                    ? $driver->fetchAccountInfo($tokens['access_token'])
                    : ['account_id' => '', 'name' => '', 'picture_url' => null]);
        } catch (\Throwable $e) {
            Log::warning('Social OAuth callback failed', [
                'workspace_id' => $wid,
                'network' => $network,
                'error' => $e->getMessage(),
            ]);

            return redirect()->route('client.social.automation.index')
                ->with('error', ($network === 'twitter' ? 'X' : ucfirst($network)).' authorization failed: '.mb_substr($e->getMessage(), 0, 240));
        }

        // For Facebook / Instagram: fetch pages the user manages and upsert each one.
        if (in_array($network, ['facebook', 'instagram'])) {
            $fields = $network === 'instagram'
                ? 'id,name,access_token,picture,instagram_business_account{id,name,username,profile_picture_url}'
                : 'id,name,access_token,picture';

            $discovery = $this->metaPages->discover($tokens['access_token'], $fields);
            $pages = $discovery['pages'];

            // Business Portfolio discovery can return every Page in the selected
            // business, even when the user selected only one Page in Meta's OAuth
            // asset picker. Filter against the granular target IDs before writing
            // anything so an unselected Page is never connected implicitly.
            $selectionScopes = $network === 'instagram'
                ? ['instagram_content_publish', 'instagram_manage_contents', 'instagram_basic', 'pages_read_engagement', 'pages_show_list']
                : ['pages_manage_posts', 'pages_read_engagement', 'pages_show_list'];

            try {
                $selectedTargetIds = $this->oauth->selectedMetaTargetIds(
                    $network,
                    $tokens['access_token'],
                    $selectionScopes,
                );
            } catch (\Throwable $e) {
                Log::warning('Social OAuth: selected Meta assets could not be verified', [
                    'workspace_id' => $wid,
                    'network' => $network,
                    'error' => $e->getMessage(),
                ]);

                return redirect()->route('client.social.automation.index')
                    ->with('error', 'Meta authorization succeeded, but WisperBot could not verify which account you selected. No accounts were connected. Please try again.');
            }

            if ($selectedTargetIds === []) {
                return redirect()->route('client.social.automation.index')
                    ->with('error', 'Meta did not return a selected Page or Instagram account. No accounts were connected. Reconnect and choose the specific account you want to add.');
            }

            $pages = $this->filterMetaPagesToSelectedTargets($pages, $selectedTargetIds);

            if ($discovery['errors'] !== []) {
                Log::warning('Social OAuth: one or more Meta Page discovery sources failed', [
                    'workspace_id' => $wid,
                    'network' => $network,
                    'errors' => $discovery['errors'],
                    'successful_sources' => $discovery['successful_sources'],
                ]);
            }

            if (empty($pages)) {
                if ($discovery['successful_sources'] === []) {
                    $message = $discovery['errors'][0]['message'] ?? 'Unknown Graph API error.';

                    return redirect()->route('client.social.automation.index')
                        ->with('error', 'Could not fetch your '.ucfirst($network).' pages: '.$message);
                }

                return redirect()->route('client.social.automation.index')
                    ->with('error', 'No '.ucfirst($network).' Pages were found. Reconnect and grant both pages_show_list and business_management so WisperBot can include Pages assigned through a Meta Business Portfolio.');
            }

            $connected = 0;

            foreach ($pages as $page) {
                $pageToken = $page['access_token'] ?? null;
                if (! is_string($pageToken) || $pageToken === '') {
                    Log::warning('Social OAuth: Page has no access token and was skipped', [
                        'workspace_id' => $wid,
                        'network' => $network,
                        'page_id' => $page['id'] ?? null,
                    ]);

                    continue;
                }

                if ($network === 'instagram') {
                    $igAccount = $page['instagram_business_account'] ?? null;
                    if (! $igAccount) {
                        // This page has no linked Instagram Business account — skip it.
                        continue;
                    }

                    $igName = ! empty($igAccount['username'])
                        ? '@'.$igAccount['username']
                        : ($igAccount['name'] ?? $page['name']);

                    $connectedAccount = SocialAccount::updateOrCreate(
                        ['workspace_id' => $wid, 'network' => 'instagram', 'account_id' => $igAccount['id']],
                        [
                            'name' => $igName,
                            'picture_url' => $igAccount['profile_picture_url'] ?? ($page['picture']['data']['url'] ?? null),
                            'access_token' => $pageToken, // page token is used for IG Graph API calls
                            'meta' => array_merge(SocialAccount::where('workspace_id', $wid)->where('network', 'instagram')->where('account_id', $igAccount['id'])->first()?->meta ?? [], ['page_id' => (string) $page['id']]),
                            'refresh_token' => null,
                            'token_expires_at' => null,
                            'active' => true,
                        ]
                    );
                } else {
                    $connectedAccount = SocialAccount::updateOrCreate(
                        ['workspace_id' => $wid, 'network' => 'facebook', 'account_id' => $page['id']],
                        [
                            'name' => $page['name'],
                            'picture_url' => $page['picture']['data']['url'] ?? null,
                            'access_token' => $pageToken,
                            'refresh_token' => null,
                            'token_expires_at' => null,
                            'active' => true,
                        ]
                    );
                }

                if (config('social_comments.enabled')) {
                    SyncSocialComments::dispatch($connectedAccount->id, $wid, true)->afterCommit();
                }
                $connected++;
            }

            if ($connected === 0) {
                $message = $network === 'instagram'
                    ? 'No Instagram Business accounts were found linked to your Facebook Pages. Make sure your Instagram account is set to Business type and connected to a Facebook Page.'
                    : 'Facebook Pages were discovered, but Meta did not return a Page access token. Reconnect and grant Page management access.';

                return redirect()->route('client.social.automation.index')->with('error', $message);
            }

            return redirect()->route('client.social.automation.index')
                ->with('success', $connected.' '.ucfirst($network).' account(s) connected.');
        }

        // LinkedIn can authorize a member plus the Company Pages they admin.
        // Ask which of them to connect instead of silently posting as the person.
        if ($network === 'linkedin' && ($stored['variant'] ?? null) === 'pages') {
            try {
                $organizations = $this->drivers['linkedin']->fetchOrganizations($tokens['access_token']);
            } catch (\Throwable $e) {
                Log::warning('LinkedIn organization lookup failed', [
                    'workspace_id' => $wid,
                    'error' => $e->getMessage(),
                ]);
                $organizations = [];
            }

            if ($organizations === []) {
                return redirect()->route('client.social.automation.index')
                    ->with('error', 'No LinkedIn Company Pages were found for this account. Connect with a LinkedIn profile that is an administrator of the Page.');
            }

            Session::put('linkedin_pending_connection', [
                'workspace_id' => $wid,
                'tokens' => $tokens,
                'member' => null,
                'organizations' => $organizations,
            ]);

            return redirect()->route('client.social.accounts.linkedin.select');
        }

        if (empty($accountInfo['account_id'])) {
            return redirect()->route('client.social.automation.index')
                ->with('error', ucfirst($network).' connected, but the provider did not return an account identity. Nothing was saved.');
        }

        $identity = ['workspace_id' => $wid, 'network' => $network, 'account_id' => $accountInfo['account_id']];
        $existing = SocialAccount::where($identity)->first();

        SocialAccount::updateOrCreate(
            $identity,
            [
                'name' => $accountInfo['name'],
                'picture_url' => $accountInfo['picture_url'],
                'access_token' => $tokens['access_token'],
                // Google commonly omits refresh_token on a repeat consent. Keep
                // the existing token instead of turning a reconnect into a
                // connection that expires one hour later.
                'refresh_token' => $tokens['refresh_token'] ?? $existing?->refresh_token,
                'token_expires_at' => isset($tokens['expires_in']) ? now()->addSeconds((int) $tokens['expires_in']) : null,
                'scopes' => isset($tokens['scope'])
                    ? preg_split('/[ ,]+/', (string) $tokens['scope'], -1, PREG_SPLIT_NO_EMPTY)
                    : $existing?->scopes,
                'active' => true,
            ]
        );

        return redirect()->route('client.social.automation.index')->with('success', ucfirst($network).' account connected.');
    }

    /**
     * Choose which LinkedIn identities to connect: the member's own profile
     * and/or the Company Pages they administer.
     */
    public function linkedinTargets(Request $request): Response|RedirectResponse
    {
        $pending = Session::get('linkedin_pending_connection');
        if (! is_array($pending) || (int) ($pending['workspace_id'] ?? 0) !== $this->workspaceId($request)) {
            return redirect()->route('client.social.automation.index')
                ->with('error', 'That LinkedIn authorization expired. Please connect again.');
        }

        return Inertia::render('Social/Accounts/LinkedIn', [
            'member' => is_array($pending['member'] ?? null) && ! empty($pending['member']['account_id'])
                ? [
                    'id' => $pending['member']['account_id'],
                    'name' => $pending['member']['name'] ?? '',
                    'picture_url' => $pending['member']['picture_url'] ?? null,
                ]
                : null,
            'organizations' => $pending['organizations'] ?? [],
        ]);
    }

    public function storeLinkedinTargets(Request $request): RedirectResponse
    {
        $pending = Session::get('linkedin_pending_connection');
        if (! is_array($pending) || (int) ($pending['workspace_id'] ?? 0) !== $this->workspaceId($request)) {
            return redirect()->route('client.social.automation.index')
                ->with('error', 'That LinkedIn authorization expired. Please connect again.');
        }

        $validated = $request->validate([
            'connect_member' => ['boolean'],
            'organization_ids' => ['array'],
            'organization_ids.*' => ['string', 'max:64'],
        ]);

        $wid = (int) $pending['workspace_id'];
        $tokens = $pending['tokens'];
        $member = $pending['member'];
        // Only ids from this authorization may be stored, never ids posted back.
        $organizations = collect($pending['organizations'] ?? [])
            ->keyBy(fn (array $organization): string => (string) $organization['id'])
            ->only($validated['organization_ids'] ?? []);

        $targets = [];
        if (($validated['connect_member'] ?? false) && is_array($member) && ! empty($member['account_id'])) {
            $targets[] = [
                'account_id' => (string) $member['account_id'],
                'name' => (string) ($member['name'] ?? 'LinkedIn member'),
                'picture_url' => $member['picture_url'] ?? null,
                'meta' => ['actor_type' => 'member'],
            ];
        }
        foreach ($organizations as $organization) {
            $targets[] = [
                'account_id' => (string) $organization['id'],
                'name' => (string) $organization['name'],
                'picture_url' => $organization['picture_url'] ?? null,
                'meta' => [
                    'actor_type' => 'organization',
                    'organization_urn' => 'urn:li:organization:'.$organization['id'],
                    'vanity_name' => $organization['vanity_name'] ?? null,
                ],
            ];
        }

        if ($targets === []) {
            return back()->with('error', 'Select at least one LinkedIn profile or Company Page.');
        }

        foreach ($targets as $target) {
            $identity = ['workspace_id' => $wid, 'network' => 'linkedin', 'account_id' => $target['account_id']];
            $existing = SocialAccount::where($identity)->first();

            SocialAccount::updateOrCreate($identity, [
                'name' => $target['name'],
                'picture_url' => $target['picture_url'],
                // Company Pages are posted to with the member's own token; LinkedIn
                // issues no separate page token.
                'access_token' => $tokens['access_token'],
                'refresh_token' => $tokens['refresh_token'] ?? $existing?->refresh_token,
                'token_expires_at' => isset($tokens['expires_in']) ? now()->addSeconds((int) $tokens['expires_in']) : null,
                'scopes' => isset($tokens['scope'])
                    ? preg_split('/[ ,]+/', (string) $tokens['scope'], -1, PREG_SPLIT_NO_EMPTY)
                    : $existing?->scopes,
                'meta' => array_merge($existing?->meta ?? [], $target['meta']),
                'active' => true,
            ]);
        }

        Session::forget('linkedin_pending_connection');

        return redirect()->route('client.social.automation.index')
            ->with('success', count($targets).' LinkedIn account(s) connected.');
    }

    public function disconnect(Request $request, SocialAccount $account): RedirectResponse
    {
        abort_unless((int) $account->workspace_id === $this->workspaceId($request), 403);
        $account->delete();

        return back()->with('success', 'Account disconnected.');
    }

    /**
     * Meta requires an exact redirect URI match. Building this URL from the
     * current request can produce http:// or a www hostname behind a proxy,
     * while the URI registered in Meta uses the canonical APP_URL. Use the
     * configured application origin for both authorization and token exchange.
     */
    private function callbackUrl(string $network): string
    {
        return rtrim((string) config('app.url'), '/')
            .'/app/social/accounts/callback/'.rawurlencode($network);
    }

    /**
     * @param  list<array<string, mixed>>  $pages
     * @param  list<string>  $selectedTargetIds
     * @return list<array<string, mixed>>
     */
    private function filterMetaPagesToSelectedTargets(array $pages, array $selectedTargetIds): array
    {
        return array_values(array_filter($pages, function (array $page) use ($selectedTargetIds): bool {
            $pageId = (string) ($page['id'] ?? '');
            $instagramId = (string) ($page['instagram_business_account']['id'] ?? '');

            return ($pageId !== '' && in_array($pageId, $selectedTargetIds, true))
                || ($instagramId !== '' && in_array($instagramId, $selectedTargetIds, true));
        }));
    }
}
