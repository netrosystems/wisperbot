<?php

namespace Tests\Feature\Inbox;

use App\Modules\Inbox\Services\InstagramDriver;
use App\Modules\Inbox\Services\MessengerDriver;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use App\Modules\Whatsapp\Models\WhatsappBusinessAccount;
use App\Modules\Whatsapp\Models\WhatsappPhoneNumber;
use App\Modules\Whatsapp\Services\WhatsappDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Smart Bot reply choices as the channel's own buttons (Smart Bot 2.0, Phase
 * 1.7): sent when they fit, numbered text otherwise or when refused, and a
 * tap is answered like any customer message.
 */
class NativeReplyChoicesTest extends TestCase
{
    use RefreshDatabase;

    private const CHOICES = [['id' => 'yes', 'label' => 'Yes, please'], ['id' => 'no', 'label' => 'No, thanks']];

    public function test_whatsapp_sends_reply_buttons_when_the_choices_fit(): void
    {
        $message = $this->botReply('whatsapp', self::CHOICES);
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.1']]])]);

        $this->assertSame('wamid.1', app(WhatsappDriver::class)->send($message));

        Http::assertSent(function (Request $request) use ($message): bool {
            $interactive = $request['interactive'] ?? [];

            return ($request['type'] ?? null) === 'interactive'
                && $interactive['body']['text'] === 'Would you like the Japan plan?'
                && array_column(array_column($interactive['action']['buttons'], 'reply'), 'title') === ['Yes, please', 'No, thanks']
                && $interactive['action']['buttons'][0]['reply']['id'] === "ai:{$message->id}:yes";
        });
    }

    public function test_a_label_too_long_for_a_button_keeps_the_numbered_text(): void
    {
        $message = $this->botReply('whatsapp', [['id' => 'a', 'label' => 'Yes, show me every Japan plan'], ['id' => 'b', 'label' => 'No']]);
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.2']]])]);

        app(WhatsappDriver::class)->send($message);

        Http::assertSent(fn (Request $request) => ($request['type'] ?? null) === 'text' && str_contains($request['text']['body'], '1. Yes, show me every Japan plan'));
        Http::assertNotSent(fn (Request $request) => ($request['type'] ?? null) === 'interactive');
    }

    public function test_refused_buttons_send_the_same_answer_as_text_once(): void
    {
        $message = $this->botReply('whatsapp', self::CHOICES);
        Http::fake(['graph.facebook.com/*' => Http::sequence()
            ->push(['error' => ['message' => 'Interactive messages are not supported']], 400)
            ->push(['messages' => [['id' => 'wamid.3']]])]);

        $this->assertSame('wamid.3', app(WhatsappDriver::class)->send($message));
        Http::assertSent(fn (Request $request) => ($request['type'] ?? null) === 'text' && str_contains($request['text']['body'], '2. No, thanks'));
    }

    public function test_messenger_and_instagram_send_quick_replies(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['message_id' => 'mid.1'])]);
        foreach (['messenger' => MessengerDriver::class, 'instagram' => InstagramDriver::class] as $channel => $driver) {
            $message = $this->botReply($channel, self::CHOICES);

            $this->assertSame('mid.1', app($driver)->send($message));
            Http::assertSent(fn (Request $request) => ($request['message']['text'] ?? null) === 'Would you like the Japan plan?'
                && array_column($request['message']['quick_replies'] ?? [], 'title') === ['Yes, please', 'No, thanks']);
        }
    }

    public function test_human_replies_and_the_switch_keep_plain_text(): void
    {
        $human = $this->botReply('messenger', self::CHOICES);
        $human->update(['sent_by' => 'human']);
        config()->set('chatbot.native_choices', false);
        $bot = $this->botReply('messenger', self::CHOICES);
        Http::fake(['graph.facebook.com/*' => Http::response(['message_id' => 'mid.x'])]);

        app(MessengerDriver::class)->send($human->fresh()->load('conversation.channelAccount'));
        config()->set('chatbot.native_choices', true);
        $bot->forceFill(['sent_by' => 'human'])->save();
        app(MessengerDriver::class)->send($bot->fresh()->load('conversation.channelAccount'));

        Http::assertNotSent(fn (Request $request) => isset($request['message']['quick_replies']));
    }

    /** @param list<array{id:string,label:string}> $choices */
    private function botReply(string $channel, array $choices): Message
    {
        $workspaceId = $this->createWorkspaceContext()['workspace']->id;
        $credentials = match ($channel) {
            'messenger' => ['page_access_token' => 'page-token'],
            'instagram' => ['access_token' => 'ig-token', 'instagram_account_id' => '1784'],
            default => [],
        };
        $account = ChannelAccount::create([
            'workspace_id' => $workspaceId, 'channel' => $channel, 'status' => 'active', 'display_name' => 'Account',
            'phone_number_id' => $channel === 'whatsapp' ? '123456' : null, 'credentials' => $credentials,
        ]);
        if ($channel === 'whatsapp') {
            $waba = WhatsappBusinessAccount::factory()->create(['workspace_id' => $workspaceId, 'status' => 'active', 'credentials' => ['access_token' => 'wa-token']]);
            WhatsappPhoneNumber::create(['waba_id_fk' => $waba->id, 'phone_number_id' => '123456', 'display_phone' => '+1 555 0100']);
        }
        $conversation = Conversation::create([
            'workspace_id' => $workspaceId, 'channel_account_id' => $account->id,
            'contact_id' => Contact::create(['workspace_id' => $workspaceId, 'phone_e164' => '+447700900111'])->id,
            'status' => 'open', 'external_thread_id' => 'PSID-123',
        ]);
        $labels = implode("\n", array_map(fn ($choice, $index) => ($index + 1).'. '.$choice['label'], $choices, array_keys($choices)));

        return Message::create([
            'conversation_id' => $conversation->id, 'direction' => 'out', 'channel' => $channel, 'type' => 'text',
            'body' => "Would you like the Japan plan?\n\n".$labels, 'sent_by' => 'bot', 'status' => 'queued', 'sent_at' => now(),
            'payload' => ['quick_replies' => $choices, 'display_body' => 'Would you like the Japan plan?', 'native_body' => 'Would you like the Japan plan?'],
        ])->load('conversation.channelAccount');
    }
}
