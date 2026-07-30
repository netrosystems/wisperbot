<?php

namespace Tests\Feature\Inbox;

use App\Modules\Inbox\Services\AmazonSpApiClient;
use App\Modules\Integrations\Models\IntegrationConfig;
use App\Modules\Integrations\Services\ConnectionTester;
use App\Modules\Shared\Models\ChannelAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AmazonSellerIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_validate_amazon_application_settings(): void
    {
        $result = app(ConnectionTester::class)->test($this->amazonConfig());

        $this->assertTrue($result['ok']);
        $this->assertStringContainsString('seller', strtolower($result['message']));
    }

    public function test_connect_uses_draft_amazon_authorization_and_secure_state(): void
    {
        $this->amazonConfig();
        $context = $this->createWorkspaceContext();

        $response = $this->actingAs($context['user'])->get(route('client.inbox.setup.amazon.connect'));

        $response->assertRedirect();
        $location = $response->headers->get('Location');
        $this->assertStringStartsWith('https://sellercentral.amazon.co.uk/apps/authorize/consent?', $location);
        $this->assertStringContainsString('application_id=amzn1.sellerapps.app.DEMO', $location);
        $this->assertStringContainsString('version=beta', $location);
        $this->assertNotEmpty(session('amazon_spapi_oauth.state'));
        $this->assertSame($context['workspace']->id, session('amazon_spapi_oauth.workspace_id'));
    }

    public function test_amazon_login_uri_handoff_only_redirects_to_amazon(): void
    {
        $this->amazonConfig();
        $context = $this->createWorkspaceContext();

        $response = $this->actingAs($context['user'])
            ->withSession(['amazon_spapi_oauth' => [
                'state' => 'secure-state',
                'workspace_id' => $context['workspace']->id,
                'created_at' => now()->timestamp,
            ]])
            ->get(route('client.inbox.setup.amazon.login', [
                'amazon_callback_uri' => 'https://amazon.com/apps/authorize/confirm/APP',
                'amazon_state' => 'amazon-state',
                'selling_partner_id' => 'SELLER_123',
                'version' => 'beta',
            ]));

        $response->assertRedirect();
        $location = $response->headers->get('Location');
        $this->assertStringStartsWith('https://amazon.com/apps/authorize/confirm/APP?', $location);
        $this->assertStringContainsString('state=secure-state', $location);
        $this->assertStringContainsString('amazon_state=amazon-state', $location);
        $this->assertStringContainsString(urlencode(route('client.inbox.setup.amazon.callback')), $location);
    }

    public function test_callback_encrypts_refresh_token_and_connects_one_seller(): void
    {
        $this->amazonConfig();
        $context = $this->createWorkspaceContext();

        Http::fake([
            'api.amazon.com/auth/o2/token' => Http::response([
                'access_token' => 'amazon-access-token',
                'refresh_token' => 'amazon-refresh-token',
                'expires_in' => 3600,
            ]),
            'sandbox.sellingpartnerapi-eu.amazon.com/sellers/v1/marketplaceParticipations' => Http::response([
                'payload' => [[
                    'marketplace' => ['id' => 'A1F83G8C2ARO7P', 'name' => 'Amazon.co.uk'],
                    'participation' => ['storeName' => 'Demo Amazon Store'],
                ]],
            ]),
        ]);

        $this->actingAs($context['user'])
            ->withSession(['amazon_spapi_oauth' => [
                'state' => 'secure-state',
                'workspace_id' => $context['workspace']->id,
                'created_at' => now()->timestamp,
                'selling_partner_id' => 'SELLER_123',
            ]])
            ->get(route('client.inbox.setup.amazon.callback', [
                'state' => 'secure-state',
                'selling_partner_id' => 'SELLER_123',
                'spapi_oauth_code' => 'oauth-code',
            ]))
            ->assertRedirect(route('client.inbox.setup'))
            ->assertSessionHas('success');

        $account = ChannelAccount::where('channel', 'amazon')->firstOrFail();
        $this->assertSame('Demo Amazon Store', $account->display_name);
        $this->assertSame('SELLER_123', $account->meta_json['selling_partner_id']);
        $this->assertSame('amazon-refresh-token', $account->credentials['refresh_token']);
    }

    public function test_order_messaging_actions_use_the_configured_marketplace(): void
    {
        $context = $this->createWorkspaceContext();
        $account = ChannelAccount::create([
            'workspace_id' => $context['workspace']->id,
            'channel' => 'amazon',
            'provider' => 'amazon_spapi',
            'display_name' => 'Demo Amazon Store',
            'status' => 'active',
            'credentials' => [
                'access_token' => 'amazon-access-token',
                'refresh_token' => 'amazon-refresh-token',
                'expires_at' => now()->addHour()->toIso8601String(),
            ],
            'meta_json' => [
                'selling_partner_id' => 'SELLER_123',
                'environment' => 'sandbox',
                'selling_region' => 'eu',
                'marketplace_id' => 'A1F83G8C2ARO7P',
            ],
        ]);

        Http::fake([
            'sandbox.sellingpartnerapi-eu.amazon.com/messaging/v1/orders/*' => Http::response([
                '_links' => ['actions' => [['href' => '/messaging/v1/orders/ORDER/createConfirmOrderDetails']]],
            ]),
        ]);

        $actions = (new AmazonSpApiClient($account))->messagingActions('ORDER-123');
        $this->assertNotEmpty($actions['_links']['actions']);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/messaging/v1/orders/ORDER-123')
            && str_contains($request->url(), 'marketplaceIds=A1F83G8C2ARO7P')
            && $request->hasHeader('x-amz-access-token', 'amazon-access-token'));
    }

    private function amazonConfig(): IntegrationConfig
    {
        return IntegrationConfig::create([
            'provider' => 'oauth_amazon_spapi',
            'label' => 'Amazon Seller Messaging (SP-API)',
            'mode' => 'live',
            'enabled' => true,
            'credentials' => [
                'lwa_client_id' => 'LWA_CLIENT_ID',
                'lwa_client_secret' => 'LWA_CLIENT_SECRET',
                'application_id' => 'amzn1.sellerapps.app.DEMO',
                'environment' => 'sandbox',
                'selling_region' => 'eu',
                'seller_central_url' => 'https://sellercentral.amazon.co.uk',
                'marketplace_id' => 'A1F83G8C2ARO7P',
            ],
        ]);
    }
}
