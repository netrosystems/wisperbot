<?php

namespace App\Modules\AI\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class KnowledgeSourceUrlResolver
{
    public function __construct(private readonly KnowledgeUrlGuard $guard) {}

    /**
     * Convert the address a customer typed into a deterministic HTTPS URL.
     * Network resolution remains asynchronous in the indexing job.
     */
    public function normaliseInput(string $input): string
    {
        $input = trim($input);
        if ($input === '') {
            return '';
        }

        if (! preg_match('/^[a-z][a-z0-9+.-]*:\/\//i', $input)) {
            $input = 'https://'.$input;
        }

        $parts = parse_url($input);
        if (! is_array($parts) || empty($parts['host'])) {
            throw new \InvalidArgumentException('Enter a valid public website address.');
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new \InvalidArgumentException('Knowledge sources must use a web address.');
        }

        if (! empty($parts['user']) || ! empty($parts['pass'])) {
            throw new \InvalidArgumentException('URLs containing credentials are not allowed.');
        }

        $host = strtolower(rtrim((string) $parts['host'], '.'));
        if (function_exists('idn_to_ascii')) {
            $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if (is_string($ascii) && $ascii !== '') {
                $host = strtolower($ascii);
            }
        }

        $port = isset($parts['port']) && (int) $parts['port'] !== 443 ? ':'.(int) $parts['port'] : '';
        $path = (string) ($parts['path'] ?? '');
        $query = isset($parts['query']) && $parts['query'] !== '' ? '?'.$parts['query'] : '';

        return 'https://'.$host.$port.$path.$query;
    }

    /**
     * Fetch a customer website while resolving only public HTTPS endpoints and
     * the verified apex/www aliases of the submitted host.
     *
     * @param  array{accept?:string,user_agent?:string,connect_timeout?:int,timeout?:int,attempts?:int,max_redirects?:int}  $options
     * @return array{response:Response,input_url:string,canonical_url:string,redirects:array<int,string>,host_adjusted:bool}
     */
    public function fetch(string $input, array $options = []): array
    {
        $normalised = $this->normaliseInput($input);
        $this->guard->assertSafe($normalised);

        $candidates = [$normalised];
        $alternate = $this->alternateWwwUrl($normalised);
        if ($alternate !== null) {
            $candidates[] = $alternate;
        }

        $firstResponse = null;
        $firstFailure = null;
        foreach (array_values(array_unique($candidates)) as $candidate) {
            try {
                $result = $this->fetchCandidate($candidate, $normalised, $options);
                if ($result['response']->successful()) {
                    return $result;
                }
                $firstResponse ??= $result;
            } catch (KnowledgeUrlResolutionException $exception) {
                $firstFailure ??= $exception;
            }
        }

        if ($firstResponse !== null) {
            return $firstResponse;
        }

        throw $firstFailure ?? new KnowledgeUrlResolutionException(
            'unreachable',
            'The website could not be reached securely.',
        );
    }

