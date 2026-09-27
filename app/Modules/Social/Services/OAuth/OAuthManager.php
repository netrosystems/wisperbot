<?php

namespace App\Modules\Social\Services\OAuth;

use App\Modules\Integrations\Services\CredentialResolver;
use App\Modules\Social\Exceptions\TokenRefreshRejectedException;
use App\Modules\Integrations\Services\Credentials\OAuthClientCredentials;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Session;

/**
 * Centralized OAuth helper for all social networks.
 *
 * Each network's OAuth credentials (client_id, client_secret) are retrieved via
 * the CredentialResolver (workspace override → system default stored in integration_configs).
 *
 * Supported networks:
 *   facebook  – FB Login / Graph API
 *   instagram – Instagram Basic Display / Graph API (via FB App)
 *   linkedin  – LinkedIn OAuth 2.0
 *   youtube   – Google OAuth 2.0
 *   tiktok    – TikTok Content Posting API OAuth 2.0
 */
class OAuthManager
{
    private const META_GRAPH_VERSION = 'v25.0';

    private const META_GRAPH_BASE = 'https://graph.facebook.com/'.self::META_GRAPH_VERSION;

    public function __construct(private readonly CredentialResolver $credentials) {}

    /**
     * @param  array<string, mixed>  $options  `variant: 'pages'` authorizes LinkedIn
     *                                         Company Pages through the separate
     *                                         Community Management app.
     */
    public function getAuthUrl(string $network, int $workspaceId, string $callbackUrl, array $options = []): string
    {
        $creds = $this->credentials->oauth($network);

        if ($creds === null) {
            throw new \RuntimeException("OAuth credentials for [{$network}] are not configured or disabled.");
        }

        return match ($network) {
            'facebook', 'instagram' => $this->facebookAuthUrl($creds, $callbackUrl, $network),
            'linkedin' => $this->linkedinAuthUrl($creds, $callbackUrl, ($options['variant'] ?? null) === 'pages'),
            'youtube' => $this->googleAuthUrl($creds, $callbackUrl),
            'tiktok' => $this->tiktokAuthUrl($creds, $callbackUrl),
            'twitter' => $this->xAuthUrl($creds, $callbackUrl),
            default => throw new \InvalidArgumentException("Unsupported network: {$network}"),
        };
    }

    /**
     * @param  array  $storedState  The already-validated session state data (passed in by the controller
     *                              after it verified the `state` query param — avoids re-reading the session).
     */
    public function exchangeCode(string $network, string $code, string $callbackUrl, array $storedState = []): array
    {
        $creds = $this->credentials->oauth($network);

        if ($creds === null) {
            throw new \RuntimeException("OAuth credentials for [{$network}] are not configured or disabled.");
        }

        return match ($network) {
            'facebook', 'instagram' => $this->facebookExchange($creds, $code, $callbackUrl),
            'linkedin' => $this->linkedinExchange($creds, $code, $callbackUrl, ($storedState['variant'] ?? null) === 'pages'),
            'youtube' => $this->googleExchange($creds, $code, $callbackUrl),
            'tiktok' => $this->tiktokExchange($creds, $code, $callbackUrl),
            'twitter' => $this->xExchange($creds, $code, $callbackUrl, $storedState),
            default => throw new \InvalidArgumentException("Unsupported network: {$network}"),
        };
    }

