<?php

namespace Tests\Feature\Inbox;

use App\Events\ConversationActivityCreated;
use App\Events\MessageSent;
use App\Events\MessageStatusUpdated;
use App\Events\WidgetMessageCreated;
use App\Modules\Inbox\Models\ChatWidget;
use App\Modules\Inbox\Models\WidgetPushSubscription;
use App\Modules\Inbox\Services\WebchatDriver;
use App\Modules\Inbox\Services\WidgetPayloadBuilder;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use App\Support\WebchatVisitorToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WidgetRealtimeTest extends TestCase
{
    use RefreshDatabase;

    public function test_choices_are_additive_in_history_and_broadcast_and_send_as_normal_text(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        [$widget] = $this->createWebchatWidget($workspace->id);
        $session = $this->postJson(route('widget.session'), ['key' => $widget->widget_key])->assertOk();
        $conversationId = $session->json('conversation_id');
        $message = Message::create([
            'conversation_id' => $conversationId, 'direction' => 'out', 'channel' => 'webchat',
            'type' => 'text', 'body' => "Which app?\n\n1. iOS app\n2. Android app", 'status' => 'sent', 'sent_by' => 'bot',
            'payload' => ['display_body' => 'Which app?', 'quick_replies' => [['id' => 'qr_1', 'label' => 'iOS app'], ['id' => 'qr_2', 'label' => 'Android app']]],
        ]);
        $builder = app(WidgetPayloadBuilder::class);
        $this->assertSame('iOS app', $builder->message($message, $widget)['quick_replies'][0]['label']);
        $this->assertSame($builder->message($message, $widget), $builder->messages($conversationId, $widget, 0)[0]);
        $this->withHeader('X-Widget-Token', $session->json('token'))
            ->getJson(route('widget.poll', ['key' => $widget->widget_key, 'after' => 0]))
            ->assertOk()->assertJsonPath('messages.0.quick_replies.0.label', 'iOS app')
            ->assertJsonPath('messages.0.display_body', 'Which app?');
        $this->assertSame('iOS app', (new WidgetMessageCreated($conversationId, $builder->message($message, $widget)))->broadcastWith()['message']['quick_replies'][0]['label']);
        $this->withHeader('X-Widget-Token', $session->json('token'))
            ->postJson(route('widget.send'), ['key' => $widget->widget_key, 'message' => 'iOS app'])
            ->assertOk()->assertJsonPath('message.body', 'iOS app');
        $this->assertDatabaseHas('messages', ['conversation_id' => $conversationId, 'direction' => 'in', 'body' => 'iOS app']);
    }

    public function test_activity_payload_is_public_safe_and_keeps_an_agent_role_fallback(): void
    {
        ['workspace' => $workspace, 'user' => $agent] = $this->createWorkspaceContext();
        [$widget, $account] = $this->createWebchatWidget($workspace->id);
        $conversation = $this->createConversation($workspace->id, $account->id);
        $message = Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'system',
            'channel' => 'webchat',
            'type' => 'event',
            'body' => "{$agent->name} joined the chat",
            'payload' => ['activity' => [
                'type' => 'conversation.joined',
                'actor' => ['id' => $agent->id, 'name' => $agent->name, 'email' => $agent->email],
            ]],
            'status' => 'delivered',
            'sent_by' => 'system',
            'user_id' => $agent->id,
            'sent_at' => now(),
        ]);

        $snapshotName = $agent->name;
        $payload = app(WidgetPayloadBuilder::class)->message($message, $widget);
        $agent->update(['name' => 'Renamed later']);
        $payloadAfterRename = app(WidgetPayloadBuilder::class)->message($message->fresh(), $widget);

        $this->assertSame('agent', $payload['role']);
        $this->assertSame('activity', $payload['kind']);
        $this->assertSame("{$snapshotName} joined the chat", $payload['body']);
        $this->assertSame([
            'type' => 'conversation.joined',
            'actor_name' => $snapshotName,
        ], $payload['activity']);
        $this->assertArrayNotHasKey('id', $payload['activity']);
        $this->assertArrayNotHasKey('email', $payload['activity']);
        $this->assertSame([], $payload['quick_replies']);
        $this->assertSame([], $payload['resources']);
        $this->assertSame($payload['activity'], $payloadAfterRename['activity']);
        $this->assertSame($snapshotName, $payloadAfterRename['agent_name']);
        $this->assertSame($payload, app(WidgetPayloadBuilder::class)->messages($conversation->id, $widget, 0)[0]);
    }

    public function test_initial_widget_history_returns_the_latest_window_in_chronological_order(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        [$widget, $account] = $this->createWebchatWidget($workspace->id);
        $conversation = $this->createConversation($workspace->id, $account->id);

        foreach (range(1, 105) as $number) {
            Message::create([
                'conversation_id' => $conversation->id,
                'direction' => 'in',
                'channel' => 'webchat',
                'type' => 'text',
                'body' => "Message {$number}",
                'status' => 'delivered',
                'sent_by' => 'human',
                'sent_at' => now()->addSeconds($number),
            ]);
        }

        $messages = app(WidgetPayloadBuilder::class)->messages($conversation->id, $widget, 0);

        $this->assertCount(100, $messages);
        $this->assertSame('Message 6', $messages[0]['body']);
        $this->assertSame('Message 105', $messages[99]['body']);
        $this->assertSame(
            collect($messages)->pluck('id')->sort()->values()->all(),
            collect($messages)->pluck('id')->values()->all(),
        );
    }

    public function test_activity_realtime_bridge_uses_the_redacted_widget_event_without_push(): void
    {
        Event::fake([WidgetMessageCreated::class]);
        Http::fake();
        ['workspace' => $workspace, 'user' => $agent] = $this->createWorkspaceContext();
        [, $account] = $this->createWebchatWidget($workspace->id);
        $conversation = $this->createConversation($workspace->id, $account->id);
        $message = Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'system',
            'channel' => 'webchat',
            'type' => 'event',
            'body' => "{$agent->name} joined the chat",
            'payload' => ['activity' => [
                'type' => 'conversation.joined',
                'actor' => ['id' => $agent->id, 'name' => $agent->name],
            ]],
            'status' => 'delivered',
            'sent_by' => 'system',
            'user_id' => $agent->id,
            'sent_at' => now(),
        ]);

        ConversationActivityCreated::dispatch($message);

        Event::assertDispatched(WidgetMessageCreated::class, fn (WidgetMessageCreated $event) => $event->conversationId === $conversation->id
            && $event->message['kind'] === 'activity'
            && $event->message['activity'] === [
                'type' => 'conversation.joined',
                'actor_name' => $agent->name,
            ]
        );
        Http::assertNothingSent();
    }

    public function test_widget_broadcast_auth_accepts_only_the_token_bound_conversation(): void
    {
        config([
            'broadcasting.connections.pusher.key' => 'test-key',
            'broadcasting.connections.pusher.secret' => 'test-secret',
            'broadcasting.connections.pusher.app_id' => 'test-app',
            'broadcasting.connections.pusher.options.cluster' => 'mt1',
        ]);

        ['workspace' => $workspace] = $this->createWorkspaceContext();
        [$widget] = $this->createWebchatWidget($workspace->id);

        $session = $this->postJson(route('widget.session'), [
            'key' => $widget->widget_key,
        ])->assertOk();

        $conversationId = $session->json('conversation_id');

        $this->withHeader('X-Widget-Token', $session->json('token'))
            ->postJson(route('widget.broadcasting-auth'), [
                'key' => $widget->widget_key,
                'socket_id' => '123.456',
                'channel_name' => "private-widget-conversation.{$conversationId}",
            ])
            ->assertOk()
            ->assertJsonStructure(['auth']);

        $this->withHeader('X-Widget-Token', $session->json('token'))
            ->postJson(route('widget.broadcasting-auth'), [
                'key' => $widget->widget_key,
                'socket_id' => '123.456',
                'channel_name' => 'private-widget-conversation.999999',
            ])
            ->assertForbidden();
    }

    public function test_widget_broadcast_auth_rejects_disallowed_domains(): void
    {
        config([
            'broadcasting.connections.pusher.key' => 'test-key',
            'broadcasting.connections.pusher.secret' => 'test-secret',
            'broadcasting.connections.pusher.app_id' => 'test-app',
        ]);

        ['workspace' => $workspace] = $this->createWorkspaceContext();
        [$widget] = $this->createWebchatWidget($workspace->id, [
            'allowed_domains' => ['allowed.example'],
        ]);

        $session = $this->withHeader('Origin', 'https://allowed.example')
            ->postJson(route('widget.session'), ['key' => $widget->widget_key])
            ->assertOk();

        $this->withHeaders([
            'Origin' => 'https://evil.example',
            'X-Widget-Token' => $session->json('token'),
        ])->postJson(route('widget.broadcasting-auth'), [
            'key' => $widget->widget_key,
            'socket_id' => '123.456',
            'channel_name' => 'private-widget-conversation.'.$session->json('conversation_id'),
        ])->assertForbidden();
    }

    public function test_web_and_sdk_keys_are_gated_by_their_own_enabled_flags(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        [$widget] = $this->createWebchatWidget($workspace->id, [
            'enabled' => false,
            'sdk_enabled' => true,
        ]);

        $this->postJson(route('widget.session'), [
            'key' => $widget->widget_key,
        ])->assertNotFound();
        $this->get("/widgets/chat/{$widget->sdk_widget_key}.js")->assertNotFound();

        $sdkSession = $this->postJson(route('widget.session'), [
            'key' => $widget->sdk_widget_key,
        ])->assertOk();
        $sdkSession->assertJsonPath('config.key', $widget->sdk_widget_key);

        $widget->update([
            'enabled' => true,
            'sdk_enabled' => false,
        ]);

        $this->postJson(route('widget.session'), [
            'key' => $widget->widget_key,
        ])->assertOk();
        $this->postJson(route('widget.session'), [
            'key' => $widget->sdk_widget_key,
        ])->assertNotFound();
    }

    public function test_web_and_sdk_sessions_mark_new_conversations_with_their_start_source(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        [$widget] = $this->createWebchatWidget($workspace->id);

        $webSession = $this->postJson(route('widget.session'), [
            'key' => $widget->widget_key,
        ])->assertOk();

        $this->assertDatabaseHas('conversations', [
            'id' => $webSession->json('conversation_id'),
            'started_from' => Conversation::STARTED_FROM_WEB_WIDGET,
        ]);

        $sdkSession = $this->postJson(route('widget.session'), [
            'key' => $widget->sdk_widget_key,
        ])->assertOk();

        $this->assertDatabaseHas('conversations', [
            'id' => $sdkSession->json('conversation_id'),
            'started_from' => Conversation::STARTED_FROM_CUSTOMER_SDK,
        ]);
    }

    public function test_sdk_disabled_blocks_public_widget_endpoints_for_sdk_key_only(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        [$widget] = $this->createWebchatWidget($workspace->id);

        $session = $this->postJson(route('widget.session'), [
            'key' => $widget->sdk_widget_key,
        ])->assertOk();
        $headers = ['X-Widget-Token' => $session->json('token')];

        $widget->update(['sdk_enabled' => false]);

        $this->withHeaders($headers)
            ->postJson(route('widget.send'), ['key' => $widget->sdk_widget_key, 'message' => 'Hello'])
            ->assertNotFound();
        $this->withHeaders($headers)
            ->getJson(route('widget.poll', ['key' => $widget->sdk_widget_key, 'after' => 0]))
            ->assertNotFound();
        $this->withHeaders($headers)
            ->postJson(route('widget.read'), ['key' => $widget->sdk_widget_key])
            ->assertNotFound();
        $this->withHeaders($headers)
            ->postJson(route('widget.delivered'), ['key' => $widget->sdk_widget_key])
            ->assertNotFound();
        $this->withHeaders($headers)
            ->postJson(route('widget.typing'), ['key' => $widget->sdk_widget_key, 'is_typing' => true])
            ->assertNotFound();
        $this->withHeaders($headers)
            ->postJson(route('widget.handoff'), ['key' => $widget->sdk_widget_key])
            ->assertNotFound();
        $this->getJson(route('widget.pusher-config', ['key' => $widget->sdk_widget_key]))
            ->assertNotFound();
        $this->withHeaders($headers)
            ->postJson(route('widget.broadcasting-auth'), [
                'key' => $widget->sdk_widget_key,
                'socket_id' => '123.456',
                'channel_name' => 'private-widget-conversation.'.$session->json('conversation_id'),
            ])
            ->assertNotFound();

        $this->postJson(route('widget.session'), [
            'key' => $widget->widget_key,
        ])->assertOk();
    }

    public function test_sdk_key_skips_website_allowed_domains_rule(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        [$widget] = $this->createWebchatWidget($workspace->id, [
            'allowed_domains' => ['allowed.example'],
        ]);

        $this->postJson(route('widget.session'), [
            'key' => $widget->widget_key,
        ])->assertForbidden();

        $this->postJson(route('widget.session'), [
            'key' => $widget->sdk_widget_key,
        ])->assertOk();
    }

    public function test_sdk_key_accepts_old_web_key_token_and_reissues_sdk_bound_token(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        [$widget] = $this->createWebchatWidget($workspace->id);

        $webSession = $this->postJson(route('widget.session'), [
            'key' => $widget->widget_key,
        ])->assertOk();

        $this->withHeader('X-Widget-Token', $webSession->json('token'))
            ->postJson(route('widget.send'), [
                'key' => $widget->widget_key,
                'message' => 'Keep this history',
            ])
            ->assertOk();

        $sdkSession = $this->withHeader('X-Widget-Token', $webSession->json('token'))
            ->postJson(route('widget.session'), [
                'key' => $widget->sdk_widget_key,
                'visitor_id' => $webSession->json('visitor_id'),
            ])
            ->assertOk()
            ->assertJsonPath('conversation_id', $webSession->json('conversation_id'))
            ->assertJsonPath('messages.0.body', 'Keep this history');

        $this->assertNull(WebchatVisitorToken::verify($sdkSession->json('token'), $widget->widget_key));
        $this->assertNotNull(WebchatVisitorToken::verify($sdkSession->json('token'), $widget->sdk_widget_key));
        $this->assertSame(
            Conversation::STARTED_FROM_WEB_WIDGET,
            Conversation::find($webSession->json('conversation_id'))->started_from,
        );
    }

    public function test_restored_existing_conversation_source_is_not_overwritten(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        [$widget] = $this->createWebchatWidget($workspace->id);

        $webSession = $this->postJson(route('widget.session'), [
            'key' => $widget->widget_key,
        ])->assertOk();

        Conversation::whereKey($webSession->json('conversation_id'))->update(['started_from' => null]);

        $this->withHeader('X-Widget-Token', $webSession->json('token'))
            ->postJson(route('widget.session'), [
                'key' => $widget->sdk_widget_key,
                'visitor_id' => $webSession->json('visitor_id'),
            ])
            ->assertOk()
            ->assertJsonPath('conversation_id', $webSession->json('conversation_id'));

        $this->assertNull(Conversation::find($webSession->json('conversation_id'))->started_from);
    }

    public function test_legacy_webchat_driver_ingest_marks_new_conversation_as_web_widget(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        [$widget] = $this->createWebchatWidget($workspace->id);

        $message = app(WebchatDriver::class)->ingestVisitorMessage($widget, 'legacy-visitor-id', 'Hello');

        $this->assertSame(
            Conversation::STARTED_FROM_WEB_WIDGET,
            $message->conversation->started_from,
        );
    }

    public function test_widget_session_can_store_optional_sdk_push_token(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        [$widget] = $this->createWebchatWidget($workspace->id);

        $session = $this->postJson(route('widget.session'), [
            'key' => $widget->widget_key,
            'push' => [
                'token' => 'onesignal-subscription-123',
            ],
        ])->assertOk();

        $this->assertDatabaseHas('widget_push_subscriptions', [
            'workspace_id' => $workspace->id,
            'chat_widget_id' => $widget->id,
            'conversation_id' => $session->json('conversation_id'),
            'visitor_id' => $session->json('visitor_id'),
            'onesignal_subscription_id' => 'onesignal-subscription-123',
            'revoked_at' => null,
        ]);
    }

    public function test_agent_and_bot_webchat_replies_broadcast_widget_safe_payloads(): void
    {
        Event::fake([WidgetMessageCreated::class]);

        ['workspace' => $workspace, 'user' => $agent] = $this->createWorkspaceContext();
        [$widget, $account] = $this->createWebchatWidget($workspace->id);
        $conversation = $this->createConversation($workspace->id, $account->id);

        $agentMessage = Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'out',
            'channel' => 'webchat',
            'type' => 'text',
            'body' => 'Agent reply',
            'status' => 'sent',
            'sent_by' => 'human',
            'user_id' => $agent->id,
            'provider_message_id' => 'private-provider-id',
            'sent_at' => now(),
        ]);

        MessageSent::dispatch($agentMessage);

        Event::assertDispatched(WidgetMessageCreated::class, function (WidgetMessageCreated $event) use ($conversation) {
            return $event->conversationId === $conversation->id
                && $event->message['role'] === 'agent'
                && $event->message['body'] === 'Agent reply'
                && ! array_key_exists('provider_message_id', $event->message);
        });

        $botMessage = Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'out',
            'channel' => 'webchat',
            'type' => 'text',
            'body' => 'Bot reply',
            'payload' => ['resources' => [[
                'version' => 1,
                'kind' => 'video',
                'provider' => 'youtube',
                'video_id' => 'dQw4w9WgXcQ',
                'title' => 'Setup guide',
                'canonical_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
                'playback_url' => 'https://www.youtube.com/embed/dQw4w9WgXcQ',
                'transcript' => 'must not be exposed',
            ]]],
            'status' => 'sent',
            'sent_by' => 'bot',
            'sent_at' => now(),
        ]);

        MessageSent::dispatch($botMessage);

        Event::assertDispatched(WidgetMessageCreated::class, function (WidgetMessageCreated $event) use ($conversation, $widget) {
            return $event->conversationId === $conversation->id
                && $event->message['body'] === 'Bot reply'
                && $event->message['resources'][0]['provider'] === 'youtube'
                && ! array_key_exists('transcript', $event->message['resources'][0])
                && $event->message['agent_name'] === ($widget->agent_name ?: 'Support');
        });
    }

    public function test_agent_webchat_reply_notifies_registered_sdk_push_subscription(): void
    {
        Event::fake([WidgetMessageCreated::class]);
        Http::fake(['https://api.onesignal.com/notifications' => Http::response(['id' => 'push-id'], 200)]);
        config([
            'services.onesignal.app_id' => 'onesignal-app-id',
            'services.onesignal.rest_api_key' => 'onesignal-rest-key',
        ]);

        ['workspace' => $workspace, 'user' => $agent] = $this->createWorkspaceContext();
        [$widget, $account] = $this->createWebchatWidget($workspace->id);
        $conversation = $this->createConversation($workspace->id, $account->id);

        WidgetPushSubscription::create([
            'workspace_id' => $workspace->id,
            'chat_widget_id' => $widget->id,
            'conversation_id' => $conversation->id,
            'visitor_id' => 'visitor-123',
            'onesignal_subscription_id' => 'sdk-subscription-123',
            'last_seen_at' => now(),
        ]);

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'out',
            'channel' => 'webchat',
            'type' => 'text',
            'body' => 'Agent reply for mobile SDK',
            'status' => 'sent',
            'sent_by' => 'human',
            'user_id' => $agent->id,
            'sent_at' => now(),
        ]);

        MessageSent::dispatch($message);

        Http::assertSent(function ($request) use ($conversation) {
            $payload = $request->data();

            return $request->url() === 'https://api.onesignal.com/notifications'
                && $payload['app_id'] === 'onesignal-app-id'
                && $payload['include_subscription_ids'] === ['sdk-subscription-123']
                && $payload['target_channel'] === 'push'
                && $payload['contents']['en'] === 'Agent reply for mobile SDK'
                && $payload['data']['type'] === 'widget_message'
                && $payload['data']['conversation_id'] === $conversation->id;
        });
    }

    public function test_non_webchat_replies_do_not_broadcast_widget_events(): void
    {
        Event::fake([WidgetMessageCreated::class]);

        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $account = ChannelAccount::create([
            'workspace_id' => $workspace->id,
            'channel' => 'whatsapp',
            'display_name' => 'WhatsApp',
            'status' => 'active',
        ]);
        $conversation = $this->createConversation($workspace->id, $account->id);
        $message = Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'out',
            'channel' => 'whatsapp',
            'type' => 'text',
            'body' => 'WhatsApp reply',
            'status' => 'sent',
            'sent_at' => now(),
        ]);

        MessageSent::dispatch($message);

        Event::assertNotDispatched(WidgetMessageCreated::class);
    }

    public function test_widget_poll_marks_sent_messages_as_delivered(): void
    {
        Event::fake([MessageStatusUpdated::class]);

        ['workspace' => $workspace] = $this->createWorkspaceContext();
        [$widget, $account] = $this->createWebchatWidget($workspace->id);
        $conversation = $this->createConversation($workspace->id, $account->id);

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'out',
            'channel' => 'webchat',
            'type' => 'text',
            'body' => 'Hello from support',
            'status' => 'sent',
            'sent_at' => now(),
        ]);

        $session = $this->postJson(route('widget.session'), [
            'key' => $widget->widget_key,
        ])->assertOk();

        // Bind conversation to session
        $token = WebchatVisitorToken::issue(
            (int) $conversation->id,
            $widget->widget_key,
            (string) $session->json('visitor_id')
        );

        $this->withHeader('X-Widget-Token', $token)
            ->getJson(route('widget.poll', [
                'key' => $widget->widget_key,
                'after' => 0,
                'active' => 1,
            ]))
            ->assertOk();

        $this->assertEquals('delivered', $message->fresh()->status);
        Event::assertDispatched(MessageStatusUpdated::class, fn ($e) => $e->message->id === $message->id && $e->message->status === 'delivered');
    }

    public function test_widget_mark_read_endpoint_marks_messages_as_read(): void
    {
        Event::fake([MessageStatusUpdated::class]);

        ['workspace' => $workspace] = $this->createWorkspaceContext();
        [$widget, $account] = $this->createWebchatWidget($workspace->id);
        $conversation = $this->createConversation($workspace->id, $account->id);

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'out',
            'channel' => 'webchat',
            'type' => 'text',
            'body' => 'Please read this',
            'status' => 'delivered',
            'sent_at' => now(),
        ]);

        $token = WebchatVisitorToken::issue(
            (int) $conversation->id,
            $widget->widget_key,
            'visitor-123'
        );

        $this->withHeader('X-Widget-Token', $token)
            ->postJson(route('widget.read'), [
                'key' => $widget->widget_key,
            ])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertEquals('read', $message->fresh()->status);
        Event::assertDispatched(MessageStatusUpdated::class, fn ($e) => $e->message->id === $message->id && $e->message->status === 'read');
    }

    /**
     * @return array{0:ChatWidget,1:ChannelAccount}
     */
    private function createWebchatWidget(int $workspaceId, array $widgetAttrs = []): array
    {
        $account = ChannelAccount::create([
            'workspace_id' => $workspaceId,
            'channel' => 'webchat',
            'display_name' => 'Website chat',
            'status' => 'active',
        ]);

        $widget = ChatWidget::create(array_merge([
            'workspace_id' => $workspaceId,
            'channel_account_id' => $account->id,
            'name' => 'Website chat',
            'position' => 'bottom_right',
        ], $widgetAttrs));

        return [$widget, $account];
    }

    private function createConversation(int $workspaceId, int $channelAccountId): Conversation
    {
        $contact = Contact::factory()->create(['workspace_id' => $workspaceId]);

        return Conversation::create([
            'workspace_id' => $workspaceId,
            'channel_account_id' => $channelAccountId,
            'contact_id' => $contact->id,
            'status' => 'open',
        ]);
    }
}
