<?php

// CLI-only release smoke check; isolated cache, no customer records or sends.
if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

function check(bool $condition, string $label): void
{
    if (! $condition) {
        throw new RuntimeException($label);
    }
    fwrite(STDOUT, "PASS: {$label}\n");
}

function mobileRequest(int $id, string $ip = '203.0.113.10'): Illuminate\Http\Request
{
    $user = new App\Models\User;
    $user->id = $id;
    $request = Illuminate\Http\Request::create('/api/v1/mobile/conversations', 'GET', server: ['REMOTE_ADDR' => $ip]);
    $request->setUserResolver(fn () => $user);

    return $request;
}

$mobile = Illuminate\Support\Facades\RateLimiter::limiter('mobile-api');
$generic = Illuminate\Support\Facades\RateLimiter::limiter('api');
check($mobile !== null, 'mobile limiter is registered');
check($mobile(mobileRequest(1))->maxAttempts === 300, 'effective production mobile allowance is 300');
check($generic(mobileRequest(1))->maxAttempts === 120, 'upstream generic allowance remains 120');

$cache = new Illuminate\Cache\Repository(new Illuminate\Cache\ArrayStore);
$isolated = new Illuminate\Cache\RateLimiter($cache);
$isolated->for('mobile-api', $mobile);
$isolated->for('api', $generic);
$middleware = new Illuminate\Routing\Middleware\ThrottleRequests($isolated);
$next = fn () => response()->json(['ok' => true]);
$request = mobileRequest(1);
for ($i = 0; $i < 300; $i++) {
    $response = $middleware->handle($request, $next, 'mobile-api');
    if ($response->getStatusCode() !== 200) {
        throw new RuntimeException('mobile request blocked before boundary');
    }
}
check(true, '300 mobile requests pass using isolated in-memory cache');
try {
    $middleware->handle(mobileRequest(1, '203.0.113.11'), $next, 'mobile-api');
    throw new RuntimeException('301st request was not throttled');
} catch (Illuminate\Http\Exceptions\HttpResponseException $error) {
    $response = $error->getResponse();
    $data = json_decode($response->getContent(), true);
    check($response->getStatusCode() === 429 && $data['code'] === 'mobile_api_rate_limited', '301st request gets structured 429; devices share user budget');
    check($data['retry_after'] > 0 && $data['retry_after'] === (int) $response->headers->get('Retry-After'), 'retry delay and response header agree');
}
check($middleware->handle(mobileRequest(2), $next, 'mobile-api')->getStatusCode() === 200, 'another user on the same IP remains independent');
check($middleware->handle($request, $next, 'api')->getStatusCode() === 200, 'generic and mobile budgets are isolated');
config(['rate_limits.mobile_api_per_minute' => 120]);
check($mobile($request)->maxAttempts === 120, 'operator configuration is applied');
config(['rate_limits.mobile_api_per_minute' => 0]);
check($mobile($request)->maxAttempts === 1, 'invalid low configuration stays bounded');

$checked = 0;
foreach (Illuminate\Support\Facades\Route::getRoutes() as $route) {
    if (str_starts_with($route->uri(), 'api/v1/mobile/')) {
        $guards = $route->gatherMiddleware();
        if (! in_array('throttle:mobile-api', $guards, true) || ! in_array('auth:sanctum', $guards, true)
            || ! in_array('mobile.request_log', $guards, true) || in_array('throttle:api', $guards, true)) {
            throw new RuntimeException('incorrect mobile route guards: '.$route->uri());
        }
        $checked++;
    }
}
check($checked > 0, "{$checked} mobile routes preserve authentication/diagnostics and use isolated limiter");
fwrite(STDOUT, "RELEASE SMOKE CHECK PASSED\n");
