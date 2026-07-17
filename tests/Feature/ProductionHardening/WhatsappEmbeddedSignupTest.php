<?php

namespace Tests\Feature\ProductionHardening;

use App\Modules\Whatsapp\Http\Controllers\WhatsappEmbeddedSignupController;
use App\Modules\Integrations\Services\Credentials\MetaCredentials;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsappEmbeddedSignupTest extends TestCase
{
    public function test_already_registered_phone_is_treated_as_idempotent(): void
    {
        Http::fake([
            'graph.facebook.com/*/register' => Http::response([
                'error' => ['message' => 'Phone number is already registered'],
            ], 400),
        ]);

        $this->assertTrue($this->registerNumber());
    }

    public function test_registration_failure_is_not_treated_as_success(): void
    {
        Http::fake([
            'graph.facebook.com/*/register' => Http::response([
                'error' => ['message' => 'Two-step verification PIN is incorrect'],
            ], 400),
        ]);

        $this->assertFalse($this->registerNumber());
    }

    public function test_waba_can_be_discovered_when_meta_omits_the_browser_session_message(): void
    {
        Http::fake([
            'graph.facebook.com/*/debug_token*' => Http::response([
                'data' => [
                    'granular_scopes' => [
                        ['scope' => 'pages_show_list', 'target_ids' => ['PAGE_123']],
                        ['scope' => 'whatsapp_business_management', 'target_ids' => ['WABA_456']],
                    ],
                ],
            ]),
        ]);

        $method = new \ReflectionMethod(WhatsappEmbeddedSignupController::class, 'discoverWabaId');
        $method->setAccessible(true);

        $wabaId = $method->invoke(
            new WhatsappEmbeddedSignupController,
            'USER_TOKEN',
            new MetaCredentials(['app_id' => 'APP_ID', 'app_secret' => 'APP_SECRET']),
        );

        $this->assertSame('WABA_456', $wabaId);
    }

    private function registerNumber(): bool
    {
        $method = new \ReflectionMethod(WhatsappEmbeddedSignupController::class, 'registerNumber');
        $method->setAccessible(true);

        return $method->invoke(
            new WhatsappEmbeddedSignupController,
            'PHONE_123',
            'TOKEN',
            'WABA_123',
            '123456',
        );
    }
}
