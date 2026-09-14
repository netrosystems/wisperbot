<?php

namespace App\Modules\Inbox\Services;

use App\Modules\AI\Models\AiChatbot;
use App\Modules\Inbox\Models\WorkspaceAiAnsweringPolicy;
use App\Modules\Shared\Models\ChannelAccount;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

class SegmentAiPolicyService
{
    public const MODES = ['off', 'always_on', 'scheduled'];

    public const OMNI_CHANNELS = ['whatsapp', 'messenger', 'instagram', 'telegram', 'ebay'];

    public function __construct(private readonly WeeklySchedule $weekly) {}

    public function segmentFor(ChannelAccount|string $account): ?string
    {
        $channel = $account instanceof ChannelAccount ? $account->channel : $account;

        return $channel === 'email' ? 'email' : (in_array($channel, self::OMNI_CHANNELS, true) ? 'omni' : null);
    }

    public function supports(ChannelAccount|string $account): bool
    {
        return $this->segmentFor($account) !== null;
    }

    public function policy(int $workspaceId, string $segment): WorkspaceAiAnsweringPolicy
    {
        abort_unless(in_array($segment, WorkspaceAiAnsweringPolicy::SEGMENTS, true), 404);

        return WorkspaceAiAnsweringPolicy::firstOrNew(
            ['workspace_id' => $workspaceId, 'segment' => $segment],
            ['mode' => 'off', 'requires_review' => false],
        );
    }

    public function configured(ChannelAccount $account): bool
    {
        $segment = $this->segmentFor($account);
        if (! $segment || ! $this->runtimeEnabled($segment) || $account->status !== 'active') {
            return false;
        }
        $policy = $this->policy((int) $account->workspace_id, $segment);

        return $policy->mode !== 'off' && $policy->chatbot_id
            && ($policy->mode !== 'scheduled' || $this->validSchedule($policy->schedule_json))
            && AiChatbot::whereKey($policy->chatbot_id)->where('workspace_id', $account->workspace_id)->exists();
    }

    public function shouldAnswerNow(ChannelAccount $account, ?CarbonInterface $at = null): bool
    {
        if (! $this->configured($account)) {
            return false;
        }
        $policy = $this->policy((int) $account->workspace_id, (string) $this->segmentFor($account));

        return $policy->mode === 'always_on'
            || ($policy->mode === 'scheduled' && $this->weekly->contains($policy->schedule_json, $at));
    }

    public function decision(ChannelAccount $account, ?CarbonInterface $messageAt = null, ?CarbonInterface $at = null): string
    {
        $segment = $this->segmentFor($account);
        if (! $segment) {
            return 'unsupported_channel';
        }
        if (! $this->runtimeEnabled($segment)) {
            return 'runtime_disabled';
        }
        if ($account->status !== 'active') {
            return 'inactive_account';
        }
        $policy = $this->policy((int) $account->workspace_id, $segment);
        if ($policy->mode === 'off') {
            return 'off';
        }
        $cutoff = collect([$policy->enabled_at, $account->ai_eligible_from_at])->filter()->max();
        if ($messageAt && $cutoff && $messageAt->lt($cutoff)) {
            return 'historical_message';
        }
        if (! $policy->chatbot_id || ! AiChatbot::whereKey($policy->chatbot_id)->where('workspace_id', $account->workspace_id)->exists()) {
            return 'missing_bot';
        }
        if ($policy->mode === 'scheduled') {
            if (! $this->validSchedule($policy->schedule_json)) {
                return 'invalid_schedule';
            }
            if (! $this->weekly->contains($policy->schedule_json, $at)) {
                return 'outside_schedule';
            }
        }

        return 'eligible';
    }

    public function initialHandler(ChannelAccount $account): string
    {
        return $this->shouldAnswerNow($account) ? 'bot' : 'human';
    }

    public function chatbotId(ChannelAccount $account): ?int
    {
        $segment = $this->segmentFor($account);
        if (! $segment) {
            return null;
        }

        return $this->policy((int) $account->workspace_id, $segment)->chatbot_id;
    }

