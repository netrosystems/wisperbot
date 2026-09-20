<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('ai_chatbots', 'kb_exact_wording')) {
            return;
        }

        Schema::table('ai_chatbots', function (Blueprint $table): void {
            // Off: the bot rewrites Knowledge Base content naturally (facts stay exact).
            // On: the bot keeps the business's approved wording, e.g. for regulated clients.
            $table->boolean('kb_exact_wording')->default(false)->after('live_product_facts_enabled');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('ai_chatbots', 'kb_exact_wording')) {
            Schema::table('ai_chatbots', function (Blueprint $table): void {
                $table->dropColumn('kb_exact_wording');
            });
        }
    }
};
