<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SystemSetting;
use App\Support\MarketingCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class LandingPageController extends Controller
{
    /** @return array<string, string> */
    public static function defaults(): array
    {
        return [
            'landing.page_enabled' => '1',
            'landing.content_version' => '2',
            'landing.announcement' => 'Your brand. Your AI agent. Start free with 100 AI credits every month.',
            'landing.announcement_url' => '/pricing',
            'landing.signin_label' => 'Log in',
            'landing.signin_link_type' => 'dynamic',
            'landing.signin_link_url' => '',
            'landing.getstarted_label' => 'Start free',
            'landing.getstarted_link_type' => 'dynamic',
            'landing.getstarted_link_url' => '',
            'landing.agent_app_ios_url' => 'https://apps.apple.com/ng/app/wisperbot/id6797157205',
            'landing.agent_app_android_url' => 'https://play.google.com/store/apps/details?id=com.wisperbot.app&pcampaignid=web_share',
            'landing.chat_sdk_pubdev_url' => '',
            'landing.developer_docs_url' => '',
            'landing.demo_url' => '/contact',
            'landing.contact_email' => 'support@wisperbot.com',
            'landing.seo_title' => 'WisperBot — Every Customer Channel. One AI Support Team.',
            'landing.seo_description' => 'AI customer support, a shared omnichannel inbox, branded chatbots, email, automations, and mobile agents. Start free with one channel and 100 monthly AI credits.',
            'landing.seo_keywords' => 'omnichannel inbox, white label AI chatbot, website chatbot, WhatsApp chatbot, AI customer support, Email MasterBox, mobile agent app',
            'landing.seo_og_image' => '',
            'landing.hero_title' => 'Every customer channel. One AI support team.',
            'landing.hero_subtitle' => 'Bring conversations, knowledge, and your people together. Let AI handle the first hello. Give your team everything they need for what comes next.',
            'landing.hero_cta_primary' => 'Start free',
            'landing.hero_cta_secondary' => 'Get Agent App',
            'landing.cta_title' => 'Your next great customer experience starts here.',
            'landing.cta_subtitle' => 'One connected channel. 100 monthly AI credits. A white-label chatbot that feels like you.',
            'landing.cta_primary' => 'Start free',
            'landing.cta_secondary' => 'Talk to us',
            'landing.faq_1_q' => 'What can I use for free?',
            'landing.faq_1_a' => 'The free plan includes one connected channel, 100 AI credits each month, and a white-label website chatbot. Upgrade when your team needs more capacity.',
            'landing.faq_1_category' => 'billing',
            'landing.faq_2_q' => 'Which channels can I connect?',
            'landing.faq_2_a' => 'Connect supported website, WhatsApp, Messenger, Instagram, Telegram, email, social publishing, and commerce accounts. Capabilities and account eligibility differ by provider; see the integrations directory.',
            'landing.faq_2_category' => 'setup',
            'landing.faq_3_q' => 'How does the AI learn about my business?',
            'landing.faq_3_a' => 'Add supported website sources and documents to a Knowledge Base, review the content, and test answers. Assign that Knowledge Base to a Smart Bot and configure its answer scope and fallback behavior.',
            'landing.faq_3_category' => 'ai',
            'landing.faq_4_q' => 'Can a real person take over?',
            'landing.faq_4_a' => 'Yes. Customers can ask for human help, and teammates can join conversations. Your team can work on web or through the dedicated Agent App.',
            'landing.faq_4_category' => 'product',
            'landing.faq_5_q' => 'How is workspace access controlled?',
            'landing.faq_5_a' => 'WisperBot scopes customer records and access to workspaces, with team roles, authenticated APIs, and encrypted stored integration credentials. Review your team permissions and provider connections as part of setup.',
            'landing.faq_5_category' => 'security',
            'landing.faq_6_q' => 'Is the mobile Agent App the same as the chat SDK?',
            'landing.faq_6_a' => 'The Agent App is for your staff to handle conversations. The Customer Chat SDK is for developers adding customer chat inside your own app.',
            'landing.faq_6_category' => 'product',
            'landing.faq_7_q' => 'Are provider charges included in AI credits?',
            'landing.faq_7_a' => 'Provider messaging, API, and gateway charges can apply separately from your WisperBot plan and AI credits. Check the provider terms for your connected accounts.',
            'landing.faq_7_category' => 'billing',
            'landing.faq_8_q' => 'Do I need a developer to get started?',
            'landing.faq_8_a' => 'Most channel, Smart Bot, and workflow setup happens in your workspace. A website embed or a custom app SDK integration may need help from your website or app team.',
            'landing.faq_8_category' => 'setup',
        ];
    }

    public function index(): Response
    {
        $settings = self::getPublicSettings();
        $settings['landing.page_enabled'] = SystemSetting::get('landing.page_enabled', '1');
        $settings['landing.content_version'] = '2';

        return Inertia::render('Admin/LandingPage/Index', ['settings' => $settings]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'settings' => ['required', 'array'],
            'settings.*' => ['nullable', 'string', 'max:5000'],
        ]);
        $defaults = self::defaults();
        foreach ($data['settings'] as $key => $value) {
            if (! array_key_exists($key, $defaults)) {
                throw ValidationException::withMessages(['settings' => 'This site-content field is no longer supported. Refresh the page.']);
            }
            if ($value && (str_ends_with($key, '_url') || $key === 'landing.seo_og_image')) {
                if (! MarketingCatalog::safeUrl($value, self::urlHosts($key))) {
                    throw ValidationException::withMessages(['settings.'.$key => 'Use a valid HTTPS URL from the expected service, or a site-relative path where supported.']);
                }
            }
            if ($key === 'landing.contact_email' && $value && ! filter_var($value, FILTER_VALIDATE_EMAIL)) {
                throw ValidationException::withMessages(['settings.'.$key => 'Enter a valid contact email address.']);
            }
            if (str_ends_with($key, '_link_type') && ! in_array($value, ['dynamic', 'static'], true)) {
                throw ValidationException::withMessages(['settings.'.$key => 'Choose a built-in page or custom URL.']);
            }
            if (str_ends_with($key, '_category') && ! in_array($value, ['product', 'setup', 'ai', 'billing', 'security'], true)) {
                throw ValidationException::withMessages(['settings.'.$key => 'Choose a supported question category.']);
            }
        }
        foreach ($data['settings'] as $key => $value) {
            SystemSetting::set($key, $value ?? '', false, 'landing');
        }

        return back()->with('success', 'Site content saved.');
    }

    /** @return list<string> */
    public static function urlHosts(string $key): array
    {
        return match ($key) {
            'landing.agent_app_ios_url' => ['apps.apple.com'],
            'landing.agent_app_android_url' => ['play.google.com'],
            'landing.chat_sdk_pubdev_url' => ['pub.dev'],
            default => [],
        };
    }

    public static function getPublicSettings(): array
    {
        $defaults = self::defaults();
        $result = [];
        $legacyCopy = SystemSetting::get('landing.content_version', '1') !== '2';
        $freeWhiteLabel = MarketingCatalog::freeWhiteLabel();
        foreach ($defaults as $key => $default) {
            if (in_array($key, ['landing.page_enabled', 'landing.content_version'], true)) {
                continue;
            }
            $value = SystemSetting::get($key, $default);
            if ($legacyCopy && (str_starts_with($key, 'landing.hero_') || str_starts_with($key, 'landing.cta_') || str_starts_with($key, 'landing.faq_') || str_starts_with($key, 'landing.seo_') || in_array($key, ['landing.signin_label', 'landing.getstarted_label'], true))) {
                $value = $default;
            }
            // Retire seeded guarantees without overwriting database content.
            if (preg_match('/14,000\+|60 countries|99\.98%|end-to-end encryption|full GDPR compliance|every answer stays accurate|gets you connected in minutes/i', (string) $value)) {
                $value = $default;
            }
            if (str_ends_with($key, '_url') || $key === 'landing.seo_og_image') {
                $value = MarketingCatalog::safeUrl($value, self::urlHosts($key)) ?? '';
            }
            $result[$key] = $value;
        }

        // The original five seeded FAQs had different categories and guarantees.
        // Replace complete known pairs in the public projection, retaining DB data.
        $legacyQuestions = [1 => 'Which channels can I connect?', 2 => 'Do I need a WhatsApp Business API account?', 3 => 'What is included in the free plan?', 4 => 'Will the AI actually understand my business?', 5 => 'Is my data safe?'];
        foreach ($legacyQuestions as $i => $question) {
            if ($result['landing.faq_'.$i.'_q'] === $question) {
                foreach (['q', 'a', 'category'] as $field) {
                    $result['landing.faq_'.$i.'_'.$field] = $defaults['landing.faq_'.$i.'_'.$field];
                }
            }
        }
        if (! $freeWhiteLabel) {
            if ($result['landing.faq_1_a'] === $defaults['landing.faq_1_a']) {
                $result['landing.faq_1_a'] = 'The free plan includes one connected channel and 100 AI credits each month. Website appearance controls help you match your brand; removing WisperBot branding requires an eligible plan.';
            }
            if ($result['landing.cta_subtitle'] === $defaults['landing.cta_subtitle']) {
                $result['landing.cta_subtitle'] = 'One connected channel. 100 monthly AI credits. A helpful place for your customers to begin.';
            }
        }

        return $result;
    }
}
