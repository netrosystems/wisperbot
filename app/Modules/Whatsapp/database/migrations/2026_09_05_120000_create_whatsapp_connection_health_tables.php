<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_connection_health', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('waba_id')->unique()->constrained('whatsapp_business_accounts')->cascadeOnDelete();
            $table->string('state')->default('check_delayed');
            $table->json('components')->nullable();
            $table->string('credential_revision', 64)->nullable();
            $table->uuid('operation_id')->nullable();
            $table->dateTime('checked_at')->nullable();
            $table->dateTime('last_success_at')->nullable();
            $table->dateTime('next_check_at')->nullable()->index();
            $table->dateTime('last_webhook_at')->nullable();
            $table->dateTime('last_live_received_at')->nullable();
            $table->unsignedInteger('pending_live_messages')->default(0);
            $table->dateTime('last_message_at')->nullable();
            $table->dateTime('last_processing_error_at')->nullable();
            $table->dateTime('repaired_at')->nullable();
            $table->unsignedInteger('transient_failures')->default(0);
            $table->string('incident_key')->nullable();
            $table->timestamps();
        });
        Schema::create('whatsapp_connection_operations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('waba_id')->constrained('whatsapp_business_accounts')->cascadeOnDelete();
            $table->string('kind');
            $table->string('state')->default('queued');
            $table->string('credential_revision', 64);
            $table->json('results')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->timestamps();
            $table->index(['workspace_id', 'waba_id', 'created_at'], 'wa_health_operation_history');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_connection_operations');
        Schema::dropIfExists('whatsapp_connection_health');
    }
};
