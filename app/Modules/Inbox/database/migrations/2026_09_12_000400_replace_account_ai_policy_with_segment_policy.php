<?php

use App\Modules\Inbox\Services\SegmentAiPolicyService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_ai_answering_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('segment', 16);
            $table->string('mode', 16)->default('off');
            $table->foreignId('chatbot_id')->nullable()->constrained('ai_chatbots')->nullOnDelete();
            $table->json('schedule_json')->nullable();
            $table->timestamp('enabled_at')->nullable();
            $table->boolean('requires_review')->default(false);
            $table->timestamps();
            $table->unique(['workspace_id', 'segment'], 'workspace_ai_segment_unique');
        });

        Schema::table('channel_accounts', function (Blueprint $table) {
            $table->timestamp('ai_eligible_from_at')->nullable()->after('status');
        });
        Schema::table('conversations', function (Blueprint $table) {
            $table->timestamp('ai_paused_at')->nullable()->after('assigned_to');
            $table->string('ai_pause_reason', 32)->nullable()->after('ai_paused_at');
        });

        $groups = DB::table('channel_accounts')
            ->whereIn('channel', ['whatsapp', 'messenger', 'instagram', 'telegram', 'ebay', 'email'])
            ->orderBy('workspace_id')->orderBy('id')->get()
            ->groupBy(fn (object $account): string => $account->workspace_id.':'.($account->channel === 'email' ? 'email' : 'omni'));

        foreach ($groups as $key => $accounts) {
            [$workspaceId, $segment] = explode(':', $key, 2);
            $states = $accounts->map(function (object $account): array {
                $meta = json_decode((string) ($account->meta_json ?? ''), true) ?: [];
                $mode = (string) ($account->ai_answering_mode ?? 'off');
                $bot = $account->ai_chatbot_id ?? ($meta['ai_chatbot_id'] ?? null);
                if ($mode === 'off' && is_numeric($bot) && $account->channel !== 'email') {
                    $mode = 'always_on';
                }

                $schedule = is_string($account->ai_schedule_json ?? null)
                    ? json_decode($account->ai_schedule_json, true)
                    : ($account->ai_schedule_json ?? null);

                return [$mode, is_numeric($bot) ? (int) $bot : null, $schedule];
            })->values();
            $first = $states->first();
            $agrees = $states->every(fn (array $state): bool => $state === $first);
            $validBot = $first[0] === 'off' || ($first[1] && DB::table('ai_chatbots')->where('id', $first[1])->where('workspace_id', $workspaceId)->exists());
            $validMode = in_array($first[0], ['off', 'always_on', 'scheduled'], true);
            $validSchedule = $first[0] !== 'scheduled';
            if ($first[0] === 'scheduled') {
                try {
                    $validSchedule = app(SegmentAiPolicyService::class)
                        ->normalizeSchedule($first[2], 'scheduled') !== null;
                } catch (Throwable) {
                    $validSchedule = false;
                }
            }
            $inherit = $agrees && $validBot && $validMode && $validSchedule;

            DB::table('workspace_ai_answering_policies')->insert([
                'workspace_id' => (int) $workspaceId,
                'segment' => $segment,
                'mode' => $inherit ? $first[0] : 'off',
                'chatbot_id' => $inherit && $first[0] !== 'off' ? $first[1] : null,
                'schedule_json' => $inherit && $first[0] === 'scheduled' ? json_encode($first[2]) : null,
                'enabled_at' => $inherit && $first[0] !== 'off' ? $accounts->max('ai_enabled_at') : null,
                'requires_review' => ! $inherit,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($accounts as $account) {
                $meta = json_decode((string) ($account->meta_json ?? ''), true) ?: [];
                unset($meta['ai_chatbot_id']);
                DB::table('channel_accounts')->where('id', $account->id)->update([
                    'ai_eligible_from_at' => $account->ai_enabled_at ?: $account->created_at,
                    'meta_json' => $meta === [] ? null : json_encode($meta),
                ]);
            }
        }

        Schema::table('channel_accounts', function (Blueprint $table) {
            $table->dropIndex('channel_accounts_workspace_ai_mode_idx');
            $table->dropIndex('channel_accounts_ai_chatbot_idx');
            $table->dropColumn(['ai_answering_mode', 'ai_chatbot_id', 'ai_schedule_json', 'ai_enabled_at']);
        });
    }

    public function down(): void
    {
        Schema::table('channel_accounts', function (Blueprint $table) {
            $table->string('ai_answering_mode', 16)->default('off');
            $table->unsignedBigInteger('ai_chatbot_id')->nullable();
            $table->json('ai_schedule_json')->nullable();
            $table->timestamp('ai_enabled_at')->nullable();
            $table->index(['workspace_id', 'ai_answering_mode'], 'channel_accounts_workspace_ai_mode_idx');
            $table->index('ai_chatbot_id', 'channel_accounts_ai_chatbot_idx');
        });
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn(['ai_paused_at', 'ai_pause_reason']);
        });
        Schema::table('channel_accounts', function (Blueprint $table) {
            $table->dropColumn('ai_eligible_from_at');
        });
        Schema::dropIfExists('workspace_ai_answering_policies');
    }
};
