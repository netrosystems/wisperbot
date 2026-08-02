<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('system_settings')) {
            return;
        }

        // Only replace untouched legacy defaults. Copy customized through the
        // Site Content editor remains exactly as the administrator wrote it.
        $updates = [
            'landing.seo_description' => [
                'WisperBot unifies WhatsApp, Messenger, Instagram, email and live chat into one AI-powered support desk — answering instantly, routing smartly and resolving more conversations with less effort. Try it free, no card needed.',
                'WisperBot unifies WhatsApp, Messenger, Instagram, Telegram Business, Gmail, ecommerce and live chat in one AI-powered support desk, with automated replies and a dedicated agent app.',
            ],
            'landing.seo_keywords' => [
                'WhatsApp Business API, shared team inbox, AI chatbot, conversational marketing, Messenger inbox, Instagram DM automation, bulk WhatsApp broadcast, customer messaging platform, chat CRM',
                'omnichannel inbox, Gmail shared inbox, Telegram Business, WhatsApp Business API, AI reply automation, ecommerce customer support, Shopify support, WooCommerce support, mobile agent app, customer messaging platform',
            ],
            'landing.hero_subtitle' => [
                'WisperBot brings every customer conversation — WhatsApp, Messenger, Instagram, email and live chat — into one AI-powered support desk that answers instantly, routes smartly, and never sleeps.',
                'Bring WhatsApp, Messenger, Instagram, Telegram Business, Gmail, live chat and ecommerce conversations into one AI-powered workspace — with smart replies and a dedicated app for agents on the move.',
            ],
            'landing.channel_5_desc' => [
                'Send transactional and marketing email from the very same customer timeline.',
                'Connect multiple Gmail, Microsoft or IMAP mailboxes, then read and reply from Email MasterBox.',
            ],
            'landing.faq_1_a' => [
                'WhatsApp Business, Facebook Messenger and Instagram DMs all land in one inbox — plus SMS and email broadcasting, run from a single dashboard.',
                'WhatsApp Business, Facebook Messenger, Instagram DMs, Telegram Business, Gmail and other connected email inboxes can be managed alongside website chat. Supported ecommerce and seller connections add order and customer context too.',
            ],
            'landing.intcat_1_items' => [
                "WhatsApp Business\nFacebook Messenger\nInstagram Direct\nSMS\nEmail",
                "WhatsApp Business\nFacebook Messenger\nInstagram Direct\nTelegram Business\nWebsite Chat",
            ],
            'landing.intcat_3_items' => [
                "Shopify\nWooCommerce\nMagento\nBigCommerce",
                "Shopify\nWooCommerce\neBay Seller\nAmazon Seller",
            ],
            'landing.intcat_5_title' => ['CRM & Automation', 'Email & Productivity'],
            'landing.intcat_5_items' => [
                "Zapier\nHubSpot\nGoogle Sheets\nWebhooks",
                "Gmail\nMicrosoft Mail\nIMAP / SMTP\nGoogle Sheets",
            ],
            'landing.intcat_6_items' => [
                "REST API\nWebhooks\nOAuth 2.0\nFirebase",
                "REST API\nWebhooks\nOAuth 2.0\nDedicated Agent App",
            ],
        ];

        foreach ($updates as $key => [$old, $new]) {
            DB::table('system_settings')
                ->where('key', $key)
                ->where('value', $old)
                ->update(['value' => $new, 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        // Marketing copy can be edited after deployment, so rollback must not
        // replace potentially newer administrator-authored content.
    }
};
