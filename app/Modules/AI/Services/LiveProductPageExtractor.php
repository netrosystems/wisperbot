<?php

namespace App\Modules\AI\Services;

use DOMDocument;
use DOMElement;
use DOMXPath;

class LiveProductPageExtractor
{
    /**
     * @return array<int,array<string,mixed>>
     */
    public function extract(string $html, string $canonicalUrl): array
    {
        $products = [];
        foreach ($this->jsonLdObjects($html) as $object) {
            if ($this->hasType($object, 'Product')) {
                $product = $this->normaliseProduct($object, $canonicalUrl, 'json_ld', 0.98);
                if ($product !== null) {
                    $products[$product['source_key']] = $product;
                }
            }
        }

        if ($products === []) {
            $fallback = $this->microdataProduct($html, $canonicalUrl);
            if ($fallback !== null) {
                $products[$fallback['source_key']] = $fallback;
            }
        }

        return array_values($products);
    }

    /** @return array<int,array<string,mixed>> */
    private function jsonLdObjects(string $html): array
    {
        $document = $this->document($html);
        if (! $document) {
            return [];
        }
        $xpath = new DOMXPath($document);
        $objects = [];
        foreach ($xpath->query('//script[contains(translate(@type,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz"),"ld+json")]') ?: [] as $node) {
            $decoded = json_decode(trim($node->textContent), true);
            if (is_array($decoded)) {
                $this->flattenJsonLd($decoded, $objects);
            }
        }

        return $objects;
    }

    /**
     * @param  array<mixed>  $value
     * @param  array<int,array<string,mixed>>  $objects
     *
     * @param-out array<int,array<string,mixed>> $objects
     */
    private function flattenJsonLd(array $value, array &$objects): void
    {
        if (array_is_list($value)) {
            foreach ($value as $item) {
                if (is_array($item)) {
                    $this->flattenJsonLd($item, $objects);
                }
            }

            return;
        }
        if (isset($value['@graph']) && is_array($value['@graph'])) {
            $this->flattenJsonLd($value['@graph'], $objects);
        }
        if (isset($value['@type'])) {
            $object = [];
            foreach ($value as $key => $item) {
                if (is_string($key)) {
                    $object[$key] = $item;
                }
            }
            $objects[] = $object;
        }
    }

