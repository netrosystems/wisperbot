<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Add the 'webchat' channel type so website live-chat widget conversations flow
 * through the same channel_accounts / conversations / messages pipeline as the
 * social channels.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE channel_accounts MODIFY COLUMN channel ENUM('whatsapp','instagram','messenger','sms','email','webchat') NOT NULL");
    }

    public function down(): void
    {
        // Reassign any webchat rows before shrinking the enum so the MODIFY doesn't fail.
        DB::table('channel_accounts')->where('channel', 'webchat')->update(['channel' => 'email']);
        DB::statement("ALTER TABLE channel_accounts MODIFY COLUMN channel ENUM('whatsapp','instagram','messenger','sms','email') NOT NULL");
    }
};
