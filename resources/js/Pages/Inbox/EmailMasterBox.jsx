import { Head, Link, router, useForm } from '@inertiajs/react';
import InboxLayout from '@/Layouts/InboxLayout';
import { Archive, CheckCircle2, Inbox, Mail, MailOpen, PenLine, Plus, Search, Send, Settings2, X } from 'lucide-react';
import { useMemo, useState } from 'react';
import { formatTimeTz } from '@/Utils/datetime';
import { usePage } from '@inertiajs/react';

const folders = [
    { key: 'inbox', label: 'All mail', icon: Inbox },
    { key: 'unread', label: 'Unread', icon: MailOpen },
    { key: 'sent', label: 'Sent', icon: Send },
    { key: 'resolved', label: 'Resolved', icon: CheckCircle2 },
];

function providerName(provider) {
    if (provider === 'microsoft_365') return 'Microsoft 365';
    if (provider === 'gmail') return 'Gmail';
    return 'IMAP / SMTP';
}

function ComposeModal({ accounts, onClose }) {
    const form = useForm({ channel_account_id: accounts[0]?.id ?? '', to: '', cc: '', bcc: '', subject: '', body: '' });
    const [showCopies, setShowCopies] = useState(false);
    const submit = event => {
        event.preventDefault();
        form.post(route('client.inbox.email.compose'));
    };

    return (
        <div className="fixed inset-0 z-50 flex items-end justify-center bg-black/40 p-0 sm:items-center sm:p-6" onMouseDown={event => event.target === event.currentTarget && onClose()}>
            <form onSubmit={submit} className="w-full max-w-2xl overflow-hidden rounded-t-2xl bg-white shadow-2xl dark:bg-neutral-900 sm:rounded-2xl">
                <div className="flex items-center justify-between border-b border-neutral-200 px-5 py-4 dark:border-neutral-800">
                    <div><h2 className="font-semibold text-neutral-900 dark:text-white">New email</h2><p className="text-xs text-neutral-500">Send from any connected mailbox.</p></div>
                    <button type="button" onClick={onClose} className="rounded-lg p-2 text-neutral-400 hover:bg-neutral-100 dark:hover:bg-neutral-800"><X className="h-4 w-4" /></button>
                </div>
                <div className="space-y-3 p-5">
                    <label className="block text-xs font-medium text-neutral-500">From<select value={form.data.channel_account_id} onChange={e => form.setData('channel_account_id', e.target.value)} className="mt-1 w-full rounded-lg border-neutral-300 text-sm dark:border-neutral-700 dark:bg-neutral-800" required><option value="">Select a mailbox</option>{accounts.map(account => <option key={account.id} value={account.id}>{account.display_name} &lt;{account.email}&gt;</option>)}</select></label>
                    <div className="flex items-end gap-2"><label className="block flex-1 text-xs font-medium text-neutral-500">To<input type="email" value={form.data.to} onChange={e => form.setData('to', e.target.value)} className="mt-1 w-full rounded-lg border-neutral-300 text-sm dark:border-neutral-700 dark:bg-neutral-800" required /></label><button type="button" onClick={() => setShowCopies(value => !value)} className="mb-1 rounded-md px-2 py-2 text-xs font-medium text-brand-600 hover:bg-brand-50">Cc / Bcc</button></div>
                    {showCopies && <div className="grid gap-3 sm:grid-cols-2"><TextField label="Cc" value={form.data.cc} onChange={value => form.setData('cc', value)} placeholder="Multiple emails, comma separated" /><TextField label="Bcc" value={form.data.bcc} onChange={value => form.setData('bcc', value)} placeholder="Multiple emails, comma separated" /></div>}
                    <TextField label="Subject" value={form.data.subject} onChange={value => form.setData('subject', value)} required />
                    <label className="block text-xs font-medium text-neutral-500">Message<textarea value={form.data.body} onChange={e => form.setData('body', e.target.value)} rows={10} className="mt-1 w-full resize-y rounded-lg border-neutral-300 text-sm dark:border-neutral-700 dark:bg-neutral-800" required /></label>
                    {(form.errors.compose || Object.keys(form.errors).length > 0) && <p className="rounded-lg bg-red-50 px-3 py-2 text-xs text-red-700">{form.errors.compose || Object.values(form.errors)[0]}</p>}
                </div>
                <div className="flex justify-end gap-2 border-t border-neutral-200 px-5 py-4 dark:border-neutral-800"><button type="button" onClick={onClose} className="rounded-lg border border-neutral-300 px-4 py-2 text-sm font-semibold">Cancel</button><button disabled={form.processing || accounts.length === 0} className="inline-flex items-center gap-2 rounded-lg bg-brand-600 px-5 py-2 text-sm font-semibold text-white disabled:opacity-50"><Send className="h-4 w-4" />{form.processing ? 'Sending…' : 'Send email'}</button></div>
            </form>
        </div>
    );
}

