<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_chatbots', function (Blueprint $table): void {
            if (! Schema::hasColumn('ai_chatbots', 'starter_questions_enabled')) {
                $table->boolean('starter_questions_enabled')->default(false)->after('kb_exact_wording');
            }
            if (! Schema::hasColumn('ai_chatbots', 'starter_questions')) {
                // Up to five {id, question, answer} items the client writes; answered without AI.
                $table->json('starter_questions')->nullable()->after('starter_questions_enabled');
            }
        });
    }

    public function down(): void
    {
        Schema::table('ai_chatbots', function (Blueprint $table): void {
            foreach (['starter_questions', 'starter_questions_enabled'] as $column) {
                if (Schema::hasColumn('ai_chatbots', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
