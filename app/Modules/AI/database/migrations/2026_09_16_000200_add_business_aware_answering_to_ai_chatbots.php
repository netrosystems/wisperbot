<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_chatbots', function (Blueprint $table): void {
            $table->string('answer_scope', 32)->default('business_only')->after('unsupported_answer_action');
            $table->string('unsupported_fallback_action', 32)->default('clarify_then_handoff')->after('answer_scope');
            $table->boolean('trusted_research_enabled')->default(false)->after('unsupported_fallback_action');
        });

        DB::table('ai_chatbots')->orderBy('id')->eachById(function (object $bot): void {
            $legacy = (string) ($bot->unsupported_answer_action ?? 'clarify_then_handoff');
            DB::table('ai_chatbots')->where('id', $bot->id)->update([
                'answer_scope' => $legacy === 'general' ? 'general' : 'business_only',
                'unsupported_fallback_action' => $legacy === 'handoff' ? 'handoff' : 'clarify_then_handoff',
            ]);
        });

        Schema::table('ai_kb_retrieval_diagnostics', function (Blueprint $table): void {
            $table->string('intent', 32)->nullable()->after('cache_source');
            $table->string('answer_origin', 32)->nullable()->after('intent');
            $table->string('research_outcome', 32)->nullable()->after('answer_origin');
            $table->unsignedInteger('research_latency_ms')->nullable()->after('research_outcome');
            $table->json('citations')->nullable()->after('research_latency_ms');
            $table->string('credit_result', 32)->nullable()->after('citations');
        });
    }

    public function down(): void
    {
        Schema::table('ai_kb_retrieval_diagnostics', function (Blueprint $table): void {
            $table->dropColumn([
                'intent', 'answer_origin', 'research_outcome', 'research_latency_ms',
                'citations', 'credit_result',
            ]);
        });
        Schema::table('ai_chatbots', function (Blueprint $table): void {
            $table->dropColumn(['answer_scope', 'unsupported_fallback_action', 'trusted_research_enabled']);
        });
    }
};