    /**
     * Return the Page/Instagram asset IDs explicitly selected in Meta's OAuth
     * dialog for the requested granular scopes.
     *
     * Scope order is significant. Meta can retain a broader target set for a
     * read/discovery scope while returning only the asset chosen in the current
     * authorization for the write scope. Unioning every scope would therefore
     * connect unselected Pages. Use the first requested scope that contains
     * target IDs so callers can make the capability-specific write scope
     * authoritative and keep read scopes as compatibility fallbacks.
     *
     * @param  list<string>  $scopes
     * @return list<string>
     */
    public function selectedMetaTargetIds(string $network, string $accessToken, array $scopes): array
    {
        if (! in_array($network, ['facebook', 'instagram'], true)) {
            throw new \InvalidArgumentException("Meta target inspection is not supported for [{$network}].");
        }

        $creds = $this->credentials->oauth($network);
        if ($creds === null || ! $creds->clientId() || ! $creds->clientSecret()) {
            throw new \RuntimeException('Meta OAuth credentials are not configured.');
        }

        $response = Http::timeout(15)->get(self::META_GRAPH_BASE.'/debug_token', [
            'input_token' => $accessToken,
            'access_token' => $creds->clientId().'|'.$creds->clientSecret(),
        ]);
        $this->assertSuccessful($response, 'Meta token inspection');

        if (! $response->json('data.is_valid', false)) {
            throw new \RuntimeException('Meta token inspection reported an invalid access token.');
        }

        /** @var array<string, list<string>> $targetsByScope */
        $targetsByScope = [];
        foreach ((array) $response->json('data.granular_scopes', []) as $scope) {
            $scopeName = (string) ($scope['scope'] ?? '');
            if (! in_array($scopeName, $scopes, true)) {
                continue;
            }

            foreach ((array) ($scope['target_ids'] ?? []) as $targetId) {
                if (is_string($targetId) || is_int($targetId)) {
                    $targetsByScope[$scopeName][] = (string) $targetId;
                }
            }
        }

        foreach ($scopes as $scopeName) {
            $targetIds = array_values(array_unique($targetsByScope[$scopeName] ?? []));
            if ($targetIds !== []) {
                return $targetIds;
            }
        }

        return [];
    }

    /**
     * Refresh an access token using a refresh_token.
     * Returns ['access_token', 'refresh_token' (if rotated), 'expires_in'] or throws on failure.
     */
    /**
     * @param  array<string, mixed>  $options  `variant: 'pages'` refreshes a LinkedIn
     *                                         Company Page token, which belongs to
     *                                         the Community Management app.
     */
    public function refresh(string $network, string $refreshToken, array $options = []): array
    {
        $creds = $this->credentials->oauth($network);
        if ($creds === null) {
            throw new \RuntimeException("OAuth credentials for [{$network}] are not configured or disabled.");
        }

        return match ($network) {
            'youtube' => $this->googleRefresh($creds, $refreshToken),
            'tiktok' => $this->tiktokRefresh($creds, $refreshToken),
            'twitter' => $this->xTokenRequest($creds, ['grant_type' => 'refresh_token', 'refresh_token' => $refreshToken]),
            'linkedin' => $this->linkedinRefresh($creds, $refreshToken, ($options['variant'] ?? null) === 'pages'),
            'facebook',
            'instagram' => throw new \RuntimeException('Facebook/Instagram tokens are long-lived; use token extension instead.'),
            default => throw new \InvalidArgumentException("Unsupported network for refresh: {$network}"),
        };
    }

    // ── Facebook / Instagram ────────────────────────────────────────────────

    private function facebookAuthUrl($creds, string $redirect, string $network): string
    {
        $scopes = $network === 'instagram'
            ? 'instagram_basic,instagram_content_publish,instagram_manage_contents,pages_read_engagement,pages_show_list,business_management'
            : 'pages_manage_posts,pages_read_engagement,pages_show_list,business_management';
        $state = $this->storeState(['network' => $network]);

        if (config('social_comments.enabled')) {
            $scopes .= $network === 'instagram'
                ? ',instagram_manage_comments,pages_manage_metadata'
                : ',pages_read_user_content,pages_manage_engagement,pages_manage_metadata';
        }

        return 'https://www.facebook.com/'.self::META_GRAPH_VERSION.'/dialog/oauth?'.http_build_query([
            'client_id' => $creds->clientId() ?? '',
            'redirect_uri' => $redirect,
            'scope' => $scopes,
            'state' => $state,
            'response_type' => 'code',
        ]);
    }

