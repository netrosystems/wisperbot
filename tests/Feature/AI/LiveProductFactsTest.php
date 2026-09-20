<?php

namespace Tests\Feature\AI;

use App\Modules\AI\Models\AiChatbot;
use App\Modules\AI\Models\AiKbDocument;
use App\Modules\AI\Models\AiKbProduct;
use App\Modules\AI\Models\AiKbProductOffer;
use App\Modules\AI\Models\AiKnowledgeBase;
use App\Modules\AI\Services\ChatbotRunner;
use App\Modules\AI\Services\LiveProductCatalogService;
use App\Modules\Ecommerce\Models\EcommerceProduct;
use App\Modules\Ecommerce\Models\EcommerceStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LiveProductFactsTest extends TestCase
{
    use RefreshDatabase;

    public function test_confirmed_product_price_is_deterministic_and_zero_credit(): void
    {
        config()->set('knowledge_base.live_product_facts_enabled', true);
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $kb = AiKnowledgeBase::create(['workspace_id' => $workspace->id, 'name' => 'Store', 'status' => 'active']);
        $document = AiKbDocument::create([
            'kb_id' => $kb->id,
            'source_type' => 'url',
            'source_ref' => 'https://shop.example/products/trail-shoe',
            'canonical_url' => 'https://shop.example/products/trail-shoe',
            'title' => 'Trail Shoe',
            'status' => 'indexed',
            'enabled' => true,
            'publication_status' => 'published',
        ]);
        $product = AiKbProduct::create([
            'workspace_id' => $workspace->id,
            'kb_id' => $kb->id,
            'document_id' => $document->id,
            'source_key' => hash('sha256', 'trail'),
            'canonical_url_hash' => hash('sha256', $document->canonical_url),
            'canonical_url' => $document->canonical_url,
            'name' => 'Trail Shoe',
            'sku' => 'TS-42',
            'extraction_method' => 'json_ld',
            'parser_confidence' => 0.98,
            'status' => 'active',
            'verified_at' => now(),
        ]);
        AiKbProductOffer::create([
            'product_id' => $product->id,
            'source_key' => hash('sha256', 'blue-42'),
            'name' => 'Blue / 42',
            'sku' => 'TS-42-B',
            'price' => 79.95,
            'currency' => 'USD',
            'availability' => 'InStock',
            'source_url' => $document->canonical_url,
            'verified_at' => now(),
        ]);
        $bot = AiChatbot::create([
            'workspace_id' => $workspace->id,
            'name' => 'Sales',
            'ai_kb_id' => $kb->id,
            'live_product_facts_enabled' => true,
        ]);

        $result = app(ChatbotRunner::class)->runForApi($bot, 'How much is the Trail Shoe blue size 42?', $workspace->id);

        $this->assertSame('live_product', $result['answer_origin']);
        $this->assertSame('answer', $result['response_mode']);
        $this->assertSame(0, $result['tokens_used']);
        $this->assertStringContainsString('USD 79.95', $result['reply']);
        $this->assertSame('Trail Shoe', $result['product_facts'][0]['product']);
        $this->assertSame($document->canonical_url, $result['citations'][0]['url']);
    }

    public function test_ambiguous_product_question_returns_compatible_quick_replies(): void
    {
        config()->set('knowledge_base.live_product_facts_enabled', true);
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $kb = AiKnowledgeBase::create(['workspace_id' => $workspace->id, 'name' => 'Store', 'status' => 'active']);
        foreach (['Trail Shoe', 'City Shoe'] as $index => $name) {
            $document = AiKbDocument::create([
                'kb_id' => $kb->id, 'source_type' => 'url',
                'source_ref' => 'https://shop.example/products/'.$index,
                'canonical_url' => 'https://shop.example/products/'.$index,
                'status' => 'indexed', 'enabled' => true, 'publication_status' => 'published',
            ]);
            $product = AiKbProduct::create([
                'workspace_id' => $workspace->id, 'kb_id' => $kb->id, 'document_id' => $document->id,
                'source_key' => hash('sha256', $name), 'canonical_url_hash' => hash('sha256', $document->canonical_url),
                'canonical_url' => $document->canonical_url, 'name' => $name, 'extraction_method' => 'json_ld',
                'parser_confidence' => 0.98, 'status' => 'active', 'verified_at' => now(),
            ]);
            AiKbProductOffer::create([
                'product_id' => $product->id, 'source_key' => hash('sha256', $name.'offer'),
                'price' => 20 + $index, 'currency' => 'USD', 'source_url' => $document->canonical_url,
                'verified_at' => now(),
            ]);
        }
        $bot = AiChatbot::create([
            'workspace_id' => $workspace->id, 'name' => 'Sales', 'ai_kb_id' => $kb->id,
            'live_product_facts_enabled' => true,
        ]);

        $result = app(ChatbotRunner::class)->runForApi($bot, 'What are your prices?', $workspace->id);

        $this->assertSame('clarification', $result['response_mode']);
        $this->assertCount(2, $result['quick_replies']);
        $this->assertStringContainsString('1. ', $result['reply']);
        $this->assertSame(0, $result['tokens_used']);
    }

    public function test_other_workspace_product_is_never_used(): void
    {
        config()->set('knowledge_base.live_product_facts_enabled', true);
        ['workspace' => $first] = $this->createWorkspaceContext();
        ['workspace' => $second] = $this->createWorkspaceContext();
        $kb = AiKnowledgeBase::create(['workspace_id' => $first->id, 'name' => 'First', 'status' => 'active']);
        $bot = AiChatbot::create([
            'workspace_id' => $first->id, 'name' => 'Sales', 'ai_kb_id' => $kb->id,
            'live_product_facts_enabled' => true, 'fallback_reply' => 'No verified answer.',
        ]);
        $otherKb = AiKnowledgeBase::create(['workspace_id' => $second->id, 'name' => 'Other', 'status' => 'active']);
        $document = AiKbDocument::create([
            'kb_id' => $otherKb->id, 'source_type' => 'url', 'source_ref' => 'https://other.example/secret',
            'canonical_url' => 'https://other.example/secret', 'status' => 'indexed', 'enabled' => true,
            'publication_status' => 'published',
        ]);
        $product = AiKbProduct::create([
            'workspace_id' => $second->id, 'kb_id' => $otherKb->id, 'document_id' => $document->id,
            'source_key' => hash('sha256', 'secret'), 'canonical_url_hash' => hash('sha256', $document->canonical_url),
            'canonical_url' => $document->canonical_url, 'name' => 'Secret Product', 'extraction_method' => 'json_ld',
            'parser_confidence' => 0.98, 'status' => 'active', 'verified_at' => now(),
        ]);
        AiKbProductOffer::create([
            'product_id' => $product->id, 'source_key' => hash('sha256', 'secret-offer'),
            'price' => 999, 'currency' => 'USD', 'source_url' => $document->canonical_url, 'verified_at' => now(),
        ]);

        $result = app(ChatbotRunner::class)->runForApi($bot, 'What is the Secret Product price?', $first->id);

        $this->assertNotSame('live_product', $result['answer_origin']);
        $this->assertStringNotContainsString('999', (string) $result['reply']);
    }

    public function test_fresh_connected_store_price_is_preferred_over_stale_website_price(): void
    {
        config()->set('knowledge_base.live_product_facts_enabled', true);
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $kb = AiKnowledgeBase::create(['workspace_id' => $workspace->id, 'name' => 'Store', 'status' => 'active']);
        $document = AiKbDocument::create([
            'kb_id' => $kb->id, 'source_type' => 'url',
            'source_ref' => 'https://shop.example/products/trail-shoe',
            'canonical_url' => 'https://shop.example/products/trail-shoe',
            'status' => 'indexed', 'enabled' => true, 'publication_status' => 'published',
        ]);
        $product = AiKbProduct::create([
            'workspace_id' => $workspace->id, 'kb_id' => $kb->id, 'document_id' => $document->id,
            'source_key' => hash('sha256', 'trail-store'), 'canonical_url_hash' => hash('sha256', $document->canonical_url),
            'canonical_url' => $document->canonical_url, 'name' => 'Trail Shoe', 'sku' => 'TS-42',
            'extraction_method' => 'json_ld', 'parser_confidence' => 0.98, 'status' => 'active',
            'verified_at' => now()->subHour(),
        ]);
        AiKbProductOffer::create([
            'product_id' => $product->id, 'source_key' => hash('sha256', 'trail-store-offer'),
            'sku' => 'TS-42', 'price' => 79.95, 'currency' => 'USD',
            'source_url' => $document->canonical_url, 'verified_at' => now()->subHour(),
        ]);
        $store = EcommerceStore::create([
            'workspace_id' => $workspace->id, 'platform' => 'shopify', 'name' => 'Shop',
            'domain' => 'shop.example', 'status' => 'connected', 'external_meta' => ['currency' => 'USD'],
        ]);
        EcommerceProduct::create([
            'workspace_id' => $workspace->id, 'store_id' => $store->id, 'external_id' => 'shopify-42',
            'platform' => 'shopify', 'name' => 'Trail Shoe', 'sku' => 'TS-42',
            'price' => 69.99, 'inventory_quantity' => 4,
        ]);
        $bot = AiChatbot::create([
            'workspace_id' => $workspace->id, 'name' => 'Sales', 'ai_kb_id' => $kb->id,
            'live_product_facts_enabled' => true,
        ]);

        $result = app(ChatbotRunner::class)->runForApi($bot, 'What is the price of Trail Shoe TS-42?', $workspace->id);

        $this->assertSame('live_product', $result['answer_origin']);
        $this->assertStringContainsString('USD 69.99', $result['reply']);
        $this->assertStringNotContainsString('79.95', $result['reply']);
        $this->assertSame('69.99', $result['product_facts'][0]['price']);
    }

    public function test_failed_stale_verification_never_presents_old_price_as_current(): void
    {
        config()->set('knowledge_base.live_product_facts_enabled', true);
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $kb = AiKnowledgeBase::create(['workspace_id' => $workspace->id, 'name' => 'Store', 'status' => 'active']);
        $document = AiKbDocument::create([
            'kb_id' => $kb->id, 'source_type' => 'url',
            'source_ref' => 'https://shop.example/products/trail-shoe',
            'canonical_url' => 'https://shop.example/products/trail-shoe',
            'status' => 'indexed', 'enabled' => true, 'publication_status' => 'published',
        ]);
        $product = AiKbProduct::create([
            'workspace_id' => $workspace->id, 'kb_id' => $kb->id, 'document_id' => $document->id,
            'source_key' => hash('sha256', 'stale-trail'), 'canonical_url_hash' => hash('sha256', $document->canonical_url),
            'canonical_url' => $document->canonical_url, 'name' => 'Trail Shoe', 'sku' => 'TS-42',
            'extraction_method' => 'json_ld', 'parser_confidence' => 0.98, 'status' => 'active',
            'verified_at' => now()->subMinutes(16),
        ]);
        AiKbProductOffer::create([
            'product_id' => $product->id, 'source_key' => hash('sha256', 'stale-offer'),
            'price' => 79.95, 'currency' => 'USD', 'source_url' => $document->canonical_url,
            'verified_at' => now()->subMinutes(16),
        ]);
        $bot = AiChatbot::create([
            'workspace_id' => $workspace->id, 'name' => 'Sales', 'ai_kb_id' => $kb->id,
            'live_product_facts_enabled' => true,
        ]);
        $this->mock(LiveProductCatalogService::class)
            ->shouldReceive('refreshDocument')
            ->once()
            ->andThrow(new \RuntimeException('Verification unavailable'));

        $result = app(ChatbotRunner::class)->runForApi($bot, 'How much is the Trail Shoe?', $workspace->id);

        $this->assertSame('live_product', $result['answer_origin']);
        $this->assertSame('fallback', $result['response_mode']);
        $this->assertSame(0, $result['tokens_used']);
        $this->assertStringContainsString('could not verify', $result['reply']);
        $this->assertStringNotContainsString('79.95', $result['reply']);
        $this->assertSame([], $result['product_facts']);
    }
}
