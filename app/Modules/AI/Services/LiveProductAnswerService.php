<?php

namespace App\Modules\AI\Services;

use App\Modules\AI\Models\AiChatbot;
use App\Modules\AI\Models\AiKbDocument;
use App\Modules\AI\Models\AiKbProduct;
use App\Modules\AI\Models\AiKbProductOffer;
use App\Modules\AI\Models\AiKnowledgeBase;
use Illuminate\Support\Collection;

class LiveProductAnswerService
{
    public function __construct(
        private readonly LiveProductCatalogService $catalog,
        private readonly KnowledgeRetrievalService $retrieval,
        private readonly SmartBotRetrievalPolicy $retrievalPolicy,
    ) {}

    /**
     * @param  array<int,array<string,mixed>>  $history
     * @return array<string,mixed>|null
     */
    public function answer(AiChatbot $bot, int $workspaceId, string $message, array $history = []): ?array
    {
        if (! config('knowledge_base.live_product_facts_enabled') || ! $bot->live_product_facts_enabled || ! $bot->ai_kb_id) {
            return null;
        }
        $intent = $this->intent($message);
        if ($intent === null) {
            return null;
        }

        $query = $this->continuationQuery($message, $history);
        $knowledgeBase = AiKnowledgeBase::where('workspace_id', $workspaceId)
            ->whereKey($bot->ai_kb_id)
            ->first();
        if (! $knowledgeBase) {
            return null;
        }
        $publishedRevisionId = $knowledgeBase->published_revision_id;
        if (config('knowledge_base.guarded_publishing') && ! $publishedRevisionId) {
            return null;
        }
        $products = AiKbProduct::query()
            ->with(['offers', 'document'])
            ->where('workspace_id', $workspaceId)
            ->where('kb_id', $bot->ai_kb_id)
            ->where('status', 'active')
            ->whereHas('document', fn ($q) => $q->where('enabled', true)->where('publication_status', 'published'))
            ->when($publishedRevisionId, fn ($query) => $query->whereHas(
                'document.revisions', fn ($revision) => $revision->whereKey($publishedRevisionId),
            ))
            ->limit(1000)
            ->get();
        if ($products->isEmpty()) {
            return null;
        }

        $ranked = $products->map(fn (AiKbProduct $product) => [
            'product' => $product,
            'score' => $this->score($query, implode(' ', array_filter([
                $product->name, $product->sku,
                $product->offers->pluck('name')->implode(' '),
                $product->offers->pluck('sku')->implode(' '),
            ]))),
        ])->sortByDesc('score')->values();

        $generic = count($this->meaningfulTerms($query)) === 0;
        $qualified = $ranked->filter(fn (array $candidate) => $candidate['score'] >= 0.18);
        if ($qualified->isEmpty() && ! $generic) {
            $semanticScores = $this->semanticDocumentScores($knowledgeBase, $workspaceId, $message, $history, $publishedRevisionId);
            if ($semanticScores !== []) {
                $ranked = $ranked->map(function (array $candidate) use ($semanticScores): array {
                    $candidate['score'] = max(
                        (float) $candidate['score'],
                        ((float) ($semanticScores[$candidate['product']->document_id] ?? 0)) * 0.90,
                    );

                    return $candidate;
                })->sortByDesc('score')->values();
                $qualified = $ranked->filter(fn (array $candidate) => $candidate['score'] >= 0.36);
            }
        }
        if ($qualified->isEmpty() && ! ($generic && $products->count() <= 3)) {
            return null;
        }
        if ($qualified->isEmpty()) {
            $qualified = $ranked;
        }

        $best = $qualified->first();
        $ambiguous = $qualified->count() > 1
            && ($generic || ((float) $best['score'] - (float) $qualified->get(1)['score']) < 0.08);
        if ($ambiguous) {
            return $this->clarification(
                'Which product would you like me to check?',
                $qualified->take(3)->pluck('product')->map(fn (AiKbProduct $product) => $product->name)->all(),
                $intent,
                $qualified->count(),
            );
        }

        /** @var AiKbProduct $product */
        $product = $best['product'];
        $offers = $product->offers->filter(fn (AiKbProductOffer $offer) => $this->hasPrice($offer))->values();
        if ($offers->isEmpty()) {
            return $this->unverified($product, $intent, $qualified->count());
        }
        $offer = $this->selectOffer($offers, $query);
        if ($offer === null) {
            return $this->clarification(
                'Which option would you like the current price for?',
                $offers->take(3)->map(fn (AiKbProductOffer $item) => $this->offerLabel($item, $product))->all(),
                $intent,
                $qualified->count(),
                $product,
            );
        }

        // A fresh connected commerce record is the strongest source and does not
        // depend on the age of the website extraction used to identify the item.
        if ($commerce = $this->connectedCommerceFact($product, $offer)) {
            return $this->confirmedCommerce($product, $commerce, $intent, $qualified->count());
        }

        if (! $product->verified_at || $product->verified_at->lt(now()->subMinutes($this->freshnessMinutes()))) {
            if (! $product->document instanceof AiKbDocument) {
                return $this->unverified($product, $intent, $qualified->count());
            }
            try {
                $this->catalog->refreshDocument($product->document);
                $product = AiKbProduct::with(['offers', 'document'])->find($product->id) ?? $product;
            } catch (\Throwable) {
                return $this->unverified($product, $intent, $qualified->count());
            }
        }
        if (! $product->verified_at || $product->verified_at->lt(now()->subMinutes($this->freshnessMinutes()))) {
            return $this->unverified($product, $intent, $qualified->count());
        }

        $offers = $product->offers->filter(fn (AiKbProductOffer $candidate) => $this->hasPrice($candidate))->values();
        if ($offers->isEmpty()) {
            return $this->unverified($product, $intent, $qualified->count());
        }
        $offer = $this->selectOffer($offers, $query);
        if ($offer === null) {
            return $this->clarification(
                'Which option would you like the current price for?',
                $offers->take(3)->map(fn (AiKbProductOffer $item) => $this->offerLabel($item, $product))->all(),
                $intent,
                $qualified->count(),
                $product,
            );
        }

        return $this->confirmed($product, $offer, $intent, $qualified->count());
    }

