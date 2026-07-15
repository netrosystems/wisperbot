<?php

namespace App\Modules\Ecommerce\Jobs;

use App\Modules\Ecommerce\Models\EcommerceStore;
use App\Modules\Ecommerce\Services\Clients\StoreClientFactory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class RegisterStoreWebhooksJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public array $backoff = [30, 120, 300];

    public function __construct(public readonly int $storeId) {}

    public function handle(): void
    {
        $store = EcommerceStore::find($this->storeId);
        if (! $store) {
            return;
        }

        $callbackUrl = EcommerceStore::webhookUrlFor($store);
        $result = StoreClientFactory::for($store)->registerWebhooks($callbackUrl);

        if ($result['ok']) {
            $store->update(['external_meta' => array_merge($store->external_meta ?? [], ['webhooks_registered' => true])]);
        } else {
            $store->update(['external_meta' => array_merge($store->external_meta ?? [], [
                'webhooks_registered' => false,
                'webhook_registration_error' => $result['message'],
            ])]);
            Log::warning('ecommerce.webhook.register_failed', [
                'store' => $store->id,
                'message' => $result['message'],
            ]);

            // A logged warning is not enough: throwing lets the queue retry and
            // prevents a failed subscription from becoming a permanent silent
            // integration outage.
            throw new \RuntimeException('Store webhook registration failed: '.$result['message']);
        }
    }
}
