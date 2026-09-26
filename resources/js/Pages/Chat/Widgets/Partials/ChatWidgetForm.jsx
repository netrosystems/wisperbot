import { useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Bot, CalendarClock, ListChecks, Lock, MessageCircle, Palette, Send, Sparkles } from 'lucide-react';
import WeeklyScheduleEditor, { defaultWeeklySchedule, normalizeAiSchedule } from '@/Components/WeeklyScheduleEditor';
import StarterQuestionsEditor from '@/Components/StarterQuestionsEditor';

const TABS = [
    { id: 'appearance', labelKey: 'widget_appearance.tab_appearance', label: 'Appearance' },
    { id: 'behaviour', labelKey: 'widget_appearance.tab_ai_visitors', label: 'AI & visitors' },
    { id: 'starter', labelKey: 'widget_appearance.tab_starter_questions', label: 'Starter questions' },
];

const APPEARANCE_FIELDS = ['name', 'title', 'subtitle', 'welcome_message', 'agent_name', 'avatar_url', 'primary_color', 'position', 'launcher_text', 'footer_company_name', 'launcher_logo', 'remove_launcher_logo'];

/** The tab that holds a validation error, so a failed save shows its field. */
export function tabForErrorKey(key) {
    if (key.startsWith('starter_questions')) return 'starter';
    return APPEARANCE_FIELDS.includes(key.split('.')[0]) ? 'appearance' : 'behaviour';
}

const TAB_STORAGE_KEY = 'wisperbot.widgetSetupTab';

