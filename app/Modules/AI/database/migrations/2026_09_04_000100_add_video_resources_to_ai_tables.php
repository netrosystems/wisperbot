<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_kb_documents', function (Blueprint $table) {
            $table->enum('source_type', ['file', 'url', 'text', 'sitemap', 'faq', 'video'])->change();
            $table->json('resource_json')->nullable()->after('source_ref');
        });

        Schema::table('ai_chatbots', function (Blueprint $table) {
            $table->decimal('video_match_threshold', 4, 3)->default(0.720)->after('max_context_chunks');
        });

        Schema::table('ai_runs', function (Blueprint $table) {
            $table->json('metadata_json')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('ai_runs', fn (Blueprint $table) => $table->dropColumn('metadata_json'));
        Schema::table('ai_chatbots', fn (Blueprint $table) => $table->dropColumn('video_match_threshold'));
        Schema::table('ai_kb_documents', function (Blueprint $table) {
            $table->dropColumn('resource_json');
            $table->enum('source_type', ['file', 'url', 'text', 'sitemap', 'faq'])->change();
        });
    }
};
