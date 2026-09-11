<?php

namespace Tests\Unit\Inbox;

use App\Modules\Inbox\Services\WeeklySchedule;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class WeeklyScheduleTest extends TestCase
{
    public function test_split_and_overnight_windows_use_the_configured_timezone(): void
    {
        $schedule = ['enabled' => true, 'timezone' => 'Asia/Dhaka', 'schedule' => [
            'mon' => ['enabled' => true, 'all_day' => false, 'windows' => [
                ['start' => '09:00', 'end' => '12:00'], ['start' => '22:00', 'end' => '02:00'],
            ]],
        ]];
        $service = new WeeklySchedule;

        $this->assertTrue($service->contains($schedule, CarbonImmutable::parse('2026-09-07 04:00 UTC'))); // Monday 10:00
        $this->assertFalse($service->contains($schedule, CarbonImmutable::parse('2026-09-07 08:00 UTC'))); // Monday 14:00
        $this->assertTrue($service->contains($schedule, CarbonImmutable::parse('2026-09-07 17:00 UTC'))); // Monday 23:00
        $this->assertTrue($service->contains($schedule, CarbonImmutable::parse('2026-09-07 19:00 UTC'))); // Tuesday 01:00
    }

    public function test_disabled_schedule_is_always_available_and_malformed_enabled_schedule_fails_closed(): void
    {
        $service = new WeeklySchedule;
        $this->assertTrue($service->contains(['enabled' => false]));
        $this->assertFalse($service->contains(['enabled' => true, 'timezone' => 'Invalid/Zone', 'schedule' => []]));
    }

    public function test_cross_day_overlap_is_detected(): void
    {
        $service = new WeeklySchedule;
        $this->assertTrue($service->hasOverlaps([
            'mon' => ['enabled' => true, 'all_day' => false, 'windows' => [['start' => '22:00', 'end' => '02:00']]],
            'tue' => ['enabled' => true, 'all_day' => false, 'windows' => [['start' => '01:00', 'end' => '03:00']]],
        ]));
    }

    public function test_identical_and_week_boundary_windows_are_detected_as_overlaps(): void
    {
        $service = new WeeklySchedule;
        $this->assertTrue($service->hasOverlaps([
            'mon' => ['enabled' => true, 'all_day' => false, 'windows' => [
                ['start' => '09:00', 'end' => '12:00'],
                ['start' => '09:00', 'end' => '12:00'],
            ]],
        ]));
        $this->assertTrue($service->hasOverlaps([
            'sat' => ['enabled' => true, 'all_day' => false, 'windows' => [['start' => '23:00', 'end' => '02:00']]],
            'sun' => ['enabled' => true, 'all_day' => false, 'windows' => [['start' => '01:00', 'end' => '03:00']]],
        ]));
    }
}
