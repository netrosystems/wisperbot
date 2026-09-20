<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_kb_products', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('workspace_id')->index();
            $table->unsignedBigInteger('kb_id')->index();
            $table->unsignedBigInteger('document_id')->index();
            $table->char('source_key', 64);
            $table->char('canonical_url_hash', 64)->index();
            $table->text('canonical_url');
            $table->string('name', 512);
            $table->string('sku', 191)->nullable()->index();
            $table->text('image_url')->nullable();
            $table->text('description')->nullable();
            $table->string('extraction_method', 40);
            $table->decimal('parser_confidence', 5, 4)->default(0);
            $table->string('status', 40)->default('active')->index();
            $table->timestamp('verified_at')->nullable()->index();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique(['document_id', 'source_key'], 'ai_kb_products_document_source_unique');
            $table->index(['workspace_id', 'kb_id', 'status'], 'ai_kb_products_scope_status_idx');
            $table->foreign('document_id')->references('id')->on('ai_kb_documents')->cascadeOnDelete();
        });

        Schema::create('ai_kb_product_offers', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('product_id')->index();
            $table->char('source_key', 64);
            $table->string('name', 512)->nullable();
            $table->string('sku', 191)->nullable()->index();
            $table->decimal('price', 14, 4)->nullable();
            $table->decimal('regular_price', 14, 4)->nullable();
            $table->decimal('sale_price', 14, 4)->nullable();
            $table->decimal('min_price', 14, 4)->nullable();
            $table->decimal('max_price', 14, 4)->nullable();
            $table->string('currency', 12)->nullable();
            $table->string('availability', 80)->nullable();
            $table->json('attributes')->nullable();
            $table->text('source_url');
            $table->timestamp('verified_at')->nullable()->index();
            $table->timestamps();

            $table->unique(['product_id', 'source_key'], 'ai_kb_product_offers_source_unique');
            $table->foreign('product_id')->references('id')->on('ai_kb_products')->cascadeOnDelete();
        });

        Schema::table('ai_chatbots', function (Blueprint $table): void {
            $table->boolean('live_product_facts_enabled')->default(false)->after('trusted_research_enabled');
        });

        Schema::table('ai_kb_documents', function (Blueprint $table): void {
            $table->string('product_detection_status', 40)->nullable()->after('next_refresh_at');
            $table->text('product_detection_message')->nullable()->after('product_detection_status');
            $table->timestamp('products_verified_at')->nullable()->after('product_detection_message');
        });

        Schema::table('ai_kb_retrieval_diagnostics', function (Blueprint $table): void {
            $table->json('product_diagnostics')->nullable()->after('citations');
        });
    }

    public function down(): void
    {
        Schema::table('ai_kb_retrieval_diagnostics', fn (Blueprint $table) => $table->dropColumn('product_diagnostics'));
        Schema::table('ai_kb_documents', fn (Blueprint $table) => $table->dropColumn([
            'product_detection_status', 'product_detection_message', 'products_verified_at',
        ]));
        Schema::table('ai_chatbots', fn (Blueprint $table) => $table->dropColumn('live_product_facts_enabled'));
        Schema::dropIfExists('ai_kb_product_offers');
        Schema::dropIfExists('ai_kb_products');
    }
};
