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

        foreach ([
            'landing.metric_1_value' => ['68M+', '50M+', '30k+'],
            'landing.metric_2_value' => ['14,500+', '12,000+', '150+'],
            'landing.metric_3_value' => ['99.98%', '99.9%', '98%'],
        ] as $key => [$legacyOne, $legacyTwo, $replacement]) {
            DB::table('system_settings')
                ->where('key', $key)
                ->whereIn('value', [$legacyOne, $legacyTwo])
                ->update(['value' => $replacement, 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        // Preserve administrator-managed metrics during rollback.
    }
};
