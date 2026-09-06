import { useEffect, useRef, useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import { Dialog, DialogPanel, DialogTitle, Menu, MenuButton, MenuItem, MenuItems } from '@headlessui/react';
import { ArrowLeft, Check, ChevronDown, ExternalLink, MessageSquare, MoreHorizontal, RefreshCw, Search, Settings2, ShieldCheck, Sparkles, X } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import axios from 'axios';
import ClientLayout from '@/Layouts/ClientLayout';
import SocialWorkspaceTabs from '@/Components/Social/SocialWorkspaceTabs';
import CommentPlatformAvailability from '@/Components/Social/CommentPlatformAvailability';
import { SocialBrandIcon } from '@/Components/BrandIcons';

const base = '/app/social/automation/comments';
const control = 'rounded-lg border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand-500 disabled:opacity-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200';
const primary = 'inline-flex items-center justify-center gap-2 rounded-lg bg-brand-500 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-600 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500 disabled:opacity-50 disabled:cursor-not-allowed';
const labels = { needs_attention: 'Needs attention', all: 'All', ai_handled: 'AI handled', resolved: 'Resolved', queued: 'Processing', sending: 'Sending', sent: 'Sent', suggested: 'AI suggestion', failed: 'Not sent', delivery_unknown: 'Delivery needs checking', canceled: 'Canceled' };
const reasons = {
    unchecked: 'Check the connection to start receiving comments.', ready: 'Connected for comments',
    reconnect_required: 'Reconnect this account to restore comment access.', permission_required: 'Additional comment permission is required. Reconnect your account.',
    platform_configuration_required: 'Comment setup needs administrator attention.', subscription_unverified: 'The comment subscription could not be verified. Contact support.',
    rate_limited: 'Meta is limiting requests. We will try again shortly.', temporarily_unavailable: 'Meta is temporarily unavailable. Try again shortly.',
    provider_rejected: 'Meta could not complete this action. Check account access and try again.', sync_limit_reached: 'The import limit was reached. Existing comments remain available; contact support to continue.',
    human_review_required: 'AI could not safely answer this comment. Please review it.', choose_public_knowledge_base: 'Select a chatbot with a published, public-safe Knowledge Base in AI reply settings.',
    verify_on_platform: 'Meta may have accepted this reply. Check the original comment before taking further action.', delivery_unknown: 'Meta may have accepted this reply. We will check delivery without sending it again.',
    comment_changed: 'The comment changed before this action could finish.', connection_changed: 'The account connection changed. Reconnect and review this comment.', agent_took_over: 'Automatic reply canceled because an agent took over.',
};
export function safeSocialLink(value) {
    try { const url = new URL(value); return url.protocol === 'https:' && !url.username && !url.password && /(^|\.)(facebook\.com|instagram\.com|fb\.com)$/.test(url.hostname) ? url.href : null; } catch { return null; }
}

function Status({ value, t }) {
    return <span className={`inline-flex shrink-0 items-center rounded px-1.5 py-0.5 text-[11px] font-medium ${value === 'needs_attention' || value === 'failed' || value === 'delivery_unknown' ? 'bg-amber-50 text-amber-800 dark:bg-amber-950 dark:text-amber-200' : 'bg-neutral-100 text-neutral-600 dark:bg-neutral-800 dark:text-neutral-300'}`}>{t(`social.comments_status_${value}`, { defaultValue: labels[value] || 'Needs attention' })}</span>;
}

export default function Comments({ comments, counts, filters, accounts, chatbots, canManage, canReply, workspaceId, commentPlatforms }) {
    const { t } = useTranslation();
    const text = (key, fallback, options = {}) => t(`social.comments_${key}`, { defaultValue: fallback, ...options });
    const selected = filters.selected ? Number(filters.selected) : null;
    const [detail, setDetail] = useState(null);
    const [loading, setLoading] = useState(false);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [notice, setNotice] = useState('');
    const [drafts, setDrafts] = useState({});
    const [settingsOpen, setSettingsOpen] = useState(false);
    const [settingsAccount, setSettingsAccount] = useState(accounts[0]?.id || '');
    const [settingForm, setSettingForm] = useState({ mode: 'off', chatbot_id: '', public_kb_confirmed: false });
    const [confirmation, setConfirmation] = useState(null);
    const [updates, setUpdates] = useState(false);
    const requestVersion = useRef(0);
    const draft = drafts[selected] || '';
    const setDraft = value => setDrafts(current => ({ ...current, [selected]: value }));
    const navigate = changes => router.get(base, { ...filters, cursor: undefined, ...changes }, { preserveState: true, preserveScroll: true, replace: true });
    const reload = () => { router.reload({ only: ['comments', 'counts', 'accounts'] }); setUpdates(false); };
    const describe = reason => text(`reason_${reason}`, reasons[reason] || 'Please review this action and try again.');
    const report = e => setError(Object.values(e.response?.data?.errors || {}).flat()[0] || e.response?.data?.message || text('request_failed', 'Unable to complete this request. Please try again.'));
    const fetchDetail = async (id, initial = false) => {
        const version = ++requestVersion.current;
        if (initial) setLoading(true);
        try {
            const response = await axios.get(`${base}/${id}`);
            if (version === requestVersion.current) setDetail(response.data);
        } catch (e) { if (version === requestVersion.current) report(e); }
        finally { if (version === requestVersion.current) setLoading(false); }
    };
    useEffect(() => {
        requestVersion.current++;
        setDetail(null); setError(''); setNotice('');
        if (!selected) return;
        fetchDetail(selected, true);
        axios.post(`${base}/${selected}/read`).catch(() => {});
        return () => { requestVersion.current++; };
    }, [selected]);
    useEffect(() => {
        const account = accounts.find(item => Number(item.id) === Number(settingsAccount));
        setSettingForm({ mode: account?.settings?.mode || 'off', chatbot_id: account?.settings?.chatbot_id || '', public_kb_confirmed: Boolean(account?.settings?.public_kb_confirmed_at) });
    }, [settingsAccount, accounts]);
    useEffect(() => {
        const channel = window.Echo?.private(`workspace.${workspaceId}`);
        const changed = event => { setUpdates(true); if (Number(event.commentId) === selected) fetchDetail(selected); };
        channel?.listen('.social.comments.changed', changed);
        const interval = window.setInterval(() => { if (!document.hidden && selected) fetchDetail(selected); }, 15000);
        return () => { channel?.stopListening('.social.comments.changed', changed); window.clearInterval(interval); };
    }, [workspaceId, selected]);
    const action = async (path, payload = {}, method = 'post') => {
        setBusy(true); setError('');
        try {
            const response = await axios[method](`${base}/${path}`, payload);
            setNotice(response.data.message || text('action_accepted', 'Action accepted. Updates will appear here.'));
            if (selected) await fetchDetail(selected);
            reload();
            return true;
        } catch (e) { report(e); return false; }
        finally { setBusy(false); }
    };
    const replyKey = useRef(null);
    const send = async () => {
        const fingerprint = `${selected}:${draft}`;
        if (replyKey.current?.fingerprint !== fingerprint) replyKey.current = { fingerprint, key: crypto.randomUUID() };
        if (await action(`${selected}/reply`, { body: draft, idempotency_key: replyKey.current.key })) { setDraft(''); replyKey.current = null; }
    };
    const record = detail?.comment;
    const link = safeSocialLink(record?.post?.permalink);
    const ready = Boolean(record?.account?.active) && detail?.settings?.connection_status === 'ready';
    const pending = detail?.operations?.some(op => ['queued', 'sending', 'delivery_unknown'].includes(op.status) && op.kind !== 'suggest');
    const currentAccount = accounts.find(account => Number(account.id) === Number(settingsAccount));

    return <ClientLayout title={text('title', 'Social Media Automation')}>
        <Head title={text('page_title', 'Comments · Social Media Automation')} />
        <div className="space-y-3">
            <header className="flex items-center justify-between gap-3">
                <div><h1 className="text-2xl font-bold tracking-tight text-neutral-900 dark:text-white">{text('title', 'Social Media Automation')}</h1><p className="mt-1 text-sm text-neutral-500">{text('subtitle', 'Review conversations. Reply confidently. Keep your community connected.')}</p></div>
                {canManage && accounts.length > 0 && <button className={`${control} inline-flex shrink-0 items-center gap-2`} onClick={() => setSettingsOpen(true)}><Settings2 className="h-4 w-4" /><span className="hidden sm:inline">{text('ai_settings', 'AI reply settings')}</span><span className="sm:hidden">{text('settings', 'Settings')}</span></button>}
            </header>
            <SocialWorkspaceTabs active="comments" />
            <CommentPlatformAvailability platforms={commentPlatforms} />
            {error && <div role="alert" className="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800 dark:bg-red-950 dark:text-red-200">{error}</div>}
            {notice && <div role="status" className="rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200">{notice}</div>}
            {accounts.length === 0 ? <div className="flex flex-wrap items-center justify-between gap-4 rounded-xl border border-neutral-200 bg-white p-5 dark:border-neutral-800 dark:bg-neutral-900"><div><h2 className="font-semibold">{text('connect_heading', 'Bring your comments together')}</h2><p className="mt-1 text-sm text-neutral-500">{text('connect_hint', 'Connect a Facebook Page or Instagram professional account to get started.')}</p></div><Link className={primary} href={route('client.social.automation.index')}>{text('connect', 'Connect account')}</Link></div> : <section className="flex h-[calc(100dvh-235px)] min-h-[440px] overflow-hidden rounded-xl border border-neutral-200 bg-white dark:border-neutral-800 dark:bg-neutral-900" aria-label={text('workspace', 'Comments workspace')}>
                <aside className={`${selected ? 'hidden md:flex' : 'flex'} w-full shrink-0 flex-col border-r border-neutral-200 md:w-[340px] lg:w-[390px] dark:border-neutral-800`}>
                    <div className="border-b border-neutral-100 p-3 dark:border-neutral-800">
                        <div className="mb-3 flex items-center justify-between"><h2 className="font-semibold">{text('heading', 'Comments')} <span className="ml-1 text-xs font-normal text-neutral-400">{counts.all}</span></h2><button onClick={reload} className="rounded p-1.5 text-neutral-500 focus-visible:ring-2 focus-visible:ring-brand-500" aria-label={text('refresh', 'Refresh comments')}><RefreshCw className="h-4 w-4" /></button></div>
                        <form className="relative" onSubmit={e => { e.preventDefault(); navigate({ search: e.currentTarget.elements.search.value || undefined }); }}><Search className="absolute left-2.5 top-2.5 h-4 w-4 text-neutral-400" /><input key={filters.search || ''} name="search" aria-label={text('search', 'Search comments')} defaultValue={filters.search || ''} placeholder={text('search', 'Search comments')} className={`${control} w-full bg-neutral-50 py-2 pl-8 dark:bg-neutral-800`} /></form>
                        <div className="mt-2 flex gap-2"><select aria-label={text('platform', 'Platform')} value={filters.network || ''} onChange={e => navigate({ network: e.target.value || undefined })} className={`${control} min-w-0 flex-1 py-1.5`}><option value="">{text('all_platforms', 'All platforms')}</option><option value="facebook">Facebook</option><option value="instagram">Instagram</option></select><select aria-label={text('account', 'Account')} value={filters.account_id || ''} onChange={e => navigate({ account_id: e.target.value || undefined })} className={`${control} min-w-0 flex-1 py-1.5`}><option value="">{text('all_accounts', 'All accounts')}</option>{accounts.map(account => <option key={account.id} value={account.id}>{account.name}</option>)}</select></div>
                        <div className="mt-3 flex flex-wrap gap-1" aria-label={text('filter_status', 'Filter comments by status')}>{['needs_attention', 'all', 'ai_handled', 'resolved'].map(tab => <button key={tab} aria-pressed={filters.tab === tab} onClick={() => navigate({ tab })} className={`rounded-md px-2 py-1.5 text-xs font-medium focus-visible:ring-2 focus-visible:ring-brand-500 ${filters.tab === tab ? 'bg-brand-50 text-brand-700 dark:bg-brand-950 dark:text-brand-300' : 'text-neutral-500 hover:bg-neutral-100 dark:hover:bg-neutral-800'}`}>{text(`tab_${tab}`, labels[tab])} <span className="ml-1 opacity-70">{counts[tab]}</span></button>)}</div>
                    </div>
                    {updates && <button className="bg-brand-50 px-3 py-2 text-xs text-brand-700 dark:bg-brand-950 dark:text-brand-200" onClick={reload}>{text('new_updates', 'New updates available — refresh list')}</button>}
                    <div className="min-h-0 flex-1 overflow-y-auto" aria-label={text('comment_list', 'Comment list')}>
                        {comments.data.map(comment => <button key={comment.id} onClick={() => navigate({ selected: comment.id, cursor: filters.cursor })} aria-current={selected === comment.id ? 'true' : undefined} className={`block w-full border-b border-neutral-100 px-3 py-3 text-left focus-visible:outline focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-brand-500 dark:border-neutral-800 ${selected === comment.id ? 'bg-brand-50/60 dark:bg-brand-950/30' : 'hover:bg-neutral-50 dark:hover:bg-neutral-800/60'}`}>
                            <div className="flex items-center gap-2"><span className="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-neutral-100 text-xs font-semibold text-neutral-600 dark:bg-neutral-800">{comment.author_name?.[0] || '?'}</span><span className={`min-w-0 flex-1 truncate text-sm ${comment.unread ? 'font-semibold' : ''}`}>{comment.author_name}</span>{comment.unread && <span className="h-1.5 w-1.5 rounded-full bg-brand-500" aria-label={text('unread', 'Unread')} />}<time className="text-[11px] text-neutral-400">{new Date(comment.posted_at || comment.created_at).toLocaleDateString(undefined, { month: 'short', day: 'numeric' })}</time></div>
                            <p className="mt-1.5 line-clamp-2 text-sm leading-5 text-neutral-700 dark:text-neutral-300">{comment.deleted ? text('deleted', 'Comment removed') : comment.body || text('attachment', 'Comment contains no text')}</p>
                            <div className="mt-2 flex items-center justify-between gap-2"><span className="flex min-w-0 items-center gap-1.5 text-[11px] text-neutral-500"><SocialBrandIcon network={comment.account?.network} className="h-3.5 w-3.5 shrink-0" /><span className="truncate">{comment.account?.name}</span></span><Status value={comment.status} t={t} /></div>
                        </button>)}
                        {!comments.data.length && <div className="px-5 py-8 text-center"><MessageSquare className="mx-auto mb-3 h-6 w-6 text-neutral-300" /><h3 className="text-sm font-semibold">{text('empty', 'No comments in this view')}</h3><p className="mt-1 text-xs leading-5 text-neutral-500">{text('empty_hint', 'Try another filter, or check your account connection in AI reply settings.')}</p>{(filters.search || filters.network || filters.account_id) && <button onClick={() => navigate({ search: undefined, network: undefined, account_id: undefined })} className="mt-3 text-sm text-brand-600">{text('clear_filters', 'Clear filters')}</button>}</div>}
                    </div>
                    {(comments.prev_page_url || comments.next_page_url) && <div className="flex justify-between border-t border-neutral-100 p-2 dark:border-neutral-800">{['prev', 'next'].map(dir => <button key={dir} disabled={!comments[`${dir}_page_url`]} className={control} onClick={() => router.get(comments[`${dir}_page_url`], {}, { preserveState: true })}>{text(dir, dir === 'prev' ? 'Previous' : 'Next')}</button>)}</div>}
                </aside>
                <main className={`${selected ? 'flex' : 'hidden md:flex'} min-w-0 flex-1 flex-col`}>
                    {!selected ? <div className="m-auto max-w-xs px-5 text-center"><MessageSquare className="mx-auto mb-3 h-7 w-7 text-neutral-300" /><h2 className="text-sm font-semibold">{text('select', 'Select a comment')}</h2><p className="mt-1 text-xs leading-5 text-neutral-500">{text('select_hint', 'Read the conversation and respond without leaving your workspace.')}</p></div> : loading || !record ? <div role="status" className="m-auto text-sm text-neutral-500">{text('loading', 'Loading conversation…')}</div> : <>
                        <div className="flex items-center gap-2 border-b border-neutral-100 px-4 py-3 dark:border-neutral-800"><button className="p-1 md:hidden" onClick={() => navigate({ selected: undefined })} aria-label={text('back', 'Back to comments')}><ArrowLeft className="h-5 w-5" /></button><SocialBrandIcon network={record.account.network} className="h-5 w-5" /><div className="min-w-0 flex-1"><h2 className="truncate text-sm font-semibold">{record.account.name}</h2><p className="text-[11px] text-neutral-500">{text('public_conversation', 'Public conversation')}</p></div>{canReply && <button className={`${control} inline-flex items-center gap-1.5 py-1.5`} disabled={busy} onClick={() => action(selected, { status: record.status === 'resolved' ? 'needs_attention' : 'resolved' }, 'patch')}><Check className="h-3.5 w-3.5" />{record.status === 'resolved' ? text('reopen', 'Reopen') : text('resolve', 'Resolve')}</button>}<Menu as="div" className="relative"><MenuButton className={`${control} px-2 py-1.5`} aria-label={text('more_actions', 'More comment actions')}><MoreHorizontal className="h-4 w-4" /></MenuButton><MenuItems anchor="bottom end" className="z-40 w-56 rounded-lg border border-neutral-200 bg-white p-1 text-sm shadow-lg dark:border-neutral-700 dark:bg-neutral-900">{link && <MenuItem><a href={link} target="_blank" rel="noopener noreferrer" className="flex items-center gap-2 rounded p-2 data-[focus]:bg-neutral-100 dark:data-[focus]:bg-neutral-800"><ExternalLink className="h-4 w-4" />{text('open_platform', 'View on platform')}</a></MenuItem>}{canReply && <MenuItem><button className="w-full rounded p-2 text-left data-[focus]:bg-neutral-100 dark:data-[focus]:bg-neutral-800" onClick={() => action(selected, { ai_paused: !record.ai_paused }, 'patch')}>{record.ai_paused ? text('resume_ai', 'Resume AI for future comments') : text('pause_ai', 'Pause AI for this thread')}</button></MenuItem>}{canReply && !record.deleted && ['hide', 'delete'].filter(cap => detail.settings.capabilities?.[cap]).map(cap => <MenuItem key={cap}><button className={`w-full rounded p-2 text-left data-[focus]:bg-neutral-100 dark:data-[focus]:bg-neutral-800 ${cap === 'delete' ? 'border-t border-neutral-100 text-red-600 dark:border-neutral-700' : ''}`} onClick={() => setConfirmation(cap === 'hide' && record.hidden ? 'unhide' : cap)}>{text(cap === 'hide' && record.hidden ? 'unhide' : cap, cap === 'delete' ? 'Delete comment on platform' : record.hidden ? 'Unhide comment' : 'Hide comment')}</button></MenuItem>)}</MenuItems></Menu></div>
                        {!ready && <div role="status" className="bg-amber-50 px-4 py-2 text-xs text-amber-800 dark:bg-amber-950 dark:text-amber-200">{describe(detail.settings.connection_status)}</div>}
                        {record.post?.body && <details className="border-b border-neutral-100 px-4 py-2 dark:border-neutral-800"><summary className="cursor-pointer text-xs text-neutral-500">{text('original_post', 'Original post')} · {record.post.body.slice(0, 65)}</summary><p className="mt-2 max-h-32 overflow-auto whitespace-pre-wrap text-sm text-neutral-600 dark:text-neutral-300">{record.post.body}</p></details>}
                        <div className="min-h-0 flex-1 space-y-4 overflow-y-auto p-4">
                            {[record, ...(detail.replies?.data || [])].map(item => <article key={item.id} className={item.is_own ? 'ml-6 border-l-2 border-brand-200 pl-3 dark:border-brand-900' : ''}><div className="flex flex-wrap items-center gap-2 text-xs"><span className="font-semibold">{item.author_name}</span>{item.is_own && <span className="rounded bg-neutral-100 px-1.5 text-neutral-500 dark:bg-neutral-800">{item.reply_source === 'ai' ? 'AI' : text('agent', 'Agent')}</span>}<time className="text-[11px] text-neutral-400">{new Date(item.posted_at || item.created_at).toLocaleString()}</time></div><p className="mt-1 whitespace-pre-wrap break-words text-sm leading-6 text-neutral-700 dark:text-neutral-300">{item.deleted ? text('deleted', 'Comment removed') : item.body}</p></article>)}
                            {detail.replies?.next_page_url && <button className={control} onClick={async () => { try { const next = await axios.get(detail.replies.next_page_url); setDetail(current => ({ ...current, replies: { ...next.data.replies, data: [...current.replies.data, ...next.data.replies.data] } })); } catch (e) { report(e); } }}>{text('more_replies', 'Load more replies')}</button>}
                            {detail.operations?.filter(op => !['sent', 'canceled'].includes(op.status)).map(op => <div key={op.id} className="rounded-lg border border-neutral-200 p-3 dark:border-neutral-700">
                                <div className="flex items-center gap-2"><Status value={op.status} t={t} />{op.kind === 'suggest' && <Sparkles className="h-3.5 w-3.5 text-brand-500" />}</div>
                                {op.body && <p className="mt-2 whitespace-pre-wrap text-sm">{op.body}</p>}
                                {op.reason && <p className="mt-2 text-xs text-neutral-500">{describe(op.reason)}</p>}
                                {op.status === 'suggested' && canReply && <div className="mt-2 flex flex-wrap gap-4">
                                    <button onClick={() => setDraft(op.body)} className="text-xs font-medium text-brand-600">{text('use_suggestion', 'Use suggestion')}</button>
                                    {canManage && <button disabled={busy} onClick={() => action(`${selected}/suggestions/${op.id}/review`)} className="text-xs font-medium text-brand-600">{text('review_preview', 'Mark preview reviewed')}</button>}
                                </div>}
                                {op.status === 'failed' && canReply && <button onClick={() => action(`operations/${op.id}/retry`)} className="mt-2 text-xs font-medium text-brand-600">{text('retry_delivery', 'Retry delivery — no extra AI credits')}</button>}
                            </div>)}
                        </div>
                        {canReply && !record.deleted && <div className="border-t border-neutral-200 p-3 dark:border-neutral-800"><div className="mb-2 flex flex-wrap items-center justify-between gap-2"><label htmlFor="public-comment-reply" className="text-xs font-medium text-neutral-600 dark:text-neutral-300">{text('reply_as', 'Public reply as {{name}}', { name: record.account.name })}</label>{record.ai_paused && <span className="text-[11px] text-amber-700 dark:text-amber-300">{text('ai_paused', 'AI paused for this thread')}</span>}</div><textarea id="public-comment-reply" rows={3} maxLength={2000} value={draft} onChange={e => setDraft(e.target.value)} placeholder={text('write_reply', 'Write a helpful public reply…')} className={`${control} w-full resize-none`} /><div className="mt-2 flex flex-wrap items-center justify-between gap-2"><button disabled={busy || !ready || detail.operations?.some(op => op.kind === 'suggest' && op.status === 'queued')} onClick={() => action(`${selected}/suggest`)} className="inline-flex items-center gap-1.5 rounded p-1 text-xs font-medium text-brand-600 disabled:opacity-50 focus-visible:ring-2 focus-visible:ring-brand-500"><Sparkles className="h-4 w-4" />{text('suggest', 'Suggest reply · 1 AI credit')}</button><button className={primary} onClick={send} disabled={busy || !ready || pending || !draft.trim()}>{text('reply_publicly', 'Reply publicly')}</button></div><p className="mt-1 text-[10px] text-neutral-400">{text('credits_hint', 'AI credit applies to successful generation. Your provider or eligible cached answers use no WisperBot credits.')}</p></div>}
                    </>}
                </main>
            </section>}
        </div>
        <Dialog open={settingsOpen} onClose={() => setSettingsOpen(false)} className="relative z-50"><div className="fixed inset-0 bg-neutral-950/40" aria-hidden="true" /><div className="fixed inset-y-0 right-0 flex max-w-full"><DialogPanel className="flex w-screen max-w-md flex-col overflow-y-auto bg-white p-5 shadow-xl dark:bg-neutral-900"><div className="flex items-center justify-between"><DialogTitle className="text-lg font-semibold">{text('ai_settings', 'AI reply settings')}</DialogTitle><button className={control} aria-label={text('close', 'Close')} onClick={() => setSettingsOpen(false)}><X className="h-4 w-4" /></button></div><p className="mt-2 text-sm text-neutral-500">{text('settings_hint', 'Choose how AI helps with public comments for each account. Automatic replies start only after you preview a suggestion.')}</p><label className="mt-5 text-xs font-medium" htmlFor="comment-account">{text('account', 'Account')}</label><select id="comment-account" value={settingsAccount} onChange={e => setSettingsAccount(e.target.value)} className={`${control} mt-1`}>{accounts.map(account => <option key={account.id} value={account.id}>{account.name}</option>)}</select><div className="my-3 rounded-lg border border-neutral-200 p-3 dark:border-neutral-700"><p className="text-xs">{describe(currentAccount?.settings?.connection_status || 'unchecked')}</p><div className="mt-2 flex gap-3"><button disabled={busy} onClick={() => action(`accounts/${settingsAccount}/sync`)} className="text-xs font-semibold text-brand-600">{text('check_sync', 'Check connection & sync')}</button><a className="text-xs text-brand-600" href={currentAccount ? route('client.social.accounts.connect', currentAccount.network) : '#'}>{text('reconnect', 'Reconnect account')}</a></div></div><label htmlFor="comment-chatbot" className="mt-2 text-xs font-medium">{text('chatbot', 'Chatbot and Knowledge Base')}</label><select id="comment-chatbot" className={`${control} mt-1`} value={settingForm.chatbot_id} onChange={e => setSettingForm({ ...settingForm, chatbot_id: e.target.value, mode: 'suggestions' })}><option value="">{text('choose_chatbot', 'Choose a chatbot')}</option>{chatbots.map(bot => <option value={bot.id} key={bot.id}>{bot.name}</option>)}</select><fieldset className="mt-5 space-y-2"><legend className="mb-2 text-xs font-medium">{text('mode', 'Reply mode')}</legend>{[['off', 'Off', 'Agents reply manually.'], ['suggestions', 'Suggestions only', 'AI drafts replies for an agent to review.'], ['automatic', 'Automatic replies', 'AI answers supported questions. Sensitive or uncertain comments wait for an agent.']].map(([mode, label, hint]) => <label key={mode} className={`flex gap-3 rounded-lg border p-3 ${settingForm.mode === mode ? 'border-brand-400 bg-brand-50/40 dark:bg-brand-950/20' : 'border-neutral-200 dark:border-neutral-700'}`}><input type="radio" name="comment-mode" value={mode} checked={settingForm.mode === mode} onChange={() => setSettingForm({ ...settingForm, mode })} className="mt-0.5 text-brand-500 focus:ring-brand-500" /><span><span className="block text-sm font-medium">{text(`mode_${mode}`, label)}</span><span className="mt-1 block text-xs text-neutral-500">{text(`mode_${mode}_hint`, hint)}</span></span></label>)}</fieldset><label className="mt-4 flex items-start gap-2 text-xs leading-5"><input type="checkbox" checked={settingForm.public_kb_confirmed} onChange={e => setSettingForm({ ...settingForm, public_kb_confirmed: e.target.checked })} className="mt-1 rounded text-brand-500" />{text('public_confirmation', 'I confirm this chatbot’s published Knowledge Base contains information appropriate for public replies.')}</label><p className="mt-3 flex gap-2 text-xs text-neutral-500"><ShieldCheck className="h-4 w-4 shrink-0" />{text('safety_hint', 'AI never uses private inbox conversations or order records. Hiding and deleting comments always requires an agent.')}</p>{error && <p role="alert" className="mt-3 text-sm text-red-600">{error}</p>}<button disabled={busy} className={`${primary} mt-5`} onClick={async () => { if (await action(`accounts/${settingsAccount}/settings`, { ...settingForm, chatbot_id: settingForm.chatbot_id || null })) setSettingsOpen(false); }}>{text('save_settings', 'Save settings')}</button></DialogPanel></div></Dialog>
        <Dialog open={Boolean(confirmation)} onClose={() => setConfirmation(null)} className="relative z-[60]"><div className="fixed inset-0 bg-neutral-950/40" aria-hidden="true" /><div className="fixed inset-0 flex items-center justify-center p-4"><DialogPanel className="w-full max-w-sm rounded-xl bg-white p-5 dark:bg-neutral-900"><DialogTitle className="font-semibold">{text('confirm_action', 'Change this comment on the platform?')}</DialogTitle><p className="mt-2 text-sm text-neutral-500">{confirmation === 'delete' ? text('delete_warning', 'This deletes the comment on Facebook or Instagram. This cannot be undone.') : text('hide_warning', 'This changes the comment’s visibility on its original platform.')}</p><div className="mt-5 flex justify-end gap-2"><button className={control} onClick={() => setConfirmation(null)}>{text('cancel', 'Cancel')}</button><button disabled={busy} className={primary} onClick={async () => { await action(`${selected}/moderate`, { action: confirmation, idempotency_key: crypto.randomUUID() }); setConfirmation(null); }}>{text('confirm', 'Confirm')}</button></div></DialogPanel></div></Dialog>
    </ClientLayout>;
}
