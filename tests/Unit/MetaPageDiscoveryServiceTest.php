<?php

namespace Tests\Unit;

use App\Modules\Integrations\Services\MetaPageDiscoveryService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MetaPageDiscoveryServiceTest extends TestCase
{
    public function test_discovers_business_portfolio_pages_when_me_accounts_is_empty(): void
    {
        Http::fake(function (Request $request) {
            return match (true) {
                str_contains($request->url(), '/me/accounts') => Http::response(['data' => []]),
                str_contains($request->url(), '/me/businesses') => Http::response(['data' => [
                    ['id' => '900', 'name' => 'Main Portfolio'],
                ]]),
                str_contains($request->url(), '/900/owned_pages') => Http::response(['data' => [
                    ['id' => '101', 'name' => 'Owned Page', 'access_token' => 'page-token-101'],
                ]]),
                str_contains($request->url(), '/900/client_pages') => Http::response(['data' => [
                    ['id' => '102', 'name' => 'Client Page', 'access_token' => 'page-token-102'],
                ]]),
                default => Http::response(['error' => ['message' => 'Unexpected URL']], 500),
            };
        });

        $result = (new MetaPageDiscoveryService)->discover('user-token', 'id,name,access_token');

        $this->assertSame(['101', '102'], array_column($result['pages'], 'id'));
        $this->assertSame(['page-token-101', 'page-token-102'], array_column($result['pages'], 'access_token'));
        $this->assertEmpty($result['errors']);
        $this->assertContains('/900/owned_pages', $result['successful_sources']);
        $this->assertContains('/900/client_pages', $result['successful_sources']);
    }

    public function test_paginates_and_deduplicates_pages_without_losing_page_tokens(): void
    {
        Http::fake(function (Request $request) {
            $query = [];
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            if (str_contains($request->url(), '/me/accounts')) {
                if (($query['after'] ?? null) === 'CLASSIC_NEXT') {
                    return Http::response(['data' => [
                        ['id' => '202', 'name' => 'Second Classic Page', 'access_token' => 'classic-202'],
                    ]]);
                }

                return Http::response([
                    'data' => [['id' => '201', 'name' => 'Classic Name', 'access_token' => 'classic-201']],
                    'paging' => [
                        'next' => 'https://graph.facebook.com/v25.0/me/accounts?after=CLASSIC_NEXT',
                        'cursors' => ['after' => 'CLASSIC_NEXT'],
                    ],
                ]);
            }

            return match (true) {
                str_contains($request->url(), '/me/businesses') => Http::response(['data' => [
                    ['id' => '901', 'name' => 'Portfolio'],
                ]]),
                str_contains($request->url(), '/901/owned_pages') => Http::response(['data' => [
                    // Same Page as /me/accounts, but the business edge supplies richer data.
                    ['id' => '201', 'name' => 'Business Name', 'picture' => ['data' => ['url' => 'https://example.test/page.jpg']]],
                    ['id' => '203', 'name' => 'Business Page', 'access_token' => 'business-203'],
                ]]),
                str_contains($request->url(), '/901/client_pages') => Http::response(['data' => []]),
                default => Http::response(['error' => ['message' => 'Unexpected URL']], 500),
            };
        });

        $result = (new MetaPageDiscoveryService)->discover('user-token', 'id,name,access_token,picture');
        $byId = collect($result['pages'])->keyBy('id');

        $this->assertCount(3, $result['pages']);
        $this->assertSame('classic-201', $byId['201']['access_token']);
        $this->assertSame('Business Name', $byId['201']['name']);
        $this->assertSame('https://example.test/page.jpg', $byId['201']['picture']['data']['url']);
        $this->assertSame('classic-202', $byId['202']['access_token']);
        $this->assertSame('business-203', $byId['203']['access_token']);

        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/me/accounts')
            && str_contains($request->url(), 'after=CLASSIC_NEXT')
        );
    }

    public function test_business_permission_failure_does_not_break_classic_page_discovery(): void
    {
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/me/accounts')) {
                return Http::response(['data' => [
                    ['id' => '301', 'name' => 'Classic Page', 'access_token' => 'classic-token'],
                ]]);
            }

            if (str_contains($request->url(), '/me/businesses')) {
                return Http::response([
                    'error' => ['message' => '(#100) Missing Permission'],
                ], 403);
            }

            return Http::response(['error' => ['message' => 'Unexpected URL']], 500);
        });

        $result = (new MetaPageDiscoveryService)->discover('user-token', 'id,name,access_token');

        $this->assertSame(['301'], array_column($result['pages'], 'id'));
        $this->assertCount(1, $result['errors']);
        $this->assertSame('/me/businesses', $result['errors'][0]['source']);
        $this->assertSame('(#100) Missing Permission', $result['errors'][0]['message']);
        $this->assertContains('/me/accounts', $result['successful_sources']);
    }

    public function test_keeps_results_from_earlier_pages_when_later_pagination_fails(): void
    {
        Http::fake(function (Request $request) {
            $query = [];
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            if (str_contains($request->url(), '/me/accounts')) {
                if (($query['after'] ?? null) === 'FAIL_NEXT') {
                    return Http::response(['error' => ['message' => 'Temporary Graph failure']], 500);
                }

                return Http::response([
                    'data' => [['id' => '401', 'name' => 'First Page', 'access_token' => 'token-401']],
                    'paging' => [
                        'next' => 'https://graph.facebook.com/v25.0/me/accounts?after=FAIL_NEXT',
                        'cursors' => ['after' => 'FAIL_NEXT'],
                    ],
                ]);
            }

            if (str_contains($request->url(), '/me/businesses')) {
                return Http::response(['data' => []]);
            }

            return Http::response(['error' => ['message' => 'Unexpected URL']], 500);
        });

        $result = (new MetaPageDiscoveryService)->discover('user-token', 'id,name,access_token');

        $this->assertSame(['401'], array_column($result['pages'], 'id'));
        $this->assertSame('/me/accounts', $result['errors'][0]['source']);
        $this->assertSame('Temporary Graph failure', $result['errors'][0]['message']);
    }
}
