<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A website live-chat widget. Each widget owns one webchat `channel_account`
 * (so its conversations land in the omnichannel inbox) and carries the theming,
 * behaviour and (optional) AI-chatbot binding used by the embeddable script.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_widgets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id');
            $table->unsignedBigInteger('channel_account_id')->nullable();
            $table->string('widget_key', 64)->unique();
            $table->string('name', 128)->nullable();

            // Appearance
            $table->string('title', 128)->nullable();          // panel header title
            $table->string('subtitle', 160)->nullable();        // under the title
            $table->text('welcome_message')->nullable();        // first bubble shown on open
            $table->string('agent_name', 64)->nullable();
            $table->string('avatar_url', 512)->nullable();
            $table->string('primary_color', 16)->default('#ff762e');
            $table->enum('position', ['bottom_right', 'bottom_left'])->default('bottom_right');
            $table->string('launcher_text', 64)->nullable();    // optional label next to the bubble

            // Behaviour
            $table->boolean('ai_enabled')->default(false);
            $table->unsignedBigInteger('ai_chatbot_id')->nullable();
            $table->boolean('require_prechat')->default(false);
            $table->json('prechat_fields')->nullable();          // e.g. ['name','email']
            $table->text('offline_message')->nullable();
            $table->json('allowed_domains')->nullable();
            $table->json('working_hours_json')->nullable();
            $table->boolean('enabled')->default(true);

            $table->timestamps();
            $table->index('workspace_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_widgets');
    }
};
