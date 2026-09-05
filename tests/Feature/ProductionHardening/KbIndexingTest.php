<?php

namespace Tests\Feature\ProductionHardening;

use App\Modules\AI\Jobs\IndexDocumentJob;
use App\Modules\AI\Models\AiKbDocument;
use App\Modules\AI\Models\AiKnowledgeBase;
use App\Modules\AI\Models\AiProviderConfig;
use App\Modules\AI\Services\KnowledgeUrlGuard;
use App\Services\StorageManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Indexing-pipeline regression tests for the knowledge base.
 *
 * These cover two production bugs:
 *  - uploaded files must be read back through the Storage disk (the stored
 *    source_ref is a disk-relative key, not an absolute local path); and
 *  - FAQ documents must be decoded from their JSON payload into clean Q&A text
 *    before they are chunked and embedded.
 */
class KbIndexingTest extends TestCase
{
    use RefreshDatabase;

    private function runIndexer(int $documentId): void
    {
        app()->call([new IndexDocumentJob($documentId), 'handle']);
    }

    public function test_root_url_discovers_sitemap_without_fetching_stalled_homepage(): void
    {
        Queue::fake();
        Http::fake(fn ($r) => match ($r->url()) {
            'https://example.com/robots.txt' => Http::response('Not found', 404),
            'https://example.com/sitemap.xml' => Http::response('<urlset><url><loc>https://example.com/help</loc></url></urlset>', 200),
            default => Http::failedConnection('cURL error 28: timed out'),
        });
        $kb = $this->seedKb();
        $doc = AiKbDocument::create(['kb_id' => $kb->id, 'title' => 'Website', 'source_type' => 'sitemap', 'source_ref' => 'https://example.com', 'status' => 'pending']);
        $this->runIndexer($doc->id);
        $this->assertSame('indexed', $doc->fresh()->status);
        Http::assertNotSent(fn ($r) => $r->url() === 'https://example.com');
        Queue::assertPushed(IndexDocumentJob::class, 1);
    }

    public function test_timeout_does_not_claim_a_certificate_problem(): void
    {
        Http::fake(['*' => Http::failedConnection('cURL error 28: timed out')]);
        $method = new \ReflectionMethod(IndexDocumentJob::class, 'fetchSiteResource');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('took too long');
        $method->invoke(new IndexDocumentJob(-1), 'https://example.com', app(KnowledgeUrlGuard::class));
    }

    public function test_blocked_crawler_response_keeps_its_actionable_error(): void
    {
        Http::fake(['*' => Http::response([], 403)]);
        $method = new \ReflectionMethod(IndexDocumentJob::class, 'fetchSiteResource');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('blocked automated access');
        $method->invoke(new IndexDocumentJob(-1), 'https://example.com', app(KnowledgeUrlGuard::class), 1, 2);
    }

    private function fakeEmbeddings(): void
    {
        Http::fake([
            'api.openai.com/v1/embeddings' => Http::response([
                'data' => [['embedding' => [0.1, 0.2, 0.3]]],
            ], 200),
        ]);
    }

    private function seedKb(): AiKnowledgeBase
    {
        $data = $this->createWorkspaceContext();
        $workspace = $data['workspace'];

        AiProviderConfig::create([
            'workspace_id' => $workspace->id,
            'provider' => 'openai',
            'credentials' => ['api_key' => 'sk-test'],
            'default_model_chat' => 'gpt-4o-mini',
            'default_model_embed' => 'text-embedding-3-small',
            'enabled' => true,
        ]);

        return AiKnowledgeBase::create([
            'workspace_id' => $workspace->id,
            'name' => 'Test KB',
            'embedding_model' => 'text-embedding-3-small',
            'dimensions' => 3,
            'status' => 'active',
        ]);
    }