    private function intent(string $message): ?string
    {
        $text = mb_strtolower($message);
        if (preg_match('/\b(prices?|pricing|costs?|how much|sale|discount|stock|available|availability|in stock)\b/u', $text)
            || preg_match('/(দাম|মূল্য|কত|স্টক|উপলব্ধ|precio|cuánto|disponible|prix|combien|preis|wieviel|verfügbar|سعر|متوفر)/u', $text)) {
            return preg_match('/\b(stock|available|availability|in stock)\b|স্টক|উপলব্ধ|disponible|verfügbar|متوفر/u', $text)
                ? 'product_availability' : 'product_price';
        }

        return null;
    }

    /**
     * @param  array<int,array<string,mixed>>  $history
     * @return array<int,float>
     */
    private function semanticDocumentScores(
        AiKnowledgeBase $knowledgeBase,
        int $workspaceId,
        string $message,
        array $history,
        ?int $revisionId,
    ): array {
        try {
            $policy = $this->retrievalPolicy->privateAnswering();
            $result = $this->retrieval->retrieve(
                $knowledgeBase,
                $workspaceId,
                $message,
                $history,
                min(5, $policy['max_context_chunks']),
                $revisionId,
                $policy['answer_threshold'],
                min(600, $policy['max_context_tokens']),
            );
            $scores = [];
            foreach ($result['candidates'] as $candidate) {
                $documentId = (int) $candidate['chunk']->document_id;
                if ($documentId > 0) {
                    $scores[$documentId] = max($scores[$documentId] ?? 0, (float) ($candidate['rank_score'] ?? 0));
                }
            }

            return $scores;
        } catch (\Throwable) {
            return [];
        }
    }

    /** @param array<int,array<string,mixed>> $history */
    private function continuationQuery(string $message, array $history): string
    {
        if (count($this->meaningfulTerms($message)) > 0 || $history === []) {
            return $message;
        }
        $previous = collect($history)->reverse()->first(fn ($turn) => in_array($turn['role'] ?? null, ['user', 'assistant'], true)
            && trim((string) ($turn['content'] ?? '')) !== '');

        return $previous ? (string) $previous['content'].' '.$message : $message;
    }

