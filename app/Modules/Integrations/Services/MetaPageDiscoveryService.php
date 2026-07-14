<?php

namespace App\Modules\Integrations\Services;

use Illuminate\Support\Facades\Http;

/**
 * Discovers every Facebook Page available to a user token.
 *
 * Meta exposes classic/direct Page roles through /me/accounts, while Pages
 * assigned through a Business Portfolio can be absent from that connection.
 * Business-owned and client Pages therefore have to be enumerated separately.
 */
class MetaPageDiscoveryService
{
    private const GRAPH_BASE = 'https://graph.facebook.com/v25.0';

    private const MAX_PAGES_PER_CONNECTION = 50;

    /**
     * @return array{
     *     pages: list<array<string, mixed>>,
     *     errors: list<array{source: string, message: string}>,
     *     successful_sources: list<string>
     * }
     */
    public function discover(string $accessToken, string $fields): array
    {
        /** @var array<string, array<string, mixed>> $pagesById */
        $pagesById = [];
        $errors = [];
        $successfulSources = [];

        $classic = $this->fetchConnection('/me/accounts', $accessToken, [
            'fields' => $fields,
            'limit' => 100,
        ]);

        $this->recordResult('/me/accounts', $classic, $pagesById, $errors, $successfulSources);

        $businesses = $this->fetchConnection('/me/businesses', $accessToken, [
            'fields' => 'id,name',
            'limit' => 100,
        ]);

        if (! $businesses['ok']) {
            $errors[] = [
                'source' => '/me/businesses',
                'message' => $businesses['error'],
            ];
        } else {
            $successfulSources[] = '/me/businesses';

            foreach ($businesses['data'] as $business) {
                $businessId = (string) ($business['id'] ?? '');
                if ($businessId === '') {
                    continue;
                }

                foreach (['owned_pages', 'client_pages'] as $edge) {
                    $source = '/'.$businessId.'/'.$edge;
                    $result = $this->fetchConnection($source, $accessToken, [
                        'fields' => $fields,
                        'limit' => 100,
                    ]);

                    $this->recordResult($source, $result, $pagesById, $errors, $successfulSources);
                }
            }
        }

        return [
            'pages' => array_values($pagesById),
            'errors' => $errors,
            'successful_sources' => array_values(array_unique($successfulSources)),
        ];
    }

    /**
     * @param  array{ok: bool, data: list<array<string, mixed>>, error: string}  $result
     * @param  array<string, array<string, mixed>>  $pagesById
     * @param  list<array{source: string, message: string}>  $errors
     * @param  list<string>  $successfulSources
     */
    private function recordResult(
        string $source,
        array $result,
        array &$pagesById,
        array &$errors,
        array &$successfulSources,
    ): void {
        // Keep any pages returned before a later pagination request failed. A
        // transient error on page 2 must not hide valid results from page 1.
        foreach ($result['data'] as $page) {
            $pageId = (string) ($page['id'] ?? '');
            if ($pageId === '') {
                continue;
            }

            // Do not let a sparse duplicate erase a token/name returned by another
            // connection. Business edges commonly return richer Page data than
            // /me/accounts, but either source may be the one carrying the token.
            if (! isset($pagesById[$pageId])) {
                $pagesById[$pageId] = [];
            }

            foreach ($page as $field => $value) {
                if ($value !== null && $value !== '') {
                    $pagesById[$pageId][$field] = $value;
                }
            }
        }

        if (! $result['ok']) {
            $errors[] = ['source' => $source, 'message' => $result['error']];

            return;
        }

        $successfulSources[] = $source;
    }

    /**
     * @param  array<string, int|string>  $params
     * @return array{ok: bool, data: list<array<string, mixed>>, error: string}
     */
    private function fetchConnection(string $path, string $accessToken, array $params): array
    {
        $items = [];
        $seenCursors = [];

        try {
            for ($page = 0; $page < self::MAX_PAGES_PER_CONNECTION; $page++) {
                $response = Http::withToken($accessToken)
                    ->acceptJson()
                    ->timeout(15)
                    ->get(self::GRAPH_BASE.$path, $params);

                $graphError = $response->json('error');
                if (! $response->successful() || is_array($graphError)) {
                    return [
                        'ok' => false,
                        'data' => $items,
                        'error' => $this->errorMessage($response->json(), $response->status()),
                    ];
                }

                $data = $response->json('data', []);
                if (is_array($data)) {
                    foreach ($data as $item) {
                        if (is_array($item)) {
                            $items[] = $item;
                        }
                    }
                }

                $next = $response->json('paging.next');
                $after = $response->json('paging.cursors.after');
                if (! is_string($next) || $next === '' || ! is_string($after) || $after === '') {
                    break;
                }

                if (isset($seenCursors[$after])) {
                    break;
                }

                $seenCursors[$after] = true;
                $params['after'] = $after;
            }
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'data' => $items,
                'error' => 'Meta Graph API request failed: '.mb_substr($e->getMessage(), 0, 240),
            ];
        }

        return ['ok' => true, 'data' => $items, 'error' => ''];
    }

    /** @param array<string, mixed>|null $payload */
    private function errorMessage(?array $payload, int $status): string
    {
        $message = $payload['error']['message'] ?? null;

        return is_string($message) && $message !== ''
            ? mb_substr($message, 0, 300)
            : "Meta Graph API returned HTTP {$status}.";
    }
}
