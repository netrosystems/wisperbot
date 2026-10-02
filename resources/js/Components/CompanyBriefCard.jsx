import { router } from '@inertiajs/react';
import { AlertTriangle, FileText, Loader2, Sparkles } from 'lucide-react';
import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { confirmDialog } from '@/Components/ConfirmDialog';

/*
 * The company brief beside a Knowledge Base's business profile (Smart Bot
 * 2.0, Phase 1.3). WisperBot drafts it from the Knowledge Base's own sources,
 * one sentence per fact with the source it came from; the client keeps, edits
 * or drops sentences and approves. Only the approved brief is used, and it
 * stays in use while a new draft waits for review.
 */
export default function CompanyBriefCard({ kb, hasSources }) {
    const { t } = useTranslation();
    const status = kb.company_brief_status ?? 'none';
    const approved = kb.company_brief_approved_at && kb.company_brief ? kb.company_brief : null;
    const [busy, setBusy] = useState(false);

    // Drafting runs on the AI queue; check back until it is done.
    useEffect(() => {
        if (status !== 'drafting') return undefined;
        const timer = window.setInterval(() => router.reload({ only: ['kb'], preserveScroll: true, preserveState: true }), 3000);
        return () => window.clearInterval(timer);
    }, [status]);

    const send = (method, name, data = {}) => {
        setBusy(true);
        router[method](route(name, { kb: kb.uuid }), data, { preserveScroll: true, onFinish: () => setBusy(false) });
    };
    const approve = data => send('post', 'client.ai.knowledge-bases.company-brief.approve', data);

    return (
        <section className="rounded-xl border border-neutral-200 bg-white p-5 dark:border-neutral-700 dark:bg-neutral-900" aria-labelledby={`company-brief-${kb.id}`}>
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0">
                    <h3 id={`company-brief-${kb.id}`} className="flex items-center gap-2 text-sm font-semibold text-neutral-900 dark:text-neutral-100">
                        <FileText className="h-4 w-4 text-brand-600" /> {t('ai.company_brief')}
                    </h3>
                    <p className="mt-1 text-xs leading-5 text-neutral-500 dark:text-neutral-400">{t('ai.company_brief_hint')}</p>
                </div>
                {status !== 'drafting' && status !== 'draft' && (
                    <button type="button" onClick={() => send('post', 'client.ai.knowledge-bases.company-brief.draft')} disabled={busy || !hasSources} className="inline-flex shrink-0 items-center gap-1.5 rounded-lg border border-brand-200 px-3 py-1.5 text-xs font-semibold text-brand-700 hover:bg-brand-50 disabled:cursor-not-allowed disabled:opacity-50 dark:border-brand-800 dark:text-brand-300 dark:hover:bg-brand-900/20">
                        <Sparkles className="h-3.5 w-3.5" /> {approved ? t('ai.company_brief_redraft') : t('ai.company_brief_draft')}
                    </button>
                )}
            </div>
            {!hasSources && status === 'none' && !approved && <p className="mt-3 text-xs text-neutral-500">{t('ai.company_brief_needs_sources')}</p>}

            {status === 'failed' && kb.company_brief_error && (
                <p className="mt-3 flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:border-amber-900/60 dark:bg-amber-900/20 dark:text-amber-200" role="alert">
                    <AlertTriangle className="mt-0.5 h-3.5 w-3.5 shrink-0" /> {kb.company_brief_error}
                </p>
            )}

            {status === 'drafting' && (
                <p className="mt-4 flex items-center gap-2 text-sm text-neutral-600 dark:text-neutral-300" role="status">
                    <Loader2 className="h-4 w-4 animate-spin" /> {t('ai.company_brief_drafting')}
                </p>
            )}

            {status === 'draft' && (kb.company_brief_draft ?? []).length > 0 && (
                <DraftReview
                    key={kb.company_brief_drafted_at}
                    draft={kb.company_brief_draft}
                    busy={busy}
                    onApprove={sentences => approve({ sentences })}
                    onDiscard={() => router.delete(route('client.ai.knowledge-bases.company-brief.destroy', { kb: kb.uuid, draft: 1 }), { preserveScroll: true })}
                />
            )}

            {approved && (
                <ApprovedBrief
                    key={approved}
                    brief={approved}
                    waitingDraft={status === 'draft'}
                    busy={busy}
                    onSave={text => approve({ text })}
                    onRemove={async () => {
                        if (await confirmDialog({ message: t('ai.company_brief_remove_confirm') })) {
                            router.delete(route('client.ai.knowledge-bases.company-brief.destroy', { kb: kb.uuid }), { preserveScroll: true });
                        }
                    }}
                />
            )}
        </section>
    );
}

