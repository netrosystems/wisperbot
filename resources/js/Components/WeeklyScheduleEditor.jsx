import TimezonePicker from '@/Components/TimezonePicker';
import { Copy, Plus, Trash2 } from 'lucide-react';

export const SCHEDULE_DAYS = [
    ['mon', 'Monday'], ['tue', 'Tuesday'], ['wed', 'Wednesday'], ['thu', 'Thursday'],
    ['fri', 'Friday'], ['sat', 'Saturday'], ['sun', 'Sunday'],
];

export function defaultWeeklySchedule(timezone = 'UTC', enabled = true) {
    return {
        enabled,
        mode: enabled ? 'scheduled' : 'permanent',
        timezone,
        schedule: Object.fromEntries(SCHEDULE_DAYS.map(([key], index) => [key, {
            enabled: index < 5,
            all_day: false,
            windows: index < 5 ? [{ start: '09:00', end: '17:00' }] : [],
        }])),
    };
}

export function normalizeWeeklySchedule(value, timezone = 'UTC') {
    const defaults = defaultWeeklySchedule(value?.timezone || timezone, Boolean(value?.enabled));
    const suppliedSchedule = value?.schedule;
    const hasSuppliedDays = suppliedSchedule && typeof suppliedSchedule === 'object' && Object.keys(suppliedSchedule).length > 0;

    return {
        ...defaults,
        ...value,
        timezone: value?.timezone || timezone,
        schedule: Object.fromEntries(SCHEDULE_DAYS.map(([key]) => {
            const fallback = hasSuppliedDays
                ? { enabled: false, all_day: false, windows: [] }
                : defaults.schedule[key];
            const day = suppliedSchedule?.[key] || fallback;

            return [key, {
                enabled: Boolean(day.enabled),
                all_day: Boolean(day.all_day),
                windows: Array.isArray(day.windows) ? day.windows : [],
            }];
        })),
    };
}

export function normalizeAiSchedule(value, timezone = 'UTC') {
    if (!value || !value.enabled || value.mode === 'permanent') return defaultWeeklySchedule(value?.timezone || timezone, false);
    if (value.mode === 'scheduled') return { ...defaultWeeklySchedule(value.timezone || timezone), ...value };

    const next = defaultWeeklySchedule(value.timezone || timezone);
    SCHEDULE_DAYS.forEach(([day]) => {
        const old = value.schedule?.[day] || {};
        if (value.mode === 'inside_hours') {
            next.schedule[day] = { enabled: Boolean(old.enabled), all_day: false, windows: old.enabled ? [{ start: old.start || '09:00', end: old.end || '17:00' }] : [] };
        } else if (!old.enabled) {
            next.schedule[day] = { enabled: true, all_day: true, windows: [] };
        } else {
            const windows = [];
            if ((old.start || '09:00') !== '00:00') windows.push({ start: '00:00', end: old.start || '09:00' });
            if ((old.end || '17:00') !== '00:00') windows.push({ start: old.end || '17:00', end: '00:00' });
            next.schedule[day] = { enabled: windows.length > 0, all_day: false, windows };
        }
    });
    return next;
}

const control = 'rounded-lg border border-neutral-300 bg-white px-2.5 py-2 text-sm text-neutral-900 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/20 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100 disabled:opacity-40';

export default function WeeklyScheduleEditor({ value, onChange, timezoneLabel = 'Timezone', showTimezone = true }) {
    const updateDay = (key, patch) => onChange({
        ...value,
        schedule: {
            ...value.schedule,
            [key]: { enabled: false, all_day: false, windows: [], ...value.schedule?.[key], ...patch },
        },
    });
    const copyWeekdays = (source) => {
        const schedule = { ...value.schedule };
        ['mon', 'tue', 'wed', 'thu', 'fri'].forEach(day => { schedule[day] = JSON.parse(JSON.stringify(source)); });
        onChange({ ...value, schedule });
    };

    return <div className="space-y-3">
        {showTimezone && <label className="block text-xs font-medium text-neutral-600 dark:text-neutral-300">
            <span className="mb-1 block">{timezoneLabel}</span>
            <TimezonePicker value={value.timezone || 'UTC'} onChange={timezone => onChange({ ...value, timezone })} />
        </label>}
        <div className="overflow-hidden rounded-xl border border-neutral-200 bg-white dark:border-neutral-700 dark:bg-neutral-900">
            {SCHEDULE_DAYS.map(([key, label]) => {
                const day = value.schedule?.[key] || { enabled: false, all_day: false, windows: [] };
                return <div key={key} className="border-b border-neutral-100 p-3 last:border-0 dark:border-neutral-800">
                    <div className="flex items-center gap-2">
                        <label className="flex min-w-[104px] items-center gap-2 text-sm font-medium">
                            <input type="checkbox" checked={Boolean(day.enabled)} onChange={e => updateDay(key, { enabled: e.target.checked, windows: e.target.checked && !day.windows?.length ? [{ start: '09:00', end: '17:00' }] : day.windows })} className="rounded text-brand-500 focus:ring-brand-500" />
                            {label.slice(0, 3)}
                        </label>
                        {day.enabled && <label className="flex items-center gap-1.5 text-xs text-neutral-500"><input type="checkbox" checked={Boolean(day.all_day)} onChange={e => updateDay(key, { all_day: e.target.checked })} className="rounded text-brand-500 focus:ring-brand-500" />All day</label>}
                        {key === 'mon' && day.enabled && <button type="button" onClick={() => copyWeekdays(day)} className="ml-auto inline-flex items-center gap-1 text-xs font-medium text-brand-600"><Copy className="h-3.5 w-3.5" />Copy weekdays</button>}
                    </div>
                    {day.enabled && !day.all_day && <div className="mt-2 space-y-2 sm:pl-[112px]">
                        {(day.windows || []).map((window, index) => <div key={index} className="flex flex-wrap items-center gap-2">
                            <input aria-label={`${label} start ${index + 1}`} type="time" value={window.start} onChange={e => updateDay(key, { windows: day.windows.map((w, i) => i === index ? { ...w, start: e.target.value } : w) })} className={`${control} min-w-[105px] flex-1`} />
                            <span className="text-xs text-neutral-400">to</span>
                            <input aria-label={`${label} end ${index + 1}`} type="time" value={window.end} onChange={e => updateDay(key, { windows: day.windows.map((w, i) => i === index ? { ...w, end: e.target.value } : w) })} className={`${control} min-w-[105px] flex-1`} />
                            <button type="button" aria-label={`Remove ${label} window ${index + 1}`} onClick={() => updateDay(key, { windows: day.windows.filter((_, i) => i !== index) })} className="rounded p-1.5 text-neutral-400 hover:bg-red-50 hover:text-red-600"><Trash2 className="h-4 w-4" /></button>
                            {window.end < window.start && <span className="basis-full text-right text-[10px] font-medium text-amber-600">Ends next day</span>}
                        </div>)}
                        {(day.windows || []).length < 3 && <button type="button" onClick={() => updateDay(key, { windows: [...(day.windows || []), { start: '09:00', end: '17:00' }] })} className="inline-flex items-center gap-1 text-xs font-medium text-brand-600"><Plus className="h-3.5 w-3.5" />Add hours</button>}
                    </div>}
                </div>;
            })}
        </div>
        <p className="text-[11px] text-neutral-500">An end time earlier than its start continues into the next day. Times follow the selected timezone.</p>
    </div>;
}
