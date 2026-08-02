<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("ALTER TABLE channel_accounts MODIFY COLUMN channel ENUM('whatsapp','instagram','messenger','sms','email','webchat','ebay','amazon','telegram') NOT NULL");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::table('channel_accounts')
            ->where('channel', 'telegram')
            ->delete();

        DB::statement("ALTER TABLE channel_accounts MODIFY COLUMN channel ENUM('whatsapp','instagram','messenger','sms','email','webchat','ebay','amazon') NOT NULL");
    }
};
