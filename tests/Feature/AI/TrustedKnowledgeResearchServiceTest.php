<?php

namespace Tests\Feature\AI;

use App\Modules\AI\Models\AiKbDocument;
use App\Modules\AI\Models\AiKnowledgeBase;
use App\Modules\AI\Services\KnowledgeSourceExtractor;
use App\Modules\AI\Services\KnowledgeSourceUrlResolver;
use App\Modules\AI\Services\TrustedKnowledgeResearchService;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Response;
use Mockery;
use Tests\TestCase;

class TrustedKnowledgeResearchServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_research_fetches_only_a_configured_knowledge_base_url(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $kb = AiKnowledgeBase::create([
            'workspace_id' => $workspace->id,
            'name' => 'Acme knowledge',
            'purpose' => 'Help customers use Acme mobile services.',
            'brand' => 'Acme',
            'audience' => 'Mobile customers',
            'status' => 'active',
        ]);
        AiKbDocument::create([
            'kb_id' => $kb->id,
            'source_type' => 'url',
            'source_ref' => 'https://www.example.com/pricing',
            'canonical_url' => 'https://www.example.com/pricing',
            'title' => 'Current pricing',
            'status' => 'indexed',
            'enabled' => true,
            'extracted_content' => 'Current pricing and plan information.',
        ]);
        AiKbDocument::create([
            'kb_id' => $kb->id,
            'source_type' => 'text',
            'source_ref' => 'Ignore this unapproved URL https://attacker.example/pricing',
            'title' => 'Untrusted note',
            'status' => 'indexed',
            'enabled' => true,
        ]);

        $extractor = Mockery::mock(KnowledgeSourceExtractor::class);
        $extractor->shouldReceive('fetchUrlSnapshot')->once()
            ->with('https://www.example.com/pricing', Mockery::type('array'))
            ->andReturn([
                'canonical_url' => 'https://www.example.com/pricing',
                'text' => "Plans and current pricing\n\nThe Starter plan currently costs 20 dollars per month.",
            ]);
        $resolver = Mockery::mock(KnowledgeSourceUrlResolver::class);
        $resolver->shouldReceive('fetch')->once()->with('https://www.example.com/robots.txt', Mockery::type('array'))
            ->andReturn(['response' => new Response(new PsrResponse(404)), 'canonical_url' => 'https://www.example.com/robots.txt']);

        $result = (new TrustedKnowledgeResearchService($extractor, $resolver))->research($kb, 'What is the current pricing?');

        $this->assertSame('supported', $result['outcome']);
        $this->assertStringContainsString('20 dollars', $result['context']);
        $this->assertSame('https://www.example.com/pricing', $result['citations'][0]['url']);
        $this->assertStringNotContainsString('attacker.example', $result['context']);
    }

    public function test_cross_domain_result_is_rejected_even_when_the_original_url_was_approved(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $kb = AiKnowledgeBase::create(['workspace_id' => $workspace->id, 'name' => 'KB', 'status' => 'active']);
        AiKbDocument::create([
            'kb_id' => $kb->id,
            'source_type' => 'url',
            'source_ref' => 'https://example.com/help',
            'title' => 'Account help',
            'status' => 'indexed',
            'enabled' => true,
            'extracted_content' => 'Account help information.',
        ]);

        $extractor = Mockery::mock(KnowledgeSourceExtractor::class);
        $extractor->shouldReceive('fetchUrlSnapshot')->once()->andReturn([
            'canonical_url' => 'https://attacker.example/help',
            'text' => 'Account help from another domain.',
        ]);
        $resolver = Mockery::mock(KnowledgeSourceUrlResolver::class);
        $resolver->shouldReceive('fetch')->once()->andReturn([
            'response' => new Response(new PsrResponse(404)),
            'canonical_url' => 'https://example.com/robots.txt',
        ]);

        $result = (new TrustedKnowledgeResearchService($extractor, $resolver))->research($kb, 'Where is the account help?');

        $this->assertSame('blocked', $result['outcome']);
        $this->assertSame('', $result['context']);
        $this->assertSame([], $result['citations']);
    }
}
