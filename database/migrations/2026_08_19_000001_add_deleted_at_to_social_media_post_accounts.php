<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_media_post_accounts', function (Blueprint $table) {
            $table->timestamp('deleted_at')->nullable()->after('published_at');
            $table->index(['post_id', 'status', 'deleted_at'], 'social_post_links_lifecycle_idx');
        });
    }

    public function down(): void
    {
        Schema::table('social_media_post_accounts', function (Blueprint $table) {
            $table->dropIndex('social_post_links_lifecycle_idx');
            $table->dropColumn('deleted_at');
        });
    }
};
