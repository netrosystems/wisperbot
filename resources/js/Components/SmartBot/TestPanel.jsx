import { Bot, Send, MessageSquare, AlertTriangle } from 'lucide-react';
import { useState, useRef, useEffect } from 'react';
import { useTranslation } from 'react-i18next';
import MarkdownLite from '@/Components/MarkdownLite';

/**
 * Chat with a bot as a customer would. Answers use the bot's real engine and
 * settings and are charged like any answer; they never join the unanswered
 * questions list.
 */
export default function TestPanel({ chatbot, aiCredits, className = 'h-[28rem]' }) {
    const { t } = useTranslation();
    const [messages, setMessages] = useState([]);
    const [input, setInput] = useState('');
    const [loading, setLoading] = useState(false);
    const [remainingCredits, setRemainingCredits] = useState(aiCredits?.remaining ?? 0);
    const bottomRef = useRef(null);
    const sendLock = useRef(false);

    useEffect(() => {
        bottomRef.current?.scrollIntoView({ behavior: 'smooth' });
    }, [messages, loading]);

    const send = async (choice) => {
        const text = typeof choice === 'string' ? choice : input;
        if (!text.trim() || sendLock.current) return;
        sendLock.current = true;
        const requestId = window.crypto.randomUUID();
        const userMsg = { role: 'user', content: text, requestId };
        setMessages(prev => [...prev, userMsg]);
        setInput('');
        setLoading(true);
        try {
            const res = await fetch(route('client.ai.chatbots.playground', chatbot.uuid), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content,
                    'Idempotency-Key': `chatbot-playground:${chatbot.id}:${requestId}`,
                },
                body: JSON.stringify({ message: userMsg.content, history: messages.filter(m => m.role === 'user' || m.role === 'assistant').slice(-20).map(m => ({ role: m.role, content: m.content })) }),
            });
            const data = await res.json();
            if (data.ai_credits) setRemainingCredits(data.ai_credits.remaining ?? 0);
            if (!res.ok && ['ai_credits_exhausted', 'ai_credits_unavailable'].includes(data.error_code)) {
                setInput(userMsg.content);
            }
            setMessages(prev => [...prev, {
                role: res.ok ? 'assistant' : 'error',
                content: data.reply ?? data.error ?? t('ai.playground_error'),
                displayBody: data.display_body,
                quickReplies: Array.isArray(data.quick_replies) ? data.quick_replies : [],
                resources: data.resources ?? [],
            }]);
        } catch {
            setMessages(prev => [...prev, { role: 'error', content: t('ai.playground_error') }]);
        } finally {
            sendLock.current = false;
            setLoading(false);
        }
    };

    const handleKey = (e) => {
        if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send(); }
    };

    return (
        <div className={`flex flex-col ${className} bg-neutral-50 dark:bg-neutral-800/50 rounded-xl border border-neutral-200 dark:border-neutral-700 overflow-hidden`}>
            <div className="flex items-center gap-2 px-4 py-2.5 border-b border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900">
                <div className="w-2 h-2 rounded-full bg-green-500" />
                <span className="text-xs font-medium text-neutral-600 dark:text-neutral-400">{chatbot.name} — {t('ai.playground')}</span>
                {messages.length > 0 && (
                    <button onClick={() => setMessages([])} className="ml-auto text-xs text-neutral-400 hover:text-neutral-600 dark:hover:text-neutral-200 transition">{t('ai.clear')}</button>
                )}
            </div>

            <div className="flex-1 overflow-y-auto p-4 space-y-3">
                {messages.length === 0 && !loading && (
                    <div className="flex flex-col items-center justify-center h-full text-center space-y-2">
                        <MessageSquare className="h-8 w-8 text-neutral-300 dark:text-neutral-600" />
                        <p className="text-sm text-neutral-400 dark:text-neutral-500">{t('ai.playground_empty')}</p>
                    </div>
                )}
                {messages.map((m, i) => {
                    const isUser = m.role === 'user';
                    const isError = m.role === 'error';

                    return (
                        <div key={i} className={`flex gap-2 ${isUser ? 'flex-row-reverse' : 'flex-row'}`}>
                            <div className={`shrink-0 w-7 h-7 rounded-full flex items-center justify-center text-xs font-semibold ${isUser ? 'bg-brand-600 text-white' : isError ? 'bg-red-100 text-red-600 dark:bg-red-900/40 dark:text-red-300' : 'bg-neutral-200 dark:bg-neutral-700 text-neutral-600 dark:text-neutral-300'}`}>
                                {isUser ? 'U' : isError ? <AlertTriangle className="h-3.5 w-3.5" /> : <Bot className="h-3.5 w-3.5" />}
                            </div>
                            <div className={`rounded-2xl px-3.5 py-2 text-sm leading-relaxed ${isUser ? 'max-w-[75%] bg-brand-600 text-white rounded-tr-sm whitespace-pre-wrap break-words' : isError ? 'max-w-[85%] border border-red-200 bg-red-50 text-red-800 dark:border-red-900/60 dark:bg-red-900/20 dark:text-red-200 rounded-tl-sm whitespace-pre-wrap break-words' : 'max-w-[85%] bg-white dark:bg-neutral-700 text-neutral-900 dark:text-neutral-100 shadow-sm rounded-tl-sm'}`}>
                                {isUser || isError ? m.content : <MarkdownLite content={m.quickReplies?.length ? m.displayBody ?? m.content : m.content} />}
                                {!isUser && !isError && m.quickReplies?.length > 0 && (
                                    <div role="group" aria-label={t('inbox.suggested_replies', 'Suggested customer replies')} className="mt-2 flex flex-wrap gap-1.5">
                                        {m.quickReplies.slice(0, 3).map(choice => (
                                            <button key={choice.id} type="button" disabled={loading || i !== messages.length - 1}
                                                onClick={() => send(choice.label)}
                                                className="min-h-[34px] [@media(any-pointer:coarse)]:min-h-11 rounded-lg border border-neutral-200 dark:border-neutral-500 bg-neutral-50 dark:bg-neutral-700 px-2.5 py-[7px] text-[13px] leading-[18px] font-medium text-neutral-700 dark:text-neutral-100 hover:bg-neutral-100 hover:border-neutral-400 dark:hover:bg-neutral-600 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500 disabled:bg-transparent disabled:text-neutral-500 dark:disabled:text-neutral-400 disabled:cursor-default">
                                                {choice.label}
                                            </button>
                                        ))}
                                    </div>
                                )}
                                {!isUser && !isError && m.resources?.map((resource, resourceIndex) => <a key={resourceIndex} href={resource.canonical_url} target="_blank" rel="noreferrer" className="mt-2 block text-xs font-medium text-brand-600 hover:underline">▶ {resource.title || t('ai.preview_video')}</a>)}
                            </div>
                        </div>
                    );
                })}
                {loading && (
                    <div className="flex gap-2">
                        <div className="shrink-0 w-7 h-7 rounded-full bg-neutral-200 dark:bg-neutral-700 flex items-center justify-center">
                            <Bot className="h-3.5 w-3.5 text-neutral-500" />
                        </div>
                        <div className="bg-white dark:bg-neutral-700 rounded-2xl rounded-tl-sm px-4 py-2.5 shadow-sm flex items-center gap-1">
                            <span className="w-1.5 h-1.5 rounded-full bg-neutral-400 animate-bounce [animation-delay:0ms]" />
                            <span className="w-1.5 h-1.5 rounded-full bg-neutral-400 animate-bounce [animation-delay:150ms]" />
                            <span className="w-1.5 h-1.5 rounded-full bg-neutral-400 animate-bounce [animation-delay:300ms]" />
                        </div>
                    </div>
                )}
                <div ref={bottomRef} />
            </div>

            <div className="p-3 border-t border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900">
                <div className="flex items-center gap-2 rounded-xl border border-neutral-200 dark:border-neutral-600 bg-neutral-50 dark:bg-neutral-800 px-3 py-1.5">
                    <input
                        type="text"
                        value={input}
                        onChange={e => setInput(e.target.value)}
                        onKeyDown={handleKey}
                        placeholder={t('ai.type_a_message')}
                        className="flex-1 bg-transparent text-sm outline-none text-neutral-900 dark:text-neutral-100 placeholder-neutral-400"
                    />
                    <button
                        onClick={send}
                        disabled={loading || !input.trim()}
                        className="shrink-0 w-7 h-7 rounded-lg bg-brand-600 hover:bg-brand-700 disabled:opacity-40 disabled:cursor-not-allowed flex items-center justify-center transition"
                    >
                        <Send className="h-3.5 w-3.5 text-white" />
                    </button>
                </div>
                <p className="mt-1 px-1 text-[11px] text-neutral-400">
                    Generated answers use 1 credit · greetings are free · {remainingCredits} remaining
                </p>
            </div>
        </div>
    );
}