    /** @return array<string, mixed> */
    public function payload(int|ChannelAccount $workspace, ?string $segment = null): array
    {
        if ($workspace instanceof ChannelAccount) {
            $segment = $this->segmentFor($workspace);
            $workspaceId = (int) $workspace->workspace_id;
        } else {
            $workspaceId = $workspace;
        }
        abort_unless($segment && in_array($segment, WorkspaceAiAnsweringPolicy::SEGMENTS, true), 404);
        $policy = $this->policy($workspaceId, $segment);
        $missing = $policy->mode !== 'off' && (! $policy->chatbot_id || ! AiChatbot::whereKey($policy->chatbot_id)->where('workspace_id', $workspaceId)->exists());

        return [
            'segment' => $segment, 'mode' => $policy->mode ?: 'off', 'chatbot_id' => $policy->chatbot_id,
            'schedule' => $policy->schedule_json, 'enabled_at' => $policy->enabled_at?->toIso8601String(),
            'missing_bot' => $missing, 'needs_setup' => (bool) $policy->requires_review,
            'runtime_state' => $this->runtimeEnabled($segment) ? ($missing ? 'missing_bot' : ($policy->mode ?: 'off')) : 'runtime_disabled',
        ];
    }

    /** @param array<string, mixed>|null $schedule
     * @return array<string, mixed>|null
     */
    public function normalizeSchedule(?array $schedule, string $mode): ?array
    {
        if ($mode !== 'scheduled') {
            return null;
        }
        if (! is_array($schedule)) {
            throw ValidationException::withMessages(['schedule' => 'Choose the hours when AI may answer.']);
        }
        $timezone = (string) ($schedule['timezone'] ?? '');
        if (! in_array($timezone, timezone_identifiers_list(), true)) {
            throw ValidationException::withMessages(['schedule.timezone' => 'Choose a valid timezone.']);
        }
        $days = [];
        foreach (WeeklySchedule::DAYS as $day) {
            $value = $schedule['schedule'][$day] ?? [];
            if (! is_array($value)) {
                throw ValidationException::withMessages(["schedule.schedule.{$day}" => 'Use a valid schedule for this day.']);
            }
            $enabled = filter_var($value['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $allDay = filter_var($value['all_day'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $windows = $value['windows'] ?? [];
            if (! is_array($windows) || count($windows) > 3) {
                throw ValidationException::withMessages(["schedule.schedule.{$day}.windows" => 'Add no more than three windows per day.']);
            }
            if ($enabled && ! $allDay && $windows === []) {
                throw ValidationException::withMessages(["schedule.schedule.{$day}.windows" => 'Add hours or turn this day off.']);
            }
            foreach ($windows as $index => $window) {
                foreach (['start', 'end'] as $field) {
                    if (! is_array($window) || ! is_string($window[$field] ?? null) || preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $window[$field]) !== 1) {
                        throw ValidationException::withMessages(["schedule.schedule.{$day}.windows.{$index}.{$field}" => 'Enter a valid time.']);
                    }
                }
                if ($window['start'] === $window['end']) {
                    throw ValidationException::withMessages(["schedule.schedule.{$day}.windows.{$index}.end" => 'Use All day for a 24-hour window.']);
                }
            }
            $days[$day] = ['enabled' => $enabled, 'all_day' => $enabled && $allDay, 'windows' => $enabled && ! $allDay ? array_values($windows) : []];
        }
        if ($this->weekly->hasOverlaps($days)) {
            throw ValidationException::withMessages(['schedule.schedule' => 'AI active windows cannot overlap.']);
        }

        return ['enabled' => true, 'mode' => 'scheduled', 'timezone' => $timezone, 'schedule' => $days];
    }

    private function runtimeEnabled(string $segment): bool
    {
        return (bool) config($segment === 'email' ? 'inbox.email_ai_answering' : 'inbox.omnichannel_ai_answering', true);
    }

    /** @param array<string, mixed>|null $schedule */
    private function validSchedule(?array $schedule): bool
    {
        try {
            return is_array($schedule) && $this->normalizeSchedule($schedule, 'scheduled') !== null;
        } catch (\Throwable) {
            return false;
        }
    }
}