    /**
     * @param  array{accept?:string,user_agent?:string,connect_timeout?:int,timeout?:int,attempts?:int,max_redirects?:int}  $options
     * @return array{response:Response,input_url:string,canonical_url:string,redirects:array<int,string>,host_adjusted:bool}
     */
    private function fetchCandidate(string $candidate, string $normalisedInput, array $options): array
    {
        $current = $this->guard->assertSafe($candidate);
        $allowedHosts = array_filter([
            strtolower((string) parse_url($normalisedInput, PHP_URL_HOST)),
            strtolower((string) parse_url($this->alternateWwwUrl($normalisedInput) ?? '', PHP_URL_HOST)),
        ]);
        $visited = [];
        $redirects = [];
        $maximum = max(1, min(10, (int) ($options['max_redirects'] ?? config('knowledge_base.url_max_redirects', 6))));

        for ($hop = 0; $hop <= $maximum; $hop++) {
            $key = strtolower($current);
            if (isset($visited[$key])) {
                throw new KnowledgeUrlResolutionException('redirect_loop', 'The website has a redirect loop.');
            }
            $visited[$key] = true;

            $connectedIp = null;
            try {
                $response = Http::withOptions([
                    'allow_redirects' => false,
                    'on_stats' => function ($stats) use (&$connectedIp): void {
                        $connectedIp = $stats->getHandlerStats()['primary_ip'] ?? null;
                    },
                ])->withHeaders([
                    'User-Agent' => $options['user_agent'] ?? 'WisperBotKnowledgeIndexer/2.0 (+https://wisperbot.com)',
                    'Accept' => $options['accept'] ?? 'text/html,application/xhtml+xml,application/xml,text/xml,text/plain;q=0.9,*/*;q=0.5',
                    'Accept-Language' => 'en,*;q=0.5',
                ])->retry((int) ($options['attempts'] ?? 1), 400, throw: false)
                    ->connectTimeout((int) ($options['connect_timeout'] ?? 5))
                    ->timeout((int) ($options['timeout'] ?? 12))
                    ->get($current);
            } catch (\Throwable $exception) {
                $reason = match (true) {
                    str_contains($exception->getMessage(), 'cURL error 28') => 'timeout',
                    str_contains($exception->getMessage(), 'cURL error 6') => 'dns',
                    str_contains($exception->getMessage(), 'cURL error 60') => 'certificate',
                    default => 'connection',
                };
                throw new KnowledgeUrlResolutionException($reason, 'The website connection failed.', $exception);
            }

            if ($connectedIp !== null) {
                $this->guard->assertPublicIp($connectedIp);
            }

            if (! in_array($response->status(), [301, 302, 303, 307, 308], true)) {
                return [
                    'response' => $response,
                    'input_url' => $normalisedInput,
                    'canonical_url' => $current,
                    'redirects' => $redirects,
                    'host_adjusted' => strtolower((string) parse_url($normalisedInput, PHP_URL_HOST))
                        !== strtolower((string) parse_url($current, PHP_URL_HOST)),
                ];
            }

            if ($hop === $maximum) {
                throw new KnowledgeUrlResolutionException('too_many_redirects', 'The website uses too many redirects.');
            }

            $location = trim((string) $response->header('Location'));
            if ($location === '') {
                throw new KnowledgeUrlResolutionException('invalid_redirect', 'The website returned an invalid redirect.');
            }

            $next = $this->resolveUrl($current, $location);
            $parts = parse_url($next);
            $nextHost = strtolower((string) ($parts['host'] ?? ''));
            if (! in_array($nextHost, $allowedHosts, true)) {
                throw new KnowledgeUrlResolutionException('cross_site_redirect', 'The website redirects to a different domain.');
            }

            // Never make an HTTP request. If a same-site server briefly sends
            // browsers through HTTP, verify the HTTPS equivalent directly.
            if (strtolower((string) ($parts['scheme'] ?? '')) === 'http') {
                $next = preg_replace('/^http:\/\//i', 'https://', $next) ?? $next;
            }
            $next = $this->normaliseInput($next);
            $next = $this->guard->assertSafe($next);
            $redirects[] = $next;
            $current = $next;
        }

        throw new KnowledgeUrlResolutionException('unreachable', 'The website could not be reached securely.');
    }

    private function alternateWwwUrl(string $url): ?string
    {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '' || filter_var($host, FILTER_VALIDATE_IP)) {
            return null;
        }

        $alternateHost = str_starts_with($host, 'www.') ? substr($host, 4) : 'www.'.$host;
        if ($alternateHost === '') {
            return null;
        }

        $port = isset($parts['port']) && (int) $parts['port'] !== 443 ? ':'.(int) $parts['port'] : '';
        $path = (string) ($parts['path'] ?? '');
        $query = isset($parts['query']) && $parts['query'] !== '' ? '?'.$parts['query'] : '';

        return 'https://'.$alternateHost.$port.$path.$query;
    }

    private function resolveUrl(string $base, string $location): string
    {
        if (preg_match('/^https?:\/\//i', $location)) {
            return $location;
        }
        if (str_starts_with($location, '//')) {
            return 'https:'.$location;
        }

        $parts = parse_url($base);
        $origin = 'https://'.($parts['host'] ?? '').(isset($parts['port']) ? ':'.$parts['port'] : '');
        if (str_starts_with($location, '/')) {
            return $origin.$location;
        }

        $path = (string) ($parts['path'] ?? '/');
        $directory = str_ends_with($path, '/') ? $path : dirname($path).'/';

        return $origin.'/'.ltrim($directory.$location, '/');
    }
}
