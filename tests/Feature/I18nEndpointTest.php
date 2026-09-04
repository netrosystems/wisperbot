<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class I18nEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_current_social_copy_is_served_without_browser_caching(): void
    {
        Cache::flush();

        $response = $this->get(route('i18n.show', ['locale' => 'en']));

        $response->assertOk();

        $translations = $response->json('translation');
        $this->assertSame('Social Media Automation', $translations['social.automation_title'] ?? null);
        $this->assertSame('No posts found', $translations['social.no_posts_for_view'] ?? null);
        $this->assertSame('No posts scheduled yet', $translations['social.no_posts_yet_compact'] ?? null);

        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }
}