    public function test_file_document_is_read_from_the_storage_disk(): void
    {
        Storage::fake('public');
        $this->fakeEmbeddings();
        $kb = $this->seedKb();

        $storage = app(StorageManager::class);
        $path = $storage->prefixedPath('kb-docs/warranty.txt');
        $storage->disk()->put($path, 'The warranty period is 12 months from purchase.');

        $doc = AiKbDocument::create([
            'kb_id' => $kb->id,
            'title' => 'warranty.txt',
            'source_type' => 'file',
            'source_ref' => $path,
            'status' => 'pending',
        ]);

        $this->runIndexer($doc->id);
        $doc->refresh();

        $this->assertSame('indexed', $doc->status);
        $this->assertTrue(
            $doc->chunks()->where('content', 'like', '%warranty period is 12 months%')->exists(),
            'The file content should have been read from the storage disk and chunked.'
        );
    }

    public function test_supported_video_links_inside_a_file_are_discovered_during_indexing(): void
    {
        Storage::fake('public');
        $this->fakeEmbeddings();
        $kb = $this->seedKb();

        $path = app(StorageManager::class)->prefixedPath('kb-docs/widget-guide.md');
        app(StorageManager::class)->disk()->put(
            $path,
            "# Configure the widget\nUse your workspace key, then watch https://youtu.be/dQw4w9WgXcQ for the complete setup.",
        );
        $doc = AiKbDocument::create([
            'kb_id' => $kb->id,
            'title' => 'Widget guide',
            'source_type' => 'file',
            'source_ref' => $path,
            'status' => 'pending',
        ]);

        $this->runIndexer($doc->id);
        $doc->refresh();

        $this->assertSame('video_collection', $doc->resource_json['kind']);
        $this->assertCount(1, $doc->resource_json['videos']);
        $this->assertSame('youtube', $doc->resource_json['videos'][0]['provider']);
        $this->assertSame('dQw4w9WgXcQ', $doc->resource_json['videos'][0]['video_id']);
    }

    public function test_embedded_video_url_is_preserved_when_a_website_iframe_is_stripped(): void
    {
        $this->fakeEmbeddings();
        Http::fake([
            'https://example.com/guide' => Http::response(
                '<html><body><main><h1>Install the widget</h1><p>Follow this walkthrough.</p><iframe src="https://www.youtube.com/embed/dQw4w9WgXcQ"></iframe></main></body></html>',
                200,
                ['Content-Type' => 'text/html'],
            ),
        ]);
        $kb = $this->seedKb();
        $doc = AiKbDocument::create([
            'kb_id' => $kb->id,
            'title' => 'Installation guide',
            'source_type' => 'url',
            'source_ref' => 'https://example.com/guide',
            'status' => 'pending',
        ]);

        $this->runIndexer($doc->id);
        $doc->refresh();

        $this->assertStringContainsString('Install the widget', $doc->extracted_content);
        $this->assertStringContainsString('https://www.youtube.com/embed/dQw4w9WgXcQ', $doc->extracted_content);
        $this->assertSame('youtube', $doc->resource_json['videos'][0]['provider']);
    }

    public function test_homepage_input_discovers_the_sitemap_declared_in_robots_txt(): void
    {
        Queue::fake();
        Http::fake([
            'https://example.com' => Http::response('', 308, ['Location' => 'https://www.example.com/']),
            'https://www.example.com/' => Http::response('<html><body><h1>Example</h1></body></html>', 200, ['Content-Type' => 'text/html']),
            'https://www.example.com/robots.txt' => Http::response("User-agent: *\nSitemap: https://www.example.com/site-map.xml", 200),
            'https://www.example.com/site-map.xml' => Http::response(<<<'XML'
            <?xml version="1.0"?>
            <urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
              <url><loc>https://www.example.com/</loc></url>
              <url><loc>https://www.example.com/help</loc></url>
            </urlset>
            XML, 200, ['Content-Type' => 'application/xml']),
            '*' => Http::response('Not found', 404),
        ]);
        $kb = $this->seedKb();
        $doc = AiKbDocument::create([
            'kb_id' => $kb->id,
            'title' => 'Example website',
            'source_type' => 'sitemap',
            'source_ref' => 'https://example.com',
            'status' => 'pending',
        ]);

        $this->runIndexer($doc->id);

        $this->assertSame('https://www.example.com/site-map.xml', $doc->fresh()->source_ref);
        $this->assertDatabaseHas('ai_kb_documents', ['kb_id' => $kb->id, 'source_type' => 'url', 'source_ref' => 'https://www.example.com/help']);
        Queue::assertPushed(IndexDocumentJob::class, 2);
    }

