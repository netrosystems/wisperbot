<?php

namespace App\Services\Billing;

use App\Contracts\AddonBillingGatewayInterface;
use App\Contracts\BillingGatewayInterface;
use App\Events\SubscriptionRenewed;
use App\Events\SubscriptionStarted;
use App\Models\ClientAddonSubscription;
use App\Models\PaymentTransaction;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\AddonEntitlementService;
use App\Services\WebhookIdempotencyService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class PaddleGateway implements AddonBillingGatewayInterface, BillingGatewayInterface
{
    public function __construct(
        private string $apiKey,
        private string $environment,
        private string $successUrl,
        private string $cancelUrl,
        private string $webhookSecret
    ) {}

    public function name(): string
    {
        return 'Paddle';
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== '';
    }

    protected function baseUrl(): string
    {
        return $this->environment === 'sandbox'
            ? 'https://sandbox-api.paddle.com'
            : 'https://api.paddle.com';
    }

    public function createCheckout(User $user, Plan $plan, string $billingCycle): array
    {
        if (! $this->isConfigured()) {
            return ['error' => 'Paddle is not configured.'];
        }

        $priceCents = $plan->priceCentsForCycle($billingCycle);
        if ($priceCents === null || $priceCents <= 0) {
            return ['error' => 'Plan has no price for this billing cycle.'];
        }

        // Paddle Billing requires a catalog price_id to create a recurring subscription;
        // inline ad-hoc prices are not accepted by the /transactions endpoint.
        $priceId = $billingCycle === 'year' ? $plan->paddle_yearly_id : $plan->paddle_monthly_id;
        if (! $priceId) {
            return ['error' => "This plan has no Paddle price configured for the '{$billingCycle}' billing cycle."];
        }

        $res = Http::withToken($this->apiKey)
            ->post($this->baseUrl().'/transactions', [
                'items' => [
                    [
                        'price_id' => $priceId,
                        'quantity' => 1,
                    ],
                ],
                'custom_data' => [
                    'user_id' => (string) $user->id,
                    'plan_id' => (string) $plan->id,
                    'billing_cycle' => $billingCycle,
                ],
            ]);

        if (! $res->successful()) {
            Log::error('Paddle create transaction failed', ['body' => $res->json(), 'user_id' => $user->id]);

            return ['error' => $res->json('error.detail', 'Paddle request failed.')];
        }

        $checkoutUrl = $res->json('data.checkout.url');
        if (! $checkoutUrl) {
            return ['error' => 'No checkout URL in Paddle response.'];
        }

        return ['url' => $checkoutUrl, 'transaction_id' => $res->json('data.id')];
    }

    public function createAddonCheckout(User $user, array $addon): array
    {
        if (! $this->isConfigured() || ! $user->client_id) {
            return ['error' => 'Paddle is not configured or the client account is missing.'];
        }

        $priceId = $addon['paddle_price_id'] ?? null;
        if (! $priceId) {
            return ['error' => 'The Paddle price ID for Developer Tools has not been configured.'];
        }

        $res = Http::withToken($this->apiKey)
            ->post($this->baseUrl().'/transactions', [
                'items' => [['price_id' => $priceId, 'quantity' => 1]],
                'custom_data' => [
                    'purchase_type' => 'addon',
                    'addon_key' => $addon['key'],
                    'client_id' => (string) $user->client_id,
                    'user_id' => (string) $user->id,
                ],
            ]);

        if (! $res->successful()) {
            return ['error' => $res->json('error.detail', 'Paddle add-on checkout failed.')];
        }

        $checkoutUrl = $res->json('data.checkout.url');
        if (! $checkoutUrl) {
            return ['error' => 'No Paddle checkout URL was returned.'];
        }

        return ['url' => $checkoutUrl, 'session_id' => $res->json('data.id')];
    }

    public function handleWebhook(Request $request): Response
    {
        $payload = $request->getContent();
        $data = json_decode($payload, true);
        $eventType = $data['event_type'] ?? '';
        $eventId = $data['event_id'] ?? ($data['notification_id'] ?? ($data['id'] ?? null));

        Log::info('Paddle webhook received', ['event_type' => $eventType]);

        if ($this->webhookSecret) {
            $sig = $request->header('Paddle-Signature');
            if (! $this->verifyPaddleSignature($payload, $sig)) {
                Log::warning('Paddle webhook signature invalid');

                return new Response('Invalid signature', 400);
            }
        } elseif (app()->environment('production')) {
            Log::warning('Paddle webhook secret not configured in production');

            return new Response('Webhook secret not configured', 401);
        }

        // Idempotency guard
        if ($eventId && ! app(WebhookIdempotencyService::class)->isNewEvent('paddle', $eventId)) {
            return new Response('OK', 200);
        }

        try {
            match ($eventType) {
                'subscription.created', 'subscription.activated' => $this->handleSubscriptionActivated($data),
                'subscription.updated' => $this->handleSubscriptionUpdated($data),
                'subscription.canceled' => $this->handleSubscriptionCanceled($data),
                'transaction.completed' => $this->handleTransactionCompleted($data),
                default => null,
            };
        } catch (\Throwable $e) {
            Log::error('Paddle webhook handler failed', ['type' => $eventType, 'error' => $e->getMessage()]);

            if ($eventId) {
                app(WebhookIdempotencyService::class)->release('paddle', (string) $eventId);
            }

            return new Response('Handler error', 500);
        }

        return new Response('OK', 200);
    }

    private function verifyPaddleSignature(string $payload, ?string $signature): bool
    {
        if (! $signature) {
            return false;
        }
        $parts = explode(';', $signature);
        $ts = null;
        $signatures = [];
        foreach ($parts as $part) {
            if (str_starts_with($part, 'ts=')) {
                $ts = substr($part, 3);
            }
            if (str_starts_with($part, 'h1=')) {
                $signatures[] = substr($part, 3);
            }
        }
        if (! $ts || $signatures === [] || ! ctype_digit((string) $ts)) {
            return false;
        }

        // Reject replayed signatures. Paddle recommends a short tolerance while
        // allowing minor clock skew between systems.
        if (abs(time() - (int) $ts) > 300) {
            return false;
        }
        $signed = $ts.':'.$payload;
        $expected = hash_hmac('sha256', $signed, $this->webhookSecret);

        return collect($signatures)->contains(fn (string $signature) => hash_equals($expected, $signature));
    }

    private function handleSubscriptionActivated(array $data): void
    {
        $resource = $data['data'] ?? [];
        $customData = $resource['custom_data'] ?? [];
        if (($customData['purchase_type'] ?? null) === 'addon') {
            $clientId = (int) ($customData['client_id'] ?? 0);
            $userId = (int) ($customData['user_id'] ?? 0);
            $addonKey = (string) ($customData['addon_key'] ?? '');
            $subId = (string) ($resource['id'] ?? '');
            if ($clientId && $userId && $addonKey && $subId) {
                app(AddonEntitlementService::class)->activate(
                    $clientId,
                    $addonKey,
                    $userId,
                    'paddle',
                    $subId,
                    isset($resource['next_billed_at']) ? Carbon::parse($resource['next_billed_at']) : null,
                    ['paddle_status' => $resource['status'] ?? 'active']
                );
            }

            return;
        }

        $userId = (int) ($customData['user_id'] ?? 0);
        $planId = (int) ($customData['plan_id'] ?? 0);
        $billingCycle = $customData['billing_cycle'] ?? 'month';
        $subId = $resource['id'] ?? '';
        if (! $userId || ! $planId || ! $subId) {
            return;
        }

        $isNew = ! Subscription::where('gateway', 'paddle')
            ->where('gateway_subscription_id', $subId)
            ->exists();

        $subscription = Subscription::updateOrCreate(
            [
                'user_id' => $userId,
                'gateway' => 'paddle',
                'gateway_subscription_id' => $subId,
            ],
            [
                'plan_id' => $planId,
                'billing_cycle' => $billingCycle,
                // Honour the real Paddle status (e.g. trialing) instead of hard-coding active.
                'status' => $this->mapStatus($resource['status'] ?? 'active'),
                'starts_at' => now(),
                'ends_at' => null,
                'renews_at' => isset($resource['next_billed_at']) ? Carbon::parse($resource['next_billed_at']) : null,
            ]
        );

        if ($isNew) {
            $user = User::find($userId);
            $plan = Plan::find($planId);
            if ($user && $plan) {
                SubscriptionStarted::dispatch($user, $subscription, $plan);
            }
        }
    }

    /** Keep local status and renewal date in sync when Paddle updates a subscription. */
    private function handleSubscriptionUpdated(array $data): void
    {
        $resource = $data['data'] ?? [];
        $subId = $resource['id'] ?? '';
        if (! $subId) {
            return;
        }

        if (app(AddonEntitlementService::class)->syncGatewayStatus(
            'paddle',
            $subId,
            $resource['status'] ?? 'active',
            isset($resource['next_billed_at']) ? Carbon::parse($resource['next_billed_at']) : null
        )) {
            return;
        }

        $subscription = Subscription::where('gateway', 'paddle')
            ->where('gateway_subscription_id', $subId)
            ->first();
        if (! $subscription) {
            return;
        }

        $subscription->update([
            'status' => $this->mapStatus($resource['status'] ?? $subscription->status),
            'renews_at' => isset($resource['next_billed_at'])
                ? Carbon::parse($resource['next_billed_at'])
                : $subscription->renews_at,
        ]);
    }

    private function handleSubscriptionCanceled(array $data): void
    {
        $subId = $data['data']['id'] ?? '';
        if ($subId && app(AddonEntitlementService::class)->syncGatewayStatus('paddle', $subId, 'cancelled', endsAt: now())) {
            return;
        }

        Subscription::where('gateway', 'paddle')
            ->where('gateway_subscription_id', $subId)
            ->update(['status' => 'canceled', 'ends_at' => now()]);
    }

    private function handleTransactionCompleted(array $data): void
    {
        $resource = $data['data'] ?? [];
        $transactionId = $resource['id'] ?? null;
        if (! $transactionId) {
            return;
        }

        $alreadyRecorded = PaymentTransaction::where('gateway', 'paddle')
            ->where('gateway_transaction_id', $transactionId)
            ->exists();
        if ($alreadyRecorded) {
            return;
        }

        // Resolve subscription and user from Paddle subscription_id
        $paddleSubId = $resource['subscription_id'] ?? null;
        $addonSubscription = $paddleSubId
            ? ClientAddonSubscription::where('gateway', 'paddle')->where('gateway_subscription_id', $paddleSubId)->first()
            : null;
        if ($addonSubscription) {
            $nextBilled = data_get($resource, 'billing_period.ends_at');
            app(AddonEntitlementService::class)->syncGatewayStatus(
                'paddle',
                $paddleSubId,
                'active',
                $nextBilled ? Carbon::parse($nextBilled) : null
            );
            PaymentTransaction::create([
                'user_id' => $addonSubscription->purchased_by_user_id,
                'subscription_id' => null,
                'gateway' => 'paddle',
                'gateway_transaction_id' => $transactionId,
                'amount_cents' => (int) ($resource['details']['totals']['grand_total'] ?? 0),
                'currency_code' => $resource['currency_code'] ?? 'USD',
                'status' => 'paid',
                'payload' => $resource,
            ]);

            return;
        }

        $subscription = $paddleSubId
            ? Subscription::where('gateway', 'paddle')->where('gateway_subscription_id', $paddleSubId)->with('user', 'plan')->first()
            : null;

        $amount = $resource['details']['totals']['grand_total'] ?? ($resource['details']['totals']['total'] ?? 0);
        $currency = $resource['details']['totals']['currency_code'] ?? 'USD';
        // Paddle totals are already expressed in the currency's minor unit.
        $amountCents = (int) $amount;

        // Paddle marks recurring renewal charges with origin 'subscription_recurring';
        // the very first charge is 'subscription_charge' / 'web' and is covered by SubscriptionStarted.
        $isRenewal = ($resource['origin'] ?? null) === 'subscription_recurring';

        $transaction = PaymentTransaction::create([
            'user_id' => $subscription?->user_id,
            'subscription_id' => $subscription?->id,
            'gateway' => 'paddle',
            'gateway_transaction_id' => $transactionId,
            'amount_cents' => $amountCents,
            'currency_code' => $currency,
            'status' => 'paid',
            'payload' => $resource,
        ]);

        $this->generateInvoicePdf($transaction);

        if ($isRenewal && $subscription && $subscription->user && $subscription->plan) {
            // Advance renews_at from the transaction's billing period when present.
            $nextBilled = data_get($resource, 'billing_period.ends_at');
            if ($nextBilled) {
                $subscription->update(['renews_at' => Carbon::parse($nextBilled)]);
                $subscription->refresh()->loadMissing('user', 'plan');
            }

            SubscriptionRenewed::dispatch(
                $subscription->user,
                $subscription,
                $subscription->plan,
                $amountCents,
                strtoupper($currency),
            );
        }
    }

    public function cancel(Subscription $subscription): bool
    {
        if ($subscription->gateway !== 'paddle' || ! $this->isConfigured()) {
            return false;
        }

        $res = Http::withToken($this->apiKey)
            ->post($this->baseUrl().'/subscriptions/'.$subscription->gateway_subscription_id.'/cancel');

        if ($res->successful()) {
            $subscription->update(['status' => 'canceled', 'ends_at' => now()]);

            return true;
        }

        Log::error('Paddle cancel failed', ['subscription_id' => $subscription->id, 'body' => $res->body()]);

        return false;
    }

    public function cancelAddon(ClientAddonSubscription $subscription): bool
    {
        if ($subscription->gateway !== 'paddle' || ! $this->isConfigured() || ! $subscription->gateway_subscription_id) {
            return false;
        }

        return Http::withToken($this->apiKey)
            ->post($this->baseUrl().'/subscriptions/'.$subscription->gateway_subscription_id.'/cancel')
            ->successful();
    }

    public function sync(Subscription $subscription): bool
    {
        if ($subscription->gateway !== 'paddle' || ! $this->isConfigured()) {
            return false;
        }

        $res = Http::withToken($this->apiKey)
            ->get($this->baseUrl().'/subscriptions/'.$subscription->gateway_subscription_id);

        if (! $res->successful()) {
            return false;
        }

        $data = $res->json('data', []);
        $subscription->update([
            'status' => $this->mapStatus($data['status'] ?? $subscription->status),
            'renews_at' => isset($data['next_billed_at']) ? Carbon::parse($data['next_billed_at']) : $subscription->renews_at,
        ]);

        return true;
    }

    /** Map Paddle subscription statuses to the app's canonical status vocabulary. */
    private function mapStatus(string $paddleStatus): string
    {
        return match (strtolower($paddleStatus)) {
            'active' => 'active',
            'trialing' => 'trialing',
            'past_due' => 'past_due',
            'paused' => 'past_due',
            'canceled', 'cancelled' => 'canceled',
            default => strtolower($paddleStatus),
        };
    }

    /** Best-effort PDF invoice generation; never let it break webhook processing. */
    private function generateInvoicePdf(PaymentTransaction $transaction): void
    {
        try {
            app(InvoiceService::class)->generate($transaction);
        } catch (\Throwable $e) {
            Log::warning('Paddle: invoice PDF generation failed', ['transaction_id' => $transaction->id, 'error' => $e->getMessage()]);
        }
    }

    public function fulfillCheckoutSession(string $sessionId): array
    {
        return ['ok' => false, 'error' => 'Paddle fulfillment is handled via webhook.'];
    }

    public function fulfillAddonCheckout(string $sessionId): array
    {
        return ['ok' => false, 'error' => 'Paddle add-on fulfillment is handled by webhook.'];
    }

    public function changePlan(Subscription $subscription, Plan $newPlan, string $billingCycle): array
    {
        if (! $this->isConfigured()) {
            return ['ok' => false, 'error' => 'Paddle is not configured.'];
        }

        $priceKey = $billingCycle === 'year' ? 'paddle_yearly_id' : 'paddle_monthly_id';
        $newPriceId = $newPlan->{$priceKey};

        if (! $newPriceId) {
            return ['ok' => false, 'error' => "New plan does not have a Paddle price configured for billing cycle '{$billingCycle}'."];
        }

        $baseUrl = $this->environment === 'production' ? 'https://api.paddle.com' : 'https://sandbox-api.paddle.com';

        $res = Http::withToken($this->apiKey)
            ->patch("{$baseUrl}/subscriptions/{$subscription->gateway_subscription_id}", [
                'items' => [['price_id' => $newPriceId, 'quantity' => 1]],
                'proration_billing_mode' => 'prorated_immediately',
            ]);

        if (! $res->successful()) {
            Log::error('Paddle changePlan failed', ['subscription_id' => $subscription->id, 'response' => $res->json()]);

            return ['ok' => false, 'error' => $res->json('error.detail') ?? 'Paddle plan change failed.'];
        }

        $subscription->update([
            'plan_id' => $newPlan->id,
            'billing_cycle' => $billingCycle,
            'status' => 'active',
        ]);

        return ['ok' => true, 'error' => null];
    }

    public function refund(PaymentTransaction $transaction, ?int $amountCents = null): array
    {
        if (! $this->isConfigured()) {
            return ['ok' => false, 'error' => 'Paddle is not configured.'];
        }

        $transactionId = $transaction->gateway_transaction_id ?? null;
        if (! $transactionId) {
            return ['ok' => false, 'error' => 'No gateway transaction ID for refund.'];
        }

        $baseUrl = $this->environment === 'production' ? 'https://api.paddle.com' : 'https://sandbox-api.paddle.com';

        if ($amountCents) {
            return ['ok' => false, 'error' => 'Paddle partial refunds require transaction line-item IDs and are not supported by this integration yet.'];
        }

        $res = Http::withToken($this->apiKey)
            ->post("{$baseUrl}/adjustments", [
                'action' => 'refund',
                'transaction_id' => $transactionId,
                'reason' => 'customer_request',
            ]);

        if (! $res->successful()) {
            Log::error('Paddle refund failed', ['transaction_id' => $transaction->id, 'response' => $res->json()]);

            return ['ok' => false, 'error' => $res->json('error.detail') ?? 'Paddle refund failed.'];
        }

        $transaction->update([
            'refunded_at' => now(),
            'refunded_cents' => $amountCents ?? $transaction->amount_cents,
            'status' => 'refunded',
        ]);

        return ['ok' => true, 'error' => null];
    }
}