/** One row per drafted sentence: keep, edit or untick; flagged ones start unticked. */
function DraftReview({ draft, busy, onApprove, onDiscard }) {
    const { t } = useTranslation();
    const [sentences, setSentences] = useState(() => draft.map(sentence => ({ ...sentence, keep: Boolean(sentence.supported) })));
    const update = (index, changes) => setSentences(previous => previous.map((sentence, i) => (i === index ? { ...sentence, ...changes } : sentence)));
    const kept = sentences.filter(sentence => sentence.keep && sentence.text.trim() !== '');

    return (
        <div className="mt-4 space-y-3">
            <p className="text-xs text-neutral-600 dark:text-neutral-300">{t('ai.company_brief_review')}</p>
            <ul className="space-y-2">
                {sentences.map((sentence, index) => (
                    <li key={index} className={`rounded-lg border p-3 ${sentence.supported ? 'border-neutral-200 dark:border-neutral-700' : 'border-amber-300 bg-amber-50/60 dark:border-amber-800 dark:bg-amber-900/10'}`}>
                        <div className="flex items-start gap-2">
                            <input type="checkbox" checked={sentence.keep} onChange={e => update(index, { keep: e.target.checked })} aria-label={t('ai.company_brief_keep')} className="mt-2 rounded text-brand-600 focus:ring-brand-500" />
                            <div className="min-w-0 flex-1">
                                <textarea value={sentence.text} onChange={e => update(index, { text: e.target.value })} rows={2} maxLength={400} aria-label={t('ai.company_brief_sentence')} className="w-full resize-y rounded-md border border-neutral-200 bg-white px-2 py-1.5 text-sm text-neutral-900 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/30 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-100" />
                                <p className="mt-1 text-[11px] text-neutral-500">{t('ai.company_brief_source', { title: sentence.source_title })}</p>
                                {!sentence.supported && <p className="mt-1 text-[11px] font-medium text-amber-700 dark:text-amber-300">{t('ai.company_brief_unsupported')}</p>}
                            </div>
                        </div>
                    </li>
                ))}
            </ul>
            <div className="flex flex-wrap gap-2">
                <button type="button" onClick={() => onApprove(kept.map(sentence => sentence.text))} disabled={busy || kept.length === 0} className="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700 disabled:opacity-50">
                    {t('ai.company_brief_approve', { count: kept.length })}
                </button>
                <button type="button" onClick={onDiscard} disabled={busy} className="rounded-lg border border-neutral-200 px-4 py-2 text-sm text-neutral-600 hover:bg-neutral-50 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-800">
                    {t('ai.company_brief_discard')}
                </button>
            </div>
        </div>
    );
}

/** The approved brief, editable as one text. */
function ApprovedBrief({ brief, waitingDraft, busy, onSave, onRemove }) {
    const { t } = useTranslation();
    const [editing, setEditing] = useState(false);
    const [text, setText] = useState(brief);

    return (
        <div className="mt-4 rounded-lg border border-green-200 bg-green-50/60 p-3 dark:border-green-900/60 dark:bg-green-900/10">
            <p className="text-[11px] font-semibold uppercase tracking-wide text-green-700 dark:text-green-300">
                {waitingDraft ? t('ai.company_brief_in_use_until') : t('ai.company_brief_in_use')}
            </p>
            {editing ? (
                <div className="mt-2 space-y-2">
                    <textarea value={text} onChange={e => setText(e.target.value)} rows={6} maxLength={3000} aria-label={t('ai.company_brief')} className="w-full resize-y rounded-md border border-neutral-200 bg-white px-2 py-1.5 text-sm text-neutral-900 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/30 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-100" />
                    <div className="flex gap-2">
                        <button type="button" onClick={() => { onSave(text); setEditing(false); }} disabled={busy} className="rounded-lg bg-brand-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-brand-700 disabled:opacity-50">{t('ai.save_changes')}</button>
                        <button type="button" onClick={() => { setText(brief); setEditing(false); }} className="rounded-lg border border-neutral-200 px-3 py-1.5 text-xs text-neutral-600 dark:border-neutral-700 dark:text-neutral-300">{t('common.cancel')}</button>
                    </div>
                </div>
            ) : (
                <>
                    <p className="mt-1 whitespace-pre-line text-sm leading-6 text-neutral-800 dark:text-neutral-200">{brief}</p>
                    <div className="mt-2 flex gap-3 text-xs">
                        <button type="button" onClick={() => setEditing(true)} className="font-semibold text-brand-600 hover:text-brand-700">{t('common.edit')}</button>
                        <button type="button" onClick={onRemove} className="font-semibold text-red-600 hover:text-red-700">{t('common.remove')}</button>
                    </div>
                </>
            )}
        </div>
    );
}
