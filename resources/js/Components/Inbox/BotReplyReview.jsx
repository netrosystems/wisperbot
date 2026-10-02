import axios from 'axios';
import { ThumbsDown, ThumbsUp } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

/*
 * Under a Smart Bot reply in the inbox (Smart Bot 2.0, Phase 1.5): the team
 * rates it, sees why the bot answered that way, and can write the right
 * answer into the Knowledge Base. `payload.ai_review` is staff-only; the
 * widget never sends it to visitors.
 */
export default function BotReplyReview({ msg, conversationId }) {
    const { t } = useTranslation();
    const review = msg.payload?.ai_review ?? {};
    const [feedback, setFeedback] = useState(msg.payload?.ai_feedback ?? {});
    const [panel, setPanel] = useState(null); // null | 'why' | 'improve'
    const [question, setQuestion] = useState('');
    const [answer, setAnswer] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState(null);
    const params = { conversation: conversationId, message: msg.id };

    const rate = async (rating) => {
        setBusy(true);
        try {
            const { data } = await axios.post(route('client.inbox.messages.ai-feedback', params), { rating });
            setFeedback(data.ai_feedback);
            if (rating === 'down' && data.ai_feedback.rating === 'down' && review.kb_id) {
                openImprove();
            }
        } finally {
            setBusy(false);
        }
    };
    const openImprove = async () => {
        setPanel('improve');
        setError(null);
        if (question === '') {
            try {
                const { data } = await axios.get(route('client.inbox.messages.ai-question', params));
                setQuestion(data.question ?? '');
            } catch {
                // The question can still be typed.
            }
        }
    };
    const improve = async (e) => {
        e.preventDefault();
        setBusy(true);
        setError(null);
        try {
            const { data } = await axios.post(route('client.inbox.messages.ai-improve', params), { question, answer });
            setFeedback(data.ai_feedback);
            setPanel(null);
            setAnswer('');
        } catch (failure) {
            setError(failure.response?.data?.errors?.answer?.[0] ?? t('inbox.ai_review_improve_failed'));
        } finally {
            setBusy(false);
        }
    };
    const toggle = (name) => (panel === name ? setPanel(null) : name === 'improve' ? openImprove() : setPanel(name));
    const button = 'inline-flex items-center gap-1 whitespace-nowrap rounded-md px-1.5 py-0.5 text-[11px] font-medium transition hover:bg-neutral-100 dark:hover:bg-neutral-800';

    return (
        <div className="mt-1 flex w-full flex-col items-end text-neutral-500 dark:text-neutral-400">
            <div className="flex flex-wrap items-center justify-end gap-1">
                <button type="button" onClick={() => rate('up')} disabled={busy} aria-pressed={feedback.rating === 'up'} aria-label={t('inbox.ai_review_good')} title={t('inbox.ai_review_good')} className={`${button} ${feedback.rating === 'up' ? 'text-green-600' : ''}`}>
                    <ThumbsUp className="h-3.5 w-3.5" />
                </button>
                <button type="button" onClick={() => rate('down')} disabled={busy} aria-pressed={feedback.rating === 'down'} aria-label={t('inbox.ai_review_bad')} title={t('inbox.ai_review_bad')} className={`${button} ${feedback.rating === 'down' ? 'text-red-600' : ''}`}>
                    <ThumbsDown className="h-3.5 w-3.5" />
                </button>
                <button type="button" onClick={() => toggle('why')} aria-expanded={panel === 'why'} className={button}>{t('inbox.ai_review_why')}</button>
                {review.kb_id && <button type="button" onClick={() => toggle('improve')} aria-expanded={panel === 'improve'} className={button}>{feedback.improved ? t('inbox.ai_review_improved') : t('inbox.ai_review_improve')}</button>}
            </div>

            {panel === 'why' && (
                <dl className="mt-1 grid w-full max-w-sm grid-cols-[auto,1fr] gap-x-3 gap-y-0.5 rounded-lg border border-neutral-200 bg-white p-2.5 text-[11px] dark:border-neutral-700 dark:bg-neutral-900">
                    <dt className="font-medium">{t('inbox.ai_review_outcome')}</dt><dd>{t(`inbox.ai_reason_${review.reason_code}`, { defaultValue: review.reason_code ?? '—' })}</dd>
                    {review.answer_origin && <><dt className="font-medium">{t('inbox.ai_review_based_on')}</dt><dd>{t(`inbox.ai_origin_${review.answer_origin}`, { defaultValue: review.answer_origin })}</dd></>}
                    {review.best_score !== undefined && <><dt className="font-medium">{t('inbox.ai_review_match')}</dt><dd>{Math.round(review.best_score * 100)}%</dd></>}
                    {review.sources?.length > 0 && <><dt className="font-medium">{t('inbox.ai_review_sources')}</dt><dd>{review.sources.join(', ')}</dd></>}
                    {review.engine && <><dt className="font-medium">{t('inbox.ai_review_engine')}</dt><dd>{review.engine === 'v2' ? t('inbox.ai_review_engine_v2') : t('inbox.ai_review_engine_v1')}</dd></>}
                </dl>
            )}

            {panel === 'improve' && (
                <form onSubmit={improve} className="mt-1 w-full max-w-sm space-y-2 rounded-lg border border-neutral-200 bg-white p-2.5 dark:border-neutral-700 dark:bg-neutral-900">
                    <p className="text-[11px]">{t('inbox.ai_review_improve_hint')}</p>
                    <input required maxLength={500} value={question} onChange={e => setQuestion(e.target.value)} aria-label={t('inbox.ai_review_question')} placeholder={t('inbox.ai_review_question')} className="w-full rounded-md border border-neutral-200 px-2 py-1 text-xs text-neutral-900 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-100" />
                    <textarea required rows={3} maxLength={4000} value={answer} onChange={e => setAnswer(e.target.value)} aria-label={t('inbox.ai_review_answer')} placeholder={t('inbox.ai_review_answer')} className="w-full resize-y rounded-md border border-neutral-200 px-2 py-1 text-xs text-neutral-900 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-100" />
                    {error && <p className="text-[11px] text-red-600" role="alert">{error}</p>}
                    <div className="flex justify-end gap-2">
                        <button type="button" onClick={() => setPanel(null)} className="rounded-md border border-neutral-200 px-2 py-1 text-[11px] dark:border-neutral-700">{t('common.cancel')}</button>
                        <button disabled={busy || answer.trim() === ''} className="rounded-md bg-brand-600 px-2 py-1 text-[11px] font-semibold text-white disabled:opacity-50">{t('inbox.ai_review_save')}</button>
                    </div>
                </form>
            )}
        </div>
    );
}
