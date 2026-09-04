<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_workspace_settings', function (Blueprint $table) {
            $table->enum('provider_mode', ['managed', 'byok', 'auto_fallback'])
                ->default('auto_fallback')
                ->change();
        });
    }

    public function down(): void
    {
        Schema::table('ai_workspace_settings', function (Blueprint $table) {
            $table->enum('provider_mode', ['managed', 'byok', 'auto_fallback'])
                ->default('managed')
                ->change();
        });
    }
};
