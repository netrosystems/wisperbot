<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_addon_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('addon_key', 100);
            $table->foreignId('purchased_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 30)->default('pending');
            $table->string('gateway', 30)->nullable();
            $table->string('gateway_subscription_id')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('renews_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('cancel_at_period_end')->default(false);
            $table->json('gateway_metadata')->nullable();
            $table->timestamps();

            $table->unique(['client_id', 'addon_key']);
            $table->index(['gateway', 'gateway_subscription_id']);
            $table->index(['addon_key', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_addon_subscriptions');
    }
};
