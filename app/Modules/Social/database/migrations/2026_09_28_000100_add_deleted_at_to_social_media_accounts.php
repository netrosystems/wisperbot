<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Disconnecting an account used to delete its row, so scheduled posts kept an
 * id that no longer existed and silently skipped that network, even after the
 * same Page was connected again under a new id. A disconnected account is now
 * soft deleted and brought back when the same account is reconnected.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('social_media_accounts', 'deleted_at')) {
            Schema::table('social_media_accounts', function (Blueprint $table) {
                $table->softDeletes();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('social_media_accounts', 'deleted_at')) {
            Schema::table('social_media_accounts', function (Blueprint $table) {
                $table->dropSoftDeletes();
            });
        }
    }
};
