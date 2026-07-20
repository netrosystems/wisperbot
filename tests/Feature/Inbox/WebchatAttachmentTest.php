<?php

namespace Tests\Feature\Inbox;

use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use App\Modules\Inbox\Models\ChatWidget;
use App\Modules\Inbox\Services\WebchatDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class WebchatAttachmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_agent_can_send_an_image_to_a_website_chat_without_whatsapp(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();
        Storage::fake('public');

        $account = ChannelAccount::create([
            'workspace_id' => $workspace->id,
            'channel' => 'webchat',
            'display_name' => 'Website chat',
            'status' => 'active',
        ]);
        $contact = Contact::create([
            'workspace_id' => $workspace->id,
            'source' => 'webchat',
            'first_name' => 'Website visitor',
        ]);
        $conversation = Conversation::create([
            'workspace_id' => $workspace->id,
            'channel_account_id' => $account->id,
            'contact_id' => $contact->id,
            'status' => 'open',
            'external_thread_id' => 'visitor-1',
            'last_message_at' => now(),
        ]);

        $image = UploadedFile::fake()->image('reply.png', 200, 200);
        $response = $this->actingAs($user)->post(
            route('client.inbox.reply', $conversation),
            ['body' => 'Here is the screenshot', 'type' => 'image', 'attachment' => $image],
            ['Accept' => 'application/json'],
        );

        $response->assertOk()->assertJsonPath('error', null);

        $message = Message::where('conversation_id', $conversation->id)->sole();
        $this->assertSame('webchat', $message->channel);
        $this->assertSame('image', $message->type);
        $this->assertSame('sent', $message->status);
        $this->assertNotEmpty($message->payload['preview_url'] ?? null);
        $this->assertArrayNotHasKey('media_id', $message->payload ?? []);
        Storage::disk('public')->assertExists('message-media/'.$image->hashName());
    }

    public function test_website_visitors_are_not_automatically_opted_into_marketing(): void
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

        $conversation = app(WebchatDriver::class)->resolveConversation($widget, 'anonymous-visitor');
        $contact = $conversation->contact;

        $this->assertFalse($contact->opt_in_whatsapp);
        $this->assertFalse($contact->opt_in_sms);
        $this->assertFalse($contact->opt_in_email);
    }
}
