<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        DB::statement("ALTER TABLE channel_accounts MODIFY COLUMN channel ENUM('whatsapp','instagram','messenger','sms','email','webchat','ebay') NOT NULL");
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        DB::table('channel_accounts')
            ->where('channel', 'ebay')
            ->update(['channel' => 'email', 'status' => 'inactive']);
        DB::statement("ALTER TABLE channel_accounts MODIFY COLUMN channel ENUM('whatsapp','instagram','messenger','sms','email','webchat') NOT NULL");
    }
};
