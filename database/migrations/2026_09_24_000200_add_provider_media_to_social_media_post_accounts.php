<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_media_post_accounts', function (Blueprint $table) {
            if (! Schema::hasColumn('social_media_post_accounts', 'provider_media')) {
                // Media already uploaded to the provider for this link (X media
                // ids, valid about 24 hours). A retry reuses them instead of
                // paying to upload the same file again.
                $table->json('provider_media')->nullable()->after('provider_attempted_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('social_media_post_accounts', function (Blueprint $table) {
            if (Schema::hasColumn('social_media_post_accounts', 'provider_media')) {
                $table->dropColumn('provider_media');
            }
        });
    }
};
