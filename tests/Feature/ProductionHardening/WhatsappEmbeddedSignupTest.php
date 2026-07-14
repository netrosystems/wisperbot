<?php

namespace Tests\Feature\ProductionHardening;

use App\Modules\Whatsapp\Http\Controllers\WhatsappEmbeddedSignupController;
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
