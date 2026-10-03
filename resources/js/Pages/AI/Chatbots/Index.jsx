import { Head, Link, router, usePage } from '@inertiajs/react';
import { BookOpen, Bot, Database, Play, Plus, Radio, Settings, Trash2, X } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import ClientLayout from '@/Layouts/ClientLayout';
import EmptyState from '@/Components/EmptyState';
import { confirmDialog } from '@/Components/ConfirmDialog';
import TestPanel from '@/Components/SmartBot/TestPanel';

const TONE_COLORS = {
    professional: 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300',
    friendly: 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/40 dark:text-yellow-300',
    formal: 'bg-purple-100 text-purple-700 dark:bg-purple-900/40 dark:text-purple-300',
    casual: 'bg-pink-100 text-pink-700 dark:bg-pink-900/40 dark:text-pink-300',
};

function KnowledgeLine({ knowledge }) {
    const { t } = useTranslation();
    if (!knowledge || knowledge.total === 0) {
        return <span className="text-amber-700 dark:text-amber-300">{t('smart_bot.list_no_knowledge')}</span>;
    }
    if (knowledge.reading > 0) {
        return <span>{t('smart_bot.list_reading', { count: knowledge.reading })}</span>;
    }

    return <span>{t('smart_bot.list_ready', { count: knowledge.ready })}{knowledge.failed > 0 && <span className="text-red-600 dark:text-red-300"> · {t('smart_bot.list_failed', { count: knowledge.failed })}</span>}</span>;
}

