<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Smart Bot 2.0 Phase 1: each bot chooses its answer engine (v1 today, v2 on
 * the canary) and a reply length; each turn records which engine answered and
 * a short trace of how (planner, answer kind, checks).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_chatbots', function (Blueprint $table): void {
            $table->string('engine', 8)->default('v1')->after('answer_scope');
            $table->string('reply_length', 16)->default('standard')->after('engine');
        });
        Schema::table('ai_kb_retrieval_diagnostics', function (Blueprint $table): void {
            $table->string('engine', 8)->nullable()->after('reason_code');
            $table->json('trace')->nullable()->after('latency_ms');
        });
    }

    public function down(): void
    {
        Schema::table('ai_kb_retrieval_diagnostics', fn (Blueprint $table) => $table->dropColumn(['engine', 'trace']));
        Schema::table('ai_chatbots', fn (Blueprint $table) => $table->dropColumn(['engine', 'reply_length']));
    }
};
