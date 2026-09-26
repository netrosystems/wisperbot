<?php

namespace App\Services\Marketing;

use App\Jobs\SendMetaConversionEvent;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Server-side Meta Conversions API events for WisperBot's own marketing funnel.
 *
 * Every public method is fire-and-forget: measurement must never block or
 * break sign-up, checkout, contact or billing. Personal data is hashed here,
 * before anything is queued, and events are sent only with recorded consent.
 */
class MetaConversions
{
    /** @var array<string, true> Event IDs already queued in this request or job. */
    private array $queued = [];

    public function __construct(private readonly MetaPixelSettings $settings) {}

    /**
     * Store the visitor's current attribution on the account so later
     * webhook-driven events (first payment) keep consent and click context.
     */
    public function remember(User $user, Request $request): MetaAttribution
    {
        $attribution = MetaAttribution::fromRequest($request);

        $this->guard(function () use ($user, $attribution) {
            $user->forceFill(['marketing_attribution' => $attribution->toArray()])->saveQuietly();
        });

        return $attribution;
    }

    /** A new customer account (not an invited teammate) was created. */
    public function registered(User $user, Request $request, string $method): void
    {
        $this->guard(function () use ($user, $request, $method) {
            $attribution = $this->remember($user, $request);

            $this->send('CompleteRegistration', $attribution, $user, [
                'content_name' => 'WisperBot account',
                'status' => true,
                'registration_method' => $method,
            ], 'registration_'.$user->getKey());
        });
    }

    public function checkoutStarted(User $user, Request $request, Plan $plan, string $cycle, string $gateway): void
    {
        $this->guard(function () use ($user, $request, $plan, $cycle, $gateway) {
            $attribution = $this->remember($user, $request);

            $this->send('InitiateCheckout', $attribution, $user, [
                ...$this->planData($plan, $cycle),
                'num_items' => 1,
                'payment_gateway' => $gateway,
            ], 'checkout_'.Str::uuid()->toString());
        });
    }

    /**
     * First paid subscription or trial. Renewals, free plans and manual
     * admin grants are not acquisition conversions and are ignored.
     */
    public function subscriptionStarted(User $user, Subscription $subscription, Plan $plan): void
    {
        $this->guard(function () use ($user, $subscription, $plan) {
            if (in_array($subscription->gateway, ['free', 'manual'], true) || $plan->isFree()) {
                return;
            }

            // The Stripe return URL runs in the customer's browser; webhooks do not.
            $request = request();
            $attribution = $request->user()?->is($user)
                ? $this->remember($user, $request)
                : MetaAttribution::fromArray($user->marketing_attribution);

            $cycle = $subscription->billing_cycle === 'year' ? 'year' : 'month';
            $trial = $subscription->status === 'trialing';

            $this->send($trial ? 'StartTrial' : 'Subscribe', $attribution, $user, [
                ...$this->planData($plan, $cycle),
                ...($trial ? ['value' => 0, 'predicted_ltv' => $this->amount($plan, $cycle)] : []),
                'subscription_id' => 'wb_sub_'.$subscription->getKey(),
            ], 'subscription_'.$subscription->getKey());
        });
    }

    /**
     * Public contact form. The browser Pixel fires the same event with the
     * same ID, so Meta counts it once.
     */
    public function lead(Request $request, string $email, ?string $name, ?string $eventId): void
    {
        $this->guard(function () use ($request, $email, $name, $eventId) {
            $eventId = is_string($eventId) && preg_match('/^[A-Za-z0-9_-]{8,64}$/', $eventId) === 1
                ? $eventId
                : 'lead_'.Str::uuid()->toString();

            $this->send('Lead', MetaAttribution::fromRequest($request), null, [
                'content_name' => 'Contact form',
                'lead_source' => 'contact_page',
            ], $eventId, $email, $name);
        });
    }

    /**
     * @param  array<string, mixed>  $customData
     */
    private function send(string $eventName, MetaAttribution $attribution, ?User $user, array $customData, string $eventId, ?string $email = null, ?string $name = null): void
    {
        // Listener discovery can register the same listener twice; one
        // conversion must still produce one queued event.
        if (! $this->settings->serverEnabled() || ! $attribution->consented || isset($this->queued[$eventId])) {
            return;
        }
        $this->queued[$eventId] = true;

        $email ??= $user?->email;
        $name ??= $user?->name;
        [$firstName, $lastName] = $this->splitName($name);

        $userData = array_filter([
            'em' => $email ? [$this->hash(mb_strtolower(trim($email)))] : null,
            'fn' => $firstName ? [$this->hash($firstName)] : null,
            'ln' => $lastName ? [$this->hash($lastName)] : null,
            'external_id' => $user ? [$this->hash('wisperbot_user_'.$user->getKey())] : null,
            'client_ip_address' => $attribution->clientIp,
            'client_user_agent' => $attribution->userAgent,
            'fbp' => $attribution->fbp,
            'fbc' => $attribution->fbc,
        ], static fn ($value) => $value !== null && $value !== '');

        $event = array_filter([
            'event_name' => $eventName,
            'event_time' => now()->getTimestamp(),
            'event_id' => $eventId,
            'action_source' => 'website',
            'event_source_url' => $attribution->sourceUrl ?? url('/'),
            'user_data' => $userData,
            'custom_data' => array_filter($customData, static fn ($value) => $value !== null && $value !== ''),
        ], static fn ($value) => $value !== []);

        SendMetaConversionEvent::dispatch($event);
    }

    /** @return array<string, mixed> */
    private function planData(Plan $plan, string $cycle): array
    {
        return [
            'value' => $this->amount($plan, $cycle),
            'currency' => strtoupper((string) ($plan->currency_code ?: 'USD')),
            'content_ids' => ['plan_'.$plan->getKey()],
            'content_type' => 'product',
            'content_name' => $plan->name,
            'content_category' => 'subscription_'.$cycle,
        ];
    }

    private function amount(Plan $plan, string $cycle): float
    {
        return round(($plan->priceCentsForCycle($cycle) ?? 0) / 100, 2);
    }

    /** @return array{0: ?string, 1: ?string} */
    private function splitName(?string $name): array
    {
        $parts = preg_split('/\s+/u', trim((string) $name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $normalize = static function (?string $part): ?string {
            $value = mb_strtolower(preg_replace('/[^\p{L}]/u', '', (string) $part) ?? '');

            return $value === '' ? null : $value;
        };

        // A lone value is usually an email-derived fallback, not a real name.
        if (count($parts) < 2) {
            return [null, null];
        }

        return [$normalize($parts[0]), $normalize($parts[count($parts) - 1])];
    }

    private function hash(string $value): string
    {
        return hash('sha256', $value);
    }

    private function guard(callable $callback): void
    {
        try {
            $callback();
        } catch (\Throwable $e) {
            Log::warning('Meta conversion event skipped', ['exception' => $e::class, 'message' => $e->getMessage()]);
        }
    }
}
