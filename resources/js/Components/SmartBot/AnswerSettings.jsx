import { useTranslation } from 'react-i18next';

// Stored values stay as they are; the names are what clients choose between.
const SCOPES = [
    { value: 'verified_only', label: 'smart_bot.scope_strict', hint: 'smart_bot.scope_strict_hint' },
    { value: 'business_only', label: 'smart_bot.scope_balanced', hint: 'smart_bot.scope_balanced_hint', recommended: true },
    { value: 'general', label: 'smart_bot.scope_flexible', hint: 'smart_bot.scope_flexible_hint' },
];

const LENGTHS = ['short', 'standard', 'detailed'];

const inputCls = 'w-full rounded-lg border border-neutral-300 bg-white px-3 py-2 text-sm text-neutral-900 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/25 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100';

function Switch({ checked, onChange, label, hint }) {
    return (
        <label className="flex items-start justify-between gap-3 rounded-lg border border-neutral-200 p-3 dark:border-neutral-700">
            <span>
                <span className="block text-sm font-medium text-neutral-900 dark:text-neutral-100">{label}</span>
                <span className="mt-0.5 block text-xs leading-5 text-neutral-500 dark:text-neutral-400">{hint}</span>
            </span>
            <input type="checkbox" role="switch" checked={checked} onChange={e => onChange(e.target.checked)} className="peer sr-only" />
            <span aria-hidden className={`relative mt-0.5 inline-flex h-5 w-9 shrink-0 rounded-full transition peer-focus-visible:ring-2 peer-focus-visible:ring-brand-500/40 ${checked ? 'bg-brand-600' : 'bg-neutral-300 dark:bg-neutral-700'}`}>
                <span className={`absolute top-0.5 h-4 w-4 rounded-full bg-white shadow transition ${checked ? 'left-4' : 'left-0.5'}`} />
            </span>
        </label>
    );
}

/**
 * "How it answers": how freely, how long, what happens without a verified
 * answer. Settings that only act behind a rollout flag appear only when it is on.
 */
export default function AnswerSettings({ data, setData, errors = {}, profileComplete = true, researchAvailable = false, liveProductFactsAvailable = false }) {
    const { t } = useTranslation();

    return (
        <div className="space-y-5">
            <fieldset>
                <legend className="text-sm font-medium text-neutral-800 dark:text-neutral-200">{t('smart_bot.how_freely')}</legend>
                <div className="mt-2 grid gap-2 sm:grid-cols-3">
                    {SCOPES.map(scope => (
                        <label key={scope.value} className={`cursor-pointer rounded-xl border p-3 transition ${data.answer_scope === scope.value ? 'border-brand-500 bg-brand-50/50 ring-1 ring-brand-500/20 dark:bg-brand-900/10' : 'border-neutral-200 hover:border-neutral-300 dark:border-neutral-700'}`}>
                            <input type="radio" name="answer_scope" value={scope.value} checked={data.answer_scope === scope.value} onChange={() => setData('answer_scope', scope.value)} className="sr-only" />
                            <span className="block text-sm font-semibold text-neutral-900 dark:text-neutral-100">
                                {t(scope.label)}
                                {scope.recommended && <span className="ml-1 text-xs font-normal text-neutral-400">({t('smart_bot.recommended')})</span>}
                            </span>
                            <span className="mt-1 block text-xs leading-5 text-neutral-500 dark:text-neutral-400">{t(scope.hint)}</span>
                        </label>
                    ))}
                </div>
                {data.answer_scope === 'business_only' && !profileComplete && (
                    <p className="mt-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs leading-5 text-amber-800 dark:border-amber-900/60 dark:bg-amber-900/20 dark:text-amber-200">{t('smart_bot.balanced_needs_business')}</p>
                )}
                {errors.answer_scope && <p className="mt-1 text-xs text-red-500">{errors.answer_scope}</p>}
            </fieldset>

            <fieldset>
                <legend className="text-sm font-medium text-neutral-800 dark:text-neutral-200">{t('ai.reply_length')}</legend>
                <div className="mt-2 inline-flex rounded-lg bg-neutral-100 p-1 dark:bg-neutral-800">
                    {LENGTHS.map(length => (
                        <label key={length} className={`cursor-pointer rounded-md px-3 py-1.5 text-sm transition ${data.reply_length === length ? 'bg-white font-semibold text-brand-700 shadow-sm dark:bg-neutral-700 dark:text-brand-300' : 'text-neutral-600 dark:text-neutral-300'}`}>
                            <input type="radio" name="reply_length" value={length} checked={data.reply_length === length} onChange={() => setData('reply_length', length)} className="sr-only" />
                            {t(`smart_bot.length_${length}`)}
                        </label>
                    ))}
                </div>
                <p className="mt-1.5 text-xs text-neutral-500 dark:text-neutral-400">{t(`smart_bot.length_${data.reply_length}_hint`)}</p>
            </fieldset>

            <label className="block">
                <span className="mb-1.5 block text-sm font-medium text-neutral-800 dark:text-neutral-200">{t('smart_bot.no_answer_label')}</span>
                <select value={data.unsupported_fallback_action} onChange={e => setData('unsupported_fallback_action', e.target.value)} className={inputCls}>
                    <option value="clarify_then_handoff">{t('smart_bot.no_answer_clarify')}</option>
                    <option value="handoff">{t('smart_bot.no_answer_handoff')}</option>
                </select>
            </label>

            <label className="block">
                <span className="mb-1.5 block text-sm font-medium text-neutral-800 dark:text-neutral-200">{t('smart_bot.cannot_answer_label')}</span>
                <textarea rows={2} maxLength={512} value={data.fallback_reply} onChange={e => setData('fallback_reply', e.target.value)} className={`${inputCls} resize-none`} />
                <span className="mt-1 block text-xs text-neutral-500 dark:text-neutral-400">{t('smart_bot.cannot_answer_hint')}</span>
                {errors.fallback_reply && <span className="mt-1 block text-xs text-red-500">{errors.fallback_reply}</span>}
            </label>

            <div className="space-y-2">
                <Switch checked={data.kb_exact_wording} onChange={value => setData('kb_exact_wording', value)} label={t('smart_bot.exact_wording')} hint={t('smart_bot.exact_wording_hint')} />
                {researchAvailable && <Switch checked={data.trusted_research_enabled} onChange={value => setData('trusted_research_enabled', value)} label={t('ai.research_approved_sources')} hint={t('ai.research_approved_sources_hint')} />}
                {liveProductFactsAvailable && <Switch checked={data.live_product_facts_enabled} onChange={value => setData('live_product_facts_enabled', value)} label={t('ai.live_product_prices')} hint={t('smart_bot.live_product_prices_hint')} />}
            </div>
        </div>
    );
}
