<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The answer-quality test set (Smart Bot 2.0, Phase 1.6; after Cerqle's):
 * questions with the answer we expect, runs of a bot against them, and each
 * answer's score. Runs are billed to the platform, never to the client.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_eval_cases', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('workspace_id');
            $table->foreignId('chatbot_id')->constrained('ai_chatbots')->cascadeOnDelete();
            // A case written from a real unanswered question goes with it.
            $table->foreignId('knowledge_gap_id')->nullable()->constrained('ai_kb_knowledge_gaps')->cascadeOnDelete();
            // A case taken from the Knowledge Base tester goes with it.
            $table->foreignId('kb_test_case_id')->nullable()->constrained('ai_kb_test_cases')->cascadeOnDelete();
            $table->text('question');
            // Earlier turns for a follow-up case ("and for a year?").
            $table->json('history')->nullable();
            // answer: the knowledge covers it; decline: it does not, and the bot must not invent.
            $table->string('expected', 16);
            // Short facts the answer must mention, copied exactly from the knowledge.
            $table->json('expected_facts')->nullable();
            $table->string('language', 16)->nullable();
            $table->string('source', 16);
            $table->unsignedBigInteger('source_document_id')->nullable();
            $table->string('fingerprint', 64);
            $table->string('status', 16)->default('active');
            $table->timestamps();

            $table->unique(['chatbot_id', 'fingerprint'], 'ai_eval_cases_bot_fp_unique');
            $table->index(['workspace_id', 'chatbot_id', 'status'], 'ai_eval_cases_ws_bot_idx');
        });

        Schema::create('ai_eval_runs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('workspace_id');
            $table->foreignId('chatbot_id')->constrained('ai_chatbots')->cascadeOnDelete();
            $table->string('engine', 8);
            $table->string('status', 16)->default('running');
            $table->boolean('judged')->default(false);
            $table->unsignedSmallInteger('cases_total')->default(0);
            $table->json('summary')->nullable();
            $table->boolean('passed')->nullable();
            $table->string('error', 255)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'chatbot_id', 'created_at'], 'ai_eval_runs_ws_bot_idx');
        });

        Schema::create('ai_eval_results', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('run_id')->constrained('ai_eval_runs')->cascadeOnDelete();
            $table->foreignId('case_id')->nullable()->constrained('ai_eval_cases')->nullOnDelete();
            $table->string('expected', 16);
            $table->text('reply')->nullable();
            $table->string('response_mode', 32)->nullable();
            $table->string('answer_origin', 32)->nullable();
            $table->string('reason_code', 48)->nullable();
            $table->string('verdict', 8);
            $table->string('failure', 32)->nullable();
            $table->json('facts_missing')->nullable();
            $table->json('invented_figures')->nullable();
            $table->unsignedTinyInteger('judge_score')->nullable();
            $table->string('judge_note', 500)->nullable();
            $table->unsignedInteger('tokens')->default(0);
            $table->unsignedInteger('latency_ms')->default(0);
            $table->json('trace')->nullable();
            $table->timestamps();

            $table->index(['run_id', 'verdict'], 'ai_eval_results_run_verdict_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_eval_results');
        Schema::dropIfExists('ai_eval_runs');
        Schema::dropIfExists('ai_eval_cases');
    }
};
