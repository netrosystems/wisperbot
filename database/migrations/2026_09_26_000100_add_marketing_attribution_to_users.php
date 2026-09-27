<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Latest consent decision and Meta click/visit context for WisperBot's own
     * ad measurement, so webhook-driven conversions keep the visitor's choice.
     */
    public function up(): void
    {
        if (Schema::hasColumn('users', 'marketing_attribution')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->json('marketing_attribution')->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('users', 'marketing_attribution')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('marketing_attribution');
        });
    }
};
