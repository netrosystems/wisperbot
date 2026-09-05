<?php

namespace App\Modules\AI\Jobs;

use App\Modules\AI\Models\AiKbChunk;
use App\Modules\AI\Models\AiKbDocument;
use App\Modules\AI\Models\AiKbEmbeddingCache;
use App\Modules\AI\Services\EmbeddingStore;
use App\Modules\AI\Services\KnowledgeBaseWorkflowService;
use App\Modules\AI\Services\KnowledgeQualityService;
use App\Modules\AI\Services\KnowledgeSourceExtractor;
use App\Modules\AI\Services\KnowledgeUrlGuard;
use App\Modules\AI\Services\Llm\LlmManager;
use App\Modules\AI\Services\LlmGateway;
use App\Modules\AI\Services\ProviderErrorPresenter;
use App\Modules\AI\Services\VideoResourceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\Response;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class IndexDocumentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public readonly int $documentId) {}

    public function handle(
        LlmGateway $llm,
        EmbeddingStore $store,
        KnowledgeSourceExtractor $extractor,
        KnowledgeQualityService $quality,
        KnowledgeBaseWorkflowService $workflow,
        KnowledgeUrlGuard $urls,
        VideoResourceService $videos,
    ): void {
        $doc = AiKbDocument::with('chunks')->find($this->documentId);
        if (! $doc) {
            return;
        }

        $doc->update(['status' => 'extracting', 'error_message' => null]);

        try {
            $kb = $doc->knowledgeBase ?? $doc->load('knowledgeBase')->knowledgeBase;
            $revision = $doc->revisions()->where('status', 'draft')->latest('version')->first()
                ?? $doc->revisions()->latest('version')->first();
            if ($doc->source_type === 'sitemap') {
                $this->processSitemap($doc, $urls, $workflow);
                $doc->update([
                    'status' => 'indexed',
                    'enabled' => false,
                    'review_status' => 'auto_approved',
                    'quality_score' => 100,
                    'extracted_content' => 'Sitemap expanded into individually reviewed page sources.',
                    'last_indexed_at' => now(),
                ]);

                return;
            }

            $text = $extractor->extract($doc);
            $documentUpdate = ['status' => 'validating', 'extracted_content' => $text];
            if ($doc->source_type !== 'video') {
                $discoveredVideos = $videos->discover($text, $doc->title ?: 'Video guide');
                $documentUpdate['resource_json'] = $discoveredVideos === [] ? null : [
                    'version' => 1,
                    'kind' => 'video_collection',
                    'videos' => $discoveredVideos,
                ];
            }
            $doc->update($documentUpdate);
            $inspection = $quality->inspect($doc, $text);
            $inspection = $quality->reviewAmbiguity((int) ($kb?->workspace_id ?? 0), $doc, $text, $inspection);
            $contentHash = hash('sha256', mb_strtolower((string) preg_replace('/\s+/u', ' ', trim($text))));
            $doc->update([
                'content_hash' => $contentHash,
                'detected_language' => $inspection['language'],
                'quality_score' => $inspection['score'],
                'quality_findings' => $inspection['findings'],
                'review_status' => config('knowledge_base.guarded_publishing') ? $inspection['review_status'] : 'auto_approved',
            ]);
            if (config('knowledge_base.guarded_publishing') && $inspection['review_status'] === 'blocked') {
                $doc->update(['status' => 'degraded', 'error_message' => 'Resolve the blocking quality findings before publishing.']);

                return;
            }

            $chunks = $this->chunk($text);

            if (empty($chunks) && $doc->source_type !== 'sitemap') {
                throw new \RuntimeException(match ($doc->source_type) {
                    'url' => 'URL indexing failed: no readable text was found on this page.',
                    'file' => 'Document indexing failed: the uploaded file could not be read or contained no extractable text.',
                    default => 'Document indexing failed: no readable text was found.',
                });
            }

            $oldEmbeddings = $doc->chunks->keyBy('content_hash')->map(fn (AiKbChunk $chunk) => [
                'embedding' => $chunk->embedding,
                'model' => $chunk->embedding_model,
                'status' => $chunk->embedding_status,
            ]);

            // Remove old vectors before deleting the relational chunks. Without
            // this, re-indexing leaves stale Qdrant points that can be returned
            // for a knowledge base even though their document no longer exists.
            $store->deleteDocumentEmbeddings($doc->id);

            // Remove old chunks
            $doc->chunks()->delete();

            $kbId = $kb?->id ?? 0;

            $chunkModels = [];
            foreach ($chunks as $i => $chunkText) {
                $chunkModels[] = AiKbChunk::create([
                    'kb_id' => $kbId,
                    'document_id' => $doc->id,
                    'ord' => $i,
                    'content' => $chunkText,
                    'content_hash' => hash('sha256', $chunkText),
                    'tokens' => (int) ceil(mb_strlen($chunkText) / 4),
                    'revision_id' => $revision?->id,
                ]);
            }

            // Embed all chunks.
            //
            // A missing embedding provider (Anthropic-only or none configured) is a
            // non-fatal condition: the document is still indexed as plain text and we
            // log it so operators can see RAG won't work until a provider is added.
            //
            // A transient embedding API error, by contrast, is allowed to propagate so
            // the queue retries — rather than silently marking the document "indexed"
            // with no vectors.
            $workspaceId = $kb?->workspace_id ?? 0;

            if ($workspaceId && ! empty($chunkModels)) {
                if ($this->embedProviderAvailable($workspaceId)) {
                    $model = (string) ($kb?->embedding_model ?: config('ai_credits.managed.embedding_model'));
                    $needsEmbedding = [];
                    foreach ($chunkModels as $chunk) {
                        $reusable = $oldEmbeddings->get($chunk->content_hash);
                        $cached = AiKbEmbeddingCache::where('content_hash', $chunk->content_hash)
                            ->where('model', $model)
                            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                            ->first();
                        $embedding = $cached?->embedding;
                        if (! is_array($embedding) && ($reusable['status'] ?? null) === 'ready' && ($reusable['model'] ?? null) === $model) {
                            $embedding = json_decode((string) ($reusable['embedding'] ?? ''), true);
                        }
                        if (is_array($embedding) && $embedding !== []) {
                            $chunk->update(['embedding_model' => $model, 'embedding_status' => 'ready']);
                            $store->storeEmbedding($chunk, $embedding);
                        } else {
                            $needsEmbedding[] = $chunk;
                        }
                    }
                    foreach (array_chunk($needsEmbedding, 20) as $batch) {
                        $texts = array_map(fn ($chunk) => $chunk->content, $batch);
                        $embeddings = $llm->embed($workspaceId, $texts);

                        foreach ($batch as $j => $chunk) {
                            if (isset($embeddings[$j])) {
                                $chunk->update(['embedding_model' => $model, 'embedding_status' => 'ready']);
                                $store->storeEmbedding($chunk, $embeddings[$j]);
                                AiKbEmbeddingCache::updateOrCreate(
                                    ['content_hash' => $chunk->content_hash, 'model' => $model],
                                    ['embedding' => $embeddings[$j], 'expires_at' => null],
                                );
                            }
                        }
                    }
                } else {
                    Log::warning('IndexDocumentJob: indexed without embeddings — no embedding-capable provider configured', [
                        'document_id' => $doc->id,
                        'kb_id' => $kbId,
                        'workspace_id' => $workspaceId,
                    ]);
                }
            }

            $hasReadyEmbeddings = collect($chunkModels)->every(fn (AiKbChunk $chunk) => $chunk->fresh()->embedding_status === 'ready');
            $doc->update([
                'status' => $hasReadyEmbeddings ? 'indexed' : 'degraded',
                'error_message' => null,
                'last_indexed_at' => now(),
                'last_refreshed_at' => now(),
                'tokens' => array_sum(array_map(fn ($c) => $c->tokens, $chunkModels)),
            ]);
            if (! config('knowledge_base.guarded_publishing')) {
                $doc->update(['publication_status' => 'published', 'review_status' => 'auto_approved']);
            } elseif ($hasReadyEmbeddings && in_array($doc->fresh()->review_status, ['auto_approved', 'approved'], true)) {
                $workflow->attemptAutoPublish($kb);
            }
        } catch (\Throwable $e) {
            $doc->update([
                'status' => 'error',
                'error_message' => $this->safeErrorMessage($e),
            ]);
            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        AiKbDocument::whereKey($this->documentId)->update([
            'status' => 'error',
            'error_message' => $this->safeErrorMessage($exception),
        ]);
    }

    private function safeErrorMessage(\Throwable $exception): string
    {
        $presented = ProviderErrorPresenter::present($exception);
        if ($presented['code'] !== 'provider_request_failed') {
            return $presented['message'];
        }

        $message = strtolower($exception->getMessage());
        foreach (['url indexing failed', 'sitemap indexing failed', 'document indexing failed'] as $safeMarker) {
            if (str_contains($message, $safeMarker)) {
                return $exception->getMessage();
            }
        }

        foreach (['openai', 'anthropic', 'gemini', 'embedding', 'ai provider'] as $providerMarker) {
            if (str_contains($message, $providerMarker)) {
                return $presented['message'];
            }
        }

        return 'Document indexing failed. Check the source file or URL and the server logs, then try re-indexing.';
    }

    /** True when the workspace has an embedding-capable provider (OpenAI/Gemini). */
    private function embedProviderAvailable(int $workspaceId): bool
    {
        // Any failure to RESOLVE a provider (none configured, orphaned workspace,
        // malformed config) is treated as "no embeddings" — a non-fatal condition,
        // so the document still indexes as plain text. This deliberately does NOT
        // swallow errors from the actual embed() call below, which must still
        // propagate so the queue retries rather than indexing with no vectors.
        try {
            LlmManager::forWorkspaceEmbed($workspaceId);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Parse a sitemap and fan out one lightweight child job per page URL.
     *
     * This job stays cheap on purpose: it only fetches + parses the XML and
     * enqueues child "url" documents. The actual page crawling/embedding happens
     * in those child jobs on the queue, so the originating web request never
     * blocks on hundreds of HTTP fetches (which previously caused a 502 when the
     * queue ran synchronously).
     *
     * Handles both <urlset> (a flat list of pages) and <sitemapindex> (a list of
     * nested sitemaps, e.g. Yoast/WordPress) — for the latter, each nested sitemap
     * is enqueued as its own "sitemap" child and expanded recursively.
     */
    private function processSitemap(AiKbDocument $doc, KnowledgeUrlGuard $urls, KnowledgeBaseWorkflowService $workflow): string
    {
        $sitemapUrl = $doc->source_ref ?? '';
        if (empty($sitemapUrl)) {
            return '';
        }
        // A streaming homepage can remain open indefinitely even when its
        // sitemap and inner pages are healthy. Discover first for root URLs.
        $sitemapUrl = $urls->assertSafe($sitemapUrl);
        $rootInput = in_array(parse_url($sitemapUrl, PHP_URL_PATH) ?: '/', ['/', ''], true);
        $discovered = $rootInput ? $this->discoverSitemap($sitemapUrl, '', $urls) : null;
        if ($discovered !== null) {
            [$resolvedUrl, $parsed] = $discovered;
            $doc->update(['source_ref' => $resolvedUrl]);
        } else {
            [$response, $resolvedUrl] = $this->fetchSiteResource($sitemapUrl, $urls);
            $parsed = $this->parseSitemapXml($response->body());
        }

        // Non-technical users commonly paste their homepage in the Sitemap tab.
        // Resolve a declared/common sitemap first; if the site has none, safely
        // fan out the homepage and its same-host links instead of failing.
        if ($parsed === null) {
            $discovered = $this->discoverSitemap($resolvedUrl, $response->body(), $urls,
                ! $rootInput || $this->origin($resolvedUrl) !== $this->origin($sitemapUrl));
            if ($discovered !== null) {
                [$resolvedUrl, $parsed] = $discovered;
                $doc->update(['source_ref' => $resolvedUrl]);
            } else {
                $pageUrls = $this->discoverPageLinks($resolvedUrl, $response->body(), $urls);
                $this->createSitemapChildren($doc, $pageUrls, 'url', (string) parse_url($resolvedUrl, PHP_URL_HOST), $urls, $workflow);

                return '';
            }
        }

        if ($parsed['urls'] === []) {
            throw new \RuntimeException('Sitemap indexing failed: no page URLs were found in '.$resolvedUrl.'.');
        }

        $this->createSitemapChildren(
            $doc,
            $parsed['urls'],
            $parsed['is_index'] ? 'sitemap' : 'url',
            (string) parse_url($resolvedUrl, PHP_URL_HOST),
            $urls,
            $workflow,
        );

        return '';
    }

    /** @return array{0:Response,1:string} */
    private function fetchSiteResource(string $url, KnowledgeUrlGuard $urls, int $timeout = 12, int $attempts = 1): array
    {
        $url = $urls->assertSafe($url);
        for ($redirects = 0; $redirects <= 4; $redirects++) {
            $connectedIp = null;
            try {
                $response = Http::withOptions([
                    'allow_redirects' => false,
                    'on_stats' => function ($stats) use (&$connectedIp): void {
                        $connectedIp = $stats->getHandlerStats()['primary_ip'] ?? null;
                    },
                ])->withHeaders([
                    'User-Agent' => 'WisperBotKnowledgeIndexer/2.0 (+https://wisperbot.com)',
                    'Accept' => 'application/xml,text/xml,text/html,text/plain;q=0.9,*/*;q=0.5',
                    'Accept-Language' => 'en,*;q=0.5',
                ])->retry($attempts, 400, throw: false)->connectTimeout(5)->timeout($timeout)->get($url);
            } catch (\Throwable $exception) {
                $reason = match (true) {
                    str_contains($exception->getMessage(), 'cURL error 28') => 'the website took too long to finish responding. Try its sitemap URL or a specific page instead.',
                    str_contains($exception->getMessage(), 'cURL error 6') => 'the website address could not be resolved. Check the address and its DNS settings.',
                    str_contains($exception->getMessage(), 'cURL error 60') => 'the website HTTPS certificate could not be verified. Ask the website owner to check its certificate.',
                    default => 'the connection to the website failed. Retry later or upload a reviewed file.',
                };
                throw new \RuntimeException('Sitemap indexing failed: '.$reason, 0, $exception);
            }
            if ($connectedIp !== null) {
                $urls->assertPublicIp($connectedIp);
            }
            if (in_array($response->status(), [301, 302, 303, 307, 308], true)) {
                if ($redirects === 4) {
                    throw new \RuntimeException('Sitemap indexing failed: too many redirects.');
                }
                $location = (string) $response->header('Location');
                $url = $urls->assertSafe($this->resolveUrl($url, $location));

                continue;
            }
            if (! $response->successful()) {
                if (in_array($response->status(), [401, 403], true)) {
                    throw new \RuntimeException('Sitemap indexing failed: the website blocked automated access. Allow WisperBotKnowledgeIndexer on the site or upload reviewed files instead.');
                }
                if ($response->status() === 429) {
                    throw new \RuntimeException('Sitemap indexing failed: the website temporarily rate-limited indexing. Wait a few minutes and retry.');
                }
                if ($response->serverError()) {
                    throw new \RuntimeException('Sitemap indexing failed: the website is temporarily unavailable. Retry after the site recovers.');
                }
                throw new \RuntimeException('Sitemap indexing failed: '.$url.' returned HTTP '.$response->status().'.');
            }
            if (strlen($response->body()) > (int) config('knowledge_base.sitemap_response_max_bytes', 20_000_000)) {
                throw new \RuntimeException('Sitemap indexing failed: the response is too large.');
            }

            return [$response, $url];
        }

        throw new \RuntimeException('Sitemap indexing failed: the site could not be reached.');
    }

    /** @return array{is_index:bool,urls:array<int,string>}|null */
    private function parseSitemapXml(string $body): ?array
    {
        if (str_starts_with($body, "\x1f\x8b")) {
            $maximum = (int) config('knowledge_base.sitemap_response_max_bytes', 20_000_000);
            $decoded = @gzdecode($body, $maximum + 1);
            if (! is_string($decoded) || strlen($decoded) > $maximum) {
                return null;
            }
            $body = $decoded;
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $xml = simplexml_load_string($body, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if ($xml === false || ! in_array(strtolower($xml->getName()), ['urlset', 'sitemapindex'], true)) {
            return null;
        }

        $isIndex = strtolower($xml->getName()) === 'sitemapindex';
        $urls = [];
        foreach (($isIndex ? $xml->sitemap : $xml->url) as $node) {
            $location = trim((string) $node->loc);
            if ($location !== '') {
                $urls[$location] = true;
            }
        }

        return ['is_index' => $isIndex, 'urls' => array_keys($urls)];
    }

    /** @return array{0:string,1:array{is_index:bool,urls:array<int,string>}}|null */
    private function discoverSitemap(string $pageUrl, string $html, KnowledgeUrlGuard $urls, bool $includeCommon = true): ?array
    {
        $candidates = [];
        if (preg_match_all('/<link\b[^>]*>/iu', $html, $linkTags)) {
            foreach ($linkTags[0] as $tag) {
                if (preg_match('/\brel\s*=\s*(["\'])?[^>"\']*sitemap[^>"\']*\1?/iu', $tag)
                    && preg_match('/\bhref\s*=\s*(["\'])([^"\']+)\1/iu', $tag, $href)) {
                    $candidates[] = $this->resolveUrl($pageUrl, html_entity_decode($href[2], ENT_QUOTES | ENT_HTML5));
                }
            }
        }

        $origin = $this->origin($pageUrl);
        if ($includeCommon) {
            try {
                [$robots] = $this->fetchSiteResource($origin.'/robots.txt', $urls, 8, 1);
                if (preg_match_all('/^\s*Sitemap\s*:\s*(\S+)\s*$/im', $robots->body(), $matches)) {
                    array_push($candidates, ...$matches[1]);
                }
            } catch (\Throwable) {
                // robots.txt is optional.
            }
            array_push($candidates, $origin.'/sitemap.xml', $origin.'/sitemap_index.xml', $origin.'/sitemap-index.xml', $origin.'/wp-sitemap.xml');
        }

        foreach (array_values(array_unique($candidates)) as $candidate) {
            try {
                [$response, $resolved] = $this->fetchSiteResource($candidate, $urls, 8, 1);
                $parsed = $this->parseSitemapXml($response->body());
                if ($parsed !== null && $parsed['urls'] !== []) {
                    return [$resolved, $parsed];
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }

    /** @return array<int,string> */
    private function discoverPageLinks(string $pageUrl, string $html, KnowledgeUrlGuard $urls): array
    {
        $host = (string) parse_url($pageUrl, PHP_URL_HOST);
        $found = [$pageUrl => true];
        preg_match_all('/<a\b[^>]*\bhref\s*=\s*(["\'])([^"\']+)\1/iu', $html, $matches);
        foreach ($matches[2] ?? [] as $href) {
            try {
                $url = $this->resolveUrl($pageUrl, html_entity_decode((string) $href, ENT_QUOTES | ENT_HTML5));
                $parts = parse_url($url);
                if (isset($parts['fragment'])) {
                    $url = substr($url, 0, -strlen((string) $parts['fragment']) - 1);
                }
                $url = $urls->assertSafe($url, $host);
                $path = strtolower((string) parse_url($url, PHP_URL_PATH));
                if (! preg_match('/\.(?:jpe?g|png|gif|webp|svg|pdf|zip|mp4|mp3|css|js)$/i', $path)) {
                    $found[rtrim($url, '/') ?: $url] = true;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return array_slice(array_keys($found), 0, (int) config('knowledge_base.sitemap_page_limit', 200));
    }

    /** @param array<int,string> $locations */
    private function createSitemapChildren(AiKbDocument $doc, array $locations, string $childType, string $host, KnowledgeUrlGuard $urls, KnowledgeBaseWorkflowService $workflow): void
    {
        $available = 0;
        $limit = (int) config('knowledge_base.sitemap_page_limit', 200);
        foreach (array_slice($locations, 0, (int) config('knowledge_base.sitemap_page_limit', 200)) as $location) {
            try {
                $locationHost = strtolower((string) parse_url($location, PHP_URL_HOST));
                if ($locationHost !== $host && ($locationHost === 'www.'.$host || $host === 'www.'.$locationHost)) {
                    // Fetch only the canonical sitemap host, never an arbitrary
                    // subdomain. Validate the original authority before rewriting.
                    $urls->assertSafe($location);
                    $location = preg_replace('#^https://'.preg_quote($locationHost, '#').'(?=[:/]|$)#i', 'https://'.$host, $location);
                }
                $location = $urls->assertSafe($location, $host);
            } catch (\Throwable) {
                continue;
            }
            $available++;
            if (AiKbDocument::where('kb_id', $doc->kb_id)->where('source_type', $childType)->where('source_ref', $location)->exists()) {
                continue;
            }
            if (AiKbDocument::where('kb_id', $doc->kb_id)->whereIn('source_type', ['url', 'sitemap'])->count() >= $limit) {
                break;
            }
            $child = AiKbDocument::create([
                'kb_id' => $doc->kb_id,
                'title' => $location,
                'source_type' => $childType,
                'source_ref' => $location,
                'status' => 'pending',
            ]);
            $workflow->attachToDraft($child);
            static::dispatch($child->id)->onQueue('ai');
        }

        if ($available === 0) {
            throw new \RuntimeException('Sitemap indexing failed: no safe same-site page URLs were found.');
        }
    }

    private function origin(string $url): string
    {
        $parts = parse_url($url);

        return 'https://'.($parts['host'] ?? '').(isset($parts['port']) ? ':'.$parts['port'] : '');
    }

    private function resolveUrl(string $base, string $location): string
    {
        $location = trim($location);
        if (str_starts_with($location, 'https://')) {
            return $location;
        }
        if (str_starts_with($location, '//')) {
            return 'https:'.$location;
        }
        if ($location === '' || preg_match('/^(?:mailto|tel|javascript|data):/i', $location)) {
            throw new \InvalidArgumentException('Unsupported site link.');
        }
        $parts = parse_url($base);
        $origin = $this->origin($base);
        if (str_starts_with($location, '/')) {
            return $origin.$location;
        }
        $directory = rtrim(str_replace('\\', '/', dirname((string) ($parts['path'] ?? '/'))), '/');

        return $origin.($directory === '' ? '' : $directory).'/'.$location;
    }

    private function chunk(string $text): array
    {
        $size = (int) config('knowledge_base.chunk_target_words', 280);
        $overlap = (int) config('knowledge_base.chunk_overlap_words', 35);
        $sections = preg_split('/\n(?=(?:#{1,6}\s|Q:\s|\d+[.)]\s))|\n{2,}/u', trim($text)) ?: [];
        $chunks = [];
        $buffer = [];
        foreach ($sections as $section) {
            $words = preg_split('/\s+/', trim($section)) ?: [];
            if (count($buffer) + count($words) <= $size) {
                $buffer = array_merge($buffer, $words);

                continue;
            }
            if ($buffer !== []) {
                $chunks[] = trim(implode(' ', $buffer));
                $buffer = array_slice($buffer, -$overlap);
            }
            $i = 0;
            while ($i < count($words)) {
                $space = max(1, $size - count($buffer));
                $part = array_slice($words, $i, $space);
                $buffer = array_merge($buffer, $part);
                $i += count($part);
                if (count($buffer) >= $size) {
                    $chunks[] = trim(implode(' ', $buffer));
                    $buffer = array_slice($buffer, -$overlap);
                }
            }
        }
        if ($buffer !== []) {
            $chunks[] = trim(implode(' ', $buffer));
        }

        return array_values(array_unique(array_filter($chunks)));
    }
}
