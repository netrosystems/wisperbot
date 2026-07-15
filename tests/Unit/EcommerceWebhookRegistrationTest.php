<?php

namespace Tests\Unit;

use App\Modules\Ecommerce\Services\Clients\BigCommerceClient;
use App\Modules\Ecommerce\Services\Clients\ShopifyClient;
use App\Modules\Ecommerce\Services\Clients\WooClient;
use App\Modules\Ecommerce\Services\Credentials\StoreCredentials;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EcommerceWebhookRegistrationTest extends TestCase
{
    public function test_clients_do_not_report_success_after_provider_http_failures(): void
    {
        Http::fake([
            '*' => Http::response(['error' => 'forbidden'], 403),
        ]);

        $shopify = new ShopifyClient('store.myshopify.com', new StoreCredentials(['access_token' => 'token']));
        $woo = new WooClient('https://woo.test', new StoreCredentials([
            'consumer_key' => 'key', 'consumer_secret' => 'secret',
        ]), 'hook-secret');
        $bigCommerce = new BigCommerceClient('storehash', new StoreCredentials(['access_token' => 'token']), 'hook-secret');

        $this->assertFalse($shopify->registerWebhooks('https://app.test/hooks')['ok']);
        $this->assertFalse($woo->registerWebhooks('https://app.test/hooks')['ok']);
        $this->assertFalse($bigCommerce->registerWebhooks('https://app.test/hooks')['ok']);
    }
}
