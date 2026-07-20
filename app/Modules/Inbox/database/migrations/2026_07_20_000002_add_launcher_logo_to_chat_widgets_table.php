<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_widgets', function (Blueprint $table) {
            $table->string('launcher_logo_path', 512)->nullable()->after('footer_company_name');
            $table->string('launcher_logo_disk', 64)->nullable()->after('launcher_logo_path');
        });
    }

    public function down(): void
    {
        Schema::table('chat_widgets', function (Blueprint $table) {
            $table->dropColumn(['launcher_logo_path', 'launcher_logo_disk']);
        });
    }
};
