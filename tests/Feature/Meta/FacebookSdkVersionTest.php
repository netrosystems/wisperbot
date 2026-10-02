<?php

namespace Tests\Feature\Meta;

use App\Modules\Integrations\Models\IntegrationConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FacebookSdkVersionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The layout loads Meta's SDK on every page, and Channel Setup reuses it,
     * so this version is the one Meta's signup windows open with. Keep it on
     * the version the server uses (CloudApiClient, OAuthManager).
     */
    public function test_the_layout_starts_the_facebook_sdk_at_the_current_graph_version(): void
    {
        IntegrationConfig::create([
            'provider' => 'meta_app', 'label' => 'Meta App', 'mode' => 'live', 'enabled' => true,
            'credentials' => ['app_id' => 'meta-app-id', 'app_secret' => 'meta-app-secret'],
        ]);

        $this->get('/login')
            ->assertOk()
            ->assertSee("version: 'v25.0'", false)
            ->assertDontSee("version: 'v20.0'", false);
    }
}
