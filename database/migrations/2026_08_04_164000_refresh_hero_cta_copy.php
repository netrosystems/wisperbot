<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('system_settings')) {
            return;
        }

        DB::table('system_settings')
            ->where('key', 'landing.hero_cta_primary')
            ->whereIn('value', ['Get started', 'Start Free Trial'])
            ->update(['value' => 'Start Free', 'updated_at' => now()]);
    }

    public function down(): void
    {
        // Marketing copy remains administrator-managed after deployment.
    }
};
