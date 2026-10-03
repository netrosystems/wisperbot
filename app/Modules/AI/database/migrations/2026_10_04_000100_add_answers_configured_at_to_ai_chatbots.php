<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Smart Bot setup page ticks "How it answers" once the client has saved
 * it: the answer settings all have defaults, so their values prove nothing.
 * Bots that existed before the setup page count as configured.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_chatbots', function (Blueprint $table): void {
            $table->timestamp('answers_configured_at')->nullable()->after('reply_length');
        });
        DB::table('ai_chatbots')->update(['answers_configured_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('ai_chatbots', fn (Blueprint $table) => $table->dropColumn('answers_configured_at'));
    }
};
