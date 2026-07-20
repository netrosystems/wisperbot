<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channel_accounts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id');
            // Keep fresh installs and SQLite test databases aligned with the
            // later webchat migration. Existing MySQL installs are upgraded by
            // 2026_07_11_000001_add_webchat_to_channel_accounts_enum.php.
            $table->enum('channel', ['whatsapp', 'instagram', 'messenger', 'sms', 'email', 'webchat']);
            $table->string('provider', 64)->nullable();
            $table->text('credentials')->nullable(); // encrypted:array
            $table->string('display_name', 128);
            $table->string('phone_number_id', 64)->nullable();
            $table->string('business_account_id', 64)->nullable();
            $table->enum('status', ['active', 'inactive', 'error'])->default('inactive');
            $table->json('meta_json')->nullable();
            $table->timestamps();

            $table->index('workspace_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_accounts');
    }
};
