import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { ArrowLeft, Bot, MessageSquare, Trash2, X } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import ClientLayout from '@/Layouts/ClientLayout';
import { confirmDialog } from '@/Components/ConfirmDialog';
import CompanyBriefCard from '@/Components/CompanyBriefCard';
import UnansweredQuestionsCard from '@/Components/UnansweredQuestionsCard';
import SetupSteps from '@/Components/SmartBot/SetupSteps';
import TestPanel from '@/Components/SmartBot/TestPanel';
import KnowledgeSources from '@/Components/SmartBot/KnowledgeSources';
import AnswerSettings from '@/Components/SmartBot/AnswerSettings';
import WhereItAnswers from '@/Components/SmartBot/WhereItAnswers';

const inputCls = 'w-full rounded-lg border border-neutral-300 bg-white px-3 py-2 text-sm text-neutral-900 placeholder-neutral-400 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/25 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100';

function Field({ label, hint, error, children }) {
    return (
        <label className="block">
            <span className="mb-1.5 block text-sm font-medium text-neutral-800 dark:text-neutral-200">{label}</span>
            {children}
            {hint && !error && <span className="mt-1 block text-xs text-neutral-500 dark:text-neutral-400">{hint}</span>}
            {error && <span className="mt-1 block text-xs text-red-500">{error}</span>}
        </label>
    );
}

function Panel({ title, description, children }) {
    return (
        <section className="rounded-2xl border border-neutral-200 bg-white p-5 dark:border-neutral-800 dark:bg-neutral-900">
            <h3 className="text-base font-semibold text-neutral-900 dark:text-neutral-100">{title}</h3>
            {description && <p className="mt-1 text-sm text-neutral-500 dark:text-neutral-400">{description}</p>}
            <div className="mt-4">{children}</div>
        </section>
    );
}

function SaveButton({ processing, label }) {
    const { t } = useTranslation();

    return (
        <button type="submit" disabled={processing} className="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-brand-700 disabled:opacity-60">
            {processing ? t('ai.saving') : label}
        </button>
    );
}

/**
 * One Smart Bot, set up step by step: who it is, the business, what it knows,
 * how it answers. Each step saves on its own; the page opens on the first one
 * still to do, and the Test panel stays beside them.
 */
