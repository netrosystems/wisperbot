import { Plus, Trash2 } from 'lucide-react';
import { useTranslation } from 'react-i18next';

export const STARTER_MAX_ITEMS = 5;
export const STARTER_QUESTION_MAX = 80;
export const STARTER_ANSWER_MAX = 1000;

/**
 * Up to five client-written questions shown at the top of website and SDK
 * chats. The saved answer is sent word for word, without AI, so the list works
 * whether or not a Smart Bot is answering.
 */
export default function StarterQuestionsEditor({ items, errors = {}, onChange }) {
    const { t } = useTranslation();
    const update = (index, field, value) => onChange(items.map((item, i) => (i === index ? { ...item, [field]: value } : item)));
    const remove = index => onChange(items.filter((_, i) => i !== index));
    const add = () => items.length < STARTER_MAX_ITEMS && onChange([...items, { question: '', answer: '' }]);
    const fieldClass = 'w-full rounded-lg border border-neutral-300 bg-white px-3 py-2 text-sm text-neutral-900 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/25 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100';

    return (
        <div className="space-y-3">
            {errors.starter_questions && <p role="alert" className="text-xs text-red-600 dark:text-red-400">{errors.starter_questions}</p>}
            {items.length === 0 && <p className="text-xs text-neutral-500 dark:text-neutral-400">{t('ai.starter_questions_empty')}</p>}
            <ol className="space-y-3">
                {items.map((item, index) => {
                    const questionError = errors[`starter_questions.${index}.question`];
                    const answerError = errors[`starter_questions.${index}.answer`];
                    return (
                        <li key={item.id ?? `new-${index}`} className="space-y-2 rounded-xl border border-neutral-200 bg-neutral-50/60 p-3 dark:border-neutral-700 dark:bg-neutral-800/40">
                            <div className="flex items-center justify-between gap-2">
                                <span className="text-xs font-medium text-neutral-600 dark:text-neutral-300">{t('ai.starter_question_number', { number: index + 1 })}</span>
                                <button type="button" onClick={() => remove(index)} aria-label={t('ai.starter_question_remove', { number: index + 1 })} className="rounded-md p-1 text-neutral-400 transition hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-950/40">
                                    <Trash2 className="h-4 w-4" />
                                </button>
                            </div>
                            <div>
                                <input
                                    type="text"
                                    value={item.question}
                                    maxLength={STARTER_QUESTION_MAX}
                                    onChange={e => update(index, 'question', e.target.value)}
                                    placeholder={t('ai.starter_question_placeholder')}
                                    aria-label={t('ai.starter_question_label', { number: index + 1 })}
                                    aria-invalid={Boolean(questionError)}
                                    className={fieldClass}
                                />
                                <div className="mt-1 flex justify-between gap-2 text-[11px]">
                                    <span className="text-red-600 dark:text-red-400">{questionError}</span>
                                    <span className="text-neutral-400">{item.question.length}/{STARTER_QUESTION_MAX}</span>
                                </div>
                            </div>
                            <div>
                                <textarea
                                    value={item.answer}
                                    rows={3}
                                    maxLength={STARTER_ANSWER_MAX}
                                    onChange={e => update(index, 'answer', e.target.value)}
                                    placeholder={t('ai.starter_answer_placeholder')}
                                    aria-label={t('ai.starter_answer_label', { number: index + 1 })}
                                    aria-invalid={Boolean(answerError)}
                                    className={`${fieldClass} resize-y`}
                                />
                                <div className="mt-1 flex justify-between gap-2 text-[11px]">
                                    <span className="text-red-600 dark:text-red-400">{answerError}</span>
                                    <span className="text-neutral-400">{item.answer.length}/{STARTER_ANSWER_MAX}</span>
                                </div>
                            </div>
                        </li>
                    );
                })}
            </ol>
            <div className="flex items-center justify-between gap-2">
                <button
                    type="button"
                    onClick={add}
                    disabled={items.length >= STARTER_MAX_ITEMS}
                    className="inline-flex items-center gap-1.5 rounded-lg border border-neutral-300 bg-white px-3 py-1.5 text-xs font-medium text-neutral-700 transition hover:bg-neutral-50 disabled:cursor-not-allowed disabled:opacity-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300 dark:hover:bg-neutral-800"
                >
                    <Plus className="h-3.5 w-3.5" /> {t('ai.starter_question_add')}
                </button>
                <span className="text-[11px] text-neutral-500 dark:text-neutral-400">{t('ai.starter_questions_count', { count: items.length, max: STARTER_MAX_ITEMS })}</span>
            </div>
        </div>
    );
}
