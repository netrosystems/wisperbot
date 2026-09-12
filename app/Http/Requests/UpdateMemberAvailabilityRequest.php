<?php

namespace App\Http\Requests;

use App\Modules\Inbox\Services\WeeklySchedule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateMemberAvailabilityRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $schedule = $this->input('schedule');
        if (! is_array($schedule)) {
            return;
        }

        foreach ($schedule as $day => $settings) {
            if (is_array($settings) && ! array_key_exists('all_day', $settings)) {
                $schedule[$day]['all_day'] = false;
            }
        }

        $this->merge(['schedule' => $schedule]);
    }

    public function authorize(): bool
    {
        return (bool) $this->user()?->isClientAdministrator();
    }

    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
            'timezone' => ['required_if:enabled,true', 'string', 'max:64', 'timezone:all'],
            'schedule' => ['required_if:enabled,true', 'array'],
            'schedule.*' => ['array'],
            'schedule.*.enabled' => ['required', 'boolean'],
            'schedule.*.all_day' => ['required', 'boolean'],
            'schedule.*.windows' => ['array', 'max:3'],
            'schedule.*.windows.*.start' => ['required', 'date_format:H:i'],
            'schedule.*.windows.*.end' => ['required', 'date_format:H:i'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            $allowedDays = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];
            foreach (($this->input('schedule') ?? []) as $day => $settings) {
                if (! in_array($day, $allowedDays, true)) {
                    $validator->errors()->add("schedule.{$day}", 'Unknown weekday.');

                    continue;
                }
                if (empty($settings['enabled']) || ! empty($settings['all_day'])) {
                    continue;
                }
                $intervals = [];
                foreach (($settings['windows'] ?? []) as $index => $window) {
                    $start = $this->minutes($window['start'] ?? null);
                    $end = $this->minutes($window['end'] ?? null);
                    if ($start === null || $end === null) {
                        continue;
                    }
                    if ($start === $end) {
                        $validator->errors()->add("schedule.{$day}.windows.{$index}.end", 'Start and end time must be different. Use All day for 24-hour coverage.');

                        continue;
                    }
                    // Split overnight windows at the week/day boundary for overlap checks.
                    $intervals[] = $end > $start ? [$start, $end, $index] : [$start, 1440, $index];
                    if ($end < $start) {
                        $intervals[] = [0, $end, $index];
                    }
                }
                usort($intervals, fn ($a, $b) => $a[0] <=> $b[0]);
                for ($i = 1; $i < count($intervals); $i++) {
                    if ($intervals[$i][0] < $intervals[$i - 1][1] && $intervals[$i][2] !== $intervals[$i - 1][2]) {
                        $validator->errors()->add("schedule.{$day}.windows.{$intervals[$i][2]}", 'Availability windows cannot overlap.');
                    }
                }
            }
            if (app(WeeklySchedule::class)->hasOverlaps($this->input('schedule') ?? [])) {
                $validator->errors()->add('schedule', 'Availability windows cannot overlap, including across overnight day boundaries.');
            }
        }];
    }

    private function minutes(mixed $value): ?int
    {
        if (! is_string($value) || preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $value) !== 1) {
            return null;
        }
        [$hours, $minutes] = array_map('intval', explode(':', $value));

        return ($hours * 60) + $minutes;
    }
}
