import { describe, expect, it } from 'vitest';
import { defaultWeeklySchedule, normalizeAiSchedule } from '@/Components/WeeklyScheduleEditor';

describe('weekly schedule adapters', () => {
    it('creates compact weekday defaults', () => {
        const schedule = defaultWeeklySchedule('Asia/Dhaka');
        expect(schedule.mode).toBe('scheduled');
        expect(schedule.schedule.mon.windows).toEqual([{ start: '09:00', end: '17:00' }]);
        expect(schedule.schedule.sun.enabled).toBe(false);
    });

    it('preserves legacy outside-hours behavior as active windows', () => {
        const converted = normalizeAiSchedule({
            enabled: true,
            mode: 'outside_hours',
            timezone: 'UTC',
            schedule: {
                mon: { enabled: true, start: '09:00', end: '17:00' },
                sun: { enabled: false, start: '09:00', end: '17:00' },
            },
        });
        expect(converted.mode).toBe('scheduled');
        expect(converted.schedule.mon.windows).toEqual([
            { start: '00:00', end: '09:00' },
            { start: '17:00', end: '00:00' },
        ]);
        expect(converted.schedule.sun.all_day).toBe(true);
    });

    it('maps a missing or disabled legacy schedule to permanent', () => {
        expect(normalizeAiSchedule(null, 'UTC').mode).toBe('permanent');
        expect(normalizeAiSchedule({ enabled: false, timezone: 'Asia/Tokyo' }).enabled).toBe(false);
    });
});