/** Every Smart Bot at a glance, each with Test and Set up. */
export default function AiChatbotsIndex({ chatbots = [], unusedKnowledge = [], aiCredits = null, engineV2Enabled = false }) {
    const { t } = useTranslation();
    const flash = usePage().props.flash ?? {};
    const [testing, setTesting] = useState(null);

    const deleteKnowledge = async (kb) => {
        if (await confirmDialog({ message: t('ai.delete_kb_confirm', { name: kb.name }) })) {
            router.delete(route('client.ai.knowledge-bases.destroy', kb.uuid), { preserveScroll: true });
        }
    };
    const deleteBot = async (bot) => {
        if (await confirmDialog({ message: t('smart_bot.delete_confirm', { name: bot.name }) })) {
            router.delete(route('client.ai.chatbots.destroy', bot.uuid), { preserveScroll: true });
        }
    };

    return (
        <ClientLayout title={t('ai.chatbots_title')}>
            <Head title={`${t('ai.chatbots_title')} · AI`} />
            <div className="space-y-6">
                <div className="flex items-start justify-between gap-3">
                    <div>
                        <h2 className="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{t('ai.chatbots_heading')}</h2>
                        <p className="mt-0.5 text-sm text-neutral-500 dark:text-neutral-400">{t('smart_bot.list_subtitle')}</p>
                    </div>
                    <Link href={route('client.ai.chatbots.create')} className="flex shrink-0 items-center gap-2 rounded-xl bg-brand-600 px-4 py-2 text-sm font-medium text-white shadow-sm transition hover:bg-brand-700">
                        <Plus className="h-4 w-4" /> {t('ai.new_chatbot')}
                    </Link>
                </div>

                {flash.success && <div className="rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800 dark:border-green-800 dark:bg-green-900/30 dark:text-green-200">{flash.success}</div>}

                <div className="space-y-3">
                    {chatbots.map(bot => (
                        <div key={bot.id} className="rounded-xl border border-neutral-200 bg-white transition hover:shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                            <div className="flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-center">
                                <Link href={route('client.ai.chatbots.show', bot.uuid)} className="flex min-w-0 flex-1 items-center gap-3">
                                    <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-brand-50 dark:bg-brand-900/30"><Bot className="h-5 w-5 text-brand-600 dark:text-brand-400" /></span>
                                    <span className="min-w-0">
                                        <span className="flex flex-wrap items-center gap-2">
                                            <span className="truncate font-semibold text-neutral-900 dark:text-neutral-100">{bot.name}</span>
                                            {bot.tone && <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${TONE_COLORS[bot.tone] ?? 'bg-neutral-100 text-neutral-500'}`}>{t(`ai.tone_${bot.tone}`)}</span>}
                                            {engineV2Enabled && bot.engine === 'v2' && <span className="rounded-full bg-brand-50 px-2 py-0.5 text-xs font-medium text-brand-700 dark:bg-brand-900/30 dark:text-brand-300">{t('ai.engine_v2_badge')}</span>}
                                        </span>
                                        <span className="mt-0.5 flex flex-wrap items-center gap-x-3 gap-y-0.5 text-xs text-neutral-500 dark:text-neutral-400">
                                            <span className="flex items-center gap-1"><BookOpen className="h-3 w-3 shrink-0" /> <KnowledgeLine knowledge={bot.knowledge} /></span>
                                            <span className="flex items-center gap-1"><Radio className="h-3 w-3 shrink-0" /> {bot.places?.length ? bot.places.map(place => t(`smart_bot.list_place_${place}`)).join(' · ') : t('smart_bot.list_not_live')}</span>
                                        </span>
                                    </span>
                                </Link>
                                <div className="flex shrink-0 items-center gap-1 self-end sm:self-auto">
                                    <button type="button" onClick={() => setTesting(bot)} className="flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-medium text-neutral-500 hover:bg-neutral-100 hover:text-neutral-700 dark:hover:bg-neutral-800 dark:hover:text-neutral-300">
                                        <Play className="h-3.5 w-3.5" /> {t('ai.test')}
                                    </button>
                                    <Link href={route('client.ai.chatbots.show', bot.uuid)} className="flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-medium text-neutral-500 hover:bg-neutral-100 hover:text-neutral-700 dark:hover:bg-neutral-800 dark:hover:text-neutral-300">
                                        <Settings className="h-3.5 w-3.5" /> {t('smart_bot.set_up')}
                                    </Link>
                                    <button type="button" onClick={() => deleteBot(bot)} aria-label={t('common.delete')} className="rounded-lg p-1.5 text-neutral-400 hover:bg-red-50 hover:text-red-500 dark:hover:bg-red-900/20">
                                        <Trash2 className="h-3.5 w-3.5" />
                                    </button>
                                </div>
                            </div>
                        </div>
                    ))}
                    {chatbots.length === 0 && (
                        <EmptyState
                            icon={<Bot className="h-8 w-8" />}
                            title={t('ai.chatbots_empty_title')}
                            description={t('smart_bot.empty_description')}
                            action={{ label: t('ai.new_chatbot'), onClick: () => router.visit(route('client.ai.chatbots.create')) }}
                        />
                    )}
                </div>

                {unusedKnowledge.length > 0 && (
                    <section className="rounded-xl border border-neutral-200 bg-neutral-50/60 p-5 dark:border-neutral-800 dark:bg-neutral-900/40">
                        <h3 className="flex items-center gap-2 text-sm font-semibold text-neutral-900 dark:text-neutral-100"><Database className="h-4 w-4 text-neutral-400" /> {t('smart_bot.unused_knowledge')}</h3>
                        <p className="mt-1 text-xs text-neutral-500 dark:text-neutral-400">{t('smart_bot.unused_knowledge_hint')}</p>
                        <ul className="mt-3 divide-y divide-neutral-200 dark:divide-neutral-800">
                            {unusedKnowledge.map(kb => (
                                <li key={kb.id} className="flex flex-col gap-2 py-2.5 sm:flex-row sm:items-center sm:justify-between">
                                    <span className="text-sm text-neutral-800 dark:text-neutral-200">{kb.name} <span className="text-xs text-neutral-500">· {t('smart_bot.list_sources', { count: kb.documents_count })}</span></span>
                                    <span className="flex gap-2">
                                        <button type="button" onClick={() => router.post(route('client.ai.chatbots.store'), { name: kb.name, from_kb: kb.uuid })} className="rounded-lg bg-brand-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-brand-700">{t('smart_bot.create_bot_from')}</button>
                                        <button type="button" onClick={() => deleteKnowledge(kb)} className="rounded-lg border border-neutral-300 px-3 py-1.5 text-xs text-neutral-600 hover:bg-red-50 hover:text-red-600 dark:border-neutral-700 dark:text-neutral-300">{t('common.delete')}</button>
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
            </div>

            {testing && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-3 backdrop-blur-sm" role="dialog" aria-modal="true" aria-label={t('ai.test')} onClick={e => e.target === e.currentTarget && setTesting(null)}>
                    <div className="flex h-[min(40rem,90vh)] w-full max-w-lg flex-col">
                        <div className="mb-2 flex justify-end"><button type="button" onClick={() => setTesting(null)} aria-label={t('common.close')} className="rounded-full bg-white p-2 text-neutral-600 shadow dark:bg-neutral-800 dark:text-neutral-200"><X className="h-4 w-4" /></button></div>
                        <TestPanel chatbot={testing} aiCredits={aiCredits} className="min-h-0 flex-1" />
                    </div>
                </div>
            )}
        </ClientLayout>
    );
}
