import { useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Bot, CalendarClock, Lock, MessageCircle, Send, Sparkles } from 'lucide-react';
import TimezonePicker from '@/Components/TimezonePicker';

const SCHEDULE_DAYS = [
    ['mon', 'Monday'],
    ['tue', 'Tuesday'],
    ['wed', 'Wednesday'],
    ['thu', 'Thursday'],
    ['fri', 'Friday'],
    ['sat', 'Saturday'],
    ['sun', 'Sunday'],
];

function defaultAiSchedule(timezone) {
    return {
        enabled: false,
        mode: 'outside_hours',
        timezone: timezone || 'UTC',
        schedule: Object.fromEntries(SCHEDULE_DAYS.map(([key], index) => [key, {
            enabled: index < 5,
            start: '09:00',
            end: '17:00',
        }])),
    };
}

/** Small labelled field wrapper. */
function Field({ label, hint, children }) {
    return (
        <label className="block">
            <span className="block text-sm font-medium text-neutral-700 dark:text-neutral-300 mb-1.5">{label}</span>
            {children}
            {hint && <span className="block text-xs text-neutral-400 mt-1">{hint}</span>}
        </label>
    );
}

const inputCls =
    'w-full rounded-lg border border-neutral-300 dark:border-neutral-700 bg-white dark:bg-neutral-900 px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/25 transition';

function Toggle({ checked, onChange, label, description }) {
    return (
        <button
            type="button"
            role="switch"
            aria-checked={checked}
            onClick={() => onChange(!checked)}
            className="flex w-full items-start gap-3 rounded-md text-left focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 focus-visible:ring-offset-2 dark:focus-visible:ring-offset-neutral-900"
        >
            <span className={`mt-0.5 relative inline-flex h-5 w-9 flex-shrink-0 items-center rounded-full transition ${checked ? 'bg-brand-500' : 'bg-neutral-300 dark:bg-neutral-700'}`}>
                <span className={`inline-block h-4 w-4 transform rounded-full bg-white transition ${checked ? 'translate-x-4' : 'translate-x-0.5'}`} />
            </span>
            <span>
                <span className="block text-sm font-medium text-neutral-800 dark:text-neutral-200">{label}</span>
                {description && <span className="block text-xs text-neutral-400">{description}</span>}
            </span>
        </button>
    );
}

function Card({ title, icon, children }) {
    return (
        <div className="rounded-2xl border border-neutral-200 dark:border-neutral-800 bg-white dark:bg-neutral-900 p-5">
            {title && (
                <h3 className="mb-4 flex items-center gap-2 text-sm font-semibold text-neutral-900 dark:text-neutral-100">
                    {icon} {title}
                </h3>
            )}
            <div className="space-y-4">{children}</div>
        </div>
    );
}

