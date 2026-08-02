<?php

use App\Support\LegalFeatureDisclosure;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cms_pages')) {
            return;
        }

        $this->appendOnce('privacy', LegalFeatureDisclosure::privacy());
        $this->appendOnce('terms', LegalFeatureDisclosure::terms());

        DB::table('cms_pages')
            ->where('slug', 'privacy')
            ->where('meta_description', 'like', 'How % collects, uses, and protects your personal data.')
            ->update(['meta_description' => 'How WisperBot processes account, messaging, email, commerce, AI, mobile-app, and connected-service data.']);

        DB::table('cms_pages')
            ->where('slug', 'terms')
            ->where('meta_description', 'like', 'The terms and conditions for using %.')
            ->update(['meta_description' => 'Terms for WisperBot messaging, email, Telegram, ecommerce, AI automation, integrations, and mobile-app services.']);
    }

    public function down(): void
    {
        // The disclosure is intentionally not removed on rollback because the
        // CMS pages may have been edited after deployment.
    }

    private function appendOnce(string $slug, string $disclosure): void
    {
        $page = DB::table('cms_pages')->where('slug', $slug)->first(['id', 'content']);
        if (! $page || str_contains((string) $page->content, LegalFeatureDisclosure::MARKER)) {
            return;
        }

        DB::table('cms_pages')->where('id', $page->id)->update([
            'content' => rtrim((string) $page->content).$disclosure,
            'updated_at' => now(),
        ]);
    }
};
