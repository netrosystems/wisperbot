<?php

namespace App\Services\Marketing;

use Illuminate\Http\Request;

/**
 * Browser context Meta uses to match a server event to an ad click or visit:
 * the Pixel's first-party `_fbp`/`_fbc` cookies, client IP and user agent.
 *
 * A snapshot is stored on the user so events that arrive later without a
 * browser (payment webhooks) keep the same consent decision and matching
 * context as the visit that produced them.
 */
final class MetaAttribution
{
    private function __construct(
        public readonly bool $consented,
        public readonly ?string $fbp,
        public readonly ?string $fbc,
        public readonly ?string $clientIp,
        public readonly ?string $userAgent,
        public readonly ?string $sourceUrl,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $fbc = self::cookie($request, '_fbc');

        // The Pixel derives _fbc from ?fbclid= itself; this covers a click that
        // lands directly on a server-handled request before the Pixel has run.
        $fbclid = $request->query('fbclid');
        if ($fbc === null && is_string($fbclid) && preg_match('/^[A-Za-z0-9_-]{10,500}$/', $fbclid) === 1) {
            $fbc = 'fb.1.'.((int) floor(microtime(true) * 1000)).'.'.$fbclid;
        }

        $referer = $request->headers->get('referer');
        $sourceUrl = $request->isMethod('GET') ? $request->fullUrl() : (is_string($referer) ? $referer : $request->url());

        return new self(
            MarketingConsent::granted($request),
            self::cookie($request, '_fbp'),
            $fbc,
            $request->ip(),
            self::clip($request->userAgent(), 512),
            self::clip(self::stripQuery($sourceUrl), 1024),
        );
    }

    /** @param array<string, mixed>|null $data */
    public static function fromArray(?array $data): self
    {
        $data ??= [];

        return new self(
            ($data['consent'] ?? null) === MarketingConsent::GRANTED,
            self::string($data['fbp'] ?? null, 255),
            self::string($data['fbc'] ?? null, 600),
            self::string($data['client_ip'] ?? null, 64),
            self::string($data['user_agent'] ?? null, 512),
            self::string($data['source_url'] ?? null, 1024),
        );
    }

    /** @return array<string, string|null> */
    public function toArray(): array
    {
        return [
            'consent' => $this->consented ? MarketingConsent::GRANTED : MarketingConsent::DENIED,
            'fbp' => $this->fbp,
            'fbc' => $this->fbc,
            'client_ip' => $this->clientIp,
            'user_agent' => $this->userAgent,
            'source_url' => $this->sourceUrl,
            'captured_at' => now()->toIso8601String(),
        ];
    }

    private static function cookie(Request $request, string $name): ?string
    {
        $value = $request->cookie($name);

        return is_string($value) && preg_match('/^fb\.\d\.\d+\.[A-Za-z0-9_.-]{1,500}$/', $value) === 1 ? $value : null;
    }

    /** Query strings can carry emails or tokens; Meta needs only the page. */
    private static function stripQuery(string $url): string
    {
        return strtok($url, '?#') ?: $url;
    }

    private static function clip(?string $value, int $max): ?string
    {
        return $value === null || $value === '' ? null : mb_substr($value, 0, $max);
    }

    private static function string(mixed $value, int $max): ?string
    {
        return is_string($value) && $value !== '' ? mb_substr($value, 0, $max) : null;
    }
}