export default function ChatWidgetForm({ widget = null, chatbots = [], canUseCustomLauncherLogo = false, submitLabel, onSubmit }) {
    const { t } = useTranslation();
    const userTimezone = usePage().props.timezone || Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC';
    const initialAiSchedule = widget?.ai_schedule_json ?? defaultAiSchedule(userTimezone);

    const { data, setData, processing, errors } = useForm({
        name: widget?.name ?? '',
        title: widget?.title ?? 'Chat with us',
        subtitle: widget?.subtitle ?? 'We typically reply in a few minutes',
        welcome_message: widget?.welcome_message ?? 'Hi there 👋 How can we help you today?',
        agent_name: widget?.agent_name ?? 'Support',
        avatar_url: widget?.avatar_url ?? '',
        primary_color: widget?.primary_color ?? '#ff762e',
        position: widget?.position ?? 'bottom_right',
        launcher_text: widget?.launcher_text ?? '',
        footer_company_name: widget?.footer_company_name ?? 'WisperBot',
        launcher_logo: null,
        remove_launcher_logo: false,
        launcher_logo_url: widget?.launcher_logo_url ?? null,
        enabled: widget?.enabled ?? true,
        ai_enabled: widget?.ai_enabled ?? false,
        ai_chatbot_id: widget?.ai_chatbot_id ?? '',
        ai_schedule_json: initialAiSchedule,
        require_prechat: widget?.require_prechat ?? false,
        prechat_fields: widget?.prechat_fields ?? ['name', 'email'],
        offline_message: widget?.offline_message ?? '',
        allowed_domains: widget?.allowed_domains ?? [],
        identity_verification: widget?.identity_verification ?? false,
    });

    const [domainsText, setDomainsText] = useState((widget?.allowed_domains ?? []).join('\n'));
    const [launcherLogoPreview, setLauncherLogoPreview] = useState(widget?.launcher_logo_url ?? null);
    const aiScheduleError = Object.entries(errors).find(([key]) => key.startsWith('ai_schedule_json'))?.[1];

    const togglePrechatField = (field) => {
        const has = data.prechat_fields.includes(field);
        setData('prechat_fields', has ? data.prechat_fields.filter((f) => f !== field) : [...data.prechat_fields, field]);
    };

    const submit = (e) => {
        e.preventDefault();
        const payload = {
            ...data,
            ai_chatbot_id: data.ai_enabled && data.ai_chatbot_id ? data.ai_chatbot_id : null,
            allowed_domains: domainsText.split(/[\n,]/).map((d) => d.trim()).filter(Boolean),
        };
        onSubmit(payload);
    };

    return (
        <form onSubmit={submit} className="grid gap-6 lg:grid-cols-[1fr_360px]">
            {/* ── Left: settings ── */}
            <div className="space-y-6">
                <Card title="Appearance" icon={<MessageCircle className="h-4 w-4 text-brand-500" />}>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="Widget name" hint="Internal label — customers don't see this.">
                            <input className={inputCls} value={data.name} onChange={(e) => setData('name', e.target.value)} placeholder="Main site chat" />
                        </Field>
                        <Field label="Brand color">
                            <div className="flex items-center gap-2">
                                <input type="color" value={data.primary_color} onChange={(e) => setData('primary_color', e.target.value)} className="h-9 w-12 rounded border border-neutral-300 dark:border-neutral-700 bg-transparent p-0.5" />
                                <input className={inputCls} value={data.primary_color} onChange={(e) => setData('primary_color', e.target.value)} />
                            </div>
                        </Field>
                        <Field label="Header title">
                            <input className={inputCls} value={data.title} onChange={(e) => setData('title', e.target.value)} />
                        </Field>
                        <Field label="Header subtitle">
                            <input className={inputCls} value={data.subtitle} onChange={(e) => setData('subtitle', e.target.value)} />
                        </Field>
                        <Field label="Agent name">
                            <input className={inputCls} value={data.agent_name} onChange={(e) => setData('agent_name', e.target.value)} />
                        </Field>
                        <Field label="Avatar URL" hint="Optional — leave blank to show initials.">
                            <input className={inputCls} value={data.avatar_url} onChange={(e) => setData('avatar_url', e.target.value)} placeholder="https://…/avatar.png" />
                        </Field>
                    </div>
                    <Field label="Welcome message" hint="The first thing visitors see when they open the chat.">
                        <textarea className={inputCls} rows={2} value={data.welcome_message} onChange={(e) => setData('welcome_message', e.target.value)} />
                    </Field>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="Launcher position">
                            <select className={inputCls} value={data.position} onChange={(e) => setData('position', e.target.value)}>
                                <option value="bottom_right">Bottom right</option>
                                <option value="bottom_left">Bottom left</option>
                            </select>
                        </Field>
                        <Field label="Launcher label" hint="Optional text next to the bubble.">
                            <input className={inputCls} value={data.launcher_text} onChange={(e) => setData('launcher_text', e.target.value)} placeholder="Chat with us" />
                        </Field>
                        <Field label="Footer company name" hint="Shown to visitors as “Powered by {Company name}”. Leave as WisperBot to use the default.">
                            <input className={inputCls} value={data.footer_company_name} onChange={(e) => setData('footer_company_name', e.target.value)} placeholder="Your company name" />
                        </Field>
                        <Field
                            label="Custom launcher icon — Pro feature"
                            hint={canUseCustomLauncherLogo
                                ? 'Optional. Upload a square PNG, JPG, WebP or GIF (max 2 MB).'
                                : 'Free workspaces use the default WisperBot icon. Upgrade to Pro to use your own launcher icon.'}
                        >
                            {canUseCustomLauncherLogo ? (
                                <>
                                <input
                                    type="file"
                                    accept="image/png,image/jpeg,image/webp,image/gif"
                                    className={inputCls}
                                    onChange={(e) => {
                                        const file = e.target.files?.[0] ?? null;
                                        setData('launcher_logo', file);
                                        setData('remove_launcher_logo', false);
                                        setLauncherLogoPreview(file ? URL.createObjectURL(file) : (widget?.launcher_logo_url ?? null));
                                    }}
                                />
                                {launcherLogoPreview && !data.remove_launcher_logo && (
                                    <div className="mt-2 flex items-center gap-2 rounded-lg border border-neutral-200 dark:border-neutral-700 bg-neutral-50 dark:bg-neutral-800/60 px-3 py-2">
                                        <img src={launcherLogoPreview} alt="Launcher icon preview" className="h-9 w-9 rounded-full object-cover" />
                                        <span className="text-xs text-neutral-500">Launcher icon preview</span>
                                    </div>
                                )}
                                {data.launcher_logo_url && !data.remove_launcher_logo && (
                                    <label className="mt-2 flex items-center gap-2 text-xs text-neutral-500">
                                        <input
                                            type="checkbox"
                                            checked={data.remove_launcher_logo}
                                            onChange={(e) => {
                                                setData('remove_launcher_logo', e.target.checked);
                                                if (e.target.checked) setLauncherLogoPreview(null);
                                                else setLauncherLogoPreview(widget?.launcher_logo_url ?? null);
                                            }}
                                            className="rounded"
                                        />
                                        Remove the current custom logo
                                    </label>
                                )}
                                </>
                            ) : (
                                <div className="flex min-h-10 items-center gap-2 rounded-lg border border-dashed border-neutral-300 bg-neutral-50 px-3 text-sm text-neutral-500 dark:border-neutral-700 dark:bg-neutral-800/50 dark:text-neutral-400">
                                    <Lock className="h-4 w-4 shrink-0" />
                                    Upgrade to Pro to replace the default WisperBot icon
                                </div>
                            )}
                        </Field>
                    </div>
                </Card>

                <Card title="AI answering" icon={<Bot className="h-4 w-4 text-brand-500" />}>
                    <Toggle
                        checked={data.ai_enabled}
                        onChange={(v) => setData('ai_enabled', v)}
                        label={t('widget_appearance.smart_bot_first', 'Let a Smart Bot answer first')}
                        description="Off = messages go straight to your live agents. On = the AI replies instantly, then hands off to a human when needed."
                    />
                    {data.ai_enabled && (
                        <>
                            {chatbots.length > 0 ? (
                                <Field label={t('widget_appearance.smart_bot', 'Smart Bot')}>
                                    <select className={inputCls} value={data.ai_chatbot_id ?? ''} onChange={(e) => setData('ai_chatbot_id', e.target.value)}>
                                        <option value="">{t('widget_appearance.select_smart_bot', 'Select a Smart Bot…')}</option>
                                        {chatbots.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
                                    </select>
                                </Field>
                            ) : (
                                <p className="rounded-lg bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 px-3 py-2 text-xs text-amber-700 dark:text-amber-300">
                                    {t('widget_appearance.no_smart_bots', 'No active Smart Bots yet. Create one under AI Automations → Smart Bots, then select it here.')}
                                </p>
                            )}

                            <div className="border-t border-neutral-200 pt-4 dark:border-neutral-800">
                                <Toggle
                                    checked={Boolean(data.ai_schedule_json?.enabled)}
                                    onChange={(enabled) => setData('ai_schedule_json', {
                                        ...(data.ai_schedule_json ?? defaultAiSchedule(userTimezone)),
                                        enabled,
                                    })}
                                    label={t('widget_appearance.schedule_ai', 'Schedule AI answering')}
                                    description={t('widget_appearance.schedule_ai_hint', 'Set office hours and choose whether the Smart Bot answers during them or while your team is away.')}
                                />

                                {data.ai_schedule_json?.enabled && (
                                    <div className="mt-4 rounded-xl border border-neutral-200 bg-neutral-50/70 p-4 dark:border-neutral-700 dark:bg-neutral-800/50">
                                        <div className="mb-3 flex items-start gap-2">
                                            <CalendarClock className="mt-0.5 h-4 w-4 shrink-0 text-brand-500" />
                                            <div>
                                                <p className="text-sm font-semibold text-neutral-900 dark:text-neutral-100">
                                                    {t('widget_appearance.ai_schedule_heading', 'AI schedule')}
                                                </p>
                                                <p className="mt-0.5 text-xs text-neutral-500 dark:text-neutral-400">
                                                    {t('widget_appearance.ai_schedule_description', 'Set your normal office hours, then choose whether AI answers inside or outside them.')}
                                                </p>
                                            </div>
                                        </div>

                                        <div className="grid gap-3 sm:grid-cols-2">
                                            <Field label={t('widget_appearance.ai_answers_when', 'AI answers')}>
                                                <select
                                                    className={inputCls}
                                                    value={data.ai_schedule_json.mode}
                                                    onChange={(e) => setData('ai_schedule_json', { ...data.ai_schedule_json, mode: e.target.value })}
                                                >
                                                    <option value="outside_hours">{t('widget_appearance.outside_office_hours', 'Outside office hours (recommended)')}</option>
                                                    <option value="inside_hours">{t('widget_appearance.during_office_hours', 'During office hours')}</option>
                                                </select>
                                            </Field>
                                            <Field label={t('widget_appearance.schedule_timezone', 'Timezone')}>
                                                <TimezonePicker
                                                    value={data.ai_schedule_json.timezone}
                                                    onChange={(timezone) => setData('ai_schedule_json', { ...data.ai_schedule_json, timezone })}
                                                />
                                            </Field>
                                        </div>

                                        <div className="mt-4 overflow-hidden rounded-lg border border-neutral-200 bg-white dark:border-neutral-700 dark:bg-neutral-900">
                                            <div className="grid grid-cols-[minmax(88px,1fr)_96px_96px] gap-2 border-b border-neutral-100 px-3 py-2 text-[11px] font-semibold uppercase tracking-wide text-neutral-400 dark:border-neutral-800">
                                                <span>{t('widget_appearance.office_day', 'Office day')}</span>
                                                <span>{t('widget_appearance.opens', 'Opens')}</span>
                                                <span>{t('widget_appearance.closes', 'Closes')}</span>
                                            </div>
                                            {SCHEDULE_DAYS.map(([key, label]) => {
                                                const hours = data.ai_schedule_json.schedule?.[key] ?? { enabled: false, start: '09:00', end: '17:00' };
                                                const updateHours = (changes) => setData('ai_schedule_json', {
                                                    ...data.ai_schedule_json,
                                                    schedule: {
                                                        ...data.ai_schedule_json.schedule,
                                                        [key]: { ...hours, ...changes },
                                                    },
                                                });

                                                return (
                                                    <div key={key} className="grid grid-cols-[minmax(88px,1fr)_96px_96px] items-center gap-2 border-b border-neutral-100 px-3 py-2 last:border-b-0 dark:border-neutral-800">
                                                        <label className="flex min-w-0 items-center gap-2 text-sm text-neutral-700 dark:text-neutral-300">
                                                            <input
                                                                type="checkbox"
                                                                checked={Boolean(hours.enabled)}
                                                                onChange={(e) => updateHours({ enabled: e.target.checked })}
                                                                className="rounded border-neutral-300 text-brand-500 focus:ring-brand-500/30"
                                                            />
                                                            <span className="truncate">{t(`common.${key}`, label)}</span>
                                                        </label>
                                                        <input aria-label={`${label} opens`} type="time" value={hours.start} disabled={!hours.enabled} onChange={(e) => updateHours({ start: e.target.value })} className={`${inputCls} px-2 disabled:opacity-40`} />
                                                        <input aria-label={`${label} closes`} type="time" value={hours.end} disabled={!hours.enabled} onChange={(e) => updateHours({ end: e.target.value })} className={`${inputCls} px-2 disabled:opacity-40`} />
                                                    </div>
                                                );
                                            })}
                                        </div>
                                        <p className="mt-2 text-xs text-neutral-500 dark:text-neutral-400">
                                            {data.ai_schedule_json.mode === 'outside_hours'
                                                ? t('widget_appearance.closed_day_ai_hint', 'On unchecked days your office is closed, so AI answers all day.')
                                                : t('widget_appearance.closed_day_rest_hint', 'On unchecked days your office is closed, so AI rests all day.')}
                                        </p>
                                        {aiScheduleError && <p role="alert" className="mt-2 text-xs font-medium text-red-600 dark:text-red-400">{aiScheduleError}</p>}
                                    </div>
                                )}
                            </div>
                        </>
                    )}
                </Card>

                <Card title="Visitor experience" icon={<Sparkles className="h-4 w-4 text-brand-500" />}>
                    <Toggle
                        checked={data.require_prechat}
                        onChange={(v) => setData('require_prechat', v)}
                        label="Ask for details before chatting"
                        description="Collect a name and/or email before the conversation starts."
                    />
                    {data.require_prechat && (
                        <div className="flex gap-4 pl-1">
                            {['name', 'email'].map((f) => (
                                <label key={f} className="flex items-center gap-2 text-sm text-neutral-700 dark:text-neutral-300">
                                    <input type="checkbox" checked={data.prechat_fields.includes(f)} onChange={() => togglePrechatField(f)} className="rounded border-neutral-300 text-brand-500 focus:ring-brand-500/30" />
                                    <span className="capitalize">{f}</span>
                                </label>
                            ))}
                        </div>
                    )}
                    <Field label="Allowed domains" hint="One per line. Leave empty to allow the widget on any site. e.g. example.com">
                        <textarea className={inputCls} rows={2} value={domainsText} onChange={(e) => setDomainsText(e.target.value)} placeholder="example.com&#10;shop.example.com" />
                    </Field>
                    <Toggle
                        checked={data.identity_verification}
                        onChange={(v) => setData('identity_verification', v)}
                        label="Verify passed identity (recommended)"
                        description="Only trust a logged-in customer's name/email if your server signs it with the widget secret. Prevents visitors impersonating others. Setup snippet is on this page after saving."
                    />
                    <Toggle checked={data.enabled} onChange={(v) => setData('enabled', v)} label="Widget enabled" description="Turn the widget off without deleting it." />
                </Card>
            </div>

            {/* ── Right: live preview ── */}
            <div className="lg:sticky lg:top-6 h-fit space-y-4">
                <div className="rounded-2xl border border-neutral-200 dark:border-neutral-800 bg-neutral-50 dark:bg-neutral-900/50 p-4">
                    <p className="mb-3 text-xs font-medium uppercase tracking-wide text-neutral-400">Live preview</p>
                    <WidgetPreview data={data} />
                </div>
                <button
                    type="submit"
                    disabled={processing}
                    className="w-full rounded-lg bg-brand-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-brand-700 disabled:opacity-60 transition"
                >
                    {submitLabel}
                </button>
                {Object.keys(errors).length > 0 && (
                    <p className="text-xs text-red-500">Please review the highlighted fields.</p>
                )}
            </div>
        </form>
    );
}

/** A faithful, static mock of the embedded widget using the live form values. */
function WidgetPreview({ data }) {
    const color = data.primary_color || '#ff762e';
    const initial = (data.agent_name || 'S').trim().charAt(0).toUpperCase();
    return (
        <div className="mx-auto w-full max-w-[300px] overflow-hidden rounded-2xl border border-neutral-200 dark:border-neutral-700 bg-white shadow-lg">
            <div className="flex items-center gap-2.5 p-3.5 text-white" style={{ background: color }}>
                {data.avatar_url
                    ? <img src={data.avatar_url} alt="" className="h-9 w-9 rounded-full object-cover" />
                    : <span className="flex h-9 w-9 items-center justify-center rounded-full bg-white/25 text-sm font-bold">{initial}</span>}
                <div className="min-w-0">
                    <p className="truncate text-sm font-semibold">{data.title || 'Chat with us'}</p>
                    <p className="flex items-center gap-1.5 text-[11px] opacity-90">
                        <span className="h-1.5 w-1.5 rounded-full bg-green-300" /> {data.subtitle || 'Online'}
                    </p>
                </div>
            </div>
            <div className="space-y-2 bg-neutral-50 p-3.5" style={{ minHeight: 120 }}>
                <div className="flex items-end gap-1.5">
                    <span className="flex h-6 w-6 items-center justify-center rounded-full text-[10px] font-bold text-white" style={{ background: color }}>{initial}</span>
                    <div className="max-w-[80%] rounded-2xl rounded-bl-sm border border-neutral-100 bg-white px-3 py-2 text-[13px] text-neutral-800">
                        {data.welcome_message || 'Hi there 👋 How can we help?'}
                    </div>
                </div>
                <div className="flex justify-end">
                    <div className="max-w-[80%] rounded-2xl rounded-br-sm px-3 py-2 text-[13px] text-white" style={{ background: color }}>
                        Hi! I have a quick question.
                    </div>
                </div>
            </div>
            <div className="flex items-center gap-2 border-t border-neutral-100 bg-white p-2.5">
                <span className="flex-1 text-[13px] text-neutral-400">Type your message…</span>
                <span className="flex h-8 w-8 items-center justify-center rounded-full text-white" style={{ background: color }}><Send className="h-4 w-4" /></span>
            </div>
            <div className="border-t border-neutral-100 bg-white py-1.5 text-center text-[10px] text-neutral-400">Powered by <b className="font-semibold text-neutral-600">{data.footer_company_name || 'WisperBot'}</b></div>
        </div>
    );
}