    private function facebookExchange($creds, string $code, string $redirect): array
    {
        $shortResponse = Http::timeout(15)->get(self::META_GRAPH_BASE.'/oauth/access_token', [
            'client_id' => $creds->clientId() ?? '',
            'client_secret' => $creds->clientSecret() ?? '',
            'redirect_uri' => $redirect,
            'code' => $code,
        ]);
        $this->assertSuccessful($shortResponse, 'Meta authorization-code exchange');

        $shortToken = $shortResponse->json('access_token');
        if (! is_string($shortToken) || $shortToken === '') {
            throw new \RuntimeException('Meta authorization-code exchange returned no access token.');
        }

        // Page tokens inherit the lifetime of the user token used to discover
        // them. Extend the short-lived login token before requesting Pages so a
        // successful connection does not silently stop working a few hours later.
        $longResponse = Http::timeout(15)->get(self::META_GRAPH_BASE.'/oauth/access_token', [
            'grant_type' => 'fb_exchange_token',
            'client_id' => $creds->clientId() ?? '',
            'client_secret' => $creds->clientSecret() ?? '',
            'fb_exchange_token' => $shortToken,
        ]);
        $this->assertSuccessful($longResponse, 'Meta long-lived token exchange');

        $token = $longResponse->json('access_token');
        if (! is_string($token) || $token === '') {
            throw new \RuntimeException('Meta long-lived token exchange returned no access token.');
        }

        return [
            'access_token' => $token,
            'expires_in' => $longResponse->json('expires_in'),
            'token_type' => $longResponse->json('token_type', 'bearer'),
        ];
    }

    // ── LinkedIn ────────────────────────────────────────────────────────────

    private function linkedinAuthUrl($creds, string $redirect, bool $pages = false): string
    {
        if ($pages && ! $creds->allowsOrganizationPosting()) {
            throw new \RuntimeException('LinkedIn Company Page credentials are not configured.');
        }

        $state = $this->storeState(array_filter(['network' => 'linkedin', 'variant' => $pages ? 'pages' : null]));

        return 'https://www.linkedin.com/oauth/v2/authorization?'.http_build_query([
            'response_type' => 'code',
            'client_id' => ($pages ? $creds->pagesClientId() : $creds->clientId()) ?? '',
            'redirect_uri' => $redirect,
            'scope' => implode(' ', $this->linkedinScopes($pages)),
            'state' => $state,
        ]);
    }

    /**
     * The two LinkedIn apps carry different permissions. The Community
     * Management app has no sign-in product, so it cannot request the OpenID
     * Connect scopes; Company Pages are identified from organizationAcls.
     *
     * @return list<string>
     */
    private function linkedinScopes(bool $pages): array
    {
        return $pages
            ? ['r_organization_admin', 'w_organization_social']
            : ['openid', 'profile', 'email', 'w_member_social'];
    }

    private function linkedinExchange($creds, string $code, string $redirect, bool $pages = false): array
    {
        $response = Http::asForm()->timeout(15)->post('https://www.linkedin.com/oauth/v2/accessToken', [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirect,
            'client_id' => ($pages ? $creds->pagesClientId() : $creds->clientId()) ?? '',
            'client_secret' => ($pages ? $creds->pagesClientSecret() : $creds->clientSecret()) ?? '',
        ]);
        $this->assertSuccessful($response, 'LinkedIn token exchange');
        $res = $response->json();

        return [
            'access_token' => $res['access_token'] ?? null,
            'refresh_token' => $res['refresh_token'] ?? null,
            'expires_in' => $res['expires_in'] ?? null,
            'scope' => $res['scope'] ?? null,
        ];
    }

    // ── X (Twitter) ─────────────────────────────────────────────────────────

    /**
     * Posting text with up to 3 images or 1 video needs exactly these;
     * `offline.access` issues the refresh token that keeps the 2-hour access
     * token alive.
     */
    public const X_SCOPES = ['tweet.read', 'tweet.write', 'users.read', 'media.write', 'offline.access'];

    /** OAuth 2.0 with PKCE (S256); the verifier stays in the server session. */
    private function xAuthUrl(OAuthClientCredentials $creds, string $redirect): string
    {
        $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        $state = $this->storeState([
            'network' => 'twitter',
            'code_verifier' => $verifier,
            'client_id' => $creds->clientId(),
        ]);

        return 'https://x.com/i/oauth2/authorize?'.http_build_query([
            'response_type' => 'code',
            'client_id' => $creds->clientId() ?? '',
            'redirect_uri' => $redirect,
            'scope' => implode(' ', self::X_SCOPES),
            'state' => $state,
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
        ]);
    }

