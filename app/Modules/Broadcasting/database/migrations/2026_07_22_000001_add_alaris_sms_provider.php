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

        DB::statement("
            ALTER TABLE sms_provider_configs
            MODIFY COLUMN provider ENUM(
                'twilio','nexmo','messagebird','smsbd','reve','alaris','bulksmsbd',
                'sms_dot_bd','mimsms','fast2sms','amazon_sns'
            ) NOT NULL
        ");
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        DB::statement("
            ALTER TABLE sms_provider_configs
            MODIFY COLUMN provider ENUM(
                'twilio','nexmo','messagebird','smsbd','reve','bulksmsbd',
                'sms_dot_bd','mimsms','fast2sms','amazon_sns'
            ) NOT NULL
        ");
    }
};
