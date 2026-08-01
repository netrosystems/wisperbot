<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureLicensed;
use App\Models\SocialAccount;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FirebaseGoogleLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        SystemSetting::set('firebase_enabled', 'true', false, 'firebase');
        SystemSetting::set('firebase_api_key', 'test-api-key', false, 'firebase');
        SystemSetting::set('firebase_project_id', 'wisperbot-test', false, 'firebase');
        SystemSetting::set('firebase_auth_domain', 'wisperbot-test.firebaseapp.com', false, 'firebase');
        SystemSetting::set('firebase_app_id', '1:123456:web:abcdef', false, 'firebase');
    }

    public function test_verified_firebase_user_can_link_and_login_to_an_existing_account(): void
    {
        $context = $this->createWorkspaceContext([], ['email' => 'client@example.com']);
        $token = $this->firebaseToken();
        $this->fakeLookup();

        $response = $this->postJson(route('auth.firebase'), ['id_token' => $token]);

        $response->assertOk()->assertJsonPath('redirect', route('client.dashboard'));
        $this->assertAuthenticatedAs($context['user']);
        $this->assertDatabaseHas('social_accounts', [
            'user_id' => $context['user']->id,
            'provider' => 'firebase',
            'provider_id' => 'firebase-user-1',
        ]);
        Http::assertSent(fn (Request $request) => str_starts_with(
            $request->url(),
            'https://identitytoolkit.googleapis.com/v1/accounts:lookup?key=test-api-key'
        ) && $request['idToken'] === $token);
    }

    public function test_token_for_another_firebase_project_is_rejected(): void
    {
        $this->fakeLookup();
        $token = $this->firebaseToken(['aud' => 'another-project']);

        $this->postJson(route('auth.firebase'), ['id_token' => $token])
            ->assertStatus(422);

        $this->assertGuest();
        $this->assertDatabaseCount('social_accounts', 0);
    }

    public function test_verified_google_user_can_create_a_new_client_account(): void
    {
        config(['auth.allow_registration' => true]);
        $this->fakeLookup([
            'email' => 'new-client@example.com',
            'displayName' => 'New Client',
        ]);
        $token = $this->firebaseToken(['email' => 'new-client@example.com']);

        $this->postJson(route('auth.firebase'), ['id_token' => $token])
            ->assertOk()
            ->assertJsonPath('redirect', route('client.dashboard'));

        $user = User::where('email', 'new-client@example.com')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertTrue($user->isClientAdministrator());
        $this->assertNotNull($user->client_id);
        $this->assertDatabaseHas('social_accounts', [
            'user_id' => $user->id,
            'provider' => 'firebase',
            'provider_id' => 'firebase-user-1',
        ]);
    }

    public function test_unverified_google_email_is_rejected(): void
    {
        $this->fakeLookup(['emailVerified' => false]);

        $this->postJson(route('auth.firebase'), ['id_token' => $this->firebaseToken()])
            ->assertStatus(422);

        $this->assertGuest();
    }

    public function test_inactive_client_user_cannot_login_or_link_google_account(): void
    {
        $context = $this->createWorkspaceContext([], [
            'email' => 'client@example.com',
            'status' => User::STATUS_INACTIVE,
        ]);
        $this->fakeLookup();

        $this->postJson(route('auth.firebase'), ['id_token' => $this->firebaseToken()])
            ->assertForbidden()
            ->assertJsonPath('message', 'Your account is inactive.');

        $this->assertGuest();
        $this->assertDatabaseMissing('social_accounts', [
            'user_id' => $context['user']->id,
            'provider' => 'firebase',
        ]);
    }

    public function test_two_factor_users_are_sent_to_the_challenge_without_being_logged_in(): void
    {
        $context = $this->createWorkspaceContext([], [
            'email' => 'client@example.com',
            'two_factor_confirmed_at' => now(),
        ]);
        SocialAccount::create([
            'user_id' => $context['user']->id,
            'provider' => 'firebase',
            'provider_id' => 'firebase-user-1',
            'email' => 'client@example.com',
        ]);
        $this->fakeLookup();

        $this->postJson(route('auth.firebase'), ['id_token' => $this->firebaseToken()])
            ->assertOk()
            ->assertJsonPath('redirect', route('auth.two-factor.challenge'))
            ->assertSessionHas('2fa_user_id', $context['user']->id);

        $this->assertGuest();
    }

    public function test_disabled_firebase_login_rejects_requests_without_contacting_google(): void
    {
        SystemSetting::set('firebase_enabled', 'false', false, 'firebase');
        Http::fake();

        $this->postJson(route('auth.firebase'), ['id_token' => $this->firebaseToken()])
            ->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_admin_cannot_enable_firebase_with_incomplete_or_malformed_configuration(): void
    {
        $admin = $this->createSuperAdmin();

        $this->withoutMiddleware(EnsureLicensed::class)
            ->actingAs($admin, 'admin')
            ->from(route('admin.settings.index'))
            ->put(route('admin.settings.firebase.update'), [
                'firebase_enabled' => 'true',
                'firebase_api_key' => '',
                'firebase_auth_domain' => 'https://wrong.firebaseapp.com/path',
                'firebase_project_id' => 'invalid project',
                'firebase_app_id' => '',
            ])
            ->assertRedirect(route('admin.settings.index'))
            ->assertSessionHasErrors([
                'firebase_api_key',
                'firebase_auth_domain',
                'firebase_project_id',
                'firebase_app_id',
            ]);
    }

    private function fakeLookup(array $overrides = []): void
    {
        Http::fake([
            'https://identitytoolkit.googleapis.com/v1/accounts:lookup*' => Http::response([
                'users' => [array_merge([
                    'localId' => 'firebase-user-1',
                    'email' => 'client@example.com',
                    'emailVerified' => true,
                    'displayName' => 'Client User',
                    'photoUrl' => 'https://example.com/avatar.png',
                ], $overrides)],
            ]),
        ]);
    }

    private function firebaseToken(array $overrides = []): string
    {
        $claims = array_merge([
            'aud' => 'wisperbot-test',
            'iss' => 'https://securetoken.google.com/wisperbot-test',
            'sub' => 'firebase-user-1',
            'email' => 'client@example.com',
            'email_verified' => true,
            'name' => 'Client User',
        ], $overrides);

        return $this->base64Url(['alg' => 'RS256', 'typ' => 'JWT'])
            .'.'.$this->base64Url($claims)
            .'.test-signature';
    }

    private function base64Url(array $value): string
    {
        return rtrim(strtr(base64_encode((string) json_encode($value)), '+/', '-_'), '=');
    }
}