    public function test_site_without_a_sitemap_falls_back_to_safe_same_host_page_links(): void
    {
        Queue::fake();
        Http::fake([
            'https://example.com' => Http::response(<<<'HTML'
            <html><body>
              <a href="/about">About</a>
              <a href="https://outside.example.org/tracker">Outside</a>
              <a href="/brochure.pdf">Brochure</a>
            </body></html>
            HTML, 200, ['Content-Type' => 'text/html']),
            '*' => Http::response('Not found', 404),
        ]);
        $kb = $this->seedKb();
        $doc = AiKbDocument::create([
            'kb_id' => $kb->id,
            'title' => 'Example website',
            'source_type' => 'sitemap',
            'source_ref' => 'https://example.com',
            'status' => 'pending',
        ]);

        $this->runIndexer($doc->id);

        $this->assertDatabaseHas('ai_kb_documents', ['kb_id' => $kb->id, 'source_type' => 'url', 'source_ref' => 'https://example.com/about']);
        $this->assertDatabaseMissing('ai_kb_documents', ['kb_id' => $kb->id, 'source_ref' => 'https://outside.example.org/tracker']);
        $this->assertDatabaseMissing('ai_kb_documents', ['kb_id' => $kb->id, 'source_ref' => 'https://example.com/brochure.pdf']);
        Queue::assertPushed(IndexDocumentJob::class, 2);
    }

    public function test_gzipped_sitemap_is_parsed_within_the_configured_size_limit(): void
    {
        Queue::fake();
        $xml = '<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>https://example.com/help</loc></url></urlset>';
        Http::fake([
            'https://example.com/sitemap.xml.gz' => Http::response(gzencode($xml), 200, ['Content-Type' => 'application/gzip']),
        ]);
        $kb = $this->seedKb();
        $doc = AiKbDocument::create([
            'kb_id' => $kb->id,
            'title' => 'Compressed sitemap',
            'source_type' => 'sitemap',
            'source_ref' => 'https://example.com/sitemap.xml.gz',
            'status' => 'pending',
        ]);

        $this->runIndexer($doc->id);

        $this->assertDatabaseHas('ai_kb_documents', ['kb_id' => $kb->id, 'source_type' => 'url', 'source_ref' => 'https://example.com/help']);
        Queue::assertPushed(IndexDocumentJob::class);
    }

    public function test_faq_document_is_decoded_into_clean_qa_text(): void
    {
        $this->fakeEmbeddings();
        $kb = $this->seedKb();

        $doc = AiKbDocument::create([
            'kb_id' => $kb->id,
            'title' => 'FAQ',
            'source_type' => 'faq',
            'source_ref' => json_encode([
                ['question' => 'What is your refund policy?', 'answer' => '30 days money back.'],
                ['question' => 'Do you ship internationally?', 'answer' => 'Yes, worldwide.'],
            ]),
            'status' => 'pending',
        ]);

        $this->runIndexer($doc->id);

        $content = $doc->chunks()->orderBy('ord')->first()?->content ?? '';

        $this->assertStringContainsString('Q: What is your refund policy?', $content);
        $this->assertStringContainsString('A: 30 days money back.', $content);
        $this->assertStringContainsString('Q: Do you ship internationally?', $content);
        // The raw JSON structure must not leak into the embedded text.
        $this->assertStringNotContainsString('"question"', $content);
        $this->assertStringNotContainsString('"answer"', $content);
    }
}
