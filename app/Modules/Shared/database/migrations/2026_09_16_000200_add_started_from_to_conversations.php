<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            if (! Schema::hasColumn('conversations', 'started_from')) {
                $table->string('started_from', 32)
                    ->nullable()
                    ->after('external_thread_id')
                    ->index('conversations_started_from_idx');
            }
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            if (Schema::hasColumn('conversations', 'started_from')) {
                $table->dropIndex('conversations_started_from_idx');
                $table->dropColumn('started_from');
            }
        });
    }
};
