<?php

namespace Tests\Feature\AI;

use App\Modules\AI\Models\AiKbDocument;
use App\Modules\AI\Models\AiKnowledgeBase;
use App\Modules\AI\Services\KnowledgeSourceExtractor;
use App\Modules\AI\Services\KnowledgeSourceUrlResolver;
use App\Modules\AI\Services\LiveProductCatalogService;
use App\Modules\AI\Services\LiveProductPageExtractor;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Response;
use Mockery;
use Tests\TestCase;

class LiveProductCatalogServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_refresh_atomically_stores_detected_products_and_offers(): void
    {
        config()->set('knowledge_base.live_product_facts_enabled', true);
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $kb = AiKnowledgeBase::create(['workspace_id' => $workspace->id, 'name' => 'Catalog', 'status' => 'active']);
        $document = AiKbDocument::create([
            'kb_id' => $kb->id, 'source_type' => 'url', 'source_ref' => 'https://shop.example/item',
            'canonical_url' => 'https://shop.example/item', 'status' => 'indexed', 'enabled' => true,
            'publication_status' => 'published',
        ]);
        $html = '<script type="application/ld+json">'.json_encode([
            '@type' => 'Product', 'name' => 'Travel Pack', 'sku' => 'PACK-1',
            'offers' => ['@type' => 'Offer', 'price' => '49.90', 'priceCurrency' => 'GBP', 'availability' => 'https://schema.org/InStock'],
        ]).'</script>';
        $extractor = Mockery::mock(KnowledgeSourceExtractor::class);
        $extractor->shouldReceive('fetchUrlSnapshot')->once()->andReturn([
            'canonical_url' => 'https://shop.example/item', 'text' => 'Travel Pack', 'html' => $html,
        ]);
        $resolver = Mockery::mock(KnowledgeSourceUrlResolver::class);
        $resolver->shouldReceive('fetch')->once()->andReturn([
            'response' => new Response(new PsrResponse(404)),
            'canonical_url' => 'https://shop.example/robots.txt',
        ]);

        $result = (new LiveProductCatalogService($extractor, $resolver, new LiveProductPageExtractor))->refreshDocument($document);

        $this->assertSame('verified', $result['outcome']);
        $this->assertDatabaseHas('ai_kb_products', [
            'workspace_id' => $workspace->id, 'kb_id' => $kb->id, 'name' => 'Travel Pack', 'sku' => 'PACK-1',
        ]);
        $this->assertDatabaseHas('ai_kb_product_offers', ['price' => 49.9, 'currency' => 'GBP', 'availability' => 'InStock']);
        $this->assertSame('ready', $document->fresh()->product_detection_status);
    }

    public function test_unsupported_page_removes_old_prices_instead_of_leaving_them_current(): void
    {
        config()->set('knowledge_base.live_product_facts_enabled', true);
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $kb = AiKnowledgeBase::create(['workspace_id' => $workspace->id, 'name' => 'Catalog', 'status' => 'active']);
        $document = AiKbDocument::create([
            'kb_id' => $kb->id, 'source_type' => 'url', 'source_ref' => 'https://shop.example/item',
            'canonical_url' => 'https://shop.example/item', 'status' => 'indexed', 'enabled' => true,
            'publication_status' => 'published',
        ]);
        $product = $document->products()->create([
            'workspace_id' => $workspace->id, 'kb_id' => $kb->id, 'source_key' => hash('sha256', 'old'),
            'canonical_url_hash' => hash('sha256', $document->canonical_url), 'canonical_url' => $document->canonical_url,
            'name' => 'Old product', 'extraction_method' => 'json_ld', 'parser_confidence' => 0.98,
            'status' => 'active', 'verified_at' => now(),
        ]);
        $product->offers()->create([
            'source_key' => hash('sha256', 'old-offer'), 'price' => 10, 'currency' => 'USD',
            'source_url' => $document->canonical_url, 'verified_at' => now(),
        ]);
        $extractor = Mockery::mock(KnowledgeSourceExtractor::class);
        $extractor->shouldReceive('fetchUrlSnapshot')->once()->andReturn([
            'canonical_url' => $document->canonical_url, 'text' => 'No public price', 'html' => '<html><body>Unavailable</body></html>',
        ]);
        $resolver = Mockery::mock(KnowledgeSourceUrlResolver::class);
        $resolver->shouldReceive('fetch')->once()->andReturn([
            'response' => new Response(new PsrResponse(404)), 'canonical_url' => 'https://shop.example/robots.txt',
        ]);

        (new LiveProductCatalogService($extractor, $resolver, new LiveProductPageExtractor))->refreshDocument($document);

        $this->assertDatabaseMissing('ai_kb_products', ['id' => $product->id]);
        $this->assertSame('unsupported', $document->fresh()->product_detection_status);
    }
}
