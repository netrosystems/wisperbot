<?php

namespace Tests\Feature;

use App\Modules\Inbox\Jobs\SyncEmailAccountJob;
use App\Modules\Inbox\Services\GenericMailboxClient;
use App\Modules\Inbox\Services\GmailApiClient;
use App\Modules\Inbox\Services\MicrosoftGraphMailClient;
use App\Modules\Integrations\Models\IntegrationConfig;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Message;
use App\Services\StorageManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class EmailInboxIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_google_authorization_and_code_exchange_use_gmail_oauth_contract(): void
    {
        IntegrationConfig::create([
            'provider' => 'oauth_google_mail',
            'label' => 'Google Mail OAuth',
            'mode' => 'live',
            'credentials' => ['client_id' => 'google-client', 'client_secret' => 'google-secret'],
            'enabled' => true,
        ]);
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'google-access', 'refresh_token' => 'google-refresh', 'expires_in' => 3600]),
            'openidconnect.googleapis.com/v1/userinfo' => Http::response(['sub' => 'google-user-1', 'email' => 'agent@gmail.com', 'name' => 'Agent']),
        ]);

        $client = app(GmailApiClient::class);
        $url = $client->authorizationUrl('state-1', 'https://example.com/google/callback');
        $this->assertStringContainsString('accounts.google.com/o/oauth2/v2/auth', $url);
        $this->assertStringContainsString('gmail.readonly', urldecode($url));
        $this->assertStringContainsString('gmail.send', urldecode($url));
        $this->assertStringContainsString('access_type=offline', $url);

        $tokens = $client->exchangeCode('code-1', 'https://example.com/google/callback');
        $profile = $client->profile($tokens['access_token']);
        $this->assertSame('agent@gmail.com', $profile['email']);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://oauth2.googleapis.com/token'
            && $request['grant_type'] === 'authorization_code'
            && $request['client_secret'] === 'google-secret');
    }

    public function test_microsoft_authorization_and_code_exchange_use_expected_graph_contract(): void
    {
        IntegrationConfig::create([
            'provider' => 'oauth_microsoft_365',
            'label' => 'Microsoft 365 Mail OAuth',
            'mode' => 'live',
            'credentials' => ['client_id' => 'client-id', 'client_secret' => 'secret', 'tenant' => 'organizations'],
            'enabled' => true,
        ]);
        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['access_token' => 'access', 'refresh_token' => 'refresh', 'expires_in' => 3600]),
            'graph.microsoft.com/*' => Http::response(['id' => 'user-1', 'mail' => 'agent@example.com', 'displayName' => 'Agent']),
        ]);

        $client = app(MicrosoftGraphMailClient::class);
        $url = $client->authorizationUrl('state-1', 'https://example.com/callback');
        $this->assertStringContainsString('/organizations/oauth2/v2.0/authorize', $url);
        $this->assertStringContainsString('Mail.ReadWrite', urldecode($url));
        $this->assertStringContainsString('offline_access', urldecode($url));

        $tokens = $client->exchangeCode('code-1', 'https://example.com/callback');
        $profile = $client->profile($tokens['access_token']);
        $this->assertSame('agent@example.com', $profile['mail']);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/oauth2/v2.0/token')
            && $request['grant_type'] === 'authorization_code'
            && $request['client_secret'] === 'secret');
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'graph.microsoft.com/v1.0/me')
            && $request->header('Authorization')[0] === 'Bearer access');
    }

    public function test_gmail_api_sync_and_send_normalize_messages_without_php_imap(): void
    {
        IntegrationConfig::create([
            'provider' => 'oauth_google_mail',
            'label' => 'Google Mail OAuth',
            'mode' => 'live',
            'credentials' => ['client_id' => 'google-client', 'client_secret' => 'google-secret'],
            'enabled' => true,
        ]);
        $context = $this->createWorkspaceContext();
        $account = ChannelAccount::create([
            'workspace_id' => $context['workspace']->id,
            'channel' => 'email',
            'provider' => 'gmail',
            'business_account_id' => 'google-user-1',
            'display_name' => 'Support',
            'status' => 'active',
            'credentials' => ['access_token' => 'google-access', 'refresh_token' => 'google-refresh', 'expires_at' => now()->addHour()->toIso8601String()],
            'meta_json' => ['email' => 'support@gmail.com'],
        ]);
        Http::fake([
            'gmail.googleapis.com/gmail/v1/users/me/messages?*' => Http::response(['messages' => [['id' => 'gmail-message-1']]]),
            'gmail.googleapis.com/gmail/v1/users/me/messages/gmail-message-1/attachments/attachment-1' => Http::response([
                'data' => rtrim(strtr(base64_encode('pdf-bytes'), '+/', '-_'), '='),
                'size' => 9,
            ]),
            'gmail.googleapis.com/gmail/v1/users/me/messages/gmail-message-1?*' => Http::response([
                'id' => 'gmail-message-1',
                'threadId' => 'gmail-thread-1',
                'internalDate' => (string) (now()->timestamp * 1000),
                'labelIds' => ['INBOX', 'UNREAD'],
                'snippet' => 'Hello support',
                'payload' => [
                    'mimeType' => 'multipart/mixed',
                    'headers' => [
                        ['name' => 'From', 'value' => 'Customer One <customer@example.com>'],
                        ['name' => 'Subject', 'value' => 'Need help'],
                        ['name' => 'Message-ID', 'value' => '<message@example.com>'],
                    ],
                    'parts' => [
                        [
                            'mimeType' => 'text/html',
                            'body' => ['data' => rtrim(strtr(base64_encode('<p><strong>Hello</strong> support</p>'), '+/', '-_'), '=')],
                        ],
                        [
                            'mimeType' => 'application/pdf',
                            'filename' => 'guide.pdf',
                            'body' => ['attachmentId' => 'attachment-1', 'size' => 9],
                        ],
                    ],
                ],
            ]),
            'gmail.googleapis.com/gmail/v1/users/me/messages/send' => Http::response(['id' => 'gmail-sent-1', 'threadId' => 'gmail-thread-2']),
        ]);

        $client = app(GmailApiClient::class);
        $items = $client->syncInbox($account);
        $this->assertSame('gmail:gmail-message-1', $items[0]['id']);
        $this->assertSame('customer@example.com', $items[0]['from']['emailAddress']['address']);
        $this->assertSame('<p><strong>Hello</strong> support</p>', $items[0]['body']['content']);
        $this->assertSame('html', $items[0]['body']['contentType']);
        $this->assertSame('guide.pdf', $items[0]['attachments'][0]['filename']);
        $this->assertSame('pdf-bytes', $items[0]['attachments'][0]['raw_bytes']);
        $this->assertSame(
            'gmail:gmail-sent-1',
            $client->sendMessage($account, 'buyer@example.net', 'Welcome', 'Hello from our team.'),
        );
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/messages/send')
            && $request->header('Authorization')[0] === 'Bearer google-access'
            && is_string($request['raw']));
    }

    public function test_microsoft_sync_downloads_file_attachments(): void
    {
        IntegrationConfig::create([
            'provider' => 'oauth_microsoft_365',
            'label' => 'Microsoft 365 Mail OAuth',
            'mode' => 'live',
            'credentials' => ['client_id' => 'client-id', 'client_secret' => 'secret', 'tenant' => 'organizations'],
            'enabled' => true,
        ]);
        $context = $this->createWorkspaceContext();
        $account = ChannelAccount::create([
            'workspace_id' => $context['workspace']->id,
            'channel' => 'email',
            'provider' => 'microsoft_365',
            'business_account_id' => 'microsoft-user-1',
            'display_name' => 'Support',
            'status' => 'active',
            'credentials' => ['access_token' => 'graph-access', 'expires_at' => now()->addHour()->toIso8601String()],
            'meta_json' => ['email' => 'support@example.com'],
        ]);
        Http::fake([
            'graph.microsoft.com/v1.0/me/messages/graph-message-1/attachments*' => Http::response(['value' => [[
                '@odata.type' => '#microsoft.graph.fileAttachment',
                'name' => 'invoice.pdf',
                'contentType' => 'application/pdf',
                'size' => 9,
                'contentBytes' => base64_encode('pdf-bytes'),
            ]]]),
            'graph.microsoft.com/v1.0/me/mailFolders/inbox/messages/delta*' => Http::response([
                'value' => [[
                    'id' => 'graph-message-1',
                    'subject' => 'Invoice',
                    'body' => ['contentType' => 'html', 'content' => '<p>Invoice attached</p>'],
                    'hasAttachments' => true,
                ]],
                '@odata.deltaLink' => 'https://graph.microsoft.com/v1.0/me/mailFolders/inbox/messages/delta?$deltatoken=next',
            ]),
        ]);

        $items = app(MicrosoftGraphMailClient::class)->syncInbox($account);

        $this->assertSame('invoice.pdf', $items[0]['attachments'][0]['filename']);
        $this->assertSame('pdf-bytes', $items[0]['attachments'][0]['raw_bytes']);
    }

    public function test_email_setup_routes_are_workspace_authenticated(): void
    {
        $this->get('/app/inbox/email-setup')->assertRedirect();
        $this->get('/app/inbox/email')->assertRedirect();
    }

    public function test_email_setup_renders_when_oauth_providers_are_configured(): void
    {
        IntegrationConfig::create([
            'provider' => 'oauth_google_mail',
            'label' => 'Google Mail OAuth',
            'mode' => 'live',
            'credentials' => ['client_id' => 'google-client', 'client_secret' => 'google-secret'],
            'enabled' => true,
        ]);
        IntegrationConfig::create([
            'provider' => 'oauth_microsoft_365',
            'label' => 'Microsoft 365 Mail OAuth',
            'mode' => 'live',
            'credentials' => ['client_id' => 'microsoft-client', 'client_secret' => 'microsoft-secret'],
            'enabled' => true,
        ]);
        $context = $this->createWorkspaceContext();

        $this->actingAs($context['user'])
            ->get(route('client.inbox.email.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Inbox/EmailSetup')
                ->where('googleEnabled', true)
                ->where('microsoftEnabled', true));
    }

    public function test_mailbox_sync_creates_one_workspace_scoped_email_conversation_and_deduplicates(): void
    {
        Storage::fake('public');
        $context = $this->createWorkspaceContext();
        $account = ChannelAccount::create([
            'workspace_id' => $context['workspace']->id,
            'channel' => 'email',
            'provider' => 'microsoft_365',
            'display_name' => 'Support',
            'status' => 'active',
            'credentials' => ['access_token' => 'token'],
            'meta_json' => ['email' => 'support@example.com'],
        ]);
        $item = [
            'id' => 'graph-message-1',
            'internetMessageId' => '<message@example.com>',
            'conversationId' => 'thread-1',
            'subject' => 'Need help',
            'from' => ['emailAddress' => ['address' => 'customer@example.com', 'name' => 'Customer One']],
            'receivedDateTime' => now()->toIso8601String(),
            'body' => ['contentType' => 'html', 'content' => '<p><strong>Hello</strong> support</p><script>alert(1)</script>'],
            'hasAttachments' => true,
            'attachments' => [[
                'filename' => 'guide.pdf',
                'mime_type' => 'application/pdf',
                'size' => 9,
                'raw_bytes' => 'pdf-bytes',
            ]],
        ];
        $microsoft = Mockery::mock(MicrosoftGraphMailClient::class);
        $microsoft->shouldReceive('syncInbox')->twice()->withArgs(fn ($value) => $value->is($account))->andReturn([$item]);
        $generic = Mockery::mock(GenericMailboxClient::class);
        $google = Mockery::mock(GmailApiClient::class);

        $job = new SyncEmailAccountJob($account->id);
        $job->handle($google, $microsoft, $generic);
        $job->handle($google, $microsoft, $generic);

        $this->assertDatabaseCount('conversations', 1);
        $this->assertDatabaseCount('messages', 1);
        $message = Message::first();
        $this->assertSame('email', $message->channel);
        $this->assertSame('Hello support', $message->body);
        $this->assertSame('<p><strong>Hello</strong> support</p>', $message->payload['html_body']);
        $this->assertSame('guide.pdf', $message->payload['attachments'][0]['name']);
        $this->assertSame('application/pdf', $message->payload['attachments'][0]['mime_type']);
        $this->assertTrue($message->payload['has_attachments']);
        $this->assertTrue(app(StorageManager::class)->disk()->exists($message->payload['attachments'][0]['path']));
        $this->assertSame('customer@example.com', $message->conversation->contact->email);
        $this->assertSame($context['workspace']->id, $message->conversation->workspace_id);
    }
}
