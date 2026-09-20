<?php

namespace Tests\Feature\Inbox;

use App\Modules\Inbox\Services\InstagramDriver;
use App\Modules\Inbox\Services\MessengerDriver;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Message;
use App\Services\Media\AttachmentService;
use App\Services\StorageManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MetaDirectMessageAttachmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Cached media is keyed by message id, which restarts every run; never
        // read or write the real public disk.
        Storage::fake('public');
    }

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
        $storedPath = app(StorageManager::class)->prefixedPath('message-media/7XLJwoyLaYMWc9E0w32TlCvKVtcU52VcAcCMDE9y.jpg');
        app(StorageManager::class)->disk()->put($storedPath, 'fake-local-image-bytes');
        $message->update([
            'payload' => array_merge($message->payload, [
                'path' => $storedPath,
                'preview_url' => 'https://wisperbot.com/message-media/broken-public-preview.jpg',
            ]),
        ]);

        Sanctum::actingAs($context['user']);
        $conversation = $message->conversation;
        $response = $this->getJson("/api/v1/mobile/conversations/{$conversation->uuid}");
        $response->assertOk()
            ->assertJsonPath('messages.0.type', 'image');

        $mediaUrl = $response->json('messages.0.payload.media_url');
        $this->assertIsString($mediaUrl);
        $this->assertStringContainsString("/api/v1/mobile/conversations/{$conversation->uuid}/messages/{$message->id}/media/signed", $mediaUrl);
        $this->assertStringContainsString('signature=', $mediaUrl);
        $this->assertStringContainsString('expires=', $mediaUrl);
        $this->assertSame($mediaUrl, $response->json('messages.0.payload.preview_url'));
        $this->assertSame($mediaUrl, $response->json('messages.0.payload.attachment_url'));
        $this->assertSame($mediaUrl, $response->json('messages.0.payload.url'));
        $this->assertSame($mediaUrl, $response->json('messages.0.payload.link'));
        $this->assertSame($mediaUrl, $response->json('messages.0.payload.image.url'));
        $this->assertSame($mediaUrl, $response->json('messages.0.payload.image.preview_url'));
        $this->assertSame($mediaUrl, $response->json('messages.0.payload.image.link'));

        $secondResponse = $this->getJson("/api/v1/mobile/conversations/{$conversation->uuid}");
        $this->assertSame($mediaUrl, $secondResponse->json('messages.0.payload.media_url'));

        $uri = (string) parse_url($mediaUrl, PHP_URL_PATH).'?'.(string) parse_url($mediaUrl, PHP_URL_QUERY);
        $this->get($uri)
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg')
            ->assertSee('fake-local-image-bytes', false);
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

    public function test_messenger_captionless_image_does_not_store_image_as_message_text(): void
    {
        $context = $this->createWorkspaceContext();
        ChannelAccount::create([
            'workspace_id' => $context['workspace']->id,
            'channel' => 'messenger',
            'provider' => 'messenger',
            'display_name' => 'Test Page',
            'status' => 'active',
            'credentials' => ['page_access_token' => 'page-token'],
            'meta_json' => ['page_id' => 'PAGE_3'],
        ]);
        Http::fake(['https://graph.facebook.com/*' => Http::response([])]);

        app(MessengerDriver::class)->processWebhookPayload([
            'object' => 'page',
            'entry' => [[
                'id' => 'PAGE_3',
                'messaging' => [[
                    'sender' => ['id' => 'CUSTOMER_3'],
                    'recipient' => ['id' => 'PAGE_3'],
                    'message' => [
                        'mid' => 'm_mid_image_no_caption',
                        'attachments' => [[
                            'type' => 'image',
                            'payload' => ['url' => 'https://lookaside.fbsbx.com/image-no-caption.jpg'],
                        ]],
                    ],
                ]],
            ]],
        ]);

        $message = Message::query()->latest('id')->firstOrFail();
        $this->assertSame('image', $message->type);
        $this->assertSame('', $message->body);
        $this->assertNull($message->payload['caption']);
        $this->assertArrayNotHasKey('caption', $message->payload['image']);
    }

    public function test_inbound_heic_media_is_cached_as_jpeg_when_converter_is_available(): void
    {
        $context = $this->createWorkspaceContext();
        ChannelAccount::create([
            'workspace_id' => $context['workspace']->id,
            'channel' => 'messenger',
            'provider' => 'messenger',
            'display_name' => 'Test Page',
            'status' => 'active',
            'credentials' => ['page_access_token' => 'page-token'],
            'meta_json' => ['page_id' => 'PAGE_HEIC'],
        ]);

        $this->app->instance(AttachmentService::class, new class(app(StorageManager::class)) extends AttachmentService
        {
            public function attemptHeicConversion(string $sourcePath, int $quality = 90): ?string
            {
                $target = tempnam(sys_get_temp_dir(), 'fake_jpg_').'.jpg';
                file_put_contents($target, 'fake-jpeg-bytes');

                return $target;
            }
        });

        Http::fake([
            'https://lookaside.fbsbx.com/*' => Http::response('fake-heic-bytes', 200, ['Content-Type' => 'image/heic']),
            'https://graph.facebook.com/*' => Http::response([]),
        ]);

        app(MessengerDriver::class)->processWebhookPayload([
            'object' => 'page',
            'entry' => [[
                'id' => 'PAGE_HEIC',
                'messaging' => [[
                    'sender' => ['id' => 'CUSTOMER_HEIC'],
                    'recipient' => ['id' => 'PAGE_HEIC'],
                    'message' => [
                        'mid' => 'm_mid_heic_1',
                        'attachments' => [[
                            'type' => 'image',
                            'payload' => ['url' => 'https://lookaside.fbsbx.com/scaled_52879.heic'],
                        ]],
                    ],
                ]],
            ]],
        ]);

        $message = Message::query()->latest('id')->firstOrFail();
        Sanctum::actingAs($context['user']);

        $response = $this->getJson("/api/v1/mobile/conversations/{$message->conversation->uuid}");
        $mediaUrl = (string) $response->json('messages.0.payload.media_url');
        $uri = (string) parse_url($mediaUrl, PHP_URL_PATH).'?'.(string) parse_url($mediaUrl, PHP_URL_QUERY);

        $this->get($uri)->assertOk()->assertHeader('Content-Type', 'image/jpeg');

        $payload = $message->fresh()->payload;
        $this->assertSame('image/jpeg', $payload['mime_type']);
        $this->assertTrue($payload['is_converted_heic']);
        $this->assertSame('scaled_52879.jpg', $payload['filename']);
        $this->assertStringEndsWith('.jpg', $payload['preview_url']);
    }

    public function test_staff_heic_upload_is_stored_as_jpeg_image_when_converter_is_available(): void
    {
        $service = new class(app(StorageManager::class)) extends AttachmentService
        {
            public function attemptHeicConversion(string $sourcePath, int $quality = 90): ?string
            {
                $target = tempnam(sys_get_temp_dir(), 'fake_staff_jpg_').'.jpg';
                file_put_contents($target, 'fake-jpeg-bytes');

                return $target;
            }
        };

        $upload = $service->processUpload(
            UploadedFile::fake()->create('staff-photo.heic', 20, 'image/heic'),
            'message-media',
        );

        $this->assertSame('image', $upload['type']);
        $this->assertSame('image/jpeg', $upload['mime_type']);
        $this->assertSame('staff-photo.jpg', $upload['filename']);
        $this->assertTrue($upload['is_converted_heic']);
        $this->assertStringEndsWith('.jpg', $upload['path']);
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
