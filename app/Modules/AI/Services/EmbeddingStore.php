<?php

namespace App\Modules\AI\Services;

use App\Modules\AI\Models\AiKbChunk;
use App\Modules\Integrations\Services\CredentialResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Vector storage and similarity search.
 *
 * Uses Qdrant when enabled in the system integration configuration; otherwise falls back to
 * MySQL JSON storage with in-PHP cosine similarity (suitable for ~50k chunks).
 */
class EmbeddingStore
{
    private const QDRANT_COLLECTION = 'kb_chunks';

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    public function storeEmbedding(AiKbChunk $chunk, array $embedding): void
    {
        // Always persist to MySQL so chunks remain queryable without Qdrant
        $chunk->update(['embedding' => json_encode($embedding)]);

        if ($this->qdrantEnabled()) {
            $this->qdrantUpsert($chunk, $embedding);
        }
    }

    /** Remove every vector belonging to a document before it is re-indexed or deleted. */
    public function deleteDocumentEmbeddings(int $documentId): void
    {
        if (! $this->qdrantEnabled()) {
            return;
        }

        try {
            $payload = [
                'filter' => [
                    'must' => [['key' => 'document_id', 'match' => ['value' => $documentId]]],
                ],
                'wait' => true,
            ];
            $response = $this->qdrantClient()->post('/collections/'.self::QDRANT_COLLECTION.'/points/delete?wait=true', $payload);
            if ($response->status() === 400 && str_contains((string) $response->json('status.error'), 'Index required but not found')) {
                $this->ensurePayloadIndexes();
                $response = $this->qdrantClient()->post('/collections/'.self::QDRANT_COLLECTION.'/points/delete?wait=true', $payload);
            }

            if ($response->status() === 404) {
                return;
            }

            if (! $response->successful()) {
                throw new \RuntimeException('Qdrant delete failed (HTTP '.$response->status().'): '.$response->body());
            }
        } catch (\Throwable $e) {
            // Do not leave the MySQL document in a state that appears indexed when
            // the vector store could not be cleaned up. The caller can retry.
            Log::warning('Qdrant document-vector delete failed', [
                'document_id' => $documentId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /** @param array<int,int> $chunkIds */
    public function deleteChunkEmbeddings(array $chunkIds): void
    {
        $chunkIds = array_values(array_unique(array_filter(array_map('intval', $chunkIds))));
        if ($chunkIds === [] || ! $this->qdrantEnabled()) {
            return;
        }

        try {
            $response = $this->qdrantClient()->post('/collections/'.self::QDRANT_COLLECTION.'/points/delete?wait=true', [
                'points' => $chunkIds,
            ]);
            if ($response->status() !== 404 && ! $response->successful()) {
                throw new \RuntimeException('Qdrant chunk delete failed (HTTP '.$response->status().'): '.$response->body());
            }
        } catch (\Throwable $e) {
            Log::warning('Qdrant chunk-vector cleanup failed', [
                'chunk_ids' => $chunkIds,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** Find top-k most similar chunks to the query embedding. */
    public function search(int $kbId, array $queryEmbedding, int $topK = 5, ?int $revisionId = null): array
    {
        if ($this->qdrantEnabled()) {
            $results = $this->qdrantSearch($kbId, $queryEmbedding, $topK, $revisionId);
            if (! empty($results)) {
                return $results;
            }
            // Fall through to MySQL if Qdrant returns nothing (e.g. collection empty)
        }

        return $this->mysqlSearch($kbId, $queryEmbedding, $topK, $revisionId);
    }

    /**
     * Similarity of a stored chunk embedding to a query embedding (0 when unavailable).
     *
     * @param  array<int,float|int>  $queryEmbedding
     */
    public function similarity(AiKbChunk $chunk, array $queryEmbedding): float
    {
        return $this->cosine($queryEmbedding, $this->unpackEmbedding((string) ($chunk->embedding ?? '')));
    }

    /**
     * Add lexical candidates to vector retrieval without crossing KB, revision,
     * source-publication, or active-index-generation boundaries.
     *
     * @param  array<int,string>  $terms
     * @return array<int,array{chunk:AiKbChunk,score:float}>
     */
    public function lexicalSearch(int $kbId, array $terms, int $topK = 20, ?int $revisionId = null): array
    {
        $terms = array_values(array_unique(array_filter(array_map(
            fn (string $term): string => mb_substr(trim($term), 0, 80),
            $terms,
        ), fn (string $term): bool => mb_strlen($term) >= 2)));
        if ($terms === []) {
            return [];
        }

        $query = $this->eligibleChunks(
            AiKbChunk::query()->with('document')->where('kb_id', $kbId),
            $revisionId,
        );
        $query->where(function ($builder) use ($terms): void {
            foreach (array_slice($terms, 0, 8) as $term) {
                $builder->orWhere('content', 'like', '%'.addcslashes($term, '%_\\').'%');
            }
        });

        return $query->limit(200)->get()->map(function (AiKbChunk $chunk) use ($terms): array {
            $haystack = mb_strtolower(implode(' ', [
                (string) $chunk->section_label,
                (string) $chunk->document?->title,
                $chunk->content,
            ]));
            $matched = collect($terms)->filter(fn (string $term): bool => str_contains($haystack, mb_strtolower($term)))->count();

            return ['chunk' => $chunk, 'score' => $matched / max(1, count($terms))];
        })->sortByDesc('score')->take($topK)->values()->all();
    }

    // -------------------------------------------------------------------------
    // Qdrant
    // -------------------------------------------------------------------------

    private function qdrantEnabled(): bool
    {
        return $this->qdrantCredentials() !== null;
    }

    private function qdrantClient(): PendingRequest
    {
        $credentials = $this->qdrantCredentials();
        if ($credentials === null) {
            throw new \RuntimeException('Qdrant is not configured or enabled.');
        }

        $client = Http::baseUrl(rtrim((string) $credentials['url'], '/'))
            ->timeout(10)
            // Callers handle HTTP status codes, including an absent collection.
            // Transport failures still throw after retries are exhausted.
            ->retry(2, 300, throw: false);

        $apiKey = $credentials['api_key'] ?? null;
        if ($apiKey) {
            $client = $client->withHeaders(['api-key' => $apiKey]);
        }

        return $client;
    }

    /** @return array{url: string, api_key?: string|null}|null */
    private function qdrantCredentials(): ?array
    {
        $credentials = CredentialResolver::system()->qdrant()?->toArray();

        return filled($credentials['url'] ?? null) ? $credentials : null;
    }

    private function qdrantUpsert(AiKbChunk $chunk, array $embedding): void
    {
        try {
            $this->ensureQdrantCollection(count($embedding));

            $response = $this->qdrantClient()->put('/collections/'.self::QDRANT_COLLECTION.'/points', [
                'points' => [[
                    'id' => $chunk->id,
                    'vector' => $embedding,
                    'payload' => [
                        'kb_id' => $chunk->kb_id,
                        'document_id' => $chunk->document_id,
                        'chunk_id' => $chunk->id,
                        'revision_id' => $chunk->revision_id,
                    ],
                ]],
            ]);

            if (! $response->successful()) {
                throw new \RuntimeException('Qdrant upsert failed (HTTP '.$response->status().'): '.$response->body());
            }
        } catch (\Throwable $e) {
            Log::warning('Qdrant upsert failed, embedding stored in MySQL only', ['error' => $e->getMessage()]);
        }
    }

    private function qdrantSearch(int $kbId, array $queryEmbedding, int $topK, ?int $revisionId = null): array
    {
        try {
            $must = [['key' => 'kb_id', 'match' => ['value' => $kbId]]];
            $resp = $this->qdrantClient()->post('/collections/'.self::QDRANT_COLLECTION.'/points/search', [
                'vector' => $queryEmbedding,
                'limit' => $revisionId !== null ? max(30, $topK) : $topK,
                'filter' => [
                    'must' => $must,
                ],
                'with_payload' => true,
            ]);

            if (! $resp->successful()) {
                return [];
            }

            $chunkIds = array_column($resp->json('result', []), 'id');
            if (empty($chunkIds)) {
                return [];
            }

            $chunks = $this->eligibleChunks(AiKbChunk::whereIn('id', $chunkIds), $revisionId)->get()->keyBy('id');
            $results = [];
            foreach ($resp->json('result', []) as $hit) {
                $chunk = $chunks->get($hit['id']);
                if ($chunk) {
                    $results[] = ['chunk' => $chunk, 'score' => $hit['score']];
                }
            }

            return $results;
        } catch (\Throwable $e) {
            Log::warning('Qdrant search failed, falling back to MySQL', ['error' => $e->getMessage()]);

            return [];
        }
    }

    private function ensureQdrantCollection(int $dimensions): void
    {
        $client = $this->qdrantClient();
        $check = $client->get('/collections/'.self::QDRANT_COLLECTION);
        if ($check->successful()) {
            $existingDimensions = $check->json('result.config.params.vectors.size');
            if (is_numeric($existingDimensions) && (int) $existingDimensions !== $dimensions) {
                throw new \RuntimeException(
                    'Qdrant collection dimension mismatch: expected '.(int) $existingDimensions.', received '.$dimensions.'. Re-index the knowledge base with one embedding model.'
                );
            }

            $this->ensurePayloadIndexes($check->json('result.payload_schema', []));

            return;
        }

        if ($check->status() !== 404) {
            throw new \RuntimeException('Qdrant collection check failed (HTTP '.$check->status().'): '.$check->body());
        }

        $created = $client->put('/collections/'.self::QDRANT_COLLECTION, [
            'vectors' => ['size' => $dimensions, 'distance' => 'Cosine'],
        ]);

        if (! $created->successful()) {
            throw new \RuntimeException('Qdrant collection creation failed (HTTP '.$created->status().'): '.$created->body());
        }
        $this->ensurePayloadIndexes();
    }

    /**
     * Qdrant Cloud strict mode requires indexed fields for filtered operations.
     *
     * @param  array<string,array<string,mixed>>  $schema
     */
    private function ensurePayloadIndexes(array $schema = []): void
    {
        foreach (['document_id', 'kb_id'] as $field) {
            if (($schema[$field]['data_type'] ?? null) === 'integer') {
                continue;
            }
            $response = $this->qdrantClient()->put('/collections/'.self::QDRANT_COLLECTION.'/index?wait=true', [
                'field_name' => $field,
                'field_schema' => 'integer',
            ]);
            if (! $response->successful()) {
                throw new \RuntimeException('Qdrant payload index creation failed (HTTP '.$response->status().').');
            }
        }
    }

    // -------------------------------------------------------------------------
    // MySQL fallback
    // -------------------------------------------------------------------------

    private function mysqlSearch(int $kbId, array $queryEmbedding, int $topK, ?int $revisionId = null): array
    {
        $chunks = $this->eligibleChunks(
            AiKbChunk::where('kb_id', $kbId)->whereNotNull('embedding'),
            $revisionId,
        )->get();

        return $chunks->map(function (AiKbChunk $chunk) use ($queryEmbedding) {
            return [
                'chunk' => $chunk,
                'score' => $this->cosine($queryEmbedding, $this->unpackEmbedding($chunk->embedding ?? '')),
            ];
        })->sortByDesc('score')->take($topK)->values()->toArray();
    }

    /**
     * @param  Builder<AiKbChunk>  $query
     * @return Builder<AiKbChunk>
     */
    private function eligibleChunks(Builder $query, ?int $revisionId): Builder
    {
        return $query
            ->where('embedding_status', 'ready')
            ->when($revisionId !== null, fn ($builder) => $builder->whereHas('document.revisions', fn ($revisions) => $revisions->where('ai_kb_revisions.id', $revisionId)))
            ->whereHas('document', fn ($documents) => $documents
                ->where('enabled', true)
                ->whereColumn('ai_kb_documents.active_index_generation', 'ai_kb_chunks.index_generation')
                ->when($revisionId === null, fn ($builder) => $builder->where('publication_status', 'published'))
                ->whereIn('review_status', ['auto_approved', 'approved'])
                ->where('status', 'indexed'));
    }

    private function unpackEmbedding(string $json): array
    {
        if (empty($json)) {
            return [];
        }
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function cosine(array $a, array $b): float
    {
        if (count($a) !== count($b) || empty($a)) {
            return 0.0;
        }
        $dot = $na = $nb = 0.0;
        foreach ($a as $i => $v) {
            $dot += $v * $b[$i];
            $na += $v * $v;
            $nb += $b[$i] * $b[$i];
        }
        $denom = sqrt($na) * sqrt($nb);

        return $denom > 0 ? $dot / $denom : 0.0;
    }
}
