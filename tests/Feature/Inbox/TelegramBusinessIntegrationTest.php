<?php

namespace Tests\Feature\Inbox;

use App\Events\MessageReceived;
use App\Modules\Inbox\Jobs\ProcessTelegramBusinessUpdateJob;
use App\Modules\Inbox\Services\TelegramBusinessClient;
use App\Modules\Inbox\Services\TelegramBusinessDriver;
use App\Modules\Inbox\Services\TelegramBusinessWebhookProcessor;
use App\Modules\Integrations\Models\IntegrationConfig;
use App\Modules\Integrations\Services\ConnectionTester;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use App\Services\StorageManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramBusinessIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_credentials_require_a_business_capable_bot(): void
    {
        Http::fake([
            'api.telegram.org/botTOKEN/getMe' => Http::response([
                'ok' => true,
                'result' => [
                    'id' => 42,
                    'username' => 'WisperBotBusinessBot',
                    'can_connect_to_business' => true,
                ],
            ]),
        ]);

        $result = app(ConnectionTester::class)->test($this->telegramConfig());

        $this->assertTrue($result['ok']);
    }

    public function test_webhook_rejects_an_invalid_secret_and_queues_valid_updates_once(): void
    {
        $this->telegramConfig();
        Bus::fake();

        $payload = ['update_id' => 987, 'business_message' => ['message_id' => 10]];

        $this->postJson(route('webhooks.telegram.receive'), $payload, [
            'X-Telegram-Bot-Api-Secret-Token' => 'wrong-secret-value',
        ])->assertUnauthorized();

        foreach ([1, 2] as $_) {
            $this->postJson(route('webhooks.telegram.receive'), $payload, [
                'X-Telegram-Bot-Api-Secret-Token' => 'telegram_secret_123456',
            ])->assertOk();
        }

        Bus::assertDispatchedTimes(ProcessTelegramBusinessUpdateJob::class, 1);
    }

    public function test_connect_registers_the_webhook_and_replaces_an_old_pending_link(): void
    {
        $this->telegramConfig();
        $context = $this->createWorkspaceContext();
        ChannelAccount::create([
            'workspace_id' => $context['workspace']->id,
            'channel' => 'telegram',
            'provider' => 'telegram_business',
            'display_name' => 'Old link',
            'status' => 'inactive',
            'meta_json' => [
                'pairing_code_hash' => hash('sha256', str_repeat('Z', 32)),
                'pairing_expires_at' => now()->addMinutes(10)->toIso8601String(),
            ],
        ]);
        Http::fake([
            'api.telegram.org/botTOKEN/setWebhook' => Http::response(['ok' => true, 'result' => true]),
        ]);

        $response = $this->actingAs($context['user'])->get(route('client.inbox.setup.telegram.connect'));

        $response->assertRedirect();
        $this->assertStringStartsWith('https://t.me/WisperBotBusinessBot?start=wb_', $response->headers->get('Location'));
        $this->assertSame(1, ChannelAccount::where('workspace_id', $context['workspace']->id)->where('channel', 'telegram')->count());
        Http::assertSent(fn ($request) => $request->url() === 'https://api.telegram.org/botTOKEN/setWebhook'
            && $request['url'] === route('webhooks.telegram.receive')
            && $request['secret_token'] === 'telegram_secret_123456'
            && in_array('business_message', $request['allowed_updates'], true));
    }

    public function test_pairing_activates_only_the_intended_workspace_and_imports_messages(): void
    {
        Event::fake([MessageReceived::class]);
        Http::fake([
            'api.telegram.org/botTOKEN/sendMessage' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 1],
            ]),
        ]);

        $config = $this->telegramConfig();
        $first = $this->createWorkspaceContext();
        $second = $this->createWorkspaceContext();
        $code = str_repeat('A', 32);

        $account = ChannelAccount::create([
            'workspace_id' => $first['workspace']->id,
            'channel' => 'telegram',
            'provider' => 'telegram_business',
            'display_name' => 'Awaiting pairing',
            'status' => 'inactive',
            'meta_json' => [
                'pairing_code_hash' => hash('sha256', $code),
                'pairing_expires_at' => now()->addMinutes(15)->toIso8601String(),
            ],
        ]);
        ChannelAccount::create([
            'workspace_id' => $second['workspace']->id,
            'channel' => 'telegram',
            'provider' => 'telegram_business',
            'display_name' => 'Other workspace',
            'status' => 'inactive',
            'meta_json' => [
                'pairing_code_hash' => hash('sha256', str_repeat('B', 32)),
                'pairing_expires_at' => now()->addMinutes(15)->toIso8601String(),
            ],
        ]);

        $processor = new TelegramBusinessWebhookProcessor(new TelegramBusinessClient($config), app(StorageManager::class));
        $processor->process(['message' => [
            'text' => '/start wb_'.$code,
            'chat' => ['id' => 7001],
            'from' => ['id' => 7001, 'first_name' => 'Business', 'username' => 'owner'],
        ]]);
        $processor->process(['business_connection' => [
            'id' => 'bc_123',
            'user' => ['id' => 7001, 'first_name' => 'Business'],
            'is_enabled' => true,
            'rights' => ['can_reply' => true],
        ]]);

        $account->refresh();
        $this->assertSame('active', $account->status);
        $this->assertSame('bc_123', $account->phone_number_id);
        $this->assertNull(ChannelAccount::find($account->id)->meta_json['pairing_code_hash'] ?? null);

        $messages = $processor->process(['business_message' => [
            'business_connection_id' => 'bc_123',
            'message_id' => 55,
            'date' => now()->timestamp,
            'chat' => ['id' => 9001, 'type' => 'private'],
            'from' => ['id' => 9001, 'first_name' => 'Customer', 'username' => 'buyer'],
            'text' => 'Hello from Telegram',
        ]]);

        $this->assertCount(1, $messages);
        $this->assertDatabaseHas('contacts', [
            'workspace_id' => $first['workspace']->id,
            'source' => 'telegram',
            'first_name' => 'Customer',
        ]);
        $this->assertDatabaseHas('messages', [
            'channel' => 'telegram',
            'direction' => 'in',
            'body' => 'Hello from Telegram',
            'provider_message_id' => 'tg:bc_123:9001:55',
        ]);
        $this->assertDatabaseMissing('conversations', ['workspace_id' => $second['workspace']->id]);
        Event::assertDispatched(MessageReceived::class);
    }

    public function test_agent_reply_is_sent_through_the_business_connection(): void
    {
        $this->telegramConfig();
        $context = $this->createWorkspaceContext();
        $account = ChannelAccount::create([
            'workspace_id' => $context['workspace']->id,
            'channel' => 'telegram',
            'provider' => 'telegram_business',
            'display_name' => 'Telegram Shop',
            'phone_number_id' => 'bc_123',
            'business_account_id' => '7001',
            'status' => 'active',
            'meta_json' => ['business_connection' => ['rights' => ['can_reply' => true]]],
        ]);
        $contact = Contact::create([
            'workspace_id' => $context['workspace']->id,
            'source' => 'telegram',
            'first_name' => 'Customer',
            'custom_fields' => ['telegram_chat_id' => '9001'],
        ]);
        $conversation = Conversation::create([
            'workspace_id' => $context['workspace']->id,
            'channel_account_id' => $account->id,
            'contact_id' => $contact->id,
            'external_thread_id' => '9001',
            'status' => 'open',
            'assigned_to' => 'human',
        ]);
        $message = Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'out',
            'channel' => 'telegram',
            'type' => 'text',
            'body' => 'Thanks for contacting us.',
            'status' => 'queued',
            'sent_by' => 'human',
            'sent_at' => now(),
        ]);

        Http::fake([
            'api.telegram.org/botTOKEN/sendMessage' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 88],
            ]),
        ]);

        $providerMessageId = app(TelegramBusinessDriver::class)->send($message);
        $this->assertSame('tg:bc_123:9001:88', $providerMessageId);
        $message->update(['provider_message_id' => $providerMessageId]);

        app(TelegramBusinessWebhookProcessor::class)->process([
            'business_message' => [
                'business_connection_id' => 'bc_123',
                'message_id' => 88,
                'date' => now()->timestamp,
                'chat' => ['id' => 9001, 'first_name' => 'Customer'],
                'sender_business_bot' => ['id' => 321],
                'text' => 'Thanks for contacting us.',
            ],
        ]);
        $this->assertSame(1, Message::where('conversation_id', $conversation->id)->count());

        Http::assertSent(fn ($request) => $request->url() === 'https://api.telegram.org/botTOKEN/sendMessage'
            && $request['business_connection_id'] === 'bc_123'
            && (string) $request['chat_id'] === '9001'
            && $request['text'] === 'Thanks for contacting us.');
    }

    private function telegramConfig(): IntegrationConfig
    {
        return IntegrationConfig::create([
            'provider' => 'telegram_business',
            'label' => 'Telegram Business Inbox',
            'mode' => 'live',
            'enabled' => true,
            'credentials' => [
                'bot_token' => 'TOKEN',
                'bot_username' => 'WisperBotBusinessBot',
                'webhook_secret' => 'telegram_secret_123456',
            ],
        ]);
    }
}
