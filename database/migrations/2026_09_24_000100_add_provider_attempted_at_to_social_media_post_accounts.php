<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_media_post_accounts', function (Blueprint $table) {
            if (! Schema::hasColumn('social_media_post_accounts', 'provider_attempted_at')) {
                // Set just before a create request whose outcome can be unknown
                // (X). While set on an unpublished link, retries must not send
                // the post again: X may already have published and charged it.
                $table->timestamp('provider_attempted_at')->nullable()->after('published_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('social_media_post_accounts', function (Blueprint $table) {
            if (Schema::hasColumn('social_media_post_accounts', 'provider_attempted_at')) {
                $table->dropColumn('provider_attempted_at');
            }
        });
    }
};
