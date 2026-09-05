<?php

namespace Tests\Feature;

use App\Modules\AI\Models\AiKbChunk;
use App\Modules\AI\Models\AiKbDocument;
use App\Modules\AI\Models\AiKnowledgeBase;
use App\Modules\AI\Services\EmbeddingStore;
use App\Modules\Integrations\Models\IntegrationConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class QdrantIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_deleting_vectors_from_a_missing_collection_is_safe_to_repeat(): void
    {
        $this->configureQdrant();
        Http::fake(['*' => Http::response(['status' => ['error' => 'Collection does not exist']], 404)]);

        app(EmbeddingStore::class)->deleteDocumentEmbeddings(123);
        app(EmbeddingStore::class)->deleteDocumentEmbeddings(123);

        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request['filter']['must'][0]['match']['value'] === 123);
    }

    public function test_real_vector_deletion_failures_are_not_silently_ignored(): void
    {
        $this->configureQdrant();
        Http::fake(['*' => Http::response([], 503)]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Qdrant delete failed (HTTP 503)');
        app(EmbeddingStore::class)->deleteDocumentEmbeddings(123);
    }

    public function test_missing_collection_can_be_created(): void
    {
        $this->configureQdrant();
        Http::fake(fn (Request $request) => Http::response([], $request->method() === 'GET' ? 404 : 200));

        $method = new \ReflectionMethod(EmbeddingStore::class, 'ensureQdrantCollection');
        $method->invoke(app(EmbeddingStore::class), 3);

        Http::assertSent(fn (Request $request) => $request->method() === 'PUT'
            && $request->url() === 'https://qdrant.test/collections/kb_chunks'
            && $request['vectors']['size'] === 3);
    }

    private function configureQdrant(): void
    {
        IntegrationConfig::create([
            'provider' => 'qdrant', 'label' => 'Qdrant', 'mode' => 'live',
            'enabled' => true, 'credentials' => ['url' => 'https://qdrant.test', 'api_key' => 'test-key'],
        ]);
    }

    public function test_strict_mode_missing_indexes_are_created_before_retrying_cleanup(): void
    {
        $this->configureQdrant();
        $indexed = false;
        Http::fake(function (Request $request) use (&$indexed) {
            if ($request->method() === 'PUT') {
                $indexed = true;

                return Http::response(['status' => 'ok']);
            }

            return $indexed ? Http::response(['status' => 'ok'])
                : Http::response(['status' => ['error' => 'Index required but not found for document_id']], 400);
        });
        app(EmbeddingStore::class)->deleteDocumentEmbeddings(123);
        foreach (['document_id', 'kb_id'] as $field) {
            Http::assertSent(fn (Request $request) => $request->method() === 'PUT'
                && $request['field_name'] === $field && $request['field_schema'] === 'integer');
        }
    }

    public function test_runtime_uses_admin_qdrant_credentials_and_checks_writes(): void
    {
        IntegrationConfig::create([
            'provider' => 'qdrant',
            'label' => 'Qdrant',
            'mode' => 'live',
            'enabled' => true,
            'credentials' => ['url' => 'https://qdrant.test', 'api_key' => 'q-key'],
        ]);

        $workspace = $this->createWorkspaceContext()['workspace'];
        $kb = AiKnowledgeBase::create([
            'workspace_id' => $workspace->id,
            'name' => 'KB',
            'embedding_model' => 'gemini-embedding-2',
            'dimensions' => 3,
            'status' => 'active',
        ]);
        $document = AiKbDocument::create([
            'kb_id' => $kb->id,
            'title' => 'Doc',
            'source_type' => 'text',
            'source_ref' => 'Text',
            'status' => 'pending',
        ]);
        $chunk = AiKbChunk::create([
            'kb_id' => $kb->id,
            'document_id' => $document->id,
            'ord' => 0,
            'content' => 'Text',
            'tokens' => 1,
        ]);

        Http::fake(function (Request $request) {
            if ($request->method() === 'GET') {
                return Http::response(['result' => ['config' => ['params' => ['vectors' => ['size' => 3]]]]], 200);
            }

            return Http::response(['status' => 'ok'], 200);
        });

        app(EmbeddingStore::class)->storeEmbedding($chunk, [0.1, 0.2, 0.3]);

        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://qdrant.test/')
            && $request->header('api-key')[0] === 'q-key');
        Http::assertSent(fn (Request $request) => $request->method() === 'PUT'
            && str_ends_with($request->url(), '/collections/kb_chunks/points'));
    }
}
