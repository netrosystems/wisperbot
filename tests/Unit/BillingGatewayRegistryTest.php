<?php

namespace Tests\Unit;

use App\Models\PaymentGatewayConfig;
use App\Services\Billing\BillingGatewayRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BillingGatewayRegistryTest extends TestCase
{
    use RefreshDatabase;

    public function test_frontend_lists_only_supported_gateways(): void
    {
        $gateways = (new BillingGatewayRegistry)->listForFrontend();

        $this->assertSame(
            ['stripe', 'paypal', 'paddle'],
            array_column($gateways, 'key')
        );
    }

    public function test_enabled_legacy_database_config_cannot_reactivate_gateway(): void
    {
        PaymentGatewayConfig::create([
            'gateway' => 'razorpay',
            'test_mode' => true,
            'enabled' => true,
            'credentials' => [
                'test' => [
                    'publishable_key' => 'rzp_test_key',
                    'secret_key' => 'legacy-secret',
                    'webhook_secret' => 'legacy-webhook-secret',
                ],
                'live' => [],
            ],
        ]);

        $registry = new BillingGatewayRegistry;

        $this->assertNull($registry->get('razorpay'));
        $this->assertArrayNotHasKey('razorpay', $registry->all());
    }

    public function test_enabled_gateway_without_webhook_secret_is_not_registered(): void
    {
        PaymentGatewayConfig::create([
            'gateway' => 'stripe',
            'test_mode' => true,
            'enabled' => true,
            'credentials' => [
                'test' => [
                    'secret_key' => 'sk_test',
                    'webhook_secret' => '',
                ],
                'live' => [],
            ],
        ]);

        $this->assertNull((new BillingGatewayRegistry)->get('stripe'));
    }
}
