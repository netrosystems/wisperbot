<?php

namespace Tests\Feature\Inbox;

use App\Events\ConversationAssigned;
use App\Events\MessageReceived;
use App\Models\Plan;
use App\Modules\AI\Models\AiChatbot;
use App\Modules\Inbox\Models\ChatWidget;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use App\Notifications\ConversationHandoverNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class WebchatIdentityWidgetTest extends TestCase
{
    use RefreshDatabase;

    public function test_logged_in_visitor_identity_is_attached_and_public_config_matches_widget_features(): void
    {
        Storage::fake('public');
        ['workspace' => $workspace, 'client' => $client] = $this->createWorkspaceContext();

        $plan = Plan::create([
            'name' => 'Pro',
            'slug' => 'pro-'.uniqid(),
            'price_cents' => 4900,
            'currency_code' => 'USD',
            'white_label_enabled' => true,
        ]);
        $this->attachPlanToClient($client, $plan);

        $account = ChannelAccount::create([
            'workspace_id' => $workspace->id,
            'channel' => 'webchat',
            'display_name' => 'Website chat',
            'status' => 'active',
        ]);
        $widget = ChatWidget::create([
            'workspace_id' => $workspace->id,
            'channel_account_id' => $account->id,
            'name' => 'Website chat',
            'position' => 'bottom_right',
            'footer_company_name' => 'Netro',
            'launcher_logo_path' => 'widget-launchers/custom.png',
            'launcher_logo_disk' => 'public',
            'identity_verification' => true,
            'identity_secret' => 'secret-for-test',
        ]);
        Storage::disk('public')->put('widget-launchers/custom.png', 'png');

        $hash = hash_hmac('sha256', 'customer-123', 'secret-for-test');

        $response = $this->postJson(route('widget.session'), [
            'key' => $widget->widget_key,
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'avatar' => 'https://example.com/jane.jpg',
            'external_id' => 'customer-123',
            'user_hash' => $hash,
        ]);

        $response->assertOk()
            ->assertJsonPath('config.footer_company_name', 'Netro')
            ->assertJsonPath('config.require_prechat', false)
            ->assertJsonPath('config.team_member_count', 1)
            ->assertJsonPath('config.team_members.0.name', $workspace->owner->name);

        $this->assertStringContainsString('/storage/', $response->json('config.launcher_logo_url'));

        $contact = Contact::where('workspace_id', $workspace->id)->sole();
        $this->assertSame('Jane', $contact->first_name);
        $this->assertSame('Doe', $contact->last_name);
        $this->assertSame('jane@example.com', $contact->email);
        $this->assertSame('https://example.com/jane.jpg', $contact->avatar);
        $this->assertSame('customer-123', $contact->custom_fields['webchat_external_id']);
        $this->assertFalse($contact->opt_in_email);
    }

    public function test_logged_in_widget_session_still_accepts_visitor_image_uploads(): void
    {
        Storage::fake('public');
        ['workspace' => $workspace] = $this->createWorkspaceContext();

        $account = ChannelAccount::create([
            'workspace_id' => $workspace->id,
            'channel' => 'webchat',
            'display_name' => 'Website chat',
            'status' => 'active',
        ]);
        $widget = ChatWidget::create([
            'workspace_id' => $workspace->id,
            'channel_account_id' => $account->id,
            'name' => 'Website chat',
            'position' => 'bottom_right',
        ]);

        $session = $this->postJson(route('widget.session'), [
            'key' => $widget->widget_key,
            'name' => 'Logged Customer',
            'email' => 'customer@example.com',
            'external_id' => 'customer-456',
        ])->assertOk();

        $image = UploadedFile::fake()->image('quote.png', 180, 180);

        $send = $this->withHeaders(['X-Widget-Token' => $session->json('token')])
            ->post(route('widget.send'), [
                'key' => $widget->widget_key,
                'message' => 'Please check this image',
                'attachment' => $image,
            ]);

        $send->assertOk()
            ->assertJsonPath('message.role', 'visitor')
            ->assertJsonPath('message.type', 'image');

        $message = Message::where('channel', 'webchat')->sole();
        $this->assertSame('image', $message->type);
        $this->assertSame('Please check this image', $message->payload['caption']);
        $this->assertNotEmpty($message->payload['preview_url']);
    }

    public function test_unverified_identity_is_ignored_when_identity_verification_is_enabled(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();

        $account = ChannelAccount::create([
            'workspace_id' => $workspace->id,
            'channel' => 'webchat',
            'display_name' => 'Website chat',
            'status' => 'active',
        ]);
        $widget = ChatWidget::create([
            'workspace_id' => $workspace->id,
            'channel_account_id' => $account->id,
            'name' => 'Website chat',
            'position' => 'bottom_right',
            'identity_verification' => true,
            'identity_secret' => 'secret-for-test',
        ]);

        $this->postJson(route('widget.session'), [
            'key' => $widget->widget_key,
            'name' => 'Spoofed Customer',
            'email' => 'spoof@example.com',
            'external_id' => 'customer-789',
            'user_hash' => 'wrong-hash',
        ])->assertOk();

        $contact = Contact::where('workspace_id', $workspace->id)->sole();
        $this->assertSame('Customer 1', $contact->first_name);
        $this->assertNull($contact->email);
        $this->assertArrayNotHasKey('webchat_external_id', $contact->custom_fields ?? []);
        $this->assertSame('anonymous', $contact->custom_fields['webchat_identity_type']);
    }

    public function test_anonymous_visitors_receive_incrementing_customer_names(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();

        $account = ChannelAccount::create([
            'workspace_id' => $workspace->id,
            'channel' => 'webchat',
            'display_name' => 'Website chat',
            'status' => 'active',
        ]);
        $widget = ChatWidget::create([
            'workspace_id' => $workspace->id,
            'channel_account_id' => $account->id,
            'name' => 'Website chat',
            'position' => 'bottom_right',
        ]);

        $this->postJson(route('widget.session'), ['key' => $widget->widget_key])->assertOk();
        $this->postJson(route('widget.session'), ['key' => $widget->widget_key])->assertOk();

        $this->assertSame(
            ['Customer 1', 'Customer 2'],
            Contact::where('workspace_id', $workspace->id)->orderBy('id')->pluck('first_name')->all(),
        );
    }

    public function test_a_visitor_id_without_its_encrypted_token_cannot_restore_private_history(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();

        $account = ChannelAccount::create([
            'workspace_id' => $workspace->id,
            'channel' => 'webchat',
            'display_name' => 'Website chat',
            'status' => 'active',
        ]);
        $widget = ChatWidget::create([
            'workspace_id' => $workspace->id,
            'channel_account_id' => $account->id,
            'name' => 'Website chat',
            'position' => 'bottom_right',
        ]);

        $first = $this->postJson(route('widget.session'), [
            'key' => $widget->widget_key,
            'visitor_id' => 'caller-controlled-id',
        ])->assertOk();

        $this->withHeaders(['X-Widget-Token' => $first->json('token')])
            ->postJson(route('widget.send'), [
                'key' => $widget->widget_key,
                'message' => 'Private message',
            ])
            ->assertOk();

        $attemptedRestore = $this
            ->withHeader('X-Widget-Token', '')
            ->postJson(route('widget.session'), [
                'key' => $widget->widget_key,
                'visitor_id' => $first->json('visitor_id'),
            ])
            ->assertOk();

        $this->assertNotSame($first->json('visitor_id'), $attemptedRestore->json('visitor_id'));
        $this->assertSame([], $attemptedRestore->json('messages'));
        $this->assertCount(2, Contact::where('workspace_id', $workspace->id)->get());
    }

    public function test_a_valid_encrypted_token_restores_only_its_bound_conversation(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();

        $account = ChannelAccount::create([
            'workspace_id' => $workspace->id,
            'channel' => 'webchat',
            'display_name' => 'Website chat',
            'status' => 'active',
        ]);
        $widget = ChatWidget::create([
            'workspace_id' => $workspace->id,
            'channel_account_id' => $account->id,
            'name' => 'Website chat',
            'position' => 'bottom_right',
        ]);

        $session = $this->postJson(route('widget.session'), ['key' => $widget->widget_key])->assertOk();
        $this->withHeaders(['X-Widget-Token' => $session->json('token')])
            ->postJson(route('widget.send'), [
                'key' => $widget->widget_key,
                'message' => 'My private history',
            ])
            ->assertOk();

        $restored = $this->withHeaders(['X-Widget-Token' => $session->json('token')])
            ->postJson(route('widget.session'), [
                'key' => $widget->widget_key,
                'visitor_id' => $session->json('visitor_id'),
            ])
            ->assertOk();

        $this->assertSame($session->json('visitor_id'), $restored->json('visitor_id'));
        $this->assertSame('My private history', $restored->json('messages.0.body'));
        $this->assertCount(1, Contact::where('workspace_id', $workspace->id)->get());
    }

    public function test_human_agent_button_becomes_available_after_two_customer_messages(): void
    {
        Event::fake([MessageReceived::class, ConversationAssigned::class]);
        Notification::fake();
        ['workspace' => $workspace, 'user' => $user] = $this->createWorkspaceContext();

        $chatbot = AiChatbot::create([
            'workspace_id' => $workspace->id,
            'name' => 'Website AI',
            'enabled' => true,
        ]);
        $account = ChannelAccount::create([
            'workspace_id' => $workspace->id,
            'channel' => 'webchat',
            'display_name' => 'Website chat',
            'status' => 'active',
            'meta_json' => ['ai_chatbot_id' => $chatbot->id],
        ]);
        $widget = ChatWidget::create([
            'workspace_id' => $workspace->id,
            'channel_account_id' => $account->id,
            'name' => 'Website chat',
            'position' => 'bottom_right',
            'ai_enabled' => true,
            'ai_chatbot_id' => $chatbot->id,
        ]);

        $session = $this->postJson(route('widget.session'), ['key' => $widget->widget_key])
            ->assertOk()
            ->assertJsonPath('config.ai_enabled', true)
            ->assertJsonPath('handoff.eligible', false);

        $headers = ['X-Widget-Token' => $session->json('token')];
        $this->withHeaders($headers)->postJson(route('widget.send'), [
            'key' => $widget->widget_key,
            'message' => 'First customer message',
        ])->assertOk()->assertJsonPath('handoff.eligible', false);

        $this->withHeaders($headers)->postJson(route('widget.send'), [
            'key' => $widget->widget_key,
            'message' => 'Second customer message',
        ])->assertOk()->assertJsonPath('handoff.eligible', true);

        $this->withHeaders($headers)->postJson(route('widget.handoff'), [
            'key' => $widget->widget_key,
        ])->assertOk()
            ->assertJsonPath('handoff.status', 'connected')
            ->assertJsonPath('handoff.eligible', false);

        $conversation = Conversation::where('workspace_id', $workspace->id)->sole();
        $this->assertSame('human', $conversation->assigned_to);
        $this->assertNotNull($conversation->handover_at);
        Notification::assertSentTo($user, ConversationHandoverNotification::class);
        Event::assertDispatched(ConversationAssigned::class);
    }

    public function test_human_agent_endpoint_is_unavailable_when_widget_ai_is_disabled(): void
    {
        Event::fake([MessageReceived::class]);
        ['workspace' => $workspace] = $this->createWorkspaceContext();

        $account = ChannelAccount::create([
            'workspace_id' => $workspace->id,
            'channel' => 'webchat',
            'display_name' => 'Website chat',
            'status' => 'active',
        ]);
        $widget = ChatWidget::create([
            'workspace_id' => $workspace->id,
            'channel_account_id' => $account->id,
            'name' => 'Website chat',
            'position' => 'bottom_right',
            'ai_enabled' => false,
        ]);
        $session = $this->postJson(route('widget.session'), ['key' => $widget->widget_key])->assertOk();
        $headers = ['X-Widget-Token' => $session->json('token')];

        foreach (['First message', 'Second message'] as $body) {
            $this->withHeaders($headers)->postJson(route('widget.send'), [
                'key' => $widget->widget_key,
                'message' => $body,
            ])->assertOk()->assertJsonPath('handoff.enabled', false);
        }

        $this->withHeaders($headers)->postJson(route('widget.handoff'), [
            'key' => $widget->widget_key,
        ])->assertStatus(422);
    }
}
