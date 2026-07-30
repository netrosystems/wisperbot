<?php

namespace Tests\Feature\Inbox;

use App\Modules\Inbox\Services\EbayConversationSyncService;
use App\Modules\Inbox\Services\EbayDriver;
use App\Modules\Integrations\Models\IntegrationConfig;
use App\Modules\Integrations\Services\ConnectionTester;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EbayIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_validate_ebay_application_credentials(): void
    {
        Http::fake([
            'api.sandbox.ebay.com/identity/v1/oauth2/token' => Http::response([
                'access_token' => 'application-token',
                'expires_in' => 7200,
            ]),
        ]);

        $config = $this->ebayConfig();
        $result = app(ConnectionTester::class)->test($config);

        $this->assertTrue($result['ok']);
        Http::assertSent(fn ($request) => $request->url() === 'https://api.sandbox.ebay.com/identity/v1/oauth2/token'
            && str_contains($request->body(), 'grant_type=client_credentials'));
    }

    public function test_client_oauth_connect_uses_state_and_ebay_runame(): void
    {
        $this->ebayConfig();
        $context = $this->createWorkspaceContext();

        $response = $this->actingAs($context['user'])->get(route('client.inbox.setup.ebay.connect'));

        $response->assertRedirect();
        $location = $response->headers->get('Location');
        $this->assertStringStartsWith('https://auth.sandbox.ebay.com/oauth2/authorize?', $location);
        $this->assertStringContainsString('redirect_uri=RUNAME_123', $location);
        $this->assertStringContainsString('commerce.message', urldecode($location));
        $this->assertNotEmpty(session('ebay_oauth.state'));
        $this->assertSame($context['workspace']->id, session('ebay_oauth.workspace_id'));
    }

    public function test_callback_connects_one_seller_account_and_initial_sync_runs(): void
    {
        $this->ebayConfig();
        $context = $this->createWorkspaceContext();

        Http::fake([
            'api.sandbox.ebay.com/identity/v1/oauth2/token' => Http::response([
                'access_token' => 'seller-access-token',
                'refresh_token' => 'seller-refresh-token',
                'expires_in' => 7200,
                'refresh_token_expires_in' => 47304000,
            ]),
            'api.sandbox.ebay.com/commerce/identity/v1/user/' => Http::response([
                'userId' => 'SELLER_123',
                'registrationMarketplaceId' => 'EBAY_GB',
            ]),
            'api.sandbox.ebay.com/commerce/message/v1/conversation*' => Http::response([
                'conversations' => [],
                'total' => 0,
            ]),
        ]);

        $this->actingAs($context['user'])
            ->withSession(['ebay_oauth' => [
                'state' => 'secure-state',
                'workspace_id' => $context['workspace']->id,
                'created_at' => now()->timestamp,
            ]])
            ->get(route('client.inbox.setup.ebay.callback', [
                'state' => 'secure-state',
                'code' => 'authorization-code',
            ]))
            ->assertRedirect(route('client.inbox.setup'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('channel_accounts', [
            'workspace_id' => $context['workspace']->id,
            'channel' => 'ebay',
            'provider' => 'ebay',
            'status' => 'active',
        ]);

        $account = ChannelAccount::where('channel', 'ebay')->firstOrFail();
        $this->assertSame('SELLER_123', $account->meta_json['seller_user_id']);
        $this->assertSame('seller-refresh-token', $account->credentials['refresh_token']);
    }

    public function test_sync_imports_buyer_message_and_reply_uses_ebay_message_api(): void
    {
        $context = $this->createWorkspaceContext();
        $account = ChannelAccount::create([
            'workspace_id' => $context['workspace']->id,
            'channel' => 'ebay',
            'provider' => 'ebay',
            'display_name' => 'Demo seller',
            'status' => 'active',
            'credentials' => [
                'access_token' => 'seller-access-token',
                'refresh_token' => 'seller-refresh-token',
                'expires_at' => now()->addHour()->toIso8601String(),
            ],
            'meta_json' => [
                'seller_user_id' => 'SELLER_123',
                'environment' => 'sandbox',
                'marketplace_id' => 'EBAY_GB',
            ],
        ]);

        Http::fake([
            'api.sandbox.ebay.com/commerce/message/v1/conversation?*' => Http::response([
                'conversations' => [['conversationId' => 'CONV_1']],
            ]),
            'api.sandbox.ebay.com/commerce/message/v1/conversation/CONV_1*' => Http::response([
                'conversationId' => 'CONV_1',
                'messages' => [[
                    'messageId' => 'MSG_IN_1',
                    'senderUserName' => 'BUYER_456',
                    'recipientUserName' => 'SELLER_123',
                    'messageText' => 'Is this item available?',
                    'createdDate' => now()->toIso8601String(),
                ]],
            ]),
            'api.sandbox.ebay.com/commerce/message/v1/send_message' => Http::response([
                'messageId' => 'MSG_OUT_1',
            ]),
        ]);

        $this->assertSame(1, app(EbayConversationSyncService::class)->sync($account));
        $this->assertDatabaseHas('messages', [
            'provider_message_id' => 'MSG_IN_1',
            'channel' => 'ebay',
            'direction' => 'in',
            'body' => 'Is this item available?',
        ]);

        $conversation = Conversation::where('external_thread_id', 'CONV_1')->firstOrFail();
        $outbound = Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'out',
            'channel' => 'ebay',
            'type' => 'text',
            'body' => 'Yes, it is available.',
            'status' => 'queued',
            'sent_by' => 'human',
            'sent_at' => now(),
        ]);

        $this->assertSame('MSG_OUT_1', app(EbayDriver::class)->send($outbound));
        Http::assertSent(fn ($request) => $request->url() === 'https://api.sandbox.ebay.com/commerce/message/v1/send_message'
            && $request['conversationId'] === 'CONV_1'
            && $request['messageText'] === 'Yes, it is available.');
    }

    private function ebayConfig(): IntegrationConfig
    {
        return IntegrationConfig::create([
            'provider' => 'oauth_ebay',
            'label' => 'eBay Seller Messaging (OAuth)',
            'mode' => 'live',
            'enabled' => true,
            'credentials' => [
                'client_id' => 'CLIENT_ID',
                'client_secret' => 'CLIENT_SECRET',
                'ru_name' => 'RUNAME_123',
                'environment' => 'sandbox',
                'marketplace_id' => 'EBAY_GB',
            ],
        ]);
    }
}