function initialTab() {
    try {
        const saved = window.sessionStorage.getItem(TAB_STORAGE_KEY);
        return TABS.some(tab => tab.id === saved) ? saved : 'appearance';
    } catch {
        return 'appearance';
    }
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
    const initialAiSchedule = normalizeAiSchedule(widget?.ai_schedule_json, userTimezone);

    const pageErrors = usePage().props.errors ?? {};
    const { data, setData, errors: formErrors } = useForm({
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
        sdk_enabled: widget?.sdk_enabled ?? true,
        ai_enabled: widget?.ai_enabled ?? false,
        ai_chatbot_id: widget?.ai_chatbot_id ?? '',
        ai_schedule_json: initialAiSchedule,
        require_prechat: widget?.require_prechat ?? false,
        prechat_fields: widget?.prechat_fields ?? ['name', 'email'],
        offline_message: widget?.offline_message ?? '',
        allowed_domains: widget?.allowed_domains ?? [],
        identity_verification: widget?.identity_verification ?? false,
        starter_questions_enabled: widget?.starter_questions_enabled ?? false,
        starter_questions: Array.isArray(widget?.starter_questions) ? widget.starter_questions : [],
    });

    // The parent pages submit with router.post, so validation errors arrive as
    // page props rather than on this useForm instance.
    const errors = { ...formErrors, ...pageErrors };
    const [processing, setProcessing] = useState(false);
    const [domainsText, setDomainsText] = useState((widget?.allowed_domains ?? []).join('\n'));
    const [launcherLogoPreview, setLauncherLogoPreview] = useState(widget?.launcher_logo_url ?? null);
    const aiScheduleError = Object.entries(errors).find(([key]) => key.startsWith('ai_schedule_json'))?.[1];
    const [tab, setTab] = useState(initialTab);
    const selectTab = (id) => {
        setTab(id);
        try { window.sessionStorage.setItem(TAB_STORAGE_KEY, id); } catch { /* storage unavailable */ }
    };
    const errorKeys = Object.keys(errors);
    const firstErrorKey = errorKeys[0];
    const tabsWithErrors = new Set(errorKeys.map(tabForErrorKey));
    // When a save fails, open the tab holding the error unless the open tab has one.
    const [shownErrorKey, setShownErrorKey] = useState(null);
    if (firstErrorKey !== shownErrorKey) {
        setShownErrorKey(firstErrorKey);
        if (firstErrorKey && !tabsWithErrors.has(tab)) setTab(tabForErrorKey(firstErrorKey));
    }
    const selectedBotUnavailable = Boolean(widget?.ai_chatbot_id) && !chatbots.some(bot => String(bot.id) === String(widget.ai_chatbot_id));

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
            // A blank row is dropped rather than failing the save.
            starter_questions: data.starter_questions
                .filter((item) => item.question.trim() !== '' || item.answer.trim() !== '')
                .map(({ id, question, answer }) => (id ? { id, question, answer } : { question, answer })),
        };
        onSubmit(payload, {
            onStart: () => setProcessing(true),
            onFinish: () => setProcessing(false),
            // The page keeps its state after a save, so pick up the ids the
            // server gave new questions; saving again then keeps them stable.
            onSuccess: (page) => {
                const saved = page?.props?.widget?.starter_questions;
                if (Array.isArray(saved)) setData('starter_questions', saved);
            },
        });
    };

    return (
        <form onSubmit={submit} className="grid gap-6 lg:grid-cols-[1fr_360px]">
            {/* ── Left: settings ── */}
            <div className="space-y-6">
                <div role="tablist" aria-label={t('widget_appearance.sections', 'Widget Setup sections')} className="flex flex-wrap items-center gap-2">
                    {TABS.map(({ id, labelKey, label }) => (
                        <button
                            key={id}
                            type="button"
                            role="tab"
                            id={`wtab-${id}`}
                            aria-selected={tab === id}
                            aria-controls={`wpanel-${id}`}
                            onClick={() => selectTab(id)}
                            className={`inline-flex items-center gap-1.5 rounded-full border px-3.5 py-1.5 text-sm font-medium transition focus:outline-none focus:ring-2 focus:ring-brand-500/30 ${
                                tab === id
                                    ? 'border-brand-200 bg-brand-50 text-brand-700 dark:border-brand-700 dark:bg-brand-900/30 dark:text-brand-300'
                                    : 'border-neutral-300 text-neutral-600 hover:bg-neutral-50 dark:border-neutral-700 dark:text-neutral-400 dark:hover:bg-neutral-800'
                            }`}
                        >
                            {t(labelKey, label)}
                            {tabsWithErrors.has(id) && <span className="h-1.5 w-1.5 rounded-full bg-red-500" aria-label={t('widget_appearance.tab_has_errors', 'Has errors')} />}
                        </button>
                    ))}
                </div>

                {/* Every panel stays mounted and inactive ones are hidden, so
                    unsaved edits survive switching tabs and one Save covers all. */}
                <div role="tabpanel" id="wpanel-appearance" aria-labelledby="wtab-appearance" hidden={tab !== 'appearance'} className="space-y-6">
                <Card title={t('widget_appearance.branding', 'Branding')} icon={<Palette className="h-4 w-4 text-brand-500" />}>
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
                        <Field label="Footer company name" hint="Shown to visitors as “Powered by {Company name}”. Leave as WisperBot to use the default.">
                            <input className={inputCls} value={data.footer_company_name} onChange={(e) => setData('footer_company_name', e.target.value)} placeholder="Your company name" />
                        </Field>
                    </div>
                </Card>

                <Card title={t('widget_appearance.launcher_welcome', 'Launcher & welcome')} icon={<MessageCircle className="h-4 w-4 text-brand-500" />}>
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
                </div>

                <div role="tabpanel" id="wpanel-behaviour" aria-labelledby="wtab-behaviour" hidden={tab !== 'behaviour'} className="space-y-6">
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
                                    {t('widget_appearance.no_smart_bots', 'No Smart Bots yet. Create one under AI Automations → Smart Bots, then select it here.')}
                                </p>
                            )}
                            {selectedBotUnavailable && <p className="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-700 dark:border-amber-800 dark:bg-amber-900/20 dark:text-amber-300">The previously selected Smart Bot is no longer available. AI will not answer until you select another bot.</p>}
                            <a href={route('client.ai.chatbots.index')} className="inline-flex text-xs font-semibold text-brand-600 hover:text-brand-700">Manage Smart Bots</a>

                            <div className="border-t border-neutral-200 pt-4 dark:border-neutral-800">
                                <div className="flex rounded-lg bg-neutral-100 p-1 dark:bg-neutral-800" role="radiogroup" aria-label="AI answering schedule">
                                    {[['permanent', 'Permanent'], ['scheduled', 'Scheduled']].map(([mode, label]) => <button
                                        key={mode}
                                        type="button"
                                        role="radio"
                                        aria-checked={(data.ai_schedule_json?.enabled ? 'scheduled' : 'permanent') === mode}
                                        onClick={() => setData('ai_schedule_json', mode === 'permanent'
                                            ? { ...data.ai_schedule_json, enabled: false, mode: 'permanent' }
                                            : { ...(data.ai_schedule_json?.schedule ? data.ai_schedule_json : defaultWeeklySchedule(userTimezone)), enabled: true, mode: 'scheduled' })}
                                        className={`flex-1 rounded-md px-3 py-2 text-xs font-semibold transition ${(data.ai_schedule_json?.enabled ? 'scheduled' : 'permanent') === mode ? 'bg-white text-brand-700 shadow-sm dark:bg-neutral-900 dark:text-brand-300' : 'text-neutral-500'}`}
                                    >{label}</button>)}
                                </div>
                                <p className="mt-2 text-xs text-neutral-500">Permanent answers whenever AI answering is on. Scheduled answers only inside the selected windows.</p>

                                {data.ai_schedule_json?.enabled && <div className="mt-4 rounded-xl border border-neutral-200 bg-neutral-50/70 p-4 dark:border-neutral-700 dark:bg-neutral-800/50">
                                    <div className="mb-3 flex items-center gap-2"><CalendarClock className="h-4 w-4 text-brand-500" /><p className="text-sm font-semibold">AI active hours</p></div>
                                    <WeeklyScheduleEditor value={data.ai_schedule_json} onChange={value => setData('ai_schedule_json', { ...value, enabled: true, mode: 'scheduled' })} />
                                    {aiScheduleError && <p role="alert" className="mt-2 text-xs font-medium text-red-600 dark:text-red-400">{aiScheduleError}</p>}
                                </div>}
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
                    <Toggle checked={data.sdk_enabled} onChange={(v) => setData('sdk_enabled', v)} label="SDK enabled" description="Turn the customer mobile SDK chat off without affecting the website widget." />
                </Card>
                </div>

                <div role="tabpanel" id="wpanel-starter" aria-labelledby="wtab-starter" hidden={tab !== 'starter'} className="space-y-6">
                <Card title={t('ai.starter_questions', 'Starter questions')} icon={<ListChecks className="h-4 w-4 text-brand-500" />}>
                    <Toggle
                        checked={data.starter_questions_enabled}
                        onChange={(v) => setData('starter_questions_enabled', v)}
                        label={t('widget_appearance.show_starter_questions', 'Show starter questions')}
                        description={t('ai.starter_questions_hint')}
                    />
                    {data.starter_questions_enabled && (
                        <StarterQuestionsEditor
                            items={data.starter_questions}
                            errors={errors}
                            onChange={(items) => setData('starter_questions', items)}
                        />
                    )}
                </Card>
                </div>
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
                    {processing ? 'Saving…' : submitLabel}
                </button>
                {Object.keys(errors).length > 0 && (
                    <p role="alert" className="text-xs text-red-500">
                        Not saved: {Object.values(errors)[0]}
                    </p>
                )}
            </div>
        </form>
    );
}

/** A faithful, static mock of the embedded widget using the live form values. */
function WidgetPreview({ data }) {
    const color = data.primary_color || '#ff762e';
    const initial = (data.agent_name || 'S').trim().charAt(0).toUpperCase();
    const starters = data.starter_questions_enabled
        ? data.starter_questions.filter((item) => item.question.trim() !== '' && item.answer.trim() !== '')
        : [];
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
                {starters.length > 0 && (
                    <div className="flex flex-wrap gap-1.5 pl-7">
                        {starters.map((item, index) => (
                            <span key={item.id ?? index} className="rounded-full border bg-white px-2.5 py-1 text-[12px] font-medium" style={{ borderColor: color, color }}>
                                {item.question}
                            </span>
                        ))}
                    </div>
                )}
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
