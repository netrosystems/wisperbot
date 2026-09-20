import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';
import { objectToFormData } from '@inertiajs/core';
import { normalizeAiSchedule } from '@/Components/WeeklyScheduleEditor';

// Widget settings are sent as multipart form data (for the launcher logo).
// Schedule windows are arrays of objects, which only survive PHP parsing
// with indexed keys: windows[0][start] + windows[0][end].
describe('widget settings form data', () => {
    const schedule = { ...normalizeAiSchedule(null, 'UTC'), enabled: true, mode: 'scheduled' };

    it('keeps each schedule window together with indexed keys', () => {
        const keys = [...objectToFormData({ ai_schedule_json: schedule }, new FormData(), null, 'indices').keys()];
        expect(keys).toContain('ai_schedule_json[schedule][mon][windows][0][start]');
        expect(keys).toContain('ai_schedule_json[schedule][mon][windows][0][end]');
        expect(keys.some(key => key.includes('[windows][]'))).toBe(false);
    });

    it('is used by both pages that save the widget form', () => {
        for (const page of ['Settings', 'Create']) {
            const source = readFileSync(`resources/js/Pages/Chat/Widgets/${page}.jsx`, 'utf8');
            expect(source).toContain("queryStringArrayFormat: 'indices'");
        }
    });
});
