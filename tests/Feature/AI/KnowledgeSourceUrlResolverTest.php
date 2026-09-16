<?php

namespace Tests\Feature\AI;

use App\Modules\AI\Services\KnowledgeSourceUrlResolver;
use App\Modules\AI\Services\KnowledgeUrlResolutionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class KnowledgeSourceUrlResolverTest extends TestCase
{
    public function test_plain_domains_and_http_inputs_are_normalised_to_https(): void
    {
        $resolver = app(KnowledgeSourceUrlResolver::class);

        $this->assertSame('https://example.com', $resolver->normaliseInput(' example.com '));
        $this->assertSame('https://www.example.com/help?q=one', $resolver->normaliseInput('www.example.com/help?q=one#ignored'));
        $this->assertSame('https://example.com/path', $resolver->normaliseInput('http://EXAMPLE.com/path'));
    }

    public function test_direct_working_www_address_is_preserved(): void
    {
        Http::fake([
            'https://www.example.com' => Http::response('Ready', 200),
            '*' => Http::response('Missing', 404),
        ]);

        $result = app(KnowledgeSourceUrlResolver::class)->fetch('https://www.example.com');

        $this->assertSame('https://www.example.com', $result['canonical_url']);
        $this->assertFalse($result['host_adjusted']);
        Http::assertSentCount(1);
    }

    public function test_same_site_http_redirect_is_upgraded_without_an_http_request(): void
    {
        Http::fake([
            'https://example.com' => Http::response('', 301, ['Location' => 'http://www.example.com/']),
            'https://www.example.com/' => Http::response('Ready', 200),
        ]);

        $result = app(KnowledgeSourceUrlResolver::class)->fetch('example.com');

        $this->assertSame('https://www.example.com/', $result['canonical_url']);
        $this->assertTrue($result['host_adjusted']);
        Http::assertNotSent(fn ($request) => str_starts_with($request->url(), 'http://'));
    }

    public function test_working_www_variant_is_selected_when_apex_is_not_available(): void
    {
        Http::fake([
            'https://example.com/help' => Http::response('Missing', 404),
            'https://www.example.com/help' => Http::response('Ready', 200),
        ]);

        $result = app(KnowledgeSourceUrlResolver::class)->fetch('example.com/help');

        $this->assertSame('https://www.example.com/help', $result['canonical_url']);
    }

    public function test_redirect_loops_are_reported_distinctly(): void
    {
        Http::fake([
            'https://example.com' => Http::response('', 301, ['Location' => 'https://www.example.com']),
            'https://www.example.com' => Http::response('', 301, ['Location' => 'https://example.com']),
        ]);

        try {
            app(KnowledgeSourceUrlResolver::class)->fetch('example.com');
            $this->fail('Expected a redirect-loop exception.');
        } catch (KnowledgeUrlResolutionException $exception) {
            $this->assertSame('redirect_loop', $exception->reason);
        }
    }

    public function test_redirects_to_unrelated_domains_are_rejected(): void
    {
        Http::fake([
            'https://example.com' => Http::response('', 301, ['Location' => 'https://example.org']),
            'https://www.example.com' => Http::response('', 301, ['Location' => 'https://example.org']),
        ]);

        try {
            app(KnowledgeSourceUrlResolver::class)->fetch('example.com');
            $this->fail('Expected a cross-site redirect exception.');
        } catch (KnowledgeUrlResolutionException $exception) {
            $this->assertSame('cross_site_redirect', $exception->reason);
        }
    }
}
