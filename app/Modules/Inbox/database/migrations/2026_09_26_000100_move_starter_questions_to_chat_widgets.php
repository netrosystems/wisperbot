<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Starter questions belong to the website widget (Widget Setup) instead of
 * the Smart Bot, so they work whether or not AI is answering. Each widget
 * takes over the questions of the Smart Bot it currently uses; the Smart Bot
 * columns stay for the read-only API and are no longer used by the widget.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_widgets', function (Blueprint $table) {
            if (! Schema::hasColumn('chat_widgets', 'starter_questions_enabled')) {
                $table->boolean('starter_questions_enabled')->default(false);
            }
            if (! Schema::hasColumn('chat_widgets', 'starter_questions')) {
                $table->json('starter_questions')->nullable();
            }
        });

        if (! Schema::hasColumn('ai_chatbots', 'starter_questions')) {
            return;
        }

        DB::table('chat_widgets')
            ->join('ai_chatbots', 'ai_chatbots.id', '=', 'chat_widgets.ai_chatbot_id')
            ->whereColumn('ai_chatbots.workspace_id', 'chat_widgets.workspace_id')
            ->whereNull('chat_widgets.starter_questions')
            ->whereNotNull('ai_chatbots.starter_questions')
            ->select('chat_widgets.id', 'ai_chatbots.starter_questions', 'ai_chatbots.starter_questions_enabled')
            ->orderBy('chat_widgets.id')
            ->get()
            ->each(function ($row): void {
                DB::table('chat_widgets')->where('id', $row->id)->update([
                    'starter_questions' => $row->starter_questions,
                    'starter_questions_enabled' => (bool) $row->starter_questions_enabled,
                ]);
            });
    }

    public function down(): void
    {
        Schema::table('chat_widgets', function (Blueprint $table) {
            foreach (['starter_questions', 'starter_questions_enabled'] as $column) {
                if (Schema::hasColumn('chat_widgets', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
