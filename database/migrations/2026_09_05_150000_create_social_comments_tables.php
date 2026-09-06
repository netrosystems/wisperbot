<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_comment_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('social_account_id')->unique()->constrained('social_media_accounts')->cascadeOnDelete();
            $table->string('mode')->default('off');
            $table->foreignId('chatbot_id')->nullable()->constrained('ai_chatbots')->nullOnDelete();
            $table->timestamp('public_kb_confirmed_at')->nullable();
            $table->unsignedBigInteger('public_kb_revision_id')->nullable();
            $table->timestamp('previewed_at')->nullable();
            $table->string('connection_status')->default('unchecked');
            $table->json('capabilities')->nullable();
            $table->text('sync_cursor')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('sync_started_at')->nullable();
            $table->timestamps();
        });
        Schema::create('social_comment_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('social_account_id')->constrained('social_media_accounts')->cascadeOnDelete();
            $table->string('remote_id', 191);
            $table->text('body')->nullable();
            $table->text('permalink')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();
            $table->unique(['social_account_id', 'remote_id'], 'social_comment_post_identity');
        });
        Schema::create('social_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('social_account_id')->constrained('social_media_accounts')->cascadeOnDelete();
            $table->foreignId('post_id')->constrained('social_comment_posts')->cascadeOnDelete();
            $table->string('remote_id', 191);
            $table->string('parent_remote_id', 191)->nullable();
            $table->string('author_id', 191)->nullable();
            $table->string('author_name')->nullable();
            $table->text('body')->nullable();
            $table->boolean('is_own')->default(false);
            $table->boolean('imported')->default(false);
            $table->boolean('ai_paused')->default(false);
            $table->boolean('hidden')->default(false);
            $table->boolean('deleted')->default(false);
            $table->unsignedInteger('revision')->default(1);
            $table->string('status')->default('needs_attention');
            $table->string('reply_source')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamp('remote_updated_at')->nullable();
            $table->timestamps();
            $table->unique(['social_account_id', 'remote_id'], 'social_comment_identity');
            $table->index(['workspace_id', 'status', 'id']);
            $table->index(['social_account_id', 'parent_remote_id']);
        });
        Schema::create('social_comment_operations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('comment_id')->constrained('social_comments')->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('kind');
            $table->string('source')->default('agent');
            $table->string('status')->default('queued');
            $table->string('idempotency_key', 64);
            $table->string('credential_fingerprint', 64);
            $table->unsignedInteger('comment_revision');
            $table->text('body')->nullable();
            $table->string('remote_id', 191)->nullable();
            $table->string('reason')->nullable();
            $table->json('diagnostics')->nullable();
            $table->timestamps();
            $table->unique(['workspace_id', 'idempotency_key'], 'social_comment_operation_key');
        });
        Schema::create('social_comment_reads', function (Blueprint $table) {
            $table->foreignId('comment_id')->constrained('social_comments')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('read_at');
            $table->primary(['comment_id', 'user_id']);
        });
    }

    public function down(): void
    {
        foreach (['social_comment_reads', 'social_comment_operations', 'social_comments', 'social_comment_posts', 'social_comment_settings'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
