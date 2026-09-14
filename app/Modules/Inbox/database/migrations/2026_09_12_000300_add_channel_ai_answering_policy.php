<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channel_accounts', function (Blueprint $table) {
            $table->string('ai_answering_mode', 16)->default('off')->after('status');
            $table->unsignedBigInteger('ai_chatbot_id')->nullable()->after('ai_answering_mode');
            $table->json('ai_schedule_json')->nullable()->after('ai_chatbot_id');
            $table->timestamp('ai_enabled_at')->nullable()->after('ai_schedule_json');
            $table->index(['workspace_id', 'ai_answering_mode'], 'channel_accounts_workspace_ai_mode_idx');
            $table->index('ai_chatbot_id', 'channel_accounts_ai_chatbot_idx');
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->unsignedBigInteger('ai_source_message_id')->nullable()->after('user_id');
            $table->unique('ai_source_message_id', 'messages_ai_source_unique');
        });

        $now = now();
        DB::table('channel_accounts')
            ->whereIn('channel', ['whatsapp', 'messenger', 'instagram', 'telegram', 'ebay'])
            ->orderBy('id')
            ->each(function (object $account) use ($now): void {
                $meta = json_decode((string) ($account->meta_json ?? ''), true);
                $chatbotId = is_array($meta) ? ($meta['ai_chatbot_id'] ?? null) : null;
                if (! is_numeric($chatbotId)) {
                    return;
                }

                DB::table('channel_accounts')->where('id', $account->id)->update([
                    'ai_answering_mode' => 'always_on',
                    'ai_chatbot_id' => (int) $chatbotId,
                    'ai_enabled_at' => $now,
                ]);
            });

        DB::table('conversations')
            ->whereIn('channel_account_id', DB::table('channel_accounts')
                ->select('id')
                ->whereIn('channel', ['whatsapp', 'messenger', 'instagram', 'telegram', 'ebay', 'email'])
                ->where('ai_answering_mode', 'off'))
            ->whereNull('assigned_user_id')
            ->whereNull('joined_user_id')
            ->whereNull('handover_at')
            ->update(['assigned_to' => 'human']);
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropUnique('messages_ai_source_unique');
            $table->dropColumn('ai_source_message_id');
        });

        Schema::table('channel_accounts', function (Blueprint $table) {
            $table->dropIndex('channel_accounts_workspace_ai_mode_idx');
            $table->dropIndex('channel_accounts_ai_chatbot_idx');
            $table->dropColumn(['ai_answering_mode', 'ai_chatbot_id', 'ai_schedule_json', 'ai_enabled_at']);
        });
    }
};