export default function SmartBotShow({
    chatbot, kb = null, sharedWith = [], hasBriefSources = false, kbUploadMaxKb, kbUploadMaxMb, tones = [],
    aiCredits = null, engineV2Enabled = false, researchAvailable = false, liveProductFactsAvailable = false,
    placements = null, canManagePlacements = false,
}) {
    const { t } = useTranslation();
    const flash = usePage().props.flash ?? {};
    const [testOpen, setTestOpen] = useState(false);

    const documents = kb?.documents ?? [];
    const indexed = documents.filter(doc => doc.status === 'indexed').length;
    const businessDone = Boolean(kb?.brand?.trim() && kb?.purpose?.trim());
    const steps = [
        { id: 'bot', title: t('smart_bot.step_bot'), done: true },
        { id: 'business', title: t('smart_bot.step_business'), done: businessDone },
        { id: 'knowledge', title: t('smart_bot.step_knowledge'), done: indexed > 0 },
        { id: 'answers', title: t('smart_bot.step_answers'), done: Boolean(chatbot.answers_configured_at) },
        { id: 'live', title: t('smart_bot.step_live'), done: Boolean(placements?.widget?.on || Object.values(placements?.segments ?? {}).some(place => place.on)) },
    ];
    const [open, setOpen] = useState(() => (steps.find(step => !step.done)?.id ?? 'knowledge'));

    const identity = useForm({ name: chatbot.name ?? '', tone: chatbot.tone || 'friendly', system_prompt: chatbot.system_prompt ?? '' });
    const business = useForm({ brand: kb?.brand ?? '', purpose: kb?.purpose ?? '', audience: kb?.audience ?? '' });
    const answers = useForm({
        name: chatbot.name,
        answer_scope: chatbot.answer_scope ?? (chatbot.unsupported_answer_action === 'general' ? 'general' : 'business_only'),
        reply_length: chatbot.reply_length ?? 'standard',
        unsupported_fallback_action: chatbot.unsupported_fallback_action ?? (chatbot.unsupported_answer_action === 'handoff' ? 'handoff' : 'clarify_then_handoff'),
        fallback_reply: chatbot.fallback_reply ?? '',
        kb_exact_wording: Boolean(chatbot.kb_exact_wording),
        trusted_research_enabled: Boolean(chatbot.trusted_research_enabled),
        live_product_facts_enabled: Boolean(chatbot.live_product_facts_enabled),
        answers_configured: true,
    });

    const saveIdentity = (e) => {
        e.preventDefault();
        identity.put(route('client.ai.chatbots.update', chatbot.uuid), { preserveScroll: true });
    };
    const saveBusiness = (e) => {
        e.preventDefault();
        business.put(route('client.ai.knowledge-bases.update', kb.uuid), { preserveScroll: true, onSuccess: () => !documents.length && setOpen('knowledge') });
    };
    const saveAnswers = (e) => {
        e.preventDefault();
        answers.transform(data => ({ ...data, name: identity.data.name || chatbot.name }));
        answers.put(route('client.ai.chatbots.update', chatbot.uuid), { preserveScroll: true });
    };
    const destroy = async () => {
        if (await confirmDialog({ message: t('smart_bot.delete_confirm', { name: chatbot.name }) })) {
            router.delete(route('client.ai.chatbots.destroy', chatbot.uuid));
        }
    };

    const noKnowledge = (
        <div className="rounded-xl border border-dashed border-neutral-300 p-5 text-center dark:border-neutral-700">
            <p className="text-sm text-neutral-600 dark:text-neutral-300">{t('smart_bot.no_knowledge')}</p>
            <button type="button" onClick={() => router.post(route('client.ai.chatbots.knowledge', chatbot.uuid), {}, { preserveScroll: true })} className="mt-3 rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">{t('smart_bot.create_knowledge')}</button>
        </div>
    );

    return (
        <ClientLayout title={chatbot.name}>
            <Head title={`${chatbot.name} · ${t('ai.chatbots_title')}`} />
            <div className="mx-auto max-w-6xl space-y-6">
                <div className="flex items-start gap-3">
                    <div className="min-w-0 flex-1">
                        <Link href={route('client.ai.chatbots.index')} className="inline-flex items-center gap-1.5 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200">
                            <ArrowLeft className="h-4 w-4" /> {t('ai.chatbots_heading')}
                        </Link>
                        <h2 className="mt-2 flex items-center gap-2 text-xl font-semibold text-neutral-900 dark:text-neutral-100">
                            <Bot className="h-5 w-5 shrink-0 text-brand-500" /> <span className="truncate">{chatbot.name}</span>
                            {engineV2Enabled && chatbot.engine === 'v2' && <span className="shrink-0 rounded-full bg-brand-50 px-2 py-0.5 text-xs font-medium text-brand-700 dark:bg-brand-900/30 dark:text-brand-300">{t('ai.engine_v2_badge')}</span>}
                        </h2>
                        <p className="mt-1 text-sm text-neutral-500 dark:text-neutral-400">{t('smart_bot.page_hint')}</p>
                    </div>
                    <button type="button" onClick={() => setTestOpen(true)} className="inline-flex items-center gap-1.5 rounded-lg border border-neutral-200 px-3 py-2 text-sm font-medium text-neutral-700 hover:bg-neutral-50 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-800 xl:hidden">
                        <MessageSquare className="h-4 w-4" /> {t('ai.test')}
                    </button>
                    <button type="button" onClick={destroy} title={t('common.delete')} aria-label={t('common.delete')} className="rounded-lg border border-neutral-200 p-2 text-neutral-500 hover:border-red-300 hover:bg-red-50 hover:text-red-500 dark:border-neutral-700 dark:hover:bg-red-900/20">
                        <Trash2 className="h-4 w-4" />
                    </button>
                </div>

                {flash.success && <div className="rounded-lg border border-green-200 bg-green-50 px-4 py-2.5 text-sm text-green-800 dark:border-green-800 dark:bg-green-900/30 dark:text-green-200">{flash.success}</div>}
                {sharedWith.length > 0 && <p className="rounded-lg border border-amber-200 bg-amber-50 px-4 py-2.5 text-sm text-amber-800 dark:border-amber-900/60 dark:bg-amber-900/20 dark:text-amber-200">{t('smart_bot.shared_knowledge', { bots: sharedWith.join(', ') })}</p>}

                <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_22rem]">
                    <div className="min-w-0 space-y-6">
                        <SetupSteps steps={steps} current={open} onSelect={setOpen} />

                        {open === 'bot' && (
                            <Panel title={t('smart_bot.step_bot')} description={t('smart_bot.bot_description')}>
                                <form onSubmit={saveIdentity} className="max-w-xl space-y-4">
                                    <Field label={t('common.name')} hint={t('smart_bot.name_hint')} error={identity.errors.name}>
                                        <input required maxLength={128} value={identity.data.name} onChange={e => identity.setData('name', e.target.value)} className={inputCls} />
                                    </Field>
                                    <Field label={t('ai.tone')} hint={t('smart_bot.tone_hint')}>
                                        <select value={identity.data.tone} onChange={e => identity.setData('tone', e.target.value)} className={inputCls}>
                                            {tones.map(tone => <option key={tone} value={tone}>{t(`ai.tone_${tone}`)}</option>)}
                                        </select>
                                    </Field>
                                    <Field label={t('smart_bot.always_do')} hint={t('smart_bot.always_do_hint')} error={identity.errors.system_prompt}>
                                        <textarea rows={3} maxLength={8192} value={identity.data.system_prompt} onChange={e => identity.setData('system_prompt', e.target.value)} className={`${inputCls} resize-y`} />
                                    </Field>
                                    <SaveButton processing={identity.processing} label={t('common.save')} />
                                </form>
                            </Panel>
                        )}

                        {open === 'business' && (
                            <Panel title={t('smart_bot.step_business')} description={t('smart_bot.business_description')}>
                                {kb ? (
                                    <div className="space-y-6">
                                        <form onSubmit={saveBusiness} className="max-w-xl space-y-4">
                                            <Field label={`${t('smart_bot.business_name')} *`} error={business.errors.brand}>
                                                <input required maxLength={128} value={business.data.brand} onChange={e => business.setData('brand', e.target.value)} placeholder={t('smart_bot.business_name_placeholder')} className={inputCls} />
                                            </Field>
                                            <Field label={`${t('smart_bot.what_you_do')} *`} error={business.errors.purpose}>
                                                <textarea required rows={2} maxLength={2000} value={business.data.purpose} onChange={e => business.setData('purpose', e.target.value)} placeholder={t('smart_bot.what_you_do_placeholder')} className={`${inputCls} resize-y`} />
                                            </Field>
                                            <Field label={t('smart_bot.who_you_serve')} hint={t('common.optional')} error={business.errors.audience}>
                                                <input maxLength={256} value={business.data.audience} onChange={e => business.setData('audience', e.target.value)} placeholder={t('smart_bot.who_you_serve_placeholder')} className={inputCls} />
                                            </Field>
                                            <SaveButton processing={business.processing} label={t('smart_bot.save_business')} />
                                        </form>
                                        <CompanyBriefCard kb={kb} hasSources={hasBriefSources} />
                                    </div>
                                ) : noKnowledge}
                            </Panel>
                        )}

                        {open === 'knowledge' && (
                            <Panel title={t('smart_bot.step_knowledge')} description={t('smart_bot.knowledge_description')}>
                                {kb ? <KnowledgeSources kb={kb} kbUploadMaxKb={kbUploadMaxKb} kbUploadMaxMb={kbUploadMaxMb} /> : noKnowledge}
                            </Panel>
                        )}

                        {open === 'answers' && (
                            <Panel title={t('smart_bot.step_answers')} description={t('smart_bot.answers_description')}>
                                <form onSubmit={saveAnswers} className="max-w-2xl space-y-5">
                                    <AnswerSettings
                                        data={answers.data}
                                        setData={answers.setData}
                                        errors={answers.errors}
                                        profileComplete={businessDone}
                                        researchAvailable={researchAvailable}
                                        liveProductFactsAvailable={liveProductFactsAvailable}
                                    />
                                    <SaveButton processing={answers.processing} label={t('common.save')} />
                                </form>
                            </Panel>
                        )}

                        {open === 'live' && (
                            <Panel title={t('smart_bot.step_live')} description={t('smart_bot.live_description')}>
                                <WhereItAnswers chatbot={chatbot} placements={placements} canManage={canManagePlacements} />
                            </Panel>
                        )}

                        {kb && <UnansweredQuestionsCard kb={kb} />}
                    </div>

                    <aside className="hidden xl:block">
                        <div className="sticky top-20">
                            <TestPanel chatbot={chatbot} aiCredits={aiCredits} className="h-[calc(100vh-7rem)] max-h-[44rem]" />
                        </div>
                    </aside>
                </div>
            </div>

            {testOpen && (
                <div className="fixed inset-0 z-50 flex flex-col bg-black/50 p-3 backdrop-blur-sm xl:hidden" role="dialog" aria-modal="true" aria-label={t('ai.test')}>
                    <div className="mb-2 flex justify-end">
                        <button type="button" onClick={() => setTestOpen(false)} aria-label={t('common.close')} className="rounded-full bg-white p-2 text-neutral-600 shadow dark:bg-neutral-800 dark:text-neutral-200"><X className="h-4 w-4" /></button>
                    </div>
                    <TestPanel chatbot={chatbot} aiCredits={aiCredits} className="min-h-0 flex-1" />
                </div>
            )}
        </ClientLayout>
    );
}
