<?php

namespace Tests\Feature\Api\V1;

use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class MobileRateLimitTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config(['rate_limits.mobile_api_per_minute' => 300]);
    }

    private function requestForUser(int $id, string $ip = '203.0.113.10'): Request
    {
        $user = new User;
        $user->id = $id;
        $request = Request::create('/api/v1/mobile/conversations', 'GET', server: ['REMOTE_ADDR' => $ip]);
        $request->setUserResolver(fn () => $user);

        return $request;
    }

    private function hit(Request $request, string $limiter = 'mobile-api'): Response
    {
        return app(ThrottleRequests::class)->handle($request, fn () => response()->json(['ok' => true]), $limiter);
    }

    public function test_mobile_allows_300_requests_then_returns_a_structured_429_and_recovers(): void
    {
        $request = $this->requestForUser(1);
        for ($attempt = 0; $attempt < 300; $attempt++) {
            $response = $this->hit($request);
            $this->assertSame(200, $response->getStatusCode());
        }

        $this->assertSame('300', $response->headers->get('X-RateLimit-Limit'));
        $this->assertSame('0', $response->headers->get('X-RateLimit-Remaining'));
        try {
            $this->hit($request);
            $this->fail('Expected mobile throttling after 300 requests.');
        } catch (HttpResponseException $exception) {
            $response = $exception->getResponse();
            $data = json_decode($response->getContent(), true);
            $this->assertSame(429, $response->getStatusCode());
            $this->assertSame('mobile_api_rate_limited', $data['code']);
            $this->assertGreaterThan(0, $data['retry_after']);
            $this->assertSame($data['retry_after'], (int) $response->headers->get('Retry-After'));
        }

        $this->travel(61)->seconds();
        $this->assertSame(200, $this->hit($request)->getStatusCode());
    }

    public function test_mobile_budget_is_separate_from_the_unchanged_generic_api_budget(): void
    {
        $request = $this->requestForUser(2);
        for ($attempt = 0; $attempt < 120; $attempt++) {
            $this->hit($request, 'api');
        }
        try {
            $this->hit($request, 'api');
            $this->fail('Expected generic API throttling after 120 requests.');
        } catch (ThrottleRequestsException $exception) {
            $this->assertSame(429, $exception->getStatusCode());
        }

        $this->assertSame(200, $this->hit($request)->getStatusCode());
    }

    public function test_devices_share_a_user_budget_but_users_on_the_same_ip_are_isolated(): void
    {
        config(['rate_limits.mobile_api_per_minute' => 1]);
        $this->hit($this->requestForUser(3));
        $this->assertSame(200, $this->hit($this->requestForUser(4))->getStatusCode());

        $this->expectException(HttpResponseException::class);
        $this->hit($this->requestForUser(3, '203.0.113.11'));
    }

    public function test_operator_configuration_changes_the_allowance_and_invalid_values_stay_bounded(): void
    {
        $limiter = RateLimiter::limiter('mobile-api');
        config(['rate_limits.mobile_api_per_minute' => 120]);
        $this->assertSame(120, $limiter($this->requestForUser(5))->maxAttempts);
        config(['rate_limits.mobile_api_per_minute' => 0]);
        $this->assertSame(1, $limiter($this->requestForUser(5))->maxAttempts);
    }

    public function test_mobile_routes_use_the_new_limiter_without_weakening_existing_guards(): void
    {
        $routes = Route::getRoutes();
        foreach ($routes as $route) {
            $uri = $route->uri();
            if (str_starts_with($uri, 'api/v1/mobile/') || in_array($uri, [
                'api/v1/auth/me', 'api/v1/auth/logout', 'api/v1/auth/profile', 'api/v1/broadcasting/auth',
            ], true)) {
                $middleware = $route->gatherMiddleware();
                $this->assertContains('auth:sanctum', $middleware, $uri);
                $this->assertContains('throttle:mobile-api', $middleware, $uri);
                $this->assertNotContains('throttle:api', $middleware, $uri);
            }
            if ($uri === 'api/v1/auth/login') {
                $this->assertContains('throttle:mobile-login', $route->gatherMiddleware());
            }
            if ($uri === 'api/v1/mobile/social/comments/{comment}/reply') {
                $this->assertContains('throttle:60,1', $route->gatherMiddleware());
            }
            if ($uri === 'api/v1/me') {
                $this->assertContains('throttle:api', $route->gatherMiddleware());
            }
        }
    }
}
