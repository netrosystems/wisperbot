import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { Dialog, DialogPanel, DialogTitle, Menu, MenuButton, MenuItem, MenuItems } from '@headlessui/react';
import ClientLayout from '@/Layouts/ClientLayout';
import AiAnsweringControl from '@/Components/Inbox/AiAnsweringControl';
import { AlertTriangle, Check, ChevronRight, EllipsisVertical, Mail, RefreshCw, Server, Trash2, X } from 'lucide-react';
import { useState } from 'react';

const providers = {
    gmail: { name: 'Gmail / Google Workspace', short: 'G', color: 'bg-red-50 text-red-600' },
    microsoft_365: { name: 'Microsoft 365 / Outlook', short: 'M', color: 'bg-blue-50 text-blue-600' },
    imap_smtp: { name: 'Other email (IMAP / SMTP)', short: '@', color: 'bg-violet-50 text-violet-600' },
};

function AccountCard({ account }) {
    const provider = providers[account.provider] || providers.imap_smtp;
    return <div className="rounded-xl border border-neutral-200 bg-neutral-50 p-3.5 dark:border-neutral-700 dark:bg-neutral-800/60">
        <div className="flex items-start gap-3">
            <div className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-xl font-bold ${provider.color}`}>{provider.short}</div>
            <div className="min-w-0 flex-1">
                <div className="flex flex-wrap items-center gap-2"><p className="truncate text-sm font-semibold text-neutral-900 dark:text-white">{account.display_name}</p><span className={`rounded-full px-2 py-0.5 text-[10px] font-semibold ${account.status === 'active' ? 'bg-emerald-50 text-emerald-700' : 'bg-red-50 text-red-700'}`}>{account.status}</span></div>
                <p className="truncate text-xs text-neutral-500">{account.email} · {provider.name}</p>
                {account.last_sync_error && <p className="mt-1 line-clamp-2 text-[11px] text-red-600">{account.last_sync_error}</p>}
            </div>
            <button onClick={() => router.post(route('client.inbox.email.sync', account.id))} className="rounded-lg p-2 text-neutral-400 hover:bg-white hover:text-brand-600 dark:hover:bg-neutral-900" aria-label="Sync now" title="Sync now"><RefreshCw className="h-4 w-4" /></button>
            <Menu as="div" className="relative"><MenuButton className="rounded-lg p-2 text-neutral-400 hover:bg-white hover:text-neutral-700 dark:hover:bg-neutral-900" aria-label="Mailbox actions"><EllipsisVertical className="h-4 w-4" /></MenuButton><MenuItems anchor="bottom end" className="z-40 mt-1 w-44 rounded-xl border border-neutral-200 bg-white p-1 shadow-lg [--anchor-gap:4px] dark:border-neutral-700 dark:bg-neutral-900"><MenuItem><button onClick={() => confirm('Disconnect this mailbox? Existing conversations will be kept.') && router.delete(route('client.inbox.email.destroy', account.id))} className="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-left text-xs font-medium text-red-600 data-[focus]:bg-red-50 dark:data-[focus]:bg-red-950/30"><Trash2 className="h-3.5 w-3.5" />Disconnect</button></MenuItem></MenuItems></Menu>
        </div>
    </div>;
}

function MailboxForm({ imapExtensionAvailable, onClose }) {
    const form = useForm({ provider: 'imap_smtp', email: '', display_name: '', imap_host: '', imap_port: 993, imap_encryption: 'ssl', smtp_host: '', smtp_port: 587, smtp_encryption: 'tls', username: '', password: '', verify_tls: true });
    const setEmail = value => form.setData(data => ({ ...data, email: value, username: data.username || value }));
    const submit = event => { event.preventDefault(); form.post(route('client.inbox.email.generic.store'), { preserveScroll: true, onSuccess: onClose }); };
    return <form onSubmit={submit} className="space-y-4">
        {!imapExtensionAvailable && <div className="flex gap-2 rounded-lg bg-amber-50 p-3 text-xs text-amber-700"><AlertTriangle className="h-4 w-4 shrink-0" />PHP IMAP is not installed on this server.</div>}
        <div className="grid gap-3 sm:grid-cols-2"><Input label="Mailbox email" value={form.data.email} onChange={setEmail} type="email" /><Input label="Display name" value={form.data.display_name} onChange={value => form.setData('display_name', value)} optional /></div>
        <div className="grid grid-cols-[1fr_90px] gap-3"><Input label="Incoming IMAP server" value={form.data.imap_host} onChange={value => form.setData('imap_host', value)} /><Input label="Port" value={form.data.imap_port} onChange={value => form.setData('imap_port', Number(value))} type="number" /></div>
        <Select label="IMAP security" value={form.data.imap_encryption} onChange={value => form.setData('imap_encryption', value)} />
        <div className="grid grid-cols-[1fr_90px] gap-3"><Input label="Outgoing SMTP server" value={form.data.smtp_host} onChange={value => form.setData('smtp_host', value)} /><Input label="Port" value={form.data.smtp_port} onChange={value => form.setData('smtp_port', Number(value))} type="number" /></div>
        <Select label="SMTP security" value={form.data.smtp_encryption} onChange={value => form.setData('smtp_encryption', value)} />
        <div className="grid gap-3 sm:grid-cols-2"><Input label="Username" value={form.data.username} onChange={value => form.setData('username', value)} /><Input label="Password / app password" value={form.data.password} onChange={value => form.setData('password', value)} type="password" /></div>
        {Object.keys(form.errors).length > 0 && <p className="rounded-lg bg-red-50 px-3 py-2 text-xs text-red-600">{Object.values(form.errors)[0]}</p>}
        <button disabled={form.processing || !imapExtensionAvailable} className="w-full rounded-lg bg-brand-600 px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-50">{form.processing ? 'Testing connection…' : 'Test and add mailbox'}</button>
    </form>;
}

export default function EmailSetup({ accounts = [], chatbots = [], aiAnswering = {}, canManageAiAnswering = false, googleEnabled, microsoftEnabled, imapExtensionAvailable }) {
    const [drawerOpen, setDrawerOpen] = useState(false);
    const { flash = {} } = usePage().props;
    return <ClientLayout title="Email Setup"><Head title="Email Setup" />
        <div className="mb-6 space-y-4">
            <header className="flex flex-wrap items-start justify-between gap-4"><div><h2 className="text-xl font-bold text-neutral-900 dark:text-neutral-100">Email Setup</h2><p className="mt-0.5 text-sm text-neutral-500 dark:text-neutral-400">Connect and manage mailboxes for Email MasterBox.</p></div><Link href={route('client.inbox.email-inbox')} className="inline-flex items-center gap-2 rounded-lg bg-brand-600 px-4 py-2.5 text-sm font-semibold text-white">Open Email MasterBox<ChevronRight className="h-4 w-4" /></Link></header>
            <AiAnsweringControl segment="email" policy={aiAnswering} chatbots={chatbots} canManage={canManageAiAnswering} />
            <section className="rounded-2xl border border-neutral-200 bg-white p-3 shadow-sm dark:border-neutral-700 dark:bg-neutral-900"><p className="mb-3 px-1 text-xs font-semibold uppercase tracking-wide text-neutral-400">Connect an account</p><div className="grid gap-2 sm:grid-cols-3">
                <ConnectButton provider="gmail" disabled={!googleEnabled} onClick={() => window.location.assign(route('client.inbox.email.google.connect'))} />
                <ConnectButton provider="microsoft_365" disabled={!microsoftEnabled} onClick={() => window.location.assign(route('client.inbox.email.microsoft.connect'))} />
                <ConnectButton provider="imap_smtp" onClick={() => setDrawerOpen(true)} />
            </div></section>
        </div>
        {flash.success && <div className="mb-4 flex items-center gap-2 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800"><Check className="h-4 w-4" />{flash.success}</div>}
        {flash.error && <div className="mb-4 flex items-center gap-2 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800"><AlertTriangle className="h-4 w-4" />{flash.error}</div>}
        <section className="rounded-2xl border border-neutral-200 bg-white shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
            <div className="flex items-center gap-3 border-b border-neutral-100 px-5 py-4 dark:border-neutral-800"><div className="rounded-xl bg-brand-50 p-2 text-brand-600"><Mail className="h-4 w-4" /></div><h3 className="text-sm font-semibold text-neutral-900 dark:text-neutral-100">Connected mailboxes</h3><span className="ml-auto rounded-full bg-neutral-100 px-2 py-0.5 text-xs font-medium text-neutral-500 dark:bg-neutral-800">{accounts.length}</span></div>
            <div className="p-5">{accounts.length > 0 ? <div className="grid gap-3 lg:grid-cols-2">{accounts.map(account => <AccountCard key={account.id} account={account} />)}</div> : <div className="rounded-xl border border-dashed border-neutral-300 px-6 py-8 text-center dark:border-neutral-700"><Mail className="mx-auto mb-2 h-6 w-6 text-neutral-300" /><p className="text-sm font-semibold text-neutral-600 dark:text-neutral-300">No mailboxes connected</p></div>}</div>
        </section>
        <Dialog open={drawerOpen} onClose={() => setDrawerOpen(false)} className="fixed inset-0 z-50"><div className="fixed inset-0 bg-black/40" aria-hidden="true" /><DialogPanel aria-label="Connect IMAP mailbox" className="fixed inset-y-0 right-0 flex w-full max-w-lg flex-col bg-white shadow-2xl dark:bg-neutral-900"><div className="flex items-center gap-3 border-b border-neutral-200 px-5 py-4 dark:border-neutral-800"><Server className="h-4 w-4 text-brand-500" /><DialogTitle className="flex-1 font-semibold">Other email (IMAP / SMTP)</DialogTitle><button onClick={() => setDrawerOpen(false)} aria-label="Close" className="rounded-lg p-2 text-neutral-400 hover:bg-neutral-100 dark:hover:bg-neutral-800"><X className="h-4 w-4" /></button></div><div className="flex-1 overflow-y-auto p-5"><MailboxForm imapExtensionAvailable={imapExtensionAvailable} onClose={() => setDrawerOpen(false)} /></div></DialogPanel></Dialog>
    </ClientLayout>;
}

function ConnectButton({ provider, disabled = false, onClick }) { const item = providers[provider]; return <button type="button" disabled={disabled} onClick={onClick} className="flex min-h-11 items-center gap-3 rounded-xl border border-neutral-200 bg-neutral-50 px-4 py-2.5 text-left text-sm font-semibold text-neutral-700 transition hover:border-brand-300 hover:bg-brand-50 disabled:cursor-not-allowed disabled:opacity-45 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200"><span className={`flex h-7 w-7 items-center justify-center rounded-lg font-bold ${item.color}`}>{item.short}</span><span className="truncate">{item.name}</span></button>; }
function Input({ label, value, onChange, type = 'text', optional = false }) { return <label className="block text-xs font-medium text-neutral-600 dark:text-neutral-300">{label}{optional && <span className="ml-1 font-normal text-neutral-400">(optional)</span>}<input required={!optional} type={type} value={value} onChange={event => onChange(event.target.value)} className="mt-1 w-full rounded-lg border-neutral-300 text-sm dark:border-neutral-700 dark:bg-neutral-800" /></label>; }
function Select({ label, value, onChange }) { return <label className="block text-xs font-medium text-neutral-600 dark:text-neutral-300">{label}<select value={value} onChange={event => onChange(event.target.value)} className="mt-1 w-full rounded-lg border-neutral-300 text-sm dark:border-neutral-700 dark:bg-neutral-800"><option value="ssl">SSL</option><option value="tls">STARTTLS</option><option value="none">None (not recommended)</option></select></label>; }
