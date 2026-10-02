<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every Smart Bot turn records why it ended, including bots without a
 * knowledge base and the turns that fell back, so fallback rates can be
 * measured before and after a change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_kb_retrieval_diagnostics', function (Blueprint $table): void {
            $table->unsignedBigInteger('kb_id')->nullable()->change();
            $table->string('reason_code', 48)->nullable()->after('decision');
            $table->string('channel', 32)->nullable()->after('reason_code');
            $table->string('model', 100)->nullable()->after('channel');
            $table->string('finish_reason', 32)->nullable()->after('model');
            $table->unsignedInteger('latency_ms')->nullable()->after('finish_reason');
            $table->index(['workspace_id', 'created_at'], 'ai_kb_diag_workspace_created_idx');
            $table->index('created_at', 'ai_kb_diag_created_idx');
        });
    }

    public function down(): void
    {
        Schema::table('ai_kb_retrieval_diagnostics', function (Blueprint $table): void {
            $table->dropIndex('ai_kb_diag_workspace_created_idx');
            $table->dropIndex('ai_kb_diag_created_idx');
            $table->dropColumn(['reason_code', 'channel', 'model', 'finish_reason', 'latency_ms']);
        });
    }
};
