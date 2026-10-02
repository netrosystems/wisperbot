<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A company brief next to the Knowledge Base's business profile (Smart Bot
 * 2.0 Phase 1.3): a short description of the business drafted from its own
 * sources, each sentence linked to the source it came from, and used by the
 * Smart Bot only after the client approves it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_knowledge_bases', function (Blueprint $table): void {
            $table->text('company_brief')->nullable()->after('audience');
            $table->json('company_brief_draft')->nullable()->after('company_brief');
            $table->string('company_brief_status', 16)->default('none')->after('company_brief_draft');
            $table->string('company_brief_error', 255)->nullable()->after('company_brief_status');
            $table->timestamp('company_brief_drafted_at')->nullable()->after('company_brief_error');
            $table->timestamp('company_brief_approved_at')->nullable()->after('company_brief_drafted_at');
            $table->unsignedBigInteger('company_brief_approved_by')->nullable()->after('company_brief_approved_at');
        });
    }

    public function down(): void
    {
        Schema::table('ai_knowledge_bases', fn (Blueprint $table) => $table->dropColumn([
            'company_brief', 'company_brief_draft', 'company_brief_status', 'company_brief_error',
            'company_brief_drafted_at', 'company_brief_approved_at', 'company_brief_approved_by',
        ]));
    }
};
