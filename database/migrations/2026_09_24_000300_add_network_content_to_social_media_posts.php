<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_media_posts', function (Blueprint $table) {
            if (! Schema::hasColumn('social_media_posts', 'network_content')) {
                // Optional per-network version of the post, keyed by network:
                // {"twitter": {"body": "...", "media_urls": [...]}}. Networks
                // without an entry publish the shared body and media.
                $table->json('network_content')->nullable()->after('media_urls');
            }
        });
    }

    public function down(): void
    {
        Schema::table('social_media_posts', function (Blueprint $table) {
            if (Schema::hasColumn('social_media_posts', 'network_content')) {
                $table->dropColumn('network_content');
            }
        });
    }
};
