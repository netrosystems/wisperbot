<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\LandingPageController;
use App\Models\Plan;
use App\Models\SystemSetting;
use App\Support\MarketingCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MarketingWebsiteTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_pillars_are_public_and_indexed_without_exposing_internal_settings(): void
    {
        $this->assertCount(14, MarketingCatalog::pages());
        foreach (MarketingCatalog::pages() as $slug => $page) {
            $this->get('/'.$slug)->assertOk()->assertInertia(fn (Assert $assert) => $assert
                ->component('marketing/Pillar')
                ->where('page.slug', $slug)
                ->has('page.sections', 3)
                ->missing('marketingSettings.landing.page_enabled'));
        }
        $sitemap = $this->get('/sitemap.xml')->assertOk();
        foreach (array_keys(MarketingCatalog::pages()) as $slug) {
            $sitemap->assertSee('/'.$slug);
        }
        $this->get('/products/not-a-product')->assertNotFound();
        $this->get('/solutions/not-a-solution')->assertNotFound();
    }

    public function test_disabled_site_also_disables_new_pillar_routes(): void
    {
        SystemSetting::set('landing.page_enabled', '0', false, 'landing');
        $this->get('/products/smart-ai-agent')->assertRedirect('/login');
        $this->get('/developers')->assertRedirect('/login');
        $this->get('/sitemap.xml')->assertDontSee('/products/smart-ai-agent');
    }

    public function test_comment_marketing_requires_the_rollout_flag(): void
    {
        config(['social_comments.enabled' => false]);
        $facebook = collect(MarketingCatalog::publicData()['integrations'])->firstWhere('key', 'facebook');
        $this->assertNotContains('Comments', $facebook['capabilities']);
        $this->assertCount(3, MarketingCatalog::pages()['products/social-media']['sections']);
        config(['social_comments.enabled' => true]);
        $facebook = collect(MarketingCatalog::publicData()['integrations'])->firstWhere('key', 'facebook');
        $this->assertContains('Comments', $facebook['capabilities']);
        $this->assertCount(4, MarketingCatalog::pages()['products/social-media']['sections']);
        $linkedin = collect(MarketingCatalog::publicData()['integrations'])->firstWhere('key', 'linkedin');
        $this->assertNotContains('Comments', $linkedin['capabilities']);
    }

    public function test_official_download_urls_are_validated_before_any_settings_are_written(): void
    {
        $admin = $this->createSuperAdmin();
        $this->actingAs($admin, 'admin')->put(route('admin.landing-page.update'), ['settings' => [
            'landing.announcement' => 'Should not save',
            'landing.chat_sdk_pubdev_url' => 'https://pub.dev.attacker.example/package',
        ]])->assertSessionHasErrors('settings.landing.chat_sdk_pubdev_url');
        $this->assertNotSame('Should not save', SystemSetting::get('landing.announcement'));
        $this->actingAs($admin, 'admin')->put(route('admin.landing-page.update'), ['settings' => [
            'landing.chat_sdk_pubdev_url' => 'https://pub.dev/packages/example',
            'landing.agent_app_ios_url' => 'https://apps.apple.com/app/id123',
            'landing.agent_app_android_url' => 'https://play.google.com/store/apps/details?id=example',
        ]])->assertSessionHasNoErrors();
        $this->assertSame('https://pub.dev/packages/example', LandingPageController::getPublicSettings()['landing.chat_sdk_pubdev_url']);
        $this->actingAs($admin, 'admin')->put(route('admin.landing-page.update'), ['settings' => ['landing.provider_secret' => 'never-allowed']])->assertSessionHasErrors('settings');
    }

    public function test_unsafe_legacy_links_and_seeded_proof_are_not_published(): void
    {
        SystemSetting::set('landing.cta_subtitle', 'Join 14,000+ teams delivering faster support.', false, 'landing');
        SystemSetting::set('landing.chat_sdk_pubdev_url', 'javascript:alert(1)', false, 'landing');
        SystemSetting::set('landing.testimonial_1_name', 'Fictional customer', false, 'landing');
        $settings = LandingPageController::getPublicSettings();
        $this->assertStringNotContainsString('14,000', $settings['landing.cta_subtitle']);
        $this->assertSame('', $settings['landing.chat_sdk_pubdev_url']);
        $this->assertArrayNotHasKey('landing.testimonial_1_name', $settings);
        $this->assertSame('Join 14,000+ teams delivering faster support.', SystemSetting::get('landing.cta_subtitle'));
        $this->assertNull(MarketingCatalog::safeUrl('//evil.example'));
        $this->assertNull(MarketingCatalog::safeUrl('/\\evil.example'));
        $this->assertNull(MarketingCatalog::safeUrl("/\nevil.example"));
        $this->assertNull(MarketingCatalog::safeUrl('https://user:password@example.com'));
        $this->assertSame('/contact', MarketingCatalog::safeUrl('/contact'));
    }

    public function test_free_branding_claim_follows_the_plan_entitlement(): void
    {
        $plan = Plan::create(['name' => 'Free', 'slug' => 'free', 'enabled' => true, 'monthly_price_cents' => 0, 'yearly_price_cents' => 0, 'price_cents' => 0, 'currency_code' => 'USD', 'interval' => 'month', 'white_label_enabled' => false]);
        $this->assertFalse(MarketingCatalog::publicData()['freeWhiteLabel']);
        $this->assertStringContainsString('eligible plan', LandingPageController::getPublicSettings()['landing.faq_1_a']);
        $plan->update(['white_label_enabled' => true]);
        $this->assertTrue(MarketingCatalog::publicData()['freeWhiteLabel']);
        $this->assertStringContainsString('white-label website chatbot', LandingPageController::getPublicSettings()['landing.faq_1_a']);
    }

    public function test_version_two_custom_copy_is_preserved(): void
    {
        SystemSetting::set('landing.content_version', '2', false, 'landing');
        SystemSetting::set('landing.cta_subtitle', 'Bring your team together.', false, 'landing');
        SystemSetting::set('landing.faq_1_a', 'Custom plan guidance.', false, 'landing');
        $settings = LandingPageController::getPublicSettings();
        $this->assertSame('Bring your team together.', $settings['landing.cta_subtitle']);
        $this->assertSame('Custom plan guidance.', $settings['landing.faq_1_a']);
    }
}