    private function score(string $query, string $candidate): float
    {
        $queryNormal = $this->normalise($query);
        $candidateNormal = $this->normalise($candidate);
        if ($queryNormal === '' || $candidateNormal === '') {
            return 0.0;
        }
        if (str_contains($queryNormal, $candidateNormal)) {
            return 1.0;
        }
        $terms = $this->meaningfulTerms($queryNormal);
        $candidateTerms = array_unique(preg_split('/\s+/u', $candidateNormal) ?: []);
        $overlap = count(array_intersect($terms, $candidateTerms)) / max(1, count($terms));
        similar_text($queryNormal, $candidateNormal, $similarity);

        return min(1.0, ($overlap * 0.78) + (($similarity / 100) * 0.22));
    }

    /** @return array<int,string> */
    private function meaningfulTerms(string $value): array
    {
        $terms = preg_split('/\s+/u', $this->normalise($value)) ?: [];
        $ignored = ['price', 'prices', 'pricing', 'cost', 'costs', 'how', 'much', 'sale', 'discount', 'stock', 'available', 'availability', 'in', 'the', 'a', 'an', 'is', 'are', 'for', 'of', 'what', 'your', 'current', 'please', 'দাম', 'মূল্য', 'কত', 'স্টক', 'উপলব্ধ'];

        return array_values(array_unique(array_filter($terms, fn ($term) => mb_strlen($term) >= 2 && ! in_array($term, $ignored, true))));
    }

    private function normalise(string $value): string
    {
        return trim((string) preg_replace('/[^\pL\pN]+/u', ' ', mb_strtolower($value)));
    }

    /** @param Collection<int,AiKbProductOffer> $offers */
    private function selectOffer(Collection $offers, string $query): ?AiKbProductOffer
    {
        if ($offers->count() === 1) {
            return $offers->first();
        }
        $ranked = $offers->map(fn (AiKbProductOffer $offer) => [
            'offer' => $offer,
            'score' => $this->score($query, implode(' ', array_filter([
                $offer->name, $offer->sku, implode(' ', $offer->attributes ?? []),
            ]))),
        ])->sortByDesc('score')->values();
        if (($ranked->first()['score'] ?? 0) < 0.18) {
            return null;
        }
        if ($ranked->count() > 1 && (($ranked[0]['score'] - $ranked[1]['score']) < 0.08)) {
            return null;
        }

        return $ranked->first()['offer'];
    }

    private function hasPrice(AiKbProductOffer $offer): bool
    {
        return $offer->price !== null || $offer->sale_price !== null || $offer->min_price !== null || $offer->max_price !== null;
    }

    /** @return array<string,mixed> */
    private function confirmed(AiKbProduct $product, AiKbProductOffer $offer, string $intent, int $candidateCount): array
    {
        if ($commerce = $this->connectedCommerceFact($product, $offer)) {
            return $this->confirmedCommerce($product, $commerce, $intent, $candidateCount);
        }
        $label = $this->offerLabel($offer, $product);
        $price = $this->priceLabel($offer);
        $availability = $this->availabilityLabel($offer->availability);
        $verified = $product->verified_at->clone()->utc()->format('Y-m-d H:i').' UTC';
        $parts = [];
        if ($intent === 'product_availability' && $availability) {
            $parts[] = $label.' is '.$availability.'.';
        }
        if ($price !== null) {
            $parts[] = 'The confirmed price for '.$label.' is '.$price.'.';
        }
        if ($availability && $intent !== 'product_availability') {
            $parts[] = 'Availability: '.$availability.'.';
        }
        $parts[] = 'Verified '.$verified.'.';
        $citation = ['title' => $product->name, 'url' => $product->canonical_url];
        $reply = implode(' ', $parts)."\n\nSource: [".$this->safeLinkTitle($product->name).']('.$product->canonical_url.')';

        return [
            'reply' => $reply,
            'display_body' => $reply,
            'tokens_used' => 0,
            'resources' => [],
            'quick_replies' => [],
            'answer_origin' => 'live_product',
            'response_mode' => 'answer',
            'citations' => [$citation],
            'product_facts' => [$this->publicFact($product, $offer)],
            'intent' => $intent,
            'diagnostics' => [
                'candidate_count' => $candidateCount,
                'verification_outcome' => 'verified',
                'source_type' => 'approved_website',
                'verified_at' => $product->verified_at->toIso8601String(),
                'credit_result' => 'zero_cost',
            ],
        ];
    }

