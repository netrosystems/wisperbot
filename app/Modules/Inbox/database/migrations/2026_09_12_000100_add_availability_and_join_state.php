<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_member_availabilities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->boolean('enabled')->default(false);
            $table->string('timezone', 64)->default('UTC');
            $table->json('schedule_json')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'user_id']);
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->foreignId('joined_user_id')->nullable()->after('assigned_user_id')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('joined_at')->nullable()->after('joined_user_id');
            $table->index(['workspace_id', 'joined_user_id']);
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropForeign(['joined_user_id']);
            $table->dropIndex(['workspace_id', 'joined_user_id']);
            $table->dropColumn(['joined_user_id', 'joined_at']);
        });

        Schema::dropIfExists('workspace_member_availabilities');
    }
};
