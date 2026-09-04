<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_kb_revisions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('kb_id')->index();
            $table->unsignedInteger('version');
            $table->enum('status', ['draft', 'published', 'superseded'])->default('draft');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedTinyInteger('readiness_score')->default(0);
            $table->enum('regression_status', ['not_run', 'running', 'passed', 'failed'])->default('not_run');
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->unique(['kb_id', 'version']);
        });

        Schema::create('ai_kb_revision_documents', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('revision_id')->index();
            $table->unsignedBigInteger('document_id')->index();
            $table->timestamps();
            $table->unique(['revision_id', 'document_id'], 'ai_kb_revision_document_unique');
        });

        Schema::table('ai_knowledge_bases', function (Blueprint $table) {
            $table->text('purpose')->nullable()->after('name');
            $table->string('language', 16)->default('en')->after('purpose');
            $table->string('brand', 128)->nullable()->after('language');
            $table->string('audience', 256)->nullable()->after('brand');
            $table->unsignedBigInteger('draft_revision_id')->nullable()->index()->after('status');
            $table->unsignedBigInteger('published_revision_id')->nullable()->index()->after('draft_revision_id');
            $table->unsignedTinyInteger('readiness_score')->default(0)->after('published_revision_id');
            $table->enum('regression_status', ['not_run', 'running', 'passed', 'failed'])->default('not_run')->after('readiness_score');
            $table->timestamp('last_published_at')->nullable()->after('regression_status');
        });

        Schema::table('ai_kb_documents', function (Blueprint $table) {
            $table->enum('status', ['pending', 'extracting', 'validating', 'indexing', 'indexed', 'degraded', 'error'])->default('pending')->change();
            $table->boolean('enabled')->default(true)->after('status');
            $table->boolean('authoritative')->default(false)->after('enabled');
            $table->unsignedTinyInteger('priority')->default(50)->after('authoritative');
            $table->string('detected_language', 16)->nullable()->after('priority');
            $table->enum('review_status', ['auto_approved', 'needs_review', 'blocked', 'approved', 'rejected'])->default('needs_review')->after('detected_language');
            $table->enum('publication_status', ['draft', 'published', 'superseded'])->default('draft')->after('review_status');
            $table->unsignedTinyInteger('quality_score')->default(0)->after('publication_status');
            $table->json('quality_findings')->nullable()->after('quality_score');
            $table->longText('extracted_content')->nullable()->after('quality_findings');
            $table->char('content_hash', 64)->nullable()->index()->after('extracted_content');
            $table->unsignedBigInteger('reviewed_by')->nullable()->after('content_hash');
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');
            $table->timestamp('last_refreshed_at')->nullable()->after('reviewed_at');
            $table->timestamp('next_refresh_at')->nullable()->after('last_refreshed_at');
        });

        Schema::table('ai_kb_chunks', function (Blueprint $table) {
            $table->char('content_hash', 64)->nullable()->index()->after('content');
            $table->string('embedding_model', 96)->nullable()->after('embedding');
            $table->enum('embedding_status', ['pending', 'ready', 'error'])->default('pending')->after('embedding_model');
            $table->unsignedBigInteger('revision_id')->nullable()->index()->after('embedding_status');
        });

        Schema::table('ai_chatbots', function (Blueprint $table) {
            $table->decimal('retrieval_match_threshold', 4, 3)->default(0.600)->after('max_context_chunks');
            $table->unsignedSmallInteger('max_context_tokens')->default(1200)->after('retrieval_match_threshold');
            $table->enum('unsupported_answer_action', ['clarify_then_handoff', 'handoff', 'general'])->default('clarify_then_handoff')->after('max_context_tokens');
        });

        Schema::create('ai_kb_test_cases', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('kb_id')->index();
            $table->text('question');
            $table->text('expected_facts')->nullable();
            $table->unsignedBigInteger('expected_document_id')->nullable();
            $table->boolean('critical')->default(false);
            $table->enum('last_status', ['not_run', 'passed', 'failed'])->default('not_run');
            $table->json('last_result')->nullable();
            $table->timestamp('last_run_at')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_kb_knowledge_gaps', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id')->index();
            $table->unsignedBigInteger('kb_id')->index();
            $table->unsignedBigInteger('chatbot_id')->nullable()->index();
            $table->char('question_hash', 64);
            $table->string('question_sample', 500)->nullable();
            $table->unsignedInteger('occurrences')->default(1);
            $table->decimal('best_score', 5, 4)->nullable();
            $table->enum('decision', ['clarify', 'handoff'])->default('handoff');
            $table->enum('status', ['open', 'resolved', 'ignored'])->default('open');
            $table->timestamp('last_seen_at');
            $table->timestamps();
            $table->unique(['kb_id', 'question_hash']);
        });

        Schema::create('ai_kb_retrieval_diagnostics', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id')->index();
            $table->unsignedBigInteger('kb_id')->index();
            $table->unsignedBigInteger('chatbot_id')->nullable()->index();
            $table->unsignedBigInteger('revision_id')->nullable()->index();
            $table->decimal('best_score', 5, 4)->nullable();
            $table->unsignedTinyInteger('passages_used')->default(0);
            $table->unsignedInteger('system_tokens')->default(0);
            $table->unsignedInteger('context_tokens')->default(0);
            $table->unsignedInteger('history_tokens')->default(0);
            $table->unsignedInteger('customer_tokens')->default(0);
            $table->unsignedInteger('completion_tokens')->default(0);
            $table->string('decision', 32);
            $table->string('cache_source', 32)->nullable();
            $table->timestamps();
        });

        Schema::create('ai_kb_answer_cache', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id')->index();
            $table->unsignedBigInteger('chatbot_id')->index();
            $table->unsignedBigInteger('revision_id')->nullable()->index();
            $table->string('language', 16);
            $table->char('question_hash', 64);
            $table->text('normalized_question');
            $table->longText('answer');
            $table->json('resources')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamps();
            $table->unique(['chatbot_id', 'revision_id', 'language', 'question_hash'], 'ai_kb_answer_cache_unique');
        });

        Schema::create('ai_kb_embedding_cache', function (Blueprint $table) {
            $table->id();
            $table->char('content_hash', 64);
            $table->string('model', 96);
            $table->longText('embedding');
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
            $table->unique(['content_hash', 'model']);
        });

        $this->migrateExistingKnowledgeBases();
    }

    private function migrateExistingKnowledgeBases(): void
    {
        DB::table('ai_knowledge_bases')->orderBy('id')->each(function ($kb): void {
            $documents = DB::table('ai_kb_documents')->where('kb_id', $kb->id)->get();
            $usable = false;
            foreach ($documents as $document) {
                $hasEmbedding = DB::table('ai_kb_chunks')
                    ->where('document_id', $document->id)
                    ->whereNotNull('embedding')
                    ->exists();
                $isUsable = $document->status === 'indexed' && $hasEmbedding;
                $usable = $usable || $isUsable;
                DB::table('ai_kb_documents')->where('id', $document->id)->update([
                    'enabled' => true,
                    'review_status' => $isUsable ? 'auto_approved' : 'needs_review',
                    'publication_status' => $isUsable ? 'published' : 'draft',
                    'quality_score' => $isUsable ? 100 : 0,
                    'status' => $document->status === 'indexed' && ! $hasEmbedding ? 'degraded' : $document->status,
                ]);
                DB::table('ai_kb_chunks')->where('document_id', $document->id)->get(['id', 'content'])
                    ->each(function ($chunk) use ($kb, $hasEmbedding): void {
                        DB::table('ai_kb_chunks')->where('id', $chunk->id)->update([
                            'content_hash' => hash('sha256', (string) $chunk->content),
                            'embedding_model' => $kb->embedding_model,
                            'embedding_status' => $hasEmbedding ? 'ready' : 'pending',
                        ]);
                    });
            }

            $revisionId = DB::table('ai_kb_revisions')->insertGetId([
                'kb_id' => $kb->id,
                'version' => 1,
                'status' => $usable ? 'published' : 'draft',
                'readiness_score' => $usable ? 100 : 0,
                'regression_status' => 'not_run',
                'published_at' => $usable ? now() : null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            foreach ($documents as $document) {
                DB::table('ai_kb_revision_documents')->insert([
                    'revision_id' => $revisionId,
                    'document_id' => $document->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                DB::table('ai_kb_chunks')->where('document_id', $document->id)->update(['revision_id' => $revisionId]);
            }
            DB::table('ai_knowledge_bases')->where('id', $kb->id)->update([
                'draft_revision_id' => $usable ? null : $revisionId,
                'published_revision_id' => $usable ? $revisionId : null,
                'readiness_score' => $usable ? 100 : 0,
                'last_published_at' => $usable ? now() : null,
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_kb_embedding_cache');
        Schema::dropIfExists('ai_kb_answer_cache');
        Schema::dropIfExists('ai_kb_retrieval_diagnostics');
        Schema::dropIfExists('ai_kb_knowledge_gaps');
        Schema::dropIfExists('ai_kb_test_cases');
        Schema::dropIfExists('ai_kb_revision_documents');
        Schema::dropIfExists('ai_kb_revisions');
        Schema::table('ai_chatbots', fn (Blueprint $table) => $table->dropColumn(['retrieval_match_threshold', 'max_context_tokens', 'unsupported_answer_action']));
        Schema::table('ai_kb_chunks', fn (Blueprint $table) => $table->dropColumn(['content_hash', 'embedding_model', 'embedding_status', 'revision_id']));
        Schema::table('ai_kb_documents', function (Blueprint $table) {
            $table->enum('status', ['pending', 'indexing', 'indexed', 'error'])->default('pending')->change();
            $table->dropColumn(['enabled', 'authoritative', 'priority', 'detected_language', 'review_status', 'publication_status', 'quality_score', 'quality_findings', 'extracted_content', 'content_hash', 'reviewed_by', 'reviewed_at', 'last_refreshed_at', 'next_refresh_at']);
        });
        Schema::table('ai_knowledge_bases', fn (Blueprint $table) => $table->dropColumn(['purpose', 'language', 'brand', 'audience', 'draft_revision_id', 'published_revision_id', 'readiness_score', 'regression_status', 'last_published_at']));
    }
};
