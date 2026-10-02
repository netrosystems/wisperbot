<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The team's rating of a Smart Bot reply (Smart Bot 2.0, Phase 1.5): one row
 * per bot message, with who rated it last and whether a better answer was
 * written into the Knowledge Base from it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_answer_feedback', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('workspace_id')->index();
            $table->foreignId('message_id')->unique()->constrained('messages')->cascadeOnDelete();
            $table->unsignedBigInteger('chatbot_id')->nullable()->index();
            $table->unsignedBigInteger('kb_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('rating', 8)->nullable();
            $table->boolean('improved')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_answer_feedback');
    }
};
