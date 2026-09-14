import { Link, router } from '@inertiajs/react';
import { Dialog, DialogPanel, DialogTitle } from '@headlessui/react';
import { AlertTriangle, ArrowLeft, Bot, CalendarClock, Check, ChevronRight, Settings2, Sparkles, X } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import WeeklyScheduleEditor, { defaultWeeklySchedule, normalizeAiSchedule } from '@/Components/WeeklyScheduleEditor';

const modes = [
    { value: 'off', label: 'Off', description: 'Send conversations to your team.' },
    { value: 'always_on', label: 'Always on', description: 'AI can answer at any time.' },
    { value: 'scheduled', label: 'Scheduled', description: 'AI answers only during set hours.' },
];

const modeStyles = {
    off: 'border-neutral-200 bg-neutral-100 text-neutral-600 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-300',
    always_on: 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300',
    scheduled: 'border-brand-200 bg-brand-50 text-brand-700 dark:border-brand-800 dark:bg-brand-950/40 dark:text-brand-300',
};

export default function AiAnsweringControl({ segment, policy = {}, chatbots = [], canManage = false }) {
    const { t } = useTranslation();
    const browserTimezone = Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC';
    const savedMode = policy.mode || 'off';
    const savedChatbotId = policy.chatbot_id ? String(policy.chatbot_id) : '';
    const savedSchedule = normalizeAiSchedule(policy.schedule, browserTimezone);
    const [mode, setMode] = useState(savedMode);
    const [chatbotId, setChatbotId] = useState(savedChatbotId);
    const [schedule, setSchedule] = useState(savedSchedule);
    const [drawerOpen, setDrawerOpen] = useState(false);
    const [scheduleView, setScheduleView] = useState(false);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState('');

    const savedBot = chatbots.find(bot => String(bot.id) === savedChatbotId);
    const segmentDescription = segment === 'email'
        ? t('inbox.ai_email_segment_description', { defaultValue: 'One setting for every connected mailbox' })
        : t('inbox.ai_omni_segment_description', { defaultValue: 'One setting for all connected inbox channels' });
    const savedModeLabel = modes.find(item => item.value === savedMode)?.label || 'Off';

    const openDrawer = () => {
        setMode(savedMode);
        setChatbotId(savedChatbotId);
        setSchedule(savedSchedule);
        setScheduleView(false);
        setError('');
        setDrawerOpen(true);
    };

    const closeDrawer = () => {
        if (saving) return;
        setDrawerOpen(false);
        setScheduleView(false);
        setError('');
    };

    const chooseMode = nextMode => {
        setMode(nextMode);
        setError('');
        if (nextMode === 'scheduled' && !schedule?.enabled) {
            setSchedule(defaultWeeklySchedule(schedule?.timezone || browserTimezone));
        }
    };

    const save = () => {
        if (mode !== 'off' && !chatbotId) {
            setError(t('inbox.choose_smart_bot', { defaultValue: 'Choose a Smart Bot to continue.' }));
            return;
        }

        setSaving(true);
        setError('');
        router.patch(route('client.inbox.ai-answering.update', { segment }), {
            mode,
            chatbot_id: mode === 'off' ? null : Number(chatbotId),
            schedule: mode === 'scheduled' ? { ...schedule, enabled: true, mode: 'scheduled' } : null,
        }, {
            preserveScroll: true,
            onError: errors => setError(Object.values(errors)[0] || t('common.error', { defaultValue: 'Could not save changes.' })),
            onSuccess: () => {
                setDrawerOpen(false);
                setScheduleView(false);
            },
            onFinish: () => setSaving(false),
        });
    };

    return <>
        <section className="group rounded-2xl border border-neutral-200 bg-white shadow-sm transition-shadow hover:shadow-soft-md dark:border-neutral-700 dark:bg-neutral-900">
            <div className="flex flex-col gap-4 px-4 py-4 sm:flex-row sm:items-center sm:px-5">
                <div className="flex min-w-0 flex-1 items-center gap-3.5">
                    <div className="relative flex h-10 w-10 shrink-0 items-center justify-center rounded-xl border border-brand-100 bg-brand-50 text-brand-600 dark:border-brand-900 dark:bg-brand-950/40 dark:text-brand-300">
                        <Bot className="h-5 w-5" />
                        {savedMode !== 'off' && <span className="absolute -right-1 -top-1 flex h-4 w-4 items-center justify-center rounded-full border-2 border-white bg-emerald-500 text-white dark:border-neutral-900"><Check className="h-2.5 w-2.5 stroke-[3]" /></span>}
                    </div>
                    <div className="min-w-0">
                        <div className="flex flex-wrap items-center gap-2">
                            <h3 className="text-sm font-semibold text-neutral-900 dark:text-neutral-100">{t('inbox.ai_answering', { defaultValue: 'AI Answering' })}</h3>
                            <span className={`rounded-full border px-2 py-0.5 text-[10px] font-semibold ${modeStyles[savedMode] || modeStyles.off}`}>
                                {t(`inbox.ai_mode_${savedMode}`, { defaultValue: savedModeLabel })}
                            </span>
                            {policy.needs_setup && <span className="inline-flex items-center gap-1 rounded-full border border-amber-200 bg-amber-50 px-2 py-0.5 text-[10px] font-semibold text-amber-700 dark:border-amber-800 dark:bg-amber-950/30 dark:text-amber-300"><AlertTriangle className="h-3 w-3" />{t('inbox.needs_setup', { defaultValue: 'Needs setup' })}</span>}
                        </div>
                        <p className="mt-0.5 truncate text-xs text-neutral-500 dark:text-neutral-400">{segmentDescription}</p>
                    </div>
                </div>

                <div className="flex min-w-0 items-center gap-3 border-t border-neutral-100 pt-3 sm:border-l sm:border-t-0 sm:pl-4 sm:pt-0 dark:border-neutral-800">
                    {savedMode !== 'off' && <div className="hidden min-w-0 text-right md:block">
                        <p className="max-w-48 truncate text-xs font-semibold text-neutral-700 dark:text-neutral-200">{savedBot?.name || t('inbox.needs_bot', { defaultValue: 'Needs bot' })}</p>
                        {savedMode === 'scheduled' && <p className="mt-0.5 flex items-center justify-end gap-1 text-[11px] text-neutral-500"><CalendarClock className="h-3 w-3" />{policy.schedule?.timezone || browserTimezone}</p>}
                    </div>}
                    {policy.last_error && <span title={policy.last_error.code} className="inline-flex text-red-500"><AlertTriangle className="h-4 w-4" /><span className="sr-only">{t('inbox.ai_send_failed', { defaultValue: 'Last AI reply failed' })}</span></span>}
                    {canManage ? <button type="button" onClick={openDrawer} className="ml-auto inline-flex h-9 items-center justify-center gap-2 rounded-lg border border-neutral-200 bg-white px-3 text-xs font-semibold text-neutral-700 shadow-sm transition hover:border-neutral-300 hover:bg-neutral-50 focus:outline-none focus:ring-2 focus:ring-brand-500/30 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800" aria-label={t('inbox.manage_ai_answering', { defaultValue: 'Manage AI answering' })}>
                        <Settings2 className="h-3.5 w-3.5" />{t('common.configure', { defaultValue: 'Configure' })}<ChevronRight className="h-3.5 w-3.5 text-neutral-400" />
                    </button> : <span className="ml-auto text-xs font-medium text-neutral-400">{t('common.view_only', { defaultValue: 'View only' })}</span>}
                </div>
            </div>
        </section>

        <Dialog open={drawerOpen} onClose={closeDrawer} className="relative z-50">
            <div className="fixed inset-0 bg-neutral-950/40 backdrop-blur-[2px]" aria-hidden="true" />
            <div className="fixed inset-0 overflow-hidden">
                <div className="absolute inset-0 flex justify-end">
                    <DialogPanel className="flex h-full w-full max-w-xl flex-col border-l border-neutral-200 bg-white shadow-2xl dark:border-neutral-800 dark:bg-neutral-900">
                        <header className="flex items-center gap-3 border-b border-neutral-200 px-5 py-4 dark:border-neutral-800">
                            {scheduleView && <button type="button" onClick={() => setScheduleView(false)} className="rounded-lg p-2 text-neutral-500 hover:bg-neutral-100 focus:outline-none focus:ring-2 focus:ring-brand-500/30 dark:hover:bg-neutral-800" aria-label={t('common.back', { defaultValue: 'Back' })}><ArrowLeft className="h-4 w-4" /></button>}
                            <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-brand-50 text-brand-600 dark:bg-brand-950/40 dark:text-brand-300">{scheduleView ? <CalendarClock className="h-4 w-4" /> : <Sparkles className="h-4 w-4" />}</div>
                            <div className="min-w-0 flex-1">
                                <DialogTitle className="text-base font-semibold text-neutral-900 dark:text-neutral-100">{scheduleView ? t('inbox.ai_schedule', { defaultValue: 'Answering schedule' }) : t('inbox.configure_ai_answering', { defaultValue: 'Configure AI Answering' })}</DialogTitle>
                                <p className="truncate text-xs text-neutral-500">{scheduleView ? t('inbox.ai_schedule_description', { defaultValue: 'Set the exact hours when AI may answer.' }) : segmentDescription}</p>
                            </div>
                            <button type="button" onClick={closeDrawer} className="rounded-lg p-2 text-neutral-400 hover:bg-neutral-100 focus:outline-none focus:ring-2 focus:ring-brand-500/30 dark:hover:bg-neutral-800" aria-label={t('common.close', { defaultValue: 'Close' })}><X className="h-4 w-4" /></button>
                        </header>

                        <div className="flex-1 overflow-y-auto px-5 py-5">
                            {scheduleView ? <WeeklyScheduleEditor value={schedule} onChange={value => setSchedule({ ...value, enabled: true, mode: 'scheduled' })} /> : <div className="space-y-6">
                                {policy.needs_setup && <div className="flex gap-2.5 rounded-xl border border-amber-200 bg-amber-50 p-3 text-xs text-amber-800 dark:border-amber-800 dark:bg-amber-950/30 dark:text-amber-200"><AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" /><span>{t('inbox.ai_setup_review', { defaultValue: 'Previous settings could not be combined safely. Review and save this setup.' })}</span></div>}

                                <fieldset>
                                    <legend className="mb-2 text-xs font-semibold uppercase tracking-wide text-neutral-500">{t('inbox.answering_mode', { defaultValue: 'Answering mode' })}</legend>
                                    <div className="grid gap-2 sm:grid-cols-3" role="radiogroup">
                                        {modes.map(item => <button key={item.value} type="button" role="radio" aria-checked={mode === item.value} disabled={saving} onClick={() => chooseMode(item.value)} className={`rounded-xl border p-3 text-left transition focus:outline-none focus:ring-2 focus:ring-brand-500/30 ${mode === item.value ? 'border-brand-300 bg-brand-50 ring-1 ring-brand-200 dark:border-brand-700 dark:bg-brand-950/30' : 'border-neutral-200 bg-white hover:border-neutral-300 hover:bg-neutral-50 dark:border-neutral-700 dark:bg-neutral-900 dark:hover:bg-neutral-800'}`}>
                                            <span className="flex items-center gap-2 text-sm font-semibold text-neutral-900 dark:text-neutral-100"><span className={`h-2 w-2 rounded-full ${mode === item.value ? 'bg-brand-500' : 'bg-neutral-300 dark:bg-neutral-600'}`} />{t(`inbox.ai_mode_${item.value}`, { defaultValue: item.label })}</span>
                                            <span className="mt-1 block text-[11px] leading-4 text-neutral-500">{t(`inbox.ai_mode_${item.value}_description`, { defaultValue: item.description })}</span>
                                        </button>)}
                                    </div>
                                </fieldset>

                                {mode !== 'off' && <div className="space-y-2">
                                    <div className="flex items-center justify-between gap-3"><label htmlFor={`ai-bot-${segment}`} className="text-xs font-semibold uppercase tracking-wide text-neutral-500">{t('inbox.smart_bot', { defaultValue: 'Smart Bot' })}</label><Link href={route('client.ai.chatbots.index')} className="text-xs font-semibold text-brand-600 hover:text-brand-700">{t('inbox.manage_bots', { defaultValue: 'Manage bots' })}</Link></div>
                                    <select id={`ai-bot-${segment}`} value={chatbotId} disabled={saving} onChange={event => { setChatbotId(event.target.value); setError(''); }} className="h-11 w-full rounded-xl border-neutral-300 bg-white px-3 text-sm text-neutral-900 focus:border-brand-500 focus:ring-brand-500/20 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-100">
                                        <option value="">{t('inbox.choose_bot', { defaultValue: 'Choose a Smart Bot' })}</option>
                                        {chatbots.map(bot => <option key={bot.id} value={String(bot.id)}>{bot.name}</option>)}
                                    </select>
                                    {chatbots.length === 0 && <p className="text-xs text-amber-700 dark:text-amber-300">{t('inbox.no_bots_short', { defaultValue: 'Create a Smart Bot before enabling AI.' })}</p>}
                                </div>}

                                {mode === 'scheduled' && <button type="button" onClick={() => setScheduleView(true)} className="flex w-full items-center gap-3 rounded-xl border border-neutral-200 bg-neutral-50 p-3 text-left transition hover:border-brand-200 hover:bg-brand-50/50 focus:outline-none focus:ring-2 focus:ring-brand-500/30 dark:border-neutral-700 dark:bg-neutral-800/60 dark:hover:border-brand-800 dark:hover:bg-brand-950/20">
                                    <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-white text-brand-600 shadow-sm dark:bg-neutral-900 dark:text-brand-300"><CalendarClock className="h-4 w-4" /></span>
                                    <span className="min-w-0 flex-1"><span className="block text-sm font-semibold text-neutral-800 dark:text-neutral-100">{t('inbox.weekly_hours', { defaultValue: 'Weekly hours' })}</span><span className="block truncate text-xs text-neutral-500">{schedule?.timezone || browserTimezone}</span></span>
                                    <span className="text-xs font-semibold text-brand-600">{t('common.edit', { defaultValue: 'Edit' })}</span><ChevronRight className="h-4 w-4 text-neutral-400" />
                                </button>}

                                {error && <p className="rounded-lg bg-red-50 px-3 py-2 text-xs font-medium text-red-700 dark:bg-red-950/30 dark:text-red-300" role="alert">{error}</p>}
                            </div>}
                        </div>

                        <footer className="flex items-center justify-end gap-2 border-t border-neutral-200 bg-neutral-50/70 px-5 py-4 dark:border-neutral-800 dark:bg-neutral-950/30">
                            {scheduleView ? <button type="button" onClick={() => setScheduleView(false)} className="inline-flex h-10 items-center rounded-lg bg-brand-600 px-4 text-sm font-semibold text-white shadow-sm hover:bg-brand-700 focus:outline-none focus:ring-2 focus:ring-brand-500/30">{t('inbox.apply_hours', { defaultValue: 'Apply hours' })}</button> : <>
                                <button type="button" onClick={closeDrawer} disabled={saving} className="inline-flex h-10 items-center rounded-lg border border-neutral-200 bg-white px-4 text-sm font-semibold text-neutral-700 hover:bg-neutral-50 disabled:opacity-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800">{t('common.cancel', { defaultValue: 'Cancel' })}</button>
                                <button type="button" onClick={save} disabled={saving || (mode !== 'off' && (!chatbotId || chatbots.length === 0))} className="inline-flex h-10 items-center rounded-lg bg-brand-600 px-4 text-sm font-semibold text-white shadow-sm hover:bg-brand-700 focus:outline-none focus:ring-2 focus:ring-brand-500/30 disabled:cursor-not-allowed disabled:opacity-50">{saving ? t('inbox.saving', { defaultValue: 'Saving…' }) : t('common.save_changes', { defaultValue: 'Save changes' })}</button>
                            </>}
                        </footer>
                    </DialogPanel>
                </div>
            </div>
        </Dialog>
    </>;
}
