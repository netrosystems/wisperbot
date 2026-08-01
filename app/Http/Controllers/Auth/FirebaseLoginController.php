<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\SocialAccount;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class FirebaseLoginController extends Controller
{
    public function login(Request $request): JsonResponse|RedirectResponse
    {
        if (SystemSetting::get('firebase_enabled', 'false') !== 'true') {
            return response()->json(['message' => 'Firebase login is not enabled.'], 403);
        }

        $request->validate([
            'id_token' => ['required', 'string', 'max:10000'],
        ]);

        $projectId = SystemSetting::get('firebase_project_id', '');
        $apiKey = SystemSetting::get('firebase_api_key', '');
        if (! $projectId || ! $apiKey) {
            return response()->json(['message' => 'Firebase project is not configured.'], 500);
        }

        $tokenInfo = $this->verifyIdToken($request->id_token, $projectId, $apiKey);
        if (! $tokenInfo) {
            Log::warning('Firebase login token verification failed', [
                'project_id' => $projectId,
                'token_fingerprint' => substr(hash('sha256', $request->id_token), 0, 12),
            ]);

            return response()->json(['message' => 'Invalid or expired Google sign-in. Please try again.'], 422);
        }

        $email = isset($tokenInfo['email']) && is_string($tokenInfo['email'])
            ? Str::lower(trim($tokenInfo['email']))
            : null;
        $uid = $tokenInfo['sub'] ?? null;
        $name = $tokenInfo['name'] ?? $email;
        $avatar = $tokenInfo['picture'] ?? null;

        if (! $email || ! filter_var($email, FILTER_VALIDATE_EMAIL) || ! $uid) {
            return response()->json(['message' => 'Could not retrieve a verified email from Google.'], 422);
        }

        $existing = SocialAccount::where('provider', 'firebase')
            ->where('provider_id', $uid)
            ->with('user.client')
            ->first();

        if ($existing) {
            return $this->completeLogin($request, $existing->user);
        }

        $user = User::where('email', $email)->first();

        if (! $user) {
            if (! config('auth.allow_registration', true)) {
                return response()->json(['message' => 'No account found. Please register first.'], 403);
            }

            $user = DB::transaction(function () use ($name, $email) {
                $client = Client::create([
                    'name' => $name,
                    'email' => $email,
                    'status' => Client::STATUS_ACTIVE,
                    'base_currency' => 'USD',
                    'currency_symbol' => '$',
                    'currency_position' => 'before',
                ]);

                return User::create([
                    'name' => $name,
                    'email' => $email,
                    'password' => bcrypt(Str::random(32)),
                    'role' => User::ROLE_CLIENT,
                    'status' => User::STATUS_ACTIVE,
                    'email_verified_at' => now(),
                    'client_id' => $client->id,
                    'client_role' => User::CLIENT_ROLE_ADMINISTRATOR,
                ]);
            });
        }

        if (! $user->isActive() || ($user->client && ! $user->client->isActive())) {
            return response()->json(['message' => 'Your account is inactive.'], 403);
        }

        $user->socialAccounts()->create([
            'provider' => 'firebase',
            'provider_id' => $uid,
            'email' => $email,
            'avatar_url' => $avatar,
        ]);

        return $this->completeLogin($request, $user->loadMissing('client'));
    }

    private function completeLogin(Request $request, ?User $user): JsonResponse
    {
        if (! $user || ! $user->isActive() || ($user->client && ! $user->client->isActive())) {
            return response()->json(['message' => 'Your account is inactive.'], 403);
        }

        $request->session()->regenerate();

        if ($user->hasTwoFactorEnabled()) {
            $request->session()->put('2fa_user_id', $user->getAuthIdentifier());

            return response()->json(['redirect' => route('auth.two-factor.challenge')]);
        }

        Auth::login($user, true);

        return response()->json(['redirect' => route('client.dashboard')]);
    }

    /**
     * Verify the Firebase ID token with Google's Identity Toolkit, then enforce
     * project-specific JWT claims locally. The remote call verifies the token's
     * signature and expiry; local checks prevent cross-project token acceptance.
     */
    private function verifyIdToken(string $idToken, string $projectId, string $apiKey): ?array
    {
        try {
            $response = Http::timeout(10)
                ->acceptJson()
                ->post('https://identitytoolkit.googleapis.com/v1/accounts:lookup?key='.urlencode($apiKey), [
                    'idToken' => $idToken,
                ]);

            if (! $response->successful()) {
                Log::warning('Firebase accounts lookup failed', [
                    'status' => $response->status(),
                    'error' => Str::limit((string) $response->json('error.message'), 200),
                ]);

                return null;
            }

            $firebaseUser = $response->json('users.0');
            $claims = $this->decodeJwtClaims($idToken);
            if (! is_array($firebaseUser) || ! is_array($claims)) {
                return null;
            }

            $uid = (string) ($firebaseUser['localId'] ?? '');
            $aud = (string) ($claims['aud'] ?? '');
            $iss = (string) ($claims['iss'] ?? '');
            $sub = (string) ($claims['sub'] ?? '');
            $emailVerified = filter_var(
                $firebaseUser['emailVerified'] ?? $claims['email_verified'] ?? false,
                FILTER_VALIDATE_BOOLEAN
            );

            if ($aud !== $projectId
                || $iss !== "https://securetoken.google.com/{$projectId}"
                || $sub === ''
                || $sub !== $uid
                || ! $emailVerified) {
                Log::warning('Firebase token claims rejected', [
                    'expected_project_id' => $projectId,
                    'aud' => $aud,
                    'iss' => $iss,
                    'subject_matches_user' => $sub !== '' && hash_equals($sub, $uid),
                    'email_verified' => $emailVerified,
                ]);

                return null;
            }

            return [
                'sub' => $uid,
                'email' => $firebaseUser['email'] ?? $claims['email'] ?? null,
                'name' => $firebaseUser['displayName'] ?? $claims['name'] ?? null,
                'picture' => $firebaseUser['photoUrl'] ?? $claims['picture'] ?? null,
            ];
        } catch (\Throwable $e) {
            Log::warning('Firebase login token verification exception', [
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function decodeJwtClaims(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }

        $payload = strtr($parts[1], '-_', '+/');
        $payload .= str_repeat('=', (4 - strlen($payload) % 4) % 4);
        $decoded = base64_decode($payload, true);
        if ($decoded === false) {
            return null;
        }

        $claims = json_decode($decoded, true);

        return is_array($claims) ? $claims : null;
    }
}
