<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The existing white_label_enabled capability is the server-side entitlement
     * used for the Pro widget-branding benefit. The internal name is retained
     * for compatibility with existing subscriptions and plan administration.
     */
    public function up(): void
    {
        DB::table('plans')
            ->where('slug', 'pro')
            ->update(['white_label_enabled' => true]);
    }

    public function down(): void
    {
        DB::table('plans')
            ->where('slug', 'pro')
            ->update(['white_label_enabled' => false]);
    }
};
