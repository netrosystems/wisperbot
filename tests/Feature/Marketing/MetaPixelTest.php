<?php

namespace Tests\Feature\Marketing;

use App\Contracts\BillingGatewayInterface;
use App\Events\SubscriptionStarted;
use App\Http\Middleware\SecureHeaders;
use App\Jobs\SendMetaConversionEvent;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Modules\Integrations\Models\IntegrationConfig;
use App\Modules\Integrations\Services\ConnectionTester;
use App\Services\Billing\BillingGatewayRegistry;
use App\Services\Marketing\MarketingConsent;
use App\Services\Marketing\MetaPixelSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MetaPixelTest extends TestCase
{
    use RefreshDatabase;

    private const PIXEL_ID = '1234567890123456';

    private function configurePixel(array $credentials = [], bool $enabled = true): void
    {
        IntegrationConfig::updateOrCreate(['provider' => 'meta_pixel', 'mode' => 'live'], [
            'label' => 'Meta Pixel',
            'enabled' => $enabled,
            'credentials' => array_merge([
                'pixel_id' => self::PIXEL_ID,
                'access_token' => 'capi-test-token',
            ], $credentials),
        ]);
        $this->app->forgetScopedInstances();
    }

    private function freePlan(): Plan
    {
        return Plan::factory()->create(['name' => 'Free', 'price_cents' => 0, 'monthly_price_cents' => 0, 'yearly_price_cents' => 0]);
    }

    private function register(): void
    {
        $this->post('/register', [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'agree_terms' => true,
            'cycle' => 'month',
        ])->assertSessionHasNoErrors()->assertRedirect();
    }

    /** @return array<string, mixed> */
    private function onlyQueuedEvent(): array
    {
        $events = [];
        Queue::assertPushed(SendMetaConversionEvent::class, function (SendMetaConversionEvent $job) use (&$events) {
            $events[] = $job->event;

            return true;
        });
        $this->assertCount(1, $events);

        return $events[0];
    }

    public function test_admin_configuration_overrides_env_and_disabled_means_off(): void
    {
        config(['services.meta_pixel.pixel_id' => '9999999999']);
        $this->assertSame('9999999999', app(MetaPixelSettings::class)->browserPixelId());

        $this->configurePixel();
        $this->assertSame(self::PIXEL_ID, app(MetaPixelSettings::class)->browserPixelId());
        $this->assertTrue(app(MetaPixelSettings::class)->serverEnabled());

        $this->configurePixel(enabled: false);
        $this->assertSame('', app(MetaPixelSettings::class)->browserPixelId());
        $this->assertFalse(app(MetaPixelSettings::class)->serverEnabled());
    }

    public function test_a_non_numeric_pixel_id_is_never_rendered(): void
    {
        $this->configurePixel(['pixel_id' => "123');alert(1);//"]);

        $this->assertSame('', app(MetaPixelSettings::class)->browserPixelId());
    }

    public function test_public_pages_share_the_pixel_but_never_the_token(): void
    {
        $this->configurePixel();

        $response = $this->get('/register')->assertOk();
        $props = $response->viewData('page')['props'];

        $this->assertSame(['pixel_id' => self::PIXEL_ID, 'enabled' => true], $props['metaPixel']);
        $this->assertStringNotContainsString('capi-test-token', $response->getContent());
    }

    public function test_workspace_pages_do_not_load_the_pixel(): void
    {
        $this->configurePixel();
        $user = User::factory()->create(['role' => 'client', 'email_verified_at' => now()]);

        $response = $this->actingAs($user)->get('/app/pricing');
        $props = $response->viewData('page')['props'] ?? null;

        if ($props !== null) {
            $this->assertFalse($props['metaPixel']['enabled']);
            $this->assertSame('', $props['metaPixel']['pixel_id']);
        } else {
            $response->assertRedirect();
        }
    }

    public function test_csp_allows_the_pixel_without_the_meta_messaging_app(): void
    {
        $this->configurePixel();

        $response = (new SecureHeaders)->handle(Request::create('/'), fn () => response('ok'));
        $csp = (string) $response->headers->get('Content-Security-Policy');

        $this->assertMatchesRegularExpression('/script-src [^;]*https:\/\/connect\.facebook\.net/', $csp);
        $this->assertMatchesRegularExpression('/connect-src [^;]*https:\/\/www\.facebook\.com/', $csp);
    }

    public function test_domain_verification_tag_is_rendered_when_configured(): void
    {
        $this->configurePixel(['domain_verification' => 'abc123verify']);

        $this->get('/register')->assertSee('<meta name="facebook-domain-verification" content="abc123verify">', false);
    }

    public function test_registration_with_consent_sends_hashed_complete_registration(): void
    {
        Queue::fake();
        $this->freePlan();
        $this->configurePixel();

        $this->withUnencryptedCookie(MarketingConsent::COOKIE, MarketingConsent::GRANTED)
            ->withUnencryptedCookie('_fbp', 'fb.1.1700000000000.123456789')
            ->withUnencryptedCookie('_fbc', 'fb.1.1700000000000.AbCdEfGhIjKl')
            ->register();

        $event = $this->onlyQueuedEvent();
        $user = User::where('email', 'ada@example.com')->firstOrFail();

        $this->assertSame('CompleteRegistration', $event['event_name']);
        $this->assertSame('registration_'.$user->id, $event['event_id']);
        $this->assertSame('website', $event['action_source']);
        $this->assertSame([hash('sha256', 'ada@example.com')], $event['user_data']['em']);
        $this->assertSame([hash('sha256', 'ada')], $event['user_data']['fn']);
        $this->assertSame([hash('sha256', 'lovelace')], $event['user_data']['ln']);
        $this->assertSame('fb.1.1700000000000.123456789', $event['user_data']['fbp']);
        $this->assertSame('fb.1.1700000000000.AbCdEfGhIjKl', $event['user_data']['fbc']);
        $this->assertStringNotContainsString('ada@example.com', json_encode($event));
        $this->assertSame(MarketingConsent::GRANTED, $user->marketing_attribution['consent']);
        $this->assertArrayNotHasKey('marketing_attribution', $user->toArray());
    }

    public function test_no_server_event_without_consent_or_token(): void
    {
        Queue::fake();
        $this->freePlan();
        $this->configurePixel();

        $this->register();
        Queue::assertNotPushed(SendMetaConversionEvent::class);

        auth()->logout();
        $this->withUnencryptedCookie(MarketingConsent::COOKIE, MarketingConsent::DENIED)
            ->post('/register', [
                'name' => 'Grace Hopper', 'email' => 'grace@example.com', 'password' => 'password',
                'password_confirmation' => 'password', 'agree_terms' => true, 'cycle' => 'month',
            ]);
        Queue::assertNotPushed(SendMetaConversionEvent::class);

        auth()->logout();
        $this->configurePixel(['access_token' => '']);
        $this->withUnencryptedCookie(MarketingConsent::COOKIE, MarketingConsent::GRANTED)
            ->post('/register', [
                'name' => 'Alan Turing', 'email' => 'alan@example.com', 'password' => 'password',
                'password_confirmation' => 'password', 'agree_terms' => true, 'cycle' => 'month',
            ]);
        Queue::assertNotPushed(SendMetaConversionEvent::class);
    }

    public function test_checkout_start_sends_initiate_checkout_with_plan_value(): void
    {
        Queue::fake();
        $this->configurePixel();
        $plan = Plan::factory()->create(['name' => 'Growth', 'price_cents' => 4900, 'monthly_price_cents' => 4900, 'currency_code' => 'usd']);
        $user = User::factory()->create(['role' => 'client', 'email_verified_at' => now()]);

        $gateway = \Mockery::mock(BillingGatewayInterface::class);
        $gateway->shouldReceive('isConfigured')->andReturn(true);
        $gateway->shouldReceive('createCheckout')->andReturn(['url' => 'https://checkout.example.test/session']);
        $this->mock(BillingGatewayRegistry::class)->shouldReceive('get')->andReturn($gateway);

        $this->actingAs($user)
            ->withUnencryptedCookie(MarketingConsent::COOKIE, MarketingConsent::GRANTED)
            ->post(route('client.checkout.store'), ['plan_id' => $plan->id, 'billing_cycle' => 'month', 'gateway' => 'stripe']);

        $event = $this->onlyQueuedEvent();
        $this->assertSame('InitiateCheckout', $event['event_name']);
        $this->assertSame(49.0, $event['custom_data']['value']);
        $this->assertSame('USD', $event['custom_data']['currency']);
        $this->assertSame(['plan_'.$plan->id], $event['custom_data']['content_ids']);
    }

    public function test_first_paid_subscription_is_reported_from_a_webhook_using_stored_consent(): void
    {
        Queue::fake();
        $this->configurePixel();
        $plan = Plan::factory()->create(['price_cents' => 2900, 'monthly_price_cents' => 2900, 'yearly_price_cents' => 29000, 'currency_code' => 'USD']);
        $user = User::factory()->create(['marketing_attribution' => ['consent' => 'granted', 'fbp' => 'fb.1.1700000000000.42', 'client_ip' => '203.0.113.9', 'user_agent' => 'UA']]);

        $subscription = Subscription::create(['user_id' => $user->id, 'plan_id' => $plan->id, 'gateway' => 'paddle', 'status' => 'active', 'billing_cycle' => 'year']);
        event(new SubscriptionStarted($user, $subscription, $plan));

        $event = $this->onlyQueuedEvent();
        $this->assertSame('Subscribe', $event['event_name']);
        $this->assertSame('subscription_'.$subscription->id, $event['event_id']);
        $this->assertSame(290.0, $event['custom_data']['value']);
        $this->assertSame('fb.1.1700000000000.42', $event['user_data']['fbp']);
        $this->assertSame('203.0.113.9', $event['user_data']['client_ip_address']);
    }

    public function test_trials_are_start_trial_and_free_or_manual_grants_are_ignored(): void
    {
        Queue::fake();
        $this->configurePixel();
        $plan = Plan::factory()->create(['price_cents' => 2900, 'monthly_price_cents' => 2900]);
        $user = User::factory()->create(['marketing_attribution' => ['consent' => 'granted']]);

        $manual = Subscription::create(['user_id' => $user->id, 'plan_id' => $plan->id, 'gateway' => 'manual', 'status' => 'active', 'billing_cycle' => 'month']);
        event(new SubscriptionStarted($user, $manual, $plan));
        Queue::assertNotPushed(SendMetaConversionEvent::class);

        $trial = Subscription::create(['user_id' => $user->id, 'plan_id' => $plan->id, 'gateway' => 'stripe', 'status' => 'trialing', 'billing_cycle' => 'month']);
        event(new SubscriptionStarted($user, $trial, $plan));

        $event = $this->onlyQueuedEvent();
        $this->assertSame('StartTrial', $event['event_name']);
        $this->assertSame(0, $event['custom_data']['value']);
        $this->assertSame(29.0, $event['custom_data']['predicted_ltv']);
    }

    public function test_declined_consent_blocks_webhook_conversions(): void
    {
        Queue::fake();
        $this->configurePixel();
        $plan = Plan::factory()->create(['price_cents' => 2900, 'monthly_price_cents' => 2900]);
        $user = User::factory()->create(['marketing_attribution' => ['consent' => 'denied']]);
        $subscription = Subscription::create(['user_id' => $user->id, 'plan_id' => $plan->id, 'gateway' => 'paypal', 'status' => 'active', 'billing_cycle' => 'month']);

        event(new SubscriptionStarted($user, $subscription, $plan));

        Queue::assertNotPushed(SendMetaConversionEvent::class);
    }

    public function test_contact_form_lead_shares_the_browser_event_id(): void
    {
        Queue::fake();
        $this->configurePixel();

        $this->withUnencryptedCookie(MarketingConsent::COOKIE, MarketingConsent::GRANTED)
            ->post('/contact', [
                'name' => 'Lin Chen', 'email' => 'lin@example.com', 'message' => 'Hello', 'meta_event_id' => 'lead_abc12345',
            ])->assertRedirect();

        $this->assertDatabaseHas('contact_messages', ['email' => 'lin@example.com']);
        $event = $this->onlyQueuedEvent();
        $this->assertSame('Lead', $event['event_name']);
        $this->assertSame('lead_abc12345', $event['event_id']);
        $this->assertSame([hash('sha256', 'lin@example.com')], $event['user_data']['em']);
    }

    public function test_job_posts_to_the_dataset_with_bearer_token_and_test_code(): void
    {
        $this->configurePixel(['test_event_code' => 'TEST123']);
        Http::preventStrayRequests();
        Http::fake(['graph.facebook.com/v25.0/'.self::PIXEL_ID.'/events' => Http::response(['events_received' => 1])]);

        (new SendMetaConversionEvent(['event_name' => 'Lead', 'event_id' => 'lead_1', 'event_time' => 1, 'action_source' => 'website']))
            ->handle(app(MetaPixelSettings::class));

        Http::assertSent(fn (HttpRequest $request) => $request->url() === 'https://graph.facebook.com/v25.0/'.self::PIXEL_ID.'/events'
            && $request->hasHeader('Authorization', 'Bearer capi-test-token')
            && $request['test_event_code'] === 'TEST123'
            && $request['data'][0]['event_id'] === 'lead_1'
            && ! str_contains($request->url(), 'access_token'));
    }

    public function test_connection_test_sends_a_test_event_only(): void
    {
        $this->configurePixel();
        Http::preventStrayRequests();
        Http::fake(['graph.facebook.com/v25.0/'.self::PIXEL_ID.'/events' => Http::response(['events_received' => 1])]);

        $result = app(ConnectionTester::class)->test(IntegrationConfig::forProvider('meta_pixel'));

        $this->assertTrue($result['ok']);
        // Always marked as a test event, so live reporting never sees it.
        Http::assertSent(fn (HttpRequest $request) => $request['test_event_code'] === 'TEST00000'
            && $request['data'][0]['event_name'] === 'WisperBotConnectionTest'
            && $request->hasHeader('Authorization', 'Bearer capi-test-token'));
    }

    public function test_connection_test_explains_a_token_without_permission(): void
    {
        $this->configurePixel();
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Missing Permission', 'code' => 100]], 400)]);

        $result = app(ConnectionTester::class)->test(IntegrationConfig::forProvider('meta_pixel'));

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('cannot send events to dataset '.self::PIXEL_ID, $result['message']);
    }

    public function test_admin_can_clear_the_test_event_code_while_blank_token_keeps_the_secret(): void
    {
        $this->configurePixel(['test_event_code' => 'TEST23108', 'domain_verification' => 'keepme']);
        $admin = $this->createSuperAdmin();

        $this->actingAs($admin, 'admin')
            ->putJson(route('admin.integrations.update', 'meta_pixel'), [
                'enabled' => true,
                'mode' => 'live',
                'credentials' => [
                    'pixel_id' => '••••••••••••',
                    'access_token' => '',
                    'test_event_code' => '',
                    'domain_verification' => '••••••••••••',
                ],
            ])
            ->assertSessionHasNoErrors();

        $credentials = IntegrationConfig::forProvider('meta_pixel')->credentials;
        $this->assertArrayNotHasKey('test_event_code', $credentials);
        $this->assertSame('capi-test-token', $credentials['access_token']);
        $this->assertSame(self::PIXEL_ID, $credentials['pixel_id']);
        $this->assertSame('keepme', $credentials['domain_verification']);
    }
}
