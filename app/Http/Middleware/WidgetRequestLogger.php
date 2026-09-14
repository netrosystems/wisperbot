<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Symfony\Component\HttpFoundation\Response;

class WidgetRequestLogger
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->enabled() || ! ($request->is('widget/v1*') || $request->is('widgets/chat/*'))) {
            return $next($request);
        }

        $startedAt = microtime(true);
        $response = $next($request);

        $this->writeEntry($request, $response, $startedAt);

        return $response;
    }

    private function enabled(): bool
    {
        return filter_var(env('WIDGET_REQUEST_LOGGING', false), FILTER_VALIDATE_BOOL);
    }

    private function writeEntry(Request $request, Response $response, float $startedAt): void
    {
        $limit = max(1, (int) env('WIDGET_REQUEST_LOG_LIMIT', 100));
        $path = storage_path('logs/widget-api-live.log');

        $entry = [
            'time' => now()->toIso8601String(),
            'method' => $request->method(),
            'path' => $this->safePath($request),
            'query' => $request->query() ? $this->safeQuery($request->query()) : null,
            'status' => $response->getStatusCode(),
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'widget_key_hash' => $this->hashValue($this->widgetKey($request)),
            'visitor_token_hash' => $this->hashValue($this->visitorToken($request)),
            'origin_host' => $this->hostOnly($request->headers->get('Origin')),
            'referer_host' => $this->hostOnly($request->headers->get('Referer')),
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
            // Diagnostic logging must never affect public widget traffic.
        }
    }

    private function safePath(Request $request): string
    {
        if ($request->is('widgets/chat/*')) {
            return '/widgets/chat/{key}.js';
        }

        return '/'.$request->path();
    }

    private function widgetKey(Request $request): ?string
    {
        if ($request->route('key')) {
            return (string) $request->route('key');
        }

        $key = $request->input('key', $request->query('key'));

        return is_string($key) && $key !== '' ? $key : null;
    }

    private function visitorToken(Request $request): ?string
    {
        $token = $request->bearerToken()
            ?: $request->headers->get('X-Widget-Token')
            ?: $request->input('token');

        return is_string($token) && $token !== '' ? $token : null;
    }

    private function hashValue(?string $value): ?string
    {
        return $value === null
            ? null
            : hash_hmac('sha256', $value, (string) config('app.key'));
    }

    private function hostOnly(?string $url): ?string
    {
        if (! is_string($url) || $url === '') {
            return null;
        }

        return parse_url($url, PHP_URL_HOST) ?: null;
    }

    /**
     * Preserve request-shape metadata, but exclude secrets and customer text.
     */
    private function safeQuery(array $query): array
    {
        $blocked = ['key', 'token', 'access_token', 'refresh_token', 'authorization', 'password', 'secret', 'message', 'body', 'q', 'search'];

        return collect($query)
            ->reject(fn ($value, string $key) => in_array(strtolower($key), $blocked, true))
            ->map(fn ($value) => is_scalar($value) ? $value : '[complex]')
            ->all();
    }
}
