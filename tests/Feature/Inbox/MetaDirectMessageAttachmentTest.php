<?php

namespace Tests\Feature\Inbox;

use App\Modules\Inbox\Services\InstagramDriver;
use App\Modules\Inbox\Services\MessengerDriver;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MetaDirectMessageAttachmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_messenger_inbound_image_is_stored_as_renderable_media_and_available_to_mobile(): void
    {
        $context = $this->createWorkspaceContext();
        ChannelAccount::create([
            'workspace_id' => $context['workspace']->id,
            'channel' => 'messenger',
            'provider' => 'messenger',
            'display_name' => 'Test Page',
            'status' => 'active',
            'credentials' => ['page_access_token' => 'page-token'],
            'meta_json' => ['page_id' => 'PAGE_1'],
        ]);

        Http::fake([
            'https://lookaside.fbsbx.com/*' => Http::response('fake-image-bytes', 200, ['Content-Type' => 'image/jpeg']),
            'https://graph.facebook.com/*' => Http::response([]),
        ]);

        app(MessengerDriver::class)->processWebhookPayload([
            'object' => 'page',
            'entry' => [[
                'id' => 'PAGE_1',
                'messaging' => [[
                    'sender' => ['id' => 'CUSTOMER_1'],
                    'recipient' => ['id' => 'PAGE_1'],
                    'timestamp' => 1234567890,
                    'message' => [
                        'mid' => 'm_mid_image_1',
                        'text' => 'Here is the photo',
                        'attachments' => [[
                            'type' => 'image',
                            'payload' => ['url' => 'https://lookaside.fbsbx.com/image.jpg'],
                        ]],
                    ],
                ]],
            ]],
        ]);

        $message = Message::query()->latest('id')->firstOrFail();
        $this->assertSame('messenger', $message->channel);
        $this->assertSame('image', $message->type);
        $this->assertSame('Here is the photo', $message->body);
        $this->assertSame('https://lookaside.fbsbx.com/image.jpg', $message->payload['image']['url']);

        Sanctum::actingAs($context['user']);
        $conversation = $message->conversation;
        $response = $this->getJson("/api/v1/mobile/conversations/{$conversation->uuid}");
        $response->assertOk()
            ->assertJsonPath('messages.0.type', 'image');

        $mediaUrl = $response->json('messages.0.payload.media_url');
        $this->assertIsString($mediaUrl);
        $this->assertStringContainsString("/api/v1/mobile/conversations/{$conversation->uuid}/messages/{$message->id}/media/signed", $mediaUrl);
        $this->assertStringContainsString('signature=', $mediaUrl);

        $uri = (string) parse_url($mediaUrl, PHP_URL_PATH).'?'.(string) parse_url($mediaUrl, PHP_URL_QUERY);
        $this->get($uri)->assertRedirect();
        $this->assertNotEmpty($message->fresh()->payload['preview_url'] ?? null);
    }

    public function test_messenger_file_attachment_is_stored_as_document(): void
    {
        $context = $this->createWorkspaceContext();
        ChannelAccount::create([
            'workspace_id' => $context['workspace']->id,
            'channel' => 'messenger',
            'provider' => 'messenger',
            'display_name' => 'Test Page',
            'status' => 'active',
            'credentials' => ['page_access_token' => 'page-token'],
            'meta_json' => ['page_id' => 'PAGE_2'],
        ]);
        Http::fake(['https://graph.facebook.com/*' => Http::response([])]);

        app(MessengerDriver::class)->processWebhookPayload([
            'object' => 'page',
            'entry' => [[
                'id' => 'PAGE_2',
                'messaging' => [[
                    'sender' => ['id' => 'CUSTOMER_2'],
                    'recipient' => ['id' => 'PAGE_2'],
                    'message' => [
                        'mid' => 'm_mid_file_1',
                        'attachments' => [[
                            'type' => 'file',
                            'payload' => ['url' => 'https://lookaside.fbsbx.com/manual.pdf', 'name' => 'manual.pdf'],
                        ]],
                    ],
                ]],
            ]],
        ]);

        $message = Message::query()->latest('id')->firstOrFail();
        $this->assertSame('document', $message->type);
        $this->assertSame('manual.pdf', $message->payload['filename']);
        $this->assertSame('application/pdf', $message->payload['mime_type']);
    }

    public function test_instagram_inbound_image_is_stored_as_renderable_media(): void
    {
        $context = $this->createWorkspaceContext();
        ChannelAccount::create([
            'workspace_id' => $context['workspace']->id,
            'channel' => 'instagram',
            'provider' => 'instagram',
            'display_name' => 'Test IG',
            'status' => 'active',
            'credentials' => ['access_token' => 'page-token', 'instagram_account_id' => 'IG_1'],
            'meta_json' => ['instagram_page_id' => 'IG_1', 'instagram_account_id' => 'IG_1'],
        ]);
        Http::fake(['https://graph.facebook.com/*' => Http::response([])]);

        app(InstagramDriver::class)->processWebhookPayload([
            'object' => 'instagram',
            'entry' => [[
                'id' => 'IG_1',
                'messaging' => [[
                    'sender' => ['id' => 'IGSID_1'],
                    'recipient' => ['id' => 'IG_1'],
                    'message' => [
                        'mid' => 'ig_mid_image_1',
                        'attachments' => [[
                            'type' => 'image',
                            'payload' => ['url' => 'https://lookaside.fbsbx.com/ig-image.jpg'],
                        ]],
                    ],
                ]],
            ]],
        ]);

        $message = Message::query()->latest('id')->firstOrFail();
        $this->assertSame('instagram', $message->channel);
        $this->assertSame('image', $message->type);
        $this->assertSame('https://lookaside.fbsbx.com/ig-image.jpg', $message->payload['image']['url']);
    }
}
