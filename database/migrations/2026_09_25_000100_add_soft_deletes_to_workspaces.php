<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            if (! Schema::hasColumn('workspaces', 'deleted_at')) {
                // A deleted workspace is hidden and inactive for 30 days, can be
                // restored by its owner, and is then permanently erased by
                // `workspaces:purge-deleted`.
                $table->softDeletes()->index();
            }
            if (! Schema::hasColumn('workspaces', 'deleted_by_user_id')) {
                $table->unsignedBigInteger('deleted_by_user_id')->nullable()->after('deleted_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            if (Schema::hasColumn('workspaces', 'deleted_by_user_id')) {
                $table->dropColumn('deleted_by_user_id');
            }
            if (Schema::hasColumn('workspaces', 'deleted_at')) {
                $table->dropSoftDeletes();
            }
        });
    }
};
