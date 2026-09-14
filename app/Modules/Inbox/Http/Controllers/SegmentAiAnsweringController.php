<?php

namespace App\Modules\Inbox\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Workspace;
use App\Modules\AI\Models\AiChatbot;
use App\Modules\Inbox\Models\WorkspaceAiAnsweringPolicy;
use App\Modules\Inbox\Services\SegmentAiPolicyService;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Conversation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class SegmentAiAnsweringController extends Controller
{
    public function __construct(private readonly SegmentAiPolicyService $policies) {}

    public function index(Request $request): JsonResponse
    {
        $workspaceId = $this->workspaceId($request);

        return response()->json([
            'data' => collect(WorkspaceAiAnsweringPolicy::SEGMENTS)->mapWithKeys(fn (string $segment) => [
                $segment => $this->policies->payload($workspaceId, $segment),
            ]),
            'chatbots' => AiChatbot::where('workspace_id', $workspaceId)->orderBy('name')->get(['id', 'name']),
            'can_manage' => $this->canManage($request, $workspaceId),
        ]);
    }

    public function update(Request $request, string $segment): JsonResponse|RedirectResponse
    {
        abort_unless(in_array($segment, WorkspaceAiAnsweringPolicy::SEGMENTS, true), 404);
        $workspaceId = $this->workspaceId($request);
        abort_unless($this->canManage($request, $workspaceId), 403);
        $validated = $request->validate([
            'mode' => ['required', 'in:off,always_on,scheduled'],
            'chatbot_id' => ['nullable', 'integer'],
            'schedule' => ['nullable', 'array'],
        ]);
        $mode = (string) $validated['mode'];
        $chatbotId = $mode === 'off' ? null : ($validated['chatbot_id'] ?? null);
        if ($mode !== 'off') {
            abort_unless($chatbotId && AiChatbot::whereKey($chatbotId)->where('workspace_id', $workspaceId)->exists(), 422, 'Choose a Smart Bot.');
        }
        $schedule = $this->policies->normalizeSchedule($validated['schedule'] ?? null, $mode);

        Cache::lock("workspace-ai-policy:{$workspaceId}:{$segment}", 150)->block(30, function () use ($workspaceId, $segment, $mode, $chatbotId, $schedule): void {
            DB::transaction(function () use ($workspaceId, $segment, $mode, $chatbotId, $schedule): void {
                $policy = WorkspaceAiAnsweringPolicy::query()->lockForUpdate()->firstOrNew(['workspace_id' => $workspaceId, 'segment' => $segment]);
                $wasOff = ! $policy->exists || $policy->mode === 'off';
                $enabledAt = $mode === 'off' ? null : ($wasOff ? now() : ($policy->enabled_at ?: now()));
                $policy->fill([
                    'mode' => $mode, 'chatbot_id' => $chatbotId, 'schedule_json' => $schedule,
                    'enabled_at' => $enabledAt, 'requires_review' => false,
                ])->save();

                $channels = $segment === 'email' ? ['email'] : SegmentAiPolicyService::OMNI_CHANNELS;
                $accounts = ChannelAccount::where('workspace_id', $workspaceId)->whereIn('channel', $channels);
                if ($wasOff && $mode !== 'off') {
                    $accounts->update(['ai_eligible_from_at' => now()]);
                }
                if ($mode === 'off') {
                    Conversation::where('workspace_id', $workspaceId)
                        ->whereIn('channel_account_id', (clone $accounts)->select('id'))
                        ->where('status', '!=', 'resolved')->whereNull('joined_user_id')
                        ->whereNull('assigned_user_id')->whereNull('handover_at')->whereNull('ai_paused_at')
                        ->where('assigned_to', 'bot')->update(['assigned_to' => 'human']);
                }
            });
        });

        $payload = $this->policies->payload($workspaceId, $segment);
        if ($request->expectsJson()) {
            return response()->json(['data' => $payload]);
        }

        return back()->with('success', 'AI answering updated.');
    }

    private function workspaceId(Request $request): int
    {
        return (int) ($request->user()->current_workspace_id ?? $request->user()->workspace_id);
    }

    private function canManage(Request $request, int $workspaceId): bool
    {
        $user = $request->user();
        $workspace = Workspace::find($workspaceId);

        return $workspace && ((int) $workspace->owner_id === (int) $user->id
            || $user->isClientAdministrator()
            || $workspace->members()->where('user_id', $user->id)->wherePivotIn('role', ['owner', 'admin', 'administrator'])->exists());
    }
}