    /** @return array<string,mixed>|null */
    private function connectedCommerceFact(AiKbProduct $product, AiKbProductOffer $offer): ?array
    {
        $model = 'App\\Modules\\Ecommerce\\Models\\EcommerceProduct';
        if (! class_exists($model)) {
            return null;
        }
        $sku = $offer->sku ?: $product->sku;
        $record = $model::query()->with('store')
            ->where('workspace_id', $product->workspace_id)
            ->where('updated_at', '>=', now()->subMinutes($this->freshnessMinutes()))
            ->whereHas('store', fn ($query) => $query->where('status', 'connected'))
            ->where(function ($query) use ($sku, $product): void {
                if ($sku) {
                    $query->where('sku', $sku)->orWhere('name', $product->name);
                } else {
                    $query->where('name', $product->name);
                }
            })
            ->orderByRaw('CASE WHEN sku = ? THEN 0 ELSE 1 END', [$sku ?: ''])
            ->first();
        if (! $record || ! $record->store || (float) $record->price < 0) {
            return null;
        }
        $approvedHost = strtolower((string) parse_url($product->canonical_url, PHP_URL_HOST));
        $storeHost = strtolower((string) parse_url(
            str_contains($record->store->domain, '://') ? $record->store->domain : 'https://'.$record->store->domain,
            PHP_URL_HOST,
        ));
        if ($approvedHost === '' || $storeHost === '' || ! in_array($storeHost, [$approvedHost, preg_replace('/^www\./', '', $approvedHost), 'www.'.preg_replace('/^www\./', '', $approvedHost)], true)) {
            return null;
        }
        $currency = strtoupper((string) ($record->store->external_meta['currency'] ?? ''));
        if (! preg_match('/^[A-Z]{3}$/', $currency)) {
            return null;
        }

        return [
            'name' => $record->name,
            'sku' => $record->sku,
            'price' => (string) $record->price,
            'currency' => $currency,
            'availability' => $record->inventory_quantity === null ? null : ($record->inventory_quantity > 0 ? 'in stock' : 'out of stock'),
            'verified_at' => $record->updated_at,
            'platform' => $record->platform,
        ];
    }

    /**
     * @param  array<string,mixed>  $commerce
     * @return array<string,mixed>
     */
    private function confirmedCommerce(AiKbProduct $product, array $commerce, string $intent, int $candidateCount): array
    {
        $label = (string) $commerce['name'];
        $availability = $commerce['availability'];
        $parts = [];
        if ($intent === 'product_availability' && $availability) {
            $parts[] = $label.' is '.$availability.'.';
        }
        $parts[] = 'The confirmed price for '.$label.' is '.$this->money($commerce['price'], $commerce['currency']).'.';
        if ($availability && $intent !== 'product_availability') {
            $parts[] = 'Availability: '.$availability.'.';
        }
        $parts[] = 'Verified '.$commerce['verified_at']->clone()->utc()->format('Y-m-d H:i').' UTC.';
        $reply = implode(' ', $parts)."\n\nSource: [".$this->safeLinkTitle($product->name).']('.$product->canonical_url.')';

        return [
            'reply' => $reply, 'display_body' => $reply, 'tokens_used' => 0, 'resources' => [],
            'quick_replies' => [], 'answer_origin' => 'live_product', 'response_mode' => 'answer',
            'citations' => [['title' => $product->name, 'url' => $product->canonical_url]],
            'product_facts' => [array_filter([
                'product' => $label, 'sku' => $commerce['sku'], 'price' => $commerce['price'],
                'currency' => $commerce['currency'], 'availability' => $availability,
                'url' => $product->canonical_url, 'verified_at' => $commerce['verified_at']->toIso8601String(),
            ], fn ($value) => $value !== null && $value !== '')],
            'intent' => $intent,
            'diagnostics' => [
                'candidate_count' => $candidateCount, 'verification_outcome' => 'verified',
                'source_type' => 'connected_store', 'platform' => $commerce['platform'],
                'verified_at' => $commerce['verified_at']->toIso8601String(), 'credit_result' => 'zero_cost',
            ],
        ];
    }

