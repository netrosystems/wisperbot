<?php

namespace Tests\Feature\Social;

use App\Models\User;
use App\Modules\Integrations\Models\IntegrationConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CanonicalMetaOAuthRedirectTest extends TestCase
{
    use RefreshDatabase;

    public function test_instagram_oauth_uses_app_url_instead_of_the_incoming_request_host(): void
    {
        $this->withoutMiddleware();
        config(['app.url' => 'https://wisperbot.test/']);

        IntegrationConfig::create([
            'provider' => 'meta_app',
            'label' => 'Meta App',
            'mode' => 'live',
            'enabled' => true,
            'credentials' => [
                'app_id' => 'meta-app-id',
                'app_secret' => 'meta-app-secret',
            ],
        ]);

        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(
            'https://www.wisperbot.test/app/social/accounts/connect/instagram'
        );

        $response->assertRedirect();

        $query = [];
        parse_str((string) parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);

        $this->assertSame(
            'https://wisperbot.test/app/social/accounts/callback/instagram',
            $query['redirect_uri'] ?? null,
        );
    }
}
