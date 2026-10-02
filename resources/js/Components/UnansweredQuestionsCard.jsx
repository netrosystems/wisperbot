import { router } from '@inertiajs/react';
import { MessageCircleQuestion } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

/*
 * Questions customers asked that this Knowledge Base could not answer
 * (Smart Bot 2.0, Phase 1.5), most asked first. "Write answer" adds the
 * answer to the Knowledge Base's own FAQ source for these answers;
 * "Dismiss" hides a question that does not need one.
 */
export default function UnansweredQuestionsCard({ kb, guardedPublishing }) {
    const { t } = useTranslation();
    const questions = kb.knowledge_gaps ?? [];
    const [open, setOpen] = useState(null);

    return (
        <section className="rounded-xl border border-neutral-200 bg-white p-5 dark:border-neutral-700 dark:bg-neutral-900" aria-labelledby={`unanswered-${kb.id}`}>
            <h3 id={`unanswered-${kb.id}`} className="flex items-center gap-2 text-sm font-semibold text-neutral-900 dark:text-neutral-100">
                <MessageCircleQuestion className="h-4 w-4 text-brand-600" /> {t('ai.unanswered_questions')}
                {questions.length > 0 && <span className="rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-semibold text-amber-800 dark:bg-amber-900/30 dark:text-amber-200">{questions.length}</span>}
            </h3>
            <p className="mt-1 text-xs leading-5 text-neutral-500 dark:text-neutral-400">{t('ai.unanswered_questions_hint')}</p>

            {questions.length === 0 ? (
                <p className="mt-4 text-sm text-neutral-500">{t('ai.unanswered_questions_empty')}</p>
            ) : (
                <ul className="mt-4 divide-y divide-neutral-100 dark:divide-neutral-800">
                    {questions.map(question => (
                        <li key={question.id} className="py-3">
                            <div className="flex flex-wrap items-start justify-between gap-3">
                                <div className="min-w-0 flex-1">
                                    <p className="break-words text-sm text-neutral-900 dark:text-neutral-100">{question.question_sample}</p>
                                    <p className="mt-0.5 text-[11px] text-neutral-500">
                                        {t('ai.unanswered_asked', { count: question.occurrences })}
                                        {question.last_seen_at && ` · ${new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' }).format(new Date(question.last_seen_at))}`}
                                    </p>
                                </div>
                                {open !== question.id && (
                                    <div className="flex shrink-0 gap-2">
                                        <button type="button" onClick={() => setOpen(question.id)} className="rounded-lg bg-brand-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-brand-700">{t('ai.unanswered_write_answer')}</button>
                                        <button type="button" onClick={() => router.post(route('client.ai.knowledge-bases.unanswered.dismiss', { kb: kb.uuid, gap: question.id }), {}, { preserveScroll: true })} className="rounded-lg border border-neutral-200 px-3 py-1.5 text-xs text-neutral-600 hover:bg-neutral-50 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-800">{t('ai.unanswered_dismiss')}</button>
                                    </div>
                                )}
                            </div>
                            {open === question.id && <AnswerForm kb={kb} question={question} guardedPublishing={guardedPublishing} onClose={() => setOpen(null)} />}
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}

function AnswerForm({ kb, question, guardedPublishing, onClose }) {
    const { t } = useTranslation();
    const [text, setText] = useState(question.question_sample);
    const [answer, setAnswer] = useState('');
    const [busy, setBusy] = useState(false);
    const save = (e) => {
        e.preventDefault();
        setBusy(true);
        router.post(route('client.ai.knowledge-bases.unanswered.answer', { kb: kb.uuid, gap: question.id }), { question: text, answer }, {
            preserveScroll: true,
            onSuccess: onClose,
            onFinish: () => setBusy(false),
        });
    };
    const field = 'mt-1 w-full rounded-lg border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-900 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/30 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-100';

    return (
        <form onSubmit={save} className="mt-3 space-y-2 rounded-lg bg-neutral-50 p-3 dark:bg-neutral-800/50">
            <label className="block text-xs font-medium text-neutral-600 dark:text-neutral-300">
                {t('ai.unanswered_question_label')}
                <input required maxLength={500} value={text} onChange={e => setText(e.target.value)} className={field} />
            </label>
            <label className="block text-xs font-medium text-neutral-600 dark:text-neutral-300">
                {t('ai.unanswered_answer_label')}
                <textarea required rows={3} maxLength={4000} value={answer} onChange={e => setAnswer(e.target.value)} placeholder={t('ai.unanswered_answer_placeholder')} className={`${field} resize-y`} />
            </label>
            <p className="text-[11px] text-neutral-500">{guardedPublishing ? t('ai.unanswered_answer_guarded') : t('ai.unanswered_answer_live')}</p>
            <div className="flex gap-2">
                <button disabled={busy || answer.trim() === ''} className="rounded-lg bg-brand-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-brand-700 disabled:opacity-50">{t('ai.unanswered_save_answer')}</button>
                <button type="button" onClick={onClose} className="rounded-lg border border-neutral-200 px-3 py-1.5 text-xs text-neutral-600 dark:border-neutral-700 dark:text-neutral-300">{t('common.cancel')}</button>
            </div>
        </form>
    );
}
