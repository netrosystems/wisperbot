<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_kb_documents', function (Blueprint $table) {
            $table->text('source_ref')->nullable()->change();
            $table->text('original_source_ref')->nullable()->after('source_ref');
            $table->text('canonical_url')->nullable()->after('original_source_ref');
        });
    }

    public function down(): void
    {
        Schema::table('ai_kb_documents', function (Blueprint $table) {
            $table->dropColumn(['original_source_ref', 'canonical_url']);
            $table->string('source_ref', 512)->nullable()->change();
        });
    }
};
