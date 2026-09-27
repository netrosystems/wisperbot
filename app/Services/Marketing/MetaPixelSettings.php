<?php

namespace App\Services\Marketing;

use App\Modules\Integrations\Models\IntegrationConfig;

/**
 * Resolves the WisperBot marketing Meta Pixel (dataset) configuration.
 *
 * This is WisperBot's own advertising measurement for wisperbot.com, not a
 * customer-facing integration: it never runs for tenant workspaces.
 *
 * Super-admin configuration (IntegrationConfig `meta_pixel`) overrides .env,
 * including an explicit disabled state. The environment remains a fallback
 * for deployments that have not saved the integration yet.
 */
class MetaPixelSettings
{
    public const GRAPH_VERSION = 'v25.0';

    /** @var array{enabled: bool, pixel_id: string, access_token: string, test_event_code: string, domain_verification: string}|null */
    private ?array $resolved = null;

    /** The dataset ID rendered in browsers. Empty when the Pixel is off. */
    public function browserPixelId(): string
    {
        $config = $this->configuration();

        return $config['enabled'] ? $config['pixel_id'] : '';
    }

    /** Whether server-side Conversions API events can be sent. */
    public function serverEnabled(): bool
    {
        $config = $this->configuration();

        return $config['enabled'] && $config['pixel_id'] !== '' && $config['access_token'] !== '';
    }

    public function pixelId(): string
    {
        return $this->configuration()['pixel_id'];
    }

    /** Never render this value in a browser or log. */
    public function accessToken(): string
    {
        return $this->configuration()['access_token'];
    }

    /** Routes events to Events Manager → Test events instead of live reporting. */
    public function testEventCode(): string
    {
        return $this->configuration()['test_event_code'];
    }

    /** Content of the facebook-domain-verification meta tag, if configured. */
    public function domainVerification(): string
    {
        $config = $this->configuration();

        return $config['enabled'] ? $config['domain_verification'] : '';
    }

    public function eventsEndpoint(): string
    {
        return 'https://graph.facebook.com/'.self::GRAPH_VERSION.'/'.rawurlencode($this->pixelId()).'/events';
    }

    /**
     * @return array{enabled: bool, pixel_id: string, access_token: string, test_event_code: string, domain_verification: string}
     */
    private function configuration(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        try {
            $saved = IntegrationConfig::forProvider('meta_pixel');
            if ($saved) {
                $credentials = $saved->credentials ?? [];

                return $this->resolved = $this->normalize(
                    (bool) $saved->enabled,
                    $credentials['pixel_id'] ?? '',
                    $credentials['access_token'] ?? '',
                    $credentials['test_event_code'] ?? '',
                    $credentials['domain_verification'] ?? '',
                );
            }
        } catch (\Throwable) {
            // During install/migrations integration_configs may not exist yet.
        }

        $pixelId = (string) config('services.meta_pixel.pixel_id', '');

        return $this->resolved = $this->normalize(
            filled($pixelId),
            $pixelId,
            config('services.meta_pixel.access_token', ''),
            config('services.meta_pixel.test_event_code', ''),
            config('services.meta_pixel.domain_verification', ''),
        );
    }

    /**
     * @return array{enabled: bool, pixel_id: string, access_token: string, test_event_code: string, domain_verification: string}
     */
    private function normalize(bool $enabled, mixed $pixelId, mixed $token, mixed $testCode, mixed $domainVerification): array
    {
        $pixelId = trim((string) $pixelId);

        return [
            // A dataset ID is numeric. Anything else would be injected into
            // browser script and a Graph URL, so treat it as unconfigured.
            'enabled' => $enabled && preg_match('/^\d{5,20}$/', $pixelId) === 1,
            'pixel_id' => preg_match('/^\d{5,20}$/', $pixelId) === 1 ? $pixelId : '',
            'access_token' => trim((string) $token),
            'test_event_code' => preg_replace('/[^A-Za-z0-9_-]/', '', trim((string) $testCode)) ?? '',
            'domain_verification' => preg_replace('/[^A-Za-z0-9_-]/', '', trim((string) $domainVerification)) ?? '',
        ];
    }
}
