<?php

namespace App\Modules\AI\Services;

use App\Modules\AI\Models\AiKbDocument;
use App\Modules\AI\Models\AiKbProduct;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

class LiveProductCatalogService
{
    public function __construct(
        private readonly KnowledgeSourceExtractor $extractor,
        private readonly KnowledgeSourceUrlResolver $resolver,
        private readonly LiveProductPageExtractor $products,
    ) {}

    /** @return array{outcome:string,count:int,verified_at:?string} */
    public function refreshDocument(AiKbDocument $document): array
    {
        if (! config('knowledge_base.live_product_facts_enabled') || $document->source_type !== 'url' || ! $document->enabled) {
            return ['outcome' => 'disabled', 'count' => 0, 'verified_at' => null];
        }
        $document->loadMissing('knowledgeBase');
        $url = (string) ($document->canonical_url ?: $document->source_ref);
        if ($url === '' || ! $this->robotsAllowed($url)) {
            $document->update([
                'product_detection_status' => 'blocked',
                'product_detection_message' => 'Product detection is blocked by the source website robots policy.',
            ]);

            return ['outcome' => 'blocked', 'count' => 0, 'verified_at' => null];
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $rateKey = 'kb-live-products-rate:'.hash('sha256', $host ?: $url);
        if (! RateLimiter::attempt(
            $rateKey,
            max(1, min(120, (int) config('knowledge_base.live_product_requests_per_minute', 20))),
            fn () => true,
            60,
        )) {
            throw new \RuntimeException('This website is being refreshed too quickly. The product verifier will retry later.');
        }
        $lock = Cache::lock('kb-live-products:'.hash('sha256', $host ?: $url), 20);
        if (! $lock->get()) {
            throw new \RuntimeException('A product refresh for this website is already running.');
        }

        try {
            $snapshot = $this->extractor->fetchUrlSnapshot($url, [
                'connect_timeout' => 3,
                'timeout' => 8,
                'attempts' => 1,
                'max_redirects' => 4,
                'user_agent' => 'WisperBotProductVerifier/1.0 (+https://wisperbot.com)',
            ]);
            $parsed = $this->products->extract($snapshot['html'], $snapshot['canonical_url']);
            $verifiedAt = now();
            DB::transaction(function () use ($document, $parsed, $snapshot, $verifiedAt): void {
                $kept = [];
                foreach ($parsed as $item) {
                    $product = AiKbProduct::updateOrCreate(
                        ['document_id' => $document->id, 'source_key' => $item['source_key']],
                        [
                            'workspace_id' => $document->knowledgeBase->workspace_id,
                            'kb_id' => $document->kb_id,
                            'canonical_url_hash' => hash('sha256', $snapshot['canonical_url']),
                            'canonical_url' => $snapshot['canonical_url'],
                            'name' => $item['name'],
                            'sku' => $item['sku'],
                            'image_url' => $item['image_url'],
                            'description' => $item['description'],
                            'extraction_method' => $item['extraction_method'],
                            'parser_confidence' => $item['parser_confidence'],
                            'status' => 'active',
                            'verified_at' => $verifiedAt,
                            'last_error' => null,
                        ],
                    );
                    $kept[] = $product->id;
                    $offerKeys = [];
                    foreach ($item['offers'] as $offer) {
                        $offerKeys[] = $offer['source_key'];
                        $product->offers()->updateOrCreate(
                            ['source_key' => $offer['source_key']],
                            array_merge($offer, ['verified_at' => $verifiedAt]),
                        );
                    }
                    $product->offers()->whereNotIn('source_key', $offerKeys)->delete();
                }
                $stale = AiKbProduct::where('document_id', $document->id);
                if ($kept !== []) {
                    $stale->whereNotIn('id', $kept);
                }
                $stale->delete();
                $document->update([
                    'canonical_url' => $snapshot['canonical_url'],
                    'product_detection_status' => $parsed === [] ? 'unsupported' : 'ready',
                    'product_detection_message' => $parsed === []
                        ? 'No supported public Product/Offer structured data was found on this page.'
                        : null,
                    'products_verified_at' => $verifiedAt,
                ]);
            });

            return [
                'outcome' => $parsed === [] ? 'unsupported' : 'verified',
                'count' => count($parsed),
                'verified_at' => $verifiedAt->toIso8601String(),
            ];
        } finally {
            $lock->release();
        }
    }

    private function robotsAllowed(string $url): bool
    {
        $parts = parse_url($url);
        $origin = 'https://'.($parts['host'] ?? '').(isset($parts['port']) ? ':'.$parts['port'] : '');
        $path = '/'.ltrim((string) ($parts['path'] ?? '/'), '/');
        try {
            $result = $this->resolver->fetch($origin.'/robots.txt', [
                'connect_timeout' => 2, 'timeout' => 4, 'attempts' => 1, 'max_redirects' => 2,
                'user_agent' => 'WisperBotProductVerifier/1.0 (+https://wisperbot.com)',
            ]);
            if ($result['response']->status() === 404) {
                return true;
            }
            if (! $result['response']->successful()) {
                return false;
            }
            $active = false;
            foreach (preg_split('/\R/u', $result['response']->body()) ?: [] as $line) {
                $line = trim(preg_replace('/#.*/', '', $line) ?? '');
                if (preg_match('/^User-agent:\s*(.+)$/i', $line, $match)) {
                    $agent = strtolower(trim($match[1]));
                    $active = in_array($agent, ['*', 'wisperbotproductverifier'], true);
                } elseif ($active && preg_match('/^Disallow:\s*(.*)$/i', $line, $match)) {
                    $blocked = trim($match[1]);
                    if ($blocked !== '' && str_starts_with($path, $blocked)) {
                        return false;
                    }
                }
            }

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
