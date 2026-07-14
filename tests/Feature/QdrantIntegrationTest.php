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
