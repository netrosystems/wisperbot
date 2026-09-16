<?php

namespace Tests\Unit;

use App\Services\Media\AttachmentService;
use App\Services\StorageManager;
use PHPUnit\Framework\TestCase;

class AttachmentServiceTest extends TestCase
{
    public function test_mobile_audio_extensions_are_inferred_as_audio_when_mime_is_generic_or_odd(): void
    {
        $service = new AttachmentService($this->createStub(StorageManager::class));

        $this->assertSame('audio', $service->inferMessageType('application/octet-stream', 'm4a'));
        $this->assertSame('audio', $service->inferMessageType('video/mp4', 'm4a'));
        $this->assertSame('audio', $service->inferMessageType('application/octet-stream', 'weba'));
        $this->assertSame('audio', $service->inferMessageType('application/octet-stream', 'opus'));
    }
}
