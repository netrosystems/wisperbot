<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_provider_configs', function (Blueprint $table) {
            $table->enum('provider', ['openai', 'anthropic', 'gemini', 'deepseek'])->change();
            $table->timestamp('last_tested_at')->nullable()->after('enabled');
            $table->timestamp('last_test_succeeded_at')->nullable()->after('last_tested_at');
            $table->string('last_test_error_code', 64)->nullable()->after('last_test_succeeded_at');
        });

        Schema::create('ai_workspace_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id')->unique();
            $table->enum('provider_mode', ['managed', 'byok', 'auto_fallback'])->default('managed');
            $table->timestamps();
        });

        Schema::create('ai_credit_periods', function (Blueprint $table) {
            $table->id();
            $table->string('account_type', 16);
            $table->unsignedBigInteger('account_id');
            $table->string('subscription_type', 32);
            $table->unsignedBigInteger('subscription_id');
            $table->timestamp('period_start');
            $table->timestamp('period_end');
            $table->unsignedInteger('allowance')->default(0);
            $table->integer('adjustment_credits')->default(0);
            $table->unsignedInteger('used_credits')->default(0);
            $table->unsignedInteger('reserved_credits')->default(0);
            $table->timestamp('warned_80_at')->nullable();
            $table->timestamp('warned_100_at')->nullable();
            $table->string('status', 16)->default('active');
            $table->timestamps();
            $table->unique(['account_type', 'account_id', 'period_start'], 'ai_credit_period_account_start_unique');
            $table->index(['period_end', 'status']);
        });

        Schema::create('ai_credit_ledgers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('period_id')->nullable()->constrained('ai_credit_periods')->nullOnDelete();
            $table->unsignedBigInteger('workspace_id')->index();
            $table->unsignedBigInteger('actor_id')->nullable()->index();
            $table->string('feature', 64);
            $table->unsignedSmallInteger('rate_version');
            $table->char('idempotency_key', 64)->unique();
            $table->char('request_fingerprint', 64)->nullable()->index();
            $table->string('provider_source', 16);
            $table->string('provider', 32)->nullable();
            $table->string('model', 96)->nullable();
            $table->unsignedSmallInteger('credits')->default(0);
            $table->integer('adjustment_delta')->default(0);
            $table->string('adjustment_reason', 500)->nullable();
            $table->unsignedInteger('prompt_tokens')->default(0);
            $table->unsignedInteger('completion_tokens')->default(0);
            $table->unsignedBigInteger('cost_microusd')->default(0);
            $table->string('status', 16);
            $table->text('result_json')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->timestamp('reserved_at')->nullable();
            $table->timestamp('finalized_at')->nullable();
            $table->timestamps();
            $table->index(['workspace_id', 'feature', 'created_at']);
            $table->index(['status', 'reserved_at']);
        });

        Schema::table('automation_runs', function (Blueprint $table) {
            $table->enum('status', ['pending', 'running', 'completed', 'failed', 'cancelled', 'waiting', 'paused'])->default('pending')->change();
        });

        // Commercial limits become finite credits. Exact rollout prices receive
        // the proposed allowances; every other existing plan safely defaults to 0.
        DB::table('plans')->orderBy('id')->each(function ($plan) {
            $limits = json_decode($plan->limits ?: '{}', true) ?: [];
            unset($limits['ai_tokens_per_month']);
            $rolloutAllowances = config('ai_credits.allowances_by_monthly_price_cents', []);
            $monthlyPrice = (int) ($plan->monthly_price_cents ?? $plan->price_cents ?? 0);
            $limits['ai_credits_per_month'] = (int) ($rolloutAllowances[$monthlyPrice] ?? 0);
            DB::table('plans')->where('id', $plan->id)->update(['limits' => json_encode($limits)]);
        });
    }

    public function down(): void
    {
        DB::table('automation_runs')->where('status', 'paused')->update(['status' => 'failed']);
        Schema::table('automation_runs', function (Blueprint $table) {
            $table->enum('status', ['pending', 'running', 'completed', 'failed', 'cancelled', 'waiting'])->default('pending')->change();
        });
        Schema::dropIfExists('ai_credit_ledgers');
        Schema::dropIfExists('ai_credit_periods');
        Schema::dropIfExists('ai_workspace_settings');
        DB::table('ai_provider_configs')->where('provider', 'deepseek')->delete();
        Schema::table('ai_provider_configs', function (Blueprint $table) {
            $table->enum('provider', ['openai', 'anthropic', 'gemini'])->change();
            $table->dropColumn(['last_tested_at', 'last_test_succeeded_at', 'last_test_error_code']);
        });
    }
};