    /** @param array<string,mixed> $object */
    private function hasType(array $object, string $type): bool
    {
        $types = is_array($object['@type'] ?? null) ? $object['@type'] : [$object['@type'] ?? null];

        return collect($types)->contains(fn ($candidate) => is_string($candidate) && strcasecmp($candidate, $type) === 0);
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>|null
     */
    private function normaliseProduct(array $data, string $canonicalUrl, string $method, float $confidence): ?array
    {
        $name = $this->clean($data['name'] ?? null, 512);
        $sku = $this->clean($data['sku'] ?? $data['mpn'] ?? $data['productID'] ?? null, 191);
        $offers = $this->normaliseOffers($data['offers'] ?? null, $canonicalUrl, $name, $sku);
        if ($name === null || $offers === []) {
            return null;
        }
        $image = $data['image'] ?? null;
        if (is_array($image)) {
            $image = is_string($image['url'] ?? null) ? $image['url'] : ($image[0] ?? null);
        }

        return [
            'source_key' => hash('sha256', mb_strtolower($canonicalUrl.'|'.($sku ?: $name))),
            'canonical_url' => $canonicalUrl,
            'name' => $name,
            'sku' => $sku,
            'image_url' => $this->httpsUrl($image),
            'description' => $this->clean($data['description'] ?? null, 4000),
            'extraction_method' => $method,
            'parser_confidence' => $confidence,
            'offers' => $offers,
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private function normaliseOffers(mixed $raw, string $canonicalUrl, ?string $productName, ?string $productSku): array
    {
        if (! is_array($raw)) {
            return [];
        }
        $items = array_is_list($raw) ? $raw : [$raw];
        $offers = [];
        foreach ($items as $index => $offer) {
            if (! is_array($offer)) {
                continue;
            }
            if ($this->hasType($offer, 'AggregateOffer')) {
                $price = null;
                $min = $this->price($offer['lowPrice'] ?? null);
                $max = $this->price($offer['highPrice'] ?? null);
            } else {
                $price = $this->price($offer['price'] ?? null) ?? $this->priceFromSpecification($offer['priceSpecification'] ?? null);
                $min = null;
                $max = null;
            }
            if ($price === null && $min === null && $max === null) {
                continue;
            }
            $name = $this->clean($offer['name'] ?? $offer['itemOffered']['name'] ?? null, 512);
            $sku = $this->clean($offer['sku'] ?? $offer['itemOffered']['sku'] ?? $productSku, 191);
            $currency = strtoupper((string) ($offer['priceCurrency'] ?? $this->currencyFromSpecification($offer['priceSpecification'] ?? null) ?? ''));
            $currency = preg_match('/^[A-Z]{3}$/', $currency) ? $currency : null;
            $availability = $this->clean($offer['availability'] ?? null, 80);
            if ($availability !== null && str_contains($availability, '/')) {
                $availability = basename($availability);
            }
            $keyMaterial = implode('|', [$sku, $name, $price, $min, $max, $currency, $index]);
            [$regularPrice, $salePrice] = $this->specifiedPrices($offer['priceSpecification'] ?? null, $price);
            $offers[] = [
                'source_key' => hash('sha256', mb_strtolower($keyMaterial)),
                'name' => $name,
                'sku' => $sku,
                'price' => $price,
                'regular_price' => $regularPrice,
                'sale_price' => $salePrice,
                'min_price' => $min,
                'max_price' => $max,
                'currency' => $currency,
                'availability' => $availability,
                'attributes' => array_filter(['product' => $productName]),
                'source_url' => $canonicalUrl,
            ];
        }

        return $offers;
    }

    /** @return array{0:?string,1:?string} */
    private function specifiedPrices(mixed $raw, ?string $current): array
    {
        if (! is_array($raw)) {
            return [null, null];
        }
        $specifications = array_is_list($raw) ? $raw : [$raw];
        $regular = null;
        $sale = null;
        foreach ($specifications as $specification) {
            if (! is_array($specification)) {
                continue;
            }
            $price = $this->price($specification['price'] ?? null);
            $type = mb_strtolower((string) ($specification['priceType'] ?? $specification['name'] ?? ''));
            if ($price !== null && preg_match('/(?:list|regular|was)/u', $type)) {
                $regular = $price;
            } elseif ($price !== null && preg_match('/(?:sale|discount|current)/u', $type)) {
                $sale = $price;
            }
        }
        if ($regular !== null && $sale === null && $current !== null && (float) $current < (float) $regular) {
            $sale = $current;
        }

        return [$regular, $sale];
    }

    private function priceFromSpecification(mixed $raw): ?string
    {
        if (! is_array($raw)) {
            return null;
        }
        $specifications = array_is_list($raw) ? $raw : [$raw];
        foreach ($specifications as $specification) {
            if (is_array($specification)) {
                $price = $this->price($specification['price'] ?? null);
                if ($price !== null && preg_match('/(?:sale|current)/iu', (string) ($specification['priceType'] ?? $specification['name'] ?? ''))) {
                    return $price;
                }
            }
        }
        foreach ($specifications as $specification) {
            if (is_array($specification) && ($price = $this->price($specification['price'] ?? null)) !== null) {
                return $price;
            }
        }

        return null;
    }

    private function currencyFromSpecification(mixed $raw): ?string
    {
        if (! is_array($raw)) {
            return null;
        }
        foreach (array_is_list($raw) ? $raw : [$raw] as $specification) {
            if (is_array($specification) && is_string($specification['priceCurrency'] ?? null)) {
                return $specification['priceCurrency'];
            }
        }

        return null;
    }

    /** @return array<string,mixed>|null */
    private function microdataProduct(string $html, string $canonicalUrl): ?array
    {
        $document = $this->document($html);
        if (! $document) {
            return null;
        }
        $xpath = new DOMXPath($document);
        $name = $this->meta($xpath, ['og:title', 'product:name']) ?: $this->itemValue($xpath, 'name');
        if (! $name) {
            $title = $xpath->query('//title')->item(0);
            $name = $title ? trim((string) $title->textContent) : '';
        }
        $price = $this->price($this->meta($xpath, ['product:price:amount', 'og:price:amount']) ?: $this->itemValue($xpath, 'price'));
        $currency = strtoupper((string) ($this->meta($xpath, ['product:price:currency', 'og:price:currency']) ?: $this->itemValue($xpath, 'priceCurrency')));
        if ($name === '' || $price === null || ! preg_match('/^[A-Z]{3}$/', $currency)) {
            return null;
        }
        $sku = $this->itemValue($xpath, 'sku') ?: null;
        $availability = $this->meta($xpath, ['product:availability']) ?: $this->itemValue($xpath, 'availability');

        return [
            'source_key' => hash('sha256', mb_strtolower($canonicalUrl.'|'.($sku ?: $name))),
            'canonical_url' => $canonicalUrl,
            'name' => mb_substr(strip_tags($name), 0, 512),
            'sku' => $sku ? mb_substr(strip_tags($sku), 0, 191) : null,
            'image_url' => $this->httpsUrl($this->meta($xpath, ['og:image', 'product:image'])),
            'description' => $this->clean($this->meta($xpath, ['og:description', 'description']), 4000),
            'extraction_method' => 'microdata',
            'parser_confidence' => 0.78,
            'offers' => [[
                'source_key' => hash('sha256', mb_strtolower(($sku ?: $name).'|'.$price.'|'.$currency)),
                'name' => null,
                'sku' => $sku,
                'price' => $price,
                'regular_price' => null,
                'sale_price' => null,
                'min_price' => null,
                'max_price' => null,
                'currency' => $currency,
                'availability' => $availability ? basename($availability) : null,
                'attributes' => [],
                'source_url' => $canonicalUrl,
            ]],
        ];
    }

    private function document(string $html): ?DOMDocument
    {
        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument;
        $loaded = $document->loadHTML($html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $loaded ? $document : null;
    }

    /** @param array<int,string> $names */
    private function meta(DOMXPath $xpath, array $names): ?string
    {
        foreach ($names as $name) {
            $escaped = str_replace("'", '', $name);
            $node = $xpath->query("//meta[@property='{$escaped}' or @name='{$escaped}']")->item(0);
            if ($node instanceof DOMElement && trim($node->getAttribute('content')) !== '') {
                return trim($node->getAttribute('content'));
            }
        }

        return null;
    }

    private function itemValue(DOMXPath $xpath, string $name): ?string
    {
        $node = $xpath->query("//*[@itemprop='{$name}']")->item(0);
        if (! $node instanceof DOMElement) {
            return null;
        }

        return trim($node->getAttribute('content') ?: $node->textContent) ?: null;
    }

    private function price(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }
        $normalised = preg_replace('/[^0-9.\-]/', '', (string) $value);
        if ($normalised === '' || ! is_numeric($normalised) || (float) $normalised < 0) {
            return null;
        }

        return number_format((float) $normalised, 4, '.', '');
    }

    private function clean(mixed $value, int $limit): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }
        $clean = trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) $value)));

        return $clean === '' ? null : mb_substr($clean, 0, $limit);
    }

    private function httpsUrl(mixed $value): ?string
    {
        if (! is_string($value) || ! str_starts_with(strtolower(trim($value)), 'https://')) {
            return null;
        }

        return mb_substr(trim($value), 0, 2048);
    }
}
