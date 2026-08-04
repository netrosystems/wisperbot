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

        $updatedCopy = 'Unify WhatsApp, Messenger and Instagram, Tiktok, Emails, eCommerce automate replies with AI chatbots, run bulk broadcasts, and turn conversations into revenue — all from one platform.';

        // Preserve any copy an administrator has intentionally edited, while
        // refreshing known historical defaults on existing installations.
        DB::table('system_settings')
            ->where('key', 'landing.hero_subtitle')
            ->whereIn('value', [
                'WisperBot brings every customer conversation — WhatsApp, Messenger, Instagram, email and live chat — into one AI-powered support desk that answers instantly, routes smartly, and never sleeps.',
                'Bring WhatsApp, Messenger, Instagram, Telegram Business, Gmail, live chat and ecommerce conversations into one AI-powered workspace — with smart replies and a dedicated app for agents on the move.',
                'Unify WhatsApp, Messenger and Instagram, automate replies with AI chatbots, run bulk broadcasts, and turn conversations into revenue — all from one platform.',
            ])
            ->update(['value' => $updatedCopy, 'updated_at' => now()]);
    }

    public function down(): void
    {
        // Do not overwrite administrator-managed marketing copy on rollback.
    }
};
