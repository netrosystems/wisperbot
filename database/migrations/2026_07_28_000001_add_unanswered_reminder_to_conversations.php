<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->timestamp('unanswered_reminder_sent_at')
                ->nullable()
                ->after('last_inbound_at');
            $table->index(
                ['status', 'last_inbound_at', 'unanswered_reminder_sent_at'],
                'conversations_unanswered_reminder_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropIndex('conversations_unanswered_reminder_idx');
            $table->dropColumn('unanswered_reminder_sent_at');
        });
    }
};
