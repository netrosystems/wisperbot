<?php

use App\Modules\Inbox\Models\ChatWidget;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Identity passthrough: let the client's website hand the widget a logged-in
 * customer's name/email/avatar. `identity_secret` is a per-widget HMAC key the
 * client signs the user id with (Intercom-style), and when `identity_verification`
 * is on we only trust signed identities — stopping visitors from spoofing others.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_widgets', function (Blueprint $table) {
            $table->boolean('identity_verification')->default(false)->after('enabled');
            $table->string('identity_secret', 64)->nullable()->after('identity_verification');
        });

        // Backfill a secret for any existing widgets.
        ChatWidget::whereNull('identity_secret')->get()->each(function (ChatWidget $w) {
            $w->update(['identity_secret' => Str::random(48)]);
        });
    }

    public function down(): void
    {
        Schema::table('chat_widgets', function (Blueprint $table) {
            $table->dropColumn(['identity_verification', 'identity_secret']);
        });
    }
};
