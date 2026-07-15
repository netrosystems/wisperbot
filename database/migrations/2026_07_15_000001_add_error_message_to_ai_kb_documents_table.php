<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_kb_documents', function (Blueprint $table): void {
            $table->string('error_message', 512)->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('ai_kb_documents', function (Blueprint $table): void {
            $table->dropColumn('error_message');
        });
    }
};
