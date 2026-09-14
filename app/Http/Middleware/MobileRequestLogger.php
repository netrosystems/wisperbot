<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Symfony\Component\HttpFoundation\Response;

class MobileRequestLogger
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->enabled() || ! $request->is('api/v1/mobile*')) {
            return $next($request);
        }

        $startedAt = microtime(true);
        $response = $next($request);

        $this->writeEntry($request, $response, $startedAt);

        return $response;
    }

    private function enabled(): bool
    {
        return filter_var(env('MOBILE_REQUEST_LOGGING', false), FILTER_VALIDATE_BOOL);
    }

    private function writeEntry(Request $request, Response $response, float $startedAt): void
    {
        $limit = max(1, (int) env('MOBILE_REQUEST_LOG_LIMIT', 100));
        $path = storage_path('logs/mobile-api-live.log');

        $entry = [
            'time' => now()->toIso8601String(),
            'method' => $request->method(),
            'path' => '/'.$request->path(),
            'query' => $request->query() ? $this->safeQuery($request->query()) : null,
            'status' => $response->getStatusCode(),
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'user_id' => $request->user()?->id,
            'workspace_id' => $request->user()?->current_workspace_id ?? $request->user()?->workspace_id,
            'ip_hash' => hash_hmac('sha256', (string) $request->ip(), (string) config('app.key')),
            'request_id' => $request->headers->get('X-Request-Id') ?: null,
        ];

        $line = json_encode($entry, JSON_UNESCAPED_SLASHES).PHP_EOL;

        try {
            File::ensureDirectoryExists(dirname($path));
            File::append($path, $line);

            $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            if (count($lines) > $limit) {
                File::put($path, implode(PHP_EOL, array_slice($lines, -$limit)).PHP_EOL);
            }
        } catch (\Throwable) {
            // Request logging is diagnostic only and must never affect mobile APIs.
        }
    }

    /**
     * Keep routing/debug metadata while excluding possible secrets or customer text.
     */
    private function safeQuery(array $query): array
    {
        $blocked = ['token', 'access_token', 'refresh_token', 'authorization', 'password', 'secret', 'message', 'body', 'q', 'search'];

        return collect($query)
            ->reject(fn ($value, string $key) => in_array(strtolower($key), $blocked, true))
            ->map(fn ($value) => is_scalar($value) ? $value : '[complex]')
            ->all();
    }
}