function TextField({ label, value, onChange, placeholder, required = false }) {
    return <label className="block text-xs font-medium text-neutral-500">{label}<input value={value} onChange={e => onChange(e.target.value)} placeholder={placeholder} className="mt-1 w-full rounded-lg border-neutral-300 text-sm dark:border-neutral-700 dark:bg-neutral-800" required={required} /></label>;
}

export default function EmailMasterBox({ conversations, accounts = [], filters = {}, counts = {} }) {
    const { props } = usePage();
    const timezone = props.timezone || 'Asia/Dhaka';
    const [compose, setCompose] = useState(false);
    const [search, setSearch] = useState('');
    const filtered = useMemo(() => conversations.data.filter(conversation => {
        const haystack = [conversation.contact?.first_name, conversation.contact?.last_name, conversation.contact?.email, conversation.last_message?.payload?.subject, conversation.last_message?.body].filter(Boolean).join(' ').toLowerCase();
        return haystack.includes(search.trim().toLowerCase());
    }), [conversations.data, search]);
    const visit = values => router.get(route('client.inbox.email-inbox'), { ...filters, ...values }, { preserveState: true, replace: true });

    return <InboxLayout><Head title="Email MasterBox" />{compose && <ComposeModal accounts={accounts} onClose={() => setCompose(false)} />}
        <div className="flex flex-1 overflow-hidden bg-white dark:bg-neutral-950">
            <aside className="hidden w-60 shrink-0 flex-col border-r border-neutral-200 bg-neutral-50/60 dark:border-neutral-800 dark:bg-neutral-900 md:flex">
                <div className="border-b border-neutral-200 p-4 dark:border-neutral-800"><div className="flex items-center gap-2"><div className="rounded-lg bg-brand-600 p-2 text-white"><Mail className="h-4 w-4" /></div><div><h1 className="text-sm font-bold text-neutral-900 dark:text-white">Email MasterBox</h1><p className="text-[11px] text-neutral-500">All business email, one place</p></div></div><button onClick={() => setCompose(true)} disabled={accounts.length === 0} className="mt-4 flex w-full items-center justify-center gap-2 rounded-lg bg-brand-600 px-3 py-2.5 text-sm font-semibold text-white disabled:opacity-50"><PenLine className="h-4 w-4" />Compose</button></div>
                <nav className="space-y-1 p-3">{folders.map(({ key, label, icon: Icon }) => <button key={key} onClick={() => visit({ folder: key })} className={`flex w-full items-center gap-3 rounded-lg px-3 py-2 text-sm ${(filters.folder || 'inbox') === key ? 'bg-white font-semibold text-brand-700 shadow-sm dark:bg-neutral-800 dark:text-brand-300' : 'text-neutral-600 hover:bg-white dark:text-neutral-400 dark:hover:bg-neutral-800'}`}><Icon className="h-4 w-4" /><span className="flex-1 text-left">{label}</span>{key === 'unread' && counts.unread > 0 && <span className="rounded-full bg-brand-600 px-1.5 text-[10px] font-bold text-white">{counts.unread}</span>}</button>)}</nav>
                <div className="mt-2 border-t border-neutral-200 p-3 dark:border-neutral-800"><div className="mb-2 flex items-center justify-between px-2"><p className="text-[10px] font-bold uppercase tracking-wider text-neutral-400">Mailboxes</p><Link href={route('client.inbox.email.index')} title="Manage mailboxes" className="text-neutral-400 hover:text-brand-600"><Settings2 className="h-4 w-4" /></Link></div><button onClick={() => visit({ account_id: undefined })} className={`mb-1 w-full rounded-lg px-2 py-2 text-left text-xs ${!filters.account_id ? 'bg-brand-50 font-semibold text-brand-700 dark:bg-brand-950/30' : 'text-neutral-500'}`}>All connected accounts</button>{accounts.map(account => <button key={account.id} onClick={() => visit({ account_id: account.id })} className={`mb-1 flex w-full items-center gap-2 rounded-lg px-2 py-2 text-left ${String(filters.account_id) === String(account.id) ? 'bg-brand-50 text-brand-700 dark:bg-brand-950/30' : 'text-neutral-600 dark:text-neutral-400'}`}><span className="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-white text-[10px] font-bold shadow-sm dark:bg-neutral-800">{providerName(account.provider)[0]}</span><span className="min-w-0"><span className="block truncate text-xs font-medium">{account.display_name}</span><span className="block truncate text-[10px] text-neutral-400">{account.email}</span></span></button>)}<Link href={route('client.inbox.email.index')} className="mt-2 flex items-center gap-2 rounded-lg px-2 py-2 text-xs font-semibold text-brand-600 hover:bg-brand-50"><Plus className="h-3.5 w-3.5" />Add another mailbox</Link></div>
            </aside>
            <section className="flex w-full shrink-0 flex-col border-r border-neutral-200 dark:border-neutral-800 md:w-96">
                <div className="border-b border-neutral-200 p-4 dark:border-neutral-800"><div className="flex items-center justify-between gap-2"><div><h2 className="font-semibold text-neutral-900 dark:text-white">{folders.find(folder => folder.key === (filters.folder || 'inbox'))?.label || 'All mail'}</h2><p className="text-xs text-neutral-400">{conversations.total} conversation{conversations.total === 1 ? '' : 's'}</p></div><button onClick={() => setCompose(true)} className="rounded-lg bg-brand-600 p-2 text-white md:hidden"><PenLine className="h-4 w-4" /></button></div><div className="relative mt-3"><Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" /><input value={search} onChange={e => setSearch(e.target.value)} placeholder="Search sender, subject or message" className="w-full rounded-lg border-0 bg-neutral-100 py-2 pl-9 pr-3 text-sm dark:bg-neutral-800" /></div></div>
                <div className="flex-1 overflow-y-auto">{filtered.length === 0 ? <div className="flex h-full flex-col items-center justify-center px-8 text-center"><Archive className="mb-3 h-10 w-10 text-neutral-300" /><p className="font-semibold text-neutral-500">No email conversations</p><p className="mt-1 text-xs text-neutral-400">Connect a mailbox or compose a new email to get started.</p></div> : filtered.map(conversation => { const message = conversation.last_message || {}; const name = `${conversation.contact?.first_name || ''} ${conversation.contact?.last_name || ''}`.trim() || conversation.contact?.email || 'Unknown sender'; const subject = message.payload?.subject || '(no subject)'; return <Link key={conversation.id} href={route('client.inbox.show', { conversation: conversation.uuid, channel: 'email', account_id: filters.account_id })} className="block border-b border-neutral-100 px-4 py-4 hover:bg-neutral-50 dark:border-neutral-800 dark:hover:bg-neutral-900"><div className="flex items-start gap-3"><div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-brand-100 text-sm font-bold text-brand-700">{name[0]?.toUpperCase()}</div><div className="min-w-0 flex-1"><div className="flex items-center justify-between gap-2"><p className={`${conversation.unread_count > 0 ? 'font-bold text-neutral-900 dark:text-white' : 'font-medium text-neutral-700 dark:text-neutral-300'} truncate text-sm`}>{name}</p><span className="shrink-0 text-[10px] text-neutral-400">{conversation.last_message_at ? formatTimeTz(conversation.last_message_at, timezone) : ''}</span></div><p className={`mt-0.5 truncate text-xs ${conversation.unread_count > 0 ? 'font-semibold text-neutral-700 dark:text-neutral-200' : 'text-neutral-500'}`}>{subject}</p><p className="mt-1 truncate text-xs text-neutral-400">{message.body || 'No preview available'}</p><div className="mt-2 flex items-center gap-2"><span className="rounded-full bg-neutral-100 px-2 py-0.5 text-[10px] text-neutral-500 dark:bg-neutral-800">{conversation.channel_account?.display_name}</span>{conversation.unread_count > 0 && <span className="h-2 w-2 rounded-full bg-brand-600" />}</div></div></div></Link>; })}</div>
            </section>
            <main className="hidden flex-1 items-center justify-center bg-neutral-50 dark:bg-neutral-950 md:flex"><div className="max-w-sm text-center"><div className="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-2xl bg-white shadow-sm dark:bg-neutral-900"><Mail className="h-8 w-8 text-brand-500" /></div><h2 className="font-semibold text-neutral-700 dark:text-neutral-300">Your email workspace</h2><p className="mt-2 text-sm leading-6 text-neutral-400">Choose an email to read and reply, or compose a new message from any connected mailbox.</p></div></main>
        </div>
    </InboxLayout>;
}
