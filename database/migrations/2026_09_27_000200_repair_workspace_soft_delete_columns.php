<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('workspaces', 'deleted_at')) {
            Schema::table('workspaces', function (Blueprint $table) {
                $table->softDeletes()->index();
            });
        }

        if (! Schema::hasColumn('workspaces', 'deleted_by_user_id')) {
            Schema::table('workspaces', function (Blueprint $table) {
                $table->unsignedBigInteger('deleted_by_user_id')->nullable()->after('deleted_at');
            });
        }
    }

    public function down(): void
    {
        // The original workspace soft-delete migration owns these columns.
    }
};
