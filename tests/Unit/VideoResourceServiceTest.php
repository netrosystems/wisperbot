<?php

namespace Tests\Unit;

use App\Modules\AI\Services\VideoResourceService;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class VideoResourceServiceTest extends TestCase
{
    public function test_it_normalises_supported_video_urls(): void
    {
        $service = app(VideoResourceService::class);

        $youtube = $service->normalise('https://youtu.be/dQw4w9WgXcQ', 'YouTube');
        $this->assertSame('youtube', $youtube['provider']);
        $this->assertSame('https://www.youtube.com/embed/dQw4w9WgXcQ', $youtube['playback_url']);

        $vimeo = $service->normalise('https://vimeo.com/123456789?h=abc123', 'Vimeo');
        $this->assertSame('https://player.vimeo.com/video/123456789?h=abc123', $vimeo['playback_url']);

        $direct = $service->normalise('https://example.com/help/setup.mp4?version=2', 'MP4');
        $this->assertSame('direct', $direct['provider']);
    }

    public function test_it_discovers_and_deduplicates_supported_links_in_extracted_content(): void
    {
        $resources = app(VideoResourceService::class)->discover(<<<'TEXT'
        ## Widget setup
        Follow the steps, then watch [Configure the widget](https://youtu.be/dQw4w9WgXcQ).
        The same guide is also linked at https://www.youtube.com/watch?v=dQw4w9WgXcQ.
        Ignore https://example.com/article and http://example.com/unsafe.mp4.
        TEXT, 'Help video');

        $this->assertCount(1, $resources);
        $this->assertSame('Configure the widget', $resources[0]['title']);
        $this->assertSame('youtube', $resources[0]['provider']);
        $this->assertStringContainsString('Widget setup', $resources[0]['match_text']);
    }

    public function test_untitled_discovered_video_uses_the_provider_title(): void
    {
        Http::fake(['https://www.youtube.com/oembed*' => Http::response(['title' => 'How to activate your eSIM'], 200)]);
        $service = app(VideoResourceService::class);

        $resources = $service->withProviderTitles($service->discover('Video tutorial: https://youtu.be/dQw4w9WgXcQ'));

        $this->assertSame('How to activate your eSIM', $resources[0]['title']);
    }

    public function test_provider_title_failure_leaves_the_video_untitled(): void
    {
        Http::fake(['https://www.youtube.com/oembed*' => Http::response('Not found', 404)]);
        $service = app(VideoResourceService::class);

        $resources = $service->withProviderTitles($service->discover('Video tutorial: https://youtu.be/dQw4w9WgXcQ'));

        $this->assertSame('', $resources[0]['title']);
        $this->assertArrayNotHasKey('title', $service->publicSnapshot($resources[0], 0.9));
    }

    public function test_it_reads_legacy_and_discovered_video_metadata(): void
    {
        $service = app(VideoResourceService::class);
        $video = $service->normalise('https://vimeo.com/123456789', 'Guide');

        $this->assertCount(1, $service->fromStoredMetadata($video));
        $this->assertCount(1, $service->fromStoredMetadata([
            'version' => 1,
            'kind' => 'video_collection',
            'videos' => [$video],
        ]));
        $this->assertSame([], $service->fromStoredMetadata(['kind' => 'unknown']));
    }

    #[DataProvider('unsafeUrls')]
    public function test_it_rejects_unsafe_or_unsupported_urls(string $url): void
    {
        $this->expectException(ValidationException::class);
        app(VideoResourceService::class)->normalise($url, 'Unsafe');
    }

    public static function unsafeUrls(): array
    {
        return [
            'http' => ['http://example.com/video.mp4'],
            'local' => ['https://127.0.0.1/video.mp4'],
            'credentials' => ['https://user:pass@example.com/video.mp4'],
            'unsupported' => ['https://example.com/video.webm'],
            'html' => ['<iframe src="https://youtube.com/embed/dQw4w9WgXcQ"></iframe>'],
        ];
    }
}
