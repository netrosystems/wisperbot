<?php

namespace App\Modules\AI\Services;

class KnowledgeUrlGuard
{
    public function assertSafe(string $url, ?string $requiredHost = null): string
    {
        $url = trim($url);
        $parts = parse_url($url);
        if (! is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
            throw new \InvalidArgumentException('Knowledge sources must use a valid HTTPS URL.');
        }
        if (! empty($parts['user']) || ! empty($parts['pass'])) {
            throw new \InvalidArgumentException('URLs containing credentials are not allowed.');
        }
        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.local')) {
            throw new \InvalidArgumentException('Local or private knowledge-source hosts are not allowed.');
        }
        if ($requiredHost !== null && $host !== strtolower(rtrim($requiredHost, '.'))) {
            throw new \InvalidArgumentException('Sitemap pages must remain on the same host.');
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : $this->resolve($host);
        foreach ($ips as $ip) {
            $this->assertPublicIp($ip);
        }
        if ($ips === []) {
            throw new \InvalidArgumentException('The knowledge-source host could not be resolved.');
        }

        return $url;
    }

    public function assertPublicIp(?string $ip): void
    {
        if (! $ip || ! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            throw new \InvalidArgumentException('Local, private, or reserved network addresses are not allowed.');
        }
    }

    private function resolve(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];

        return array_values(array_unique(array_filter(array_map(
            fn (array $record) => $record['ip'] ?? $record['ipv6'] ?? null,
            $records,
        ))));
    }
}