    /**
     * @param  array<string, mixed>  $storedState
     * @return array{access_token: string, refresh_token: ?string, expires_in: ?int, scope: ?string}
     */
    private function xExchange(OAuthClientCredentials $creds, string $code, string $redirect, array $storedState): array
    {
        if (empty($storedState['code_verifier']) || ($storedState['client_id'] ?? null) !== $creds->clientId()) {
            throw new \RuntimeException('X application changed during sign-in. Please connect again.');
        }

        $tokens = $this->xTokenRequest($creds, [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirect,
            'code_verifier' => $storedState['code_verifier'],
        ]);

        $granted = preg_split('/[ ,]+/', (string) ($tokens['scope'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (array_diff(self::X_SCOPES, $granted) !== [] || empty($tokens['refresh_token'])) {
            throw new \RuntimeException('X authorization is missing required permissions. Reconnect and approve all permissions.');
        }

        return $tokens;
    }

    /**
     * Confidential client: Basic auth with the client secret, plus client_id in the body.
     *
     * @param  array<string, string>  $data
     * @return array{access_token: string, refresh_token: ?string, expires_in: ?int, scope: ?string}
     */
    private function xTokenRequest(OAuthClientCredentials $creds, array $data): array
    {
        $response = Http::asForm()
            ->withBasicAuth($creds->clientId() ?? '', $creds->clientSecret() ?? '')
            ->timeout(15)
            ->post('https://api.x.com/2/oauth2/token', $data + ['client_id' => $creds->clientId()]);

        if (! $response->successful() || ! $response->json('access_token')) {
            if (($data['grant_type'] ?? null) === 'refresh_token') {
                $this->assertRefreshAccepted($response, 'X token refresh');
            }
            throw new \RuntimeException(in_array($response->status(), [400, 401], true)
                ? 'X authorization must be reconnected.'
                : 'X authorization is temporarily unavailable.');
        }

        return [
            'access_token' => $response->json('access_token'),
            'refresh_token' => $response->json('refresh_token'),
            'expires_in' => $response->json('expires_in'),
            'scope' => $response->json('scope'),
        ];
    }

    // ── Google / YouTube ────────────────────────────────────────────────────

    private function googleAuthUrl($creds, string $redirect): string
    {
        $state = $this->storeState(['network' => 'youtube']);

        return 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
            'client_id' => $creds->clientId() ?? '',
            'redirect_uri' => $redirect,
            'response_type' => 'code',
            'scope' => 'https://www.googleapis.com/auth/youtube.upload https://www.googleapis.com/auth/youtube.readonly',
            'access_type' => 'offline',
            'state' => $state,
        ]);
    }

    private function googleExchange($creds, string $code, string $redirect): array
    {
        $response = Http::asForm()->timeout(15)->post('https://oauth2.googleapis.com/token', [
            'code' => $code,
            'client_id' => $creds->clientId() ?? '',
            'client_secret' => $creds->clientSecret() ?? '',
            'redirect_uri' => $redirect,
            'grant_type' => 'authorization_code',
        ]);
        $this->assertSuccessful($response, 'Google token exchange');
        $res = $response->json();

        return ['access_token' => $res['access_token'] ?? null, 'refresh_token' => $res['refresh_token'] ?? null, 'expires_in' => $res['expires_in'] ?? null];
    }

    // ── TikTok ──────────────────────────────────────────────────────────────

    private function tiktokAuthUrl($creds, string $redirect): string
    {
        $state = $this->storeState(['network' => 'tiktok']);

        return 'https://www.tiktok.com/v2/auth/authorize?'.http_build_query([
            'client_key' => $creds->clientId() ?? '',
            'redirect_uri' => $redirect,
            'response_type' => 'code',
            'scope' => 'user.info.basic,video.publish',
            'state' => $state,
        ]);
    }

    private function tiktokExchange($creds, string $code, string $redirect): array
    {
        $response = Http::asForm()->timeout(15)->post('https://open.tiktokapis.com/v2/oauth/token/', [
            'client_key' => $creds->clientId() ?? '',
            'client_secret' => $creds->clientSecret() ?? '',
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirect,
        ]);
        $this->assertSuccessful($response, 'TikTok token exchange');
        $res = $response->json();

        return [
            'access_token' => $res['access_token'] ?? null,
            'refresh_token' => $res['refresh_token'] ?? null,
            'expires_in' => $res['expires_in'] ?? null,
            'refresh_expires_in' => $res['refresh_expires_in'] ?? null,
            'open_id' => $res['open_id'] ?? null,
            'scope' => $res['scope'] ?? null,
        ];
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function googleRefresh($creds, string $refreshToken): array
    {
        $response = Http::asForm()->timeout(15)->post('https://oauth2.googleapis.com/token', [
            'client_id' => $creds->clientId() ?? '',
            'client_secret' => $creds->clientSecret() ?? '',
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ]);
        $this->assertRefreshAccepted($response, 'Google token refresh');
        $res = $response->json();

        if (empty($res['access_token'])) {
            throw new \RuntimeException('Google token refresh failed: '.json_encode($res));
        }

        return ['access_token' => $res['access_token'], 'refresh_token' => $refreshToken, 'expires_in' => $res['expires_in'] ?? 3600];
    }

    private function tiktokRefresh($creds, string $refreshToken): array
    {
        $response = Http::asForm()->timeout(15)->post('https://open.tiktokapis.com/v2/oauth/token/', [
            'client_key' => $creds->clientId() ?? '',
            'client_secret' => $creds->clientSecret() ?? '',
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ]);
        $this->assertRefreshAccepted($response, 'TikTok token refresh');
        $res = $response->json();

        $token = $res['access_token'] ?? null;
        if (! $token) {
            // TikTok can answer 200 with an error body.
            if (in_array($res['error'] ?? null, ['invalid_grant', 'access_token_invalid', 'refresh_token_invalid'], true)) {
                throw new TokenRefreshRejectedException('TikTok rejected the refresh token: '.($res['error_description'] ?? $res['error']));
            }
            throw new \RuntimeException('TikTok token refresh failed: '.json_encode($res));
        }

        return ['access_token' => $token, 'refresh_token' => $res['refresh_token'] ?? $refreshToken, 'expires_in' => $res['expires_in'] ?? null];
    }

    private function linkedinRefresh($creds, string $refreshToken, bool $pages = false): array
    {
        $response = Http::asForm()->timeout(15)->post('https://www.linkedin.com/oauth/v2/accessToken', [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id' => ($pages ? $creds->pagesClientId() : $creds->clientId()) ?? '',
            'client_secret' => ($pages ? $creds->pagesClientSecret() : $creds->clientSecret()) ?? '',
        ]);
        $this->assertRefreshAccepted($response, 'LinkedIn token refresh');
        $res = $response->json();

        if (empty($res['access_token'])) {
            throw new \RuntimeException('LinkedIn token refresh failed: '.json_encode($res));
        }

        return ['access_token' => $res['access_token'], 'refresh_token' => $res['refresh_token'] ?? $refreshToken, 'expires_in' => $res['expires_in'] ?? null];
    }

    private function storeState(array $data): string
    {
        $state = bin2hex(random_bytes(16));
        Session::put('social_oauth_state', array_merge($data, ['state' => $state]));

        return $state;
    }

    /**
     * A 400/401 about the token means the refresh token is no longer valid and
     * the account must be reconnected. A client error (`invalid_client`,
     * `unauthorized_client`) is a platform configuration problem, and a 5xx or
     * timeout is temporary: neither may disconnect client accounts.
     */
    private function assertRefreshAccepted(Response $response, string $operation): void
    {
        if ($response->successful()) {
            return;
        }

        $error = (string) ($response->json('error') ?? '');
        $message = $response->json('error_description') ?? $response->json('error.message') ?? $response->json('message') ?? $error;
        $detail = $operation.' failed (HTTP '.$response->status().'): '.mb_substr((string) $message, 0, 300);

        if (in_array($response->status(), [400, 401], true) && ! in_array($error, ['invalid_client', 'unauthorized_client'], true)) {
            throw new TokenRefreshRejectedException($detail);
        }

        throw new \RuntimeException($detail);
    }

    private function assertSuccessful(Response $response, string $operation): void
    {
        if ($response->successful()) {
            return;
        }

        $message = $response->json('error.message')
            ?? $response->json('error_description')
            ?? $response->json('message')
            ?? $response->body();

        throw new \RuntimeException($operation.' failed (HTTP '.$response->status().'): '.mb_substr((string) $message, 0, 500));
    }
}
