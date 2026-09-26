<?php

namespace App\Jobs;

use App\Services\Marketing\MetaPixelSettings;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Delivers one already-hashed event to the Meta Conversions API.
 *
 * The payload contains no raw email or name. Meta de-duplicates by event_id,
 * so a retry after a timeout never double-counts a conversion.
 */
class SendMetaConversionEvent implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    /** @param array<string, mixed> $event */
    public function __construct(public readonly array $event)
    {
        $this->onQueue('default');
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(MetaPixelSettings $settings): void
    {
        if (! $settings->serverEnabled()) {
            return;
        }

        $body = array_filter([
            'data' => [$this->event],
            'test_event_code' => $settings->testEventCode() ?: null,
        ]);

        try {
            $response = Http::acceptJson()
                ->asJson()
                ->withToken($settings->accessToken())
                ->connectTimeout(5)
                ->timeout(15)
                ->withoutRedirecting()
                ->post($settings->eventsEndpoint(), $body);
        } catch (ConnectionException $e) {
            $this->release($this->backoff()[min($this->attempts() - 1, 1)]);

            return;
        }

        if ($response->successful()) {
            return;
        }

        $context = [
            'event_name' => $this->event['event_name'] ?? null,
            'event_id' => $this->event['event_id'] ?? null,
            'status' => $response->status(),
            'error' => $response->json('error.message'),
            'error_code' => $response->json('error.code'),
        ];

        // Throttling and Meta-side outages are worth retrying; a bad token,
        // missing permission or invalid payload will not fix itself.
        if ($response->status() === 429 || $response->serverError()) {
            Log::info('Meta Conversions API temporarily unavailable; retrying', $context);
            $this->release($this->backoff()[min($this->attempts() - 1, 1)]);

            return;
        }

        Log::warning('Meta Conversions API rejected an event', $context);
    }
}
