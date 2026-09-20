<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_kb_documents', function (Blueprint $table): void {
            $table->unsignedSmallInteger('index_version')->default(1)->after('content_hash');
            $table->string('active_index_generation', 36)->default('legacy')->after('index_version');
            $table->string('pending_index_generation', 36)->nullable()->after('active_index_generation');
        });

        Schema::table('ai_kb_chunks', function (Blueprint $table): void {
            $table->string('index_generation', 36)->default('legacy')->after('revision_id');
            $table->string('section_label', 190)->nullable()->after('index_generation');
            $table->string('chunk_kind', 24)->default('prose')->after('section_label');
            $table->index(['document_id', 'index_generation'], 'ai_kb_chunks_document_generation_index');
        });

        Schema::table('ai_kb_retrieval_diagnostics', function (Blueprint $table): void {
            $table->string('response_mode', 24)->nullable()->after('answer_origin');
            $table->string('retrieval_strategy', 32)->nullable()->after('response_mode');
            $table->decimal('semantic_score', 6, 5)->nullable()->after('retrieval_strategy');
            $table->decimal('lexical_score', 6, 5)->nullable()->after('semantic_score');
            $table->string('acceptance_reason', 48)->nullable()->after('lexical_score');
        });

        DB::table('ai_kb_documents')->whereNull('active_index_generation')->update([
            'active_index_generation' => 'legacy',
        ]);
        DB::table('ai_kb_chunks')->whereNull('index_generation')->update([
            'index_generation' => 'legacy',
        ]);
    }

    public function down(): void
    {
        Schema::table('ai_kb_retrieval_diagnostics', function (Blueprint $table): void {
            $table->dropColumn(['response_mode', 'retrieval_strategy', 'semantic_score', 'lexical_score', 'acceptance_reason']);
        });
        Schema::table('ai_kb_chunks', function (Blueprint $table): void {
            $table->dropIndex('ai_kb_chunks_document_generation_index');
            $table->dropColumn(['index_generation', 'section_label', 'chunk_kind']);
        });
        Schema::table('ai_kb_documents', function (Blueprint $table): void {
            $table->dropColumn(['index_version', 'active_index_generation', 'pending_index_generation']);
        });
    }
};
