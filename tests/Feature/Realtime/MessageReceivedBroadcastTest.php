<?php

namespace Tests\Feature\Realtime;

use App\Events\MessageReceived;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class MessageReceivedBroadcastTest extends TestCase
{
    use RefreshDatabase;

    public function test_message_received_broadcasts_on_correct_channels(): void
    {
        Event::fake([MessageReceived::class]);

        $ctx = $this->createWorkspaceContext();
        $contact = Contact::factory()->create(['workspace_id' => $ctx['workspace']->id]);
        $conv = Conversation::create([
            'workspace_id' => $ctx['workspace']->id,
            'contact_id' => $contact->id,
            'status' => 'open',
        ]);
        $message = Message::create([
            'conversation_id' => $conv->id,
            'direction' => 'in',
            'channel' => 'whatsapp',
            'body' => 'Hello',
            'status' => 'sent',
            'sent_at' => now(),
        ]);

        MessageReceived::dispatch($message);

        Event::assertDispatched(MessageReceived::class, function ($event) use ($message) {
            return $event->message->id === $message->id;
        });
    }

    public function test_message_received_broadcast_with_contains_expected_fields(): void
    {
        $ctx = $this->createWorkspaceContext();
        $contact = Contact::factory()->create(['workspace_id' => $ctx['workspace']->id]);
        $conv = Conversation::create([
            'workspace_id' => $ctx['workspace']->id,
            'contact_id' => $contact->id,
            'status' => 'open',
        ]);
        $message = Message::create([
            'conversation_id' => $conv->id,
            'direction' => 'in',
            'channel' => 'whatsapp',
            'body' => 'Hello broadcast',
            'status' => 'sent',
            'sent_at' => now(),
        ]);

        // Explicitly load the conversation relationship so broadcastOn() can access workspace_id
        $message->setRelation('conversation', $conv);

        $event = new MessageReceived($message, true);
        $payload = $event->broadcastWith();

        $this->assertArrayHasKey('id', $payload);
        $this->assertArrayHasKey('conversation_id', $payload);
        $this->assertArrayHasKey('body', $payload);
        $this->assertEquals('Hello broadcast', $payload['body']);
        $this->assertTrue($payload['reopened']);
        $this->assertSame($conv->uuid, $payload['conversation']['uuid']);
        $this->assertSame('open', $payload['conversation']['status']);

        $channels = $event->broadcastOn();
        $channelNames = array_map(fn ($c) => $c->name, $channels);
        $this->assertContains("private-conversation.{$conv->id}", $channelNames);
        $this->assertContains("private-workspace.{$ctx['workspace']->id}", $channelNames);
    }

    public function test_email_broadcast_uses_compact_payload_for_rich_content(): void
    {
        $ctx = $this->createWorkspaceContext();
        $contact = Contact::factory()->create(['workspace_id' => $ctx['workspace']->id]);
        $conv = Conversation::create([
            'workspace_id' => $ctx['workspace']->id,
            'contact_id' => $contact->id,
            'status' => 'open',
        ]);
        $message = Message::create([
            'conversation_id' => $conv->id,
            'direction' => 'in',
            'channel' => 'email',
            'type' => 'text',
            'body' => 'Rich email',
            'payload' => [
                'html_body' => '<p>'.str_repeat('Formatted content ', 1000).'</p>',
                'has_attachments' => true,
                'attachments' => [['name' => 'invoice.pdf', 'url' => 'https://example.test/invoice.pdf']],
            ],
            'status' => 'delivered',
            'sent_at' => now(),
        ]);
        $message->setRelation('conversation', $conv);

        $payload = (new MessageReceived($message))->broadcastWith();

        $this->assertTrue($payload['payload']['has_html_body']);
        $this->assertTrue($payload['payload']['has_attachments']);
        $this->assertArrayNotHasKey('html_body', $payload['payload']);
        $this->assertArrayNotHasKey('attachments', $payload['payload']);
        $this->assertLessThan(10240, strlen(json_encode($payload, JSON_THROW_ON_ERROR)));
    }
}