    /**
     * @param  array<int,string>  $choices
     * @return array<string,mixed>
     */
    private function clarification(string $question, array $choices, string $intent, int $candidateCount, ?AiKbProduct $product = null): array
    {
        $choices = array_values(array_unique(array_filter(array_map(fn ($choice) => mb_substr(trim((string) $choice), 0, 72), $choices))));
        $quickReplies = collect($choices)->take(3)->values()->map(fn ($label, $index) => [
            'id' => 'product_'.($index + 1), 'label' => $label,
        ])->all();
        $body = $question;
        if ($quickReplies !== []) {
            $body .= "\n\n".collect($quickReplies)->map(fn ($choice, $index) => ($index + 1).'. '.$choice['label'])->implode("\n");
        }

        return [
            'reply' => $body,
            'display_body' => $question,
            'tokens_used' => 0,
            'resources' => [],
            'quick_replies' => $quickReplies,
            'answer_origin' => 'live_product',
            'response_mode' => 'clarification',
            'citations' => [],
            'product_facts' => [],
            'intent' => $intent,
            'diagnostics' => [
                'candidate_count' => $candidateCount,
                'verification_outcome' => 'clarification',
                'source_type' => $product ? 'approved_website' : null,
                'credit_result' => 'zero_cost',
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function unverified(AiKbProduct $product, string $intent, int $candidateCount): array
    {
        $reply = 'I found '.$product->name.', but I could not verify its current price or availability right now. Please check the product page or ask a person for help.'
            ."\n\nSource: [".$this->safeLinkTitle($product->name).']('.$product->canonical_url.')';

        return [
            'reply' => $reply,
            'display_body' => $reply,
            'tokens_used' => 0,
            'resources' => [],
            'quick_replies' => [],
            'answer_origin' => 'live_product',
            'response_mode' => 'fallback',
            'citations' => [['title' => $product->name, 'url' => $product->canonical_url]],
            'product_facts' => [],
            'intent' => $intent,
            'diagnostics' => [
                'candidate_count' => $candidateCount,
                'verification_outcome' => 'failed',
                'source_type' => 'approved_website',
                'credit_result' => 'not_charged',
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function publicFact(AiKbProduct $product, AiKbProductOffer $offer): array
    {
        return array_filter([
            'product' => $product->name,
            'variant' => $offer->name,
            'sku' => $offer->sku ?: $product->sku,
            'price' => $offer->price ?? $offer->sale_price,
            'regular_price' => $offer->regular_price,
            'sale_price' => $offer->sale_price,
            'min_price' => $offer->min_price,
            'max_price' => $offer->max_price,
            'currency' => $offer->currency,
            'availability' => $this->availabilityLabel($offer->availability),
            'url' => $product->canonical_url,
            'verified_at' => $product->verified_at?->toIso8601String(),
        ], fn ($value) => $value !== null && $value !== '');
    }

    private function offerLabel(AiKbProductOffer $offer, AiKbProduct $product): string
    {
        return trim($product->name.($offer->name && strcasecmp($offer->name, $product->name) !== 0 ? ' — '.$offer->name : ''));
    }

    private function priceLabel(AiKbProductOffer $offer): ?string
    {
        $currency = $offer->currency;
        if (! $currency) {
            return null;
        }
        if ($offer->sale_price !== null) {
            $sale = $this->money($offer->sale_price, $currency);

            return $offer->regular_price !== null ? $sale.' (regularly '.$this->money($offer->regular_price, $currency).')' : $sale;
        }
        if ($offer->min_price !== null || $offer->max_price !== null) {
            $min = $offer->min_price ?? $offer->max_price;
            $max = $offer->max_price ?? $offer->min_price;

            return $min === $max ? $this->money($min, $currency) : $this->money($min, $currency).'–'.$this->money($max, $currency);
        }

        return $offer->price !== null ? $this->money($offer->price, $currency) : null;
    }

    private function money(string $amount, string $currency): string
    {
        $number = rtrim(rtrim(number_format((float) $amount, 4, '.', ','), '0'), '.');

        return strtoupper($currency).' '.$number;
    }

    private function availabilityLabel(?string $availability): ?string
    {
        if (! $availability) {
            return null;
        }

        return match (mb_strtolower($availability)) {
            'instock', 'in_stock' => 'in stock',
            'outofstock', 'out_of_stock', 'soldout' => 'out of stock',
            'preorder', 'pre_order' => 'available for pre-order',
            default => mb_strtolower(trim(preg_replace('/(?<!^)([A-Z])/', ' $1', $availability) ?? $availability)),
        };
    }

    private function freshnessMinutes(): int
    {
        return max(5, min(1440, (int) config('knowledge_base.live_product_freshness_minutes', 15)));
    }

    private function safeLinkTitle(string $title): string
    {
        return str_replace(['[', ']'], '', $title);
    }
}
