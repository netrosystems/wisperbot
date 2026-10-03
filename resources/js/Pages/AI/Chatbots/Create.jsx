import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft, Bot } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import ClientLayout from '@/Layouts/ClientLayout';
import SetupSteps from '@/Components/SmartBot/SetupSteps';

const inputCls = 'w-full rounded-lg border border-neutral-300 bg-white px-3 py-2 text-sm text-neutral-900 placeholder-neutral-400 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/25 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100';

/**
 * A new Smart Bot: who it is. Its knowledge is created with it; business
 * details, sources and answer settings follow on the bot's own page.
 */
export default function SmartBotCreate({ tones = [] }) {
    const { t } = useTranslation();
    const { data, setData, post, processing, errors } = useForm({ name: '', tone: 'friendly', system_prompt: '' });

    const submit = (e) => {
        e.preventDefault();
        post(route('client.ai.chatbots.store'));
    };

    return (
        <ClientLayout title={t('ai.new_chatbot')}>
            <Head title={t('ai.new_chatbot')} />
            <div className="mx-auto max-w-3xl space-y-6">
                <div>
                    <Link href={route('client.ai.chatbots.index')} className="inline-flex items-center gap-1.5 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200">
                        <ArrowLeft className="h-4 w-4" /> {t('ai.chatbots_heading')}
                    </Link>
                    <h2 className="mt-2 text-xl font-semibold text-neutral-900 dark:text-neutral-100">{t('smart_bot.create_title')}</h2>
                    <p className="mt-1 text-sm text-neutral-500 dark:text-neutral-400">{t('smart_bot.create_hint')}</p>
                </div>

                <SetupSteps
                    disabled
                    current="bot"
                    onSelect={() => {}}
                    steps={[
                        { id: 'bot', title: t('smart_bot.step_bot'), done: false },
                        { id: 'business', title: t('smart_bot.step_business'), done: false },
                        { id: 'knowledge', title: t('smart_bot.step_knowledge'), done: false },
                        { id: 'answers', title: t('smart_bot.step_answers'), done: false },
                    ]}
                />

                <form onSubmit={submit} className="space-y-5 rounded-2xl border border-neutral-200 bg-white p-5 dark:border-neutral-800 dark:bg-neutral-900">
                    <div className="flex items-start gap-3">
                        <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-brand-50 text-brand-600 dark:bg-brand-900/30 dark:text-brand-300"><Bot className="h-4 w-4" /></span>
                        <div>
                            <h3 className="text-sm font-semibold text-neutral-900 dark:text-neutral-100">{t('smart_bot.step_bot')}</h3>
                            <p className="text-xs text-neutral-500 dark:text-neutral-400">{t('smart_bot.bot_description')}</p>
                        </div>
                    </div>
                    <label className="block">
                        <span className="mb-1.5 block text-sm font-medium text-neutral-800 dark:text-neutral-200">{t('common.name')}</span>
                        <input required autoFocus maxLength={128} value={data.name} onChange={e => setData('name', e.target.value)} placeholder={t('smart_bot.name_placeholder')} className={inputCls} />
                        <span className="mt-1 block text-xs text-neutral-500 dark:text-neutral-400">{t('smart_bot.name_hint')}</span>
                        {errors.name && <span className="mt-1 block text-xs text-red-500">{errors.name}</span>}
                    </label>
                    <label className="block">
                        <span className="mb-1.5 block text-sm font-medium text-neutral-800 dark:text-neutral-200">{t('ai.tone')}</span>
                        <select value={data.tone} onChange={e => setData('tone', e.target.value)} className={inputCls}>
                            {tones.map(tone => <option key={tone} value={tone}>{t(`ai.tone_${tone}`)}</option>)}
                        </select>
                        <span className="mt-1 block text-xs text-neutral-500 dark:text-neutral-400">{t('smart_bot.tone_hint')}</span>
                    </label>
                    <label className="block">
                        <span className="mb-1.5 block text-sm font-medium text-neutral-800 dark:text-neutral-200">{t('smart_bot.always_do')}</span>
                        <textarea rows={3} maxLength={8192} value={data.system_prompt} onChange={e => setData('system_prompt', e.target.value)} className={`${inputCls} resize-y`} />
                        <span className="mt-1 block text-xs text-neutral-500 dark:text-neutral-400">{t('smart_bot.always_do_hint')}</span>
                    </label>
                    {errors.plan_limit && <p className="text-xs text-red-500">{errors.plan_limit}</p>}
                    <div className="flex items-center gap-3">
                        <button type="submit" disabled={processing || !data.name.trim()} className="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700 disabled:opacity-50">
                            {processing ? t('ai.creating') : t('smart_bot.create_button')}
                        </button>
                        {!data.name.trim() && <span className="text-xs text-neutral-500">{t('smart_bot.name_first')}</span>}
                    </div>
                </form>
            </div>
        </ClientLayout>
    );
}
