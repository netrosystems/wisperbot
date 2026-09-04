import { useMemo, useState } from 'react';
import { Dialog, DialogPanel, DialogTitle, Menu, MenuButton, MenuItem, MenuItems } from '@headlessui/react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    CalendarDays, CheckCircle2, ChevronLeft, ChevronRight, Clock, ExternalLink,
    Image as ImageIcon, LayoutList, MoreVertical, Pencil, Plus, RefreshCw, Search, Send,
    Sparkles, Trash2, X, XCircle, Zap,
} from 'lucide-react';
import { useTranslation } from 'react-i18next';
import ClientLayout from '@/Layouts/ClientLayout';
import { SocialBrandIcon } from '@/Components/BrandIcons';
import { browserTz, formatInTz } from '@/Utils/datetime';
import AiPlannerModal from '../Posts/AiPlannerModal';

const NETWORKS = [
    { id: 'facebook', label: 'Facebook' },
    { id: 'instagram', label: 'Instagram' },
    { id: 'linkedin', label: 'LinkedIn' },
    { id: 'youtube', label: 'YouTube' },
    { id: 'tiktok', label: 'TikTok' },
];

const TABS = ['upcoming', 'drafts', 'published', 'failed', 'all'];

const STATUS_META = {
    draft: { icon: Pencil, classes: 'bg-neutral-100 text-neutral-700 dark:bg-neutral-800 dark:text-neutral-300' },
    scheduled: { icon: Clock, classes: 'bg-blue-50 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300' },
    publishing: { icon: Send, classes: 'bg-amber-50 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300' },
    published: { icon: CheckCircle2, classes: 'bg-emerald-50 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300' },
    failed: { icon: XCircle, classes: 'bg-red-50 text-red-700 dark:bg-red-900/30 dark:text-red-300' },
};

const MONTH_KEYS = [
    'social.month_january', 'social.month_february', 'social.month_march', 'social.month_april',
    'social.month_may', 'social.month_june', 'social.month_july', 'social.month_august',
    'social.month_september', 'social.month_october', 'social.month_november', 'social.month_december',
];

const DAY_KEYS = ['social.day_sun', 'social.day_mon', 'social.day_tue', 'social.day_wed', 'social.day_thu', 'social.day_fri', 'social.day_sat'];

function StatusBadge({ status }) {
    const { t } = useTranslation();
    const meta = STATUS_META[status] ?? STATUS_META.draft;
    const Icon = meta.icon;
    return (
        <span className={`inline-flex items-center gap-1 rounded-full px-2 py-1 text-xs font-medium ${meta.classes}`}>
            <Icon className="h-3 w-3" aria-hidden="true" /> {t(`social.status_${status}`, { defaultValue: status })}
        </span>
    );
}

function AccountAvatar({ account, showName = true }) {
    return (
        <span className="inline-flex min-w-0 items-center gap-2">
            <span className="relative shrink-0">
                {account.picture_url ? (
                    <img src={account.picture_url} alt="" className="h-7 w-7 rounded-full object-cover" />
                ) : (
                    <span className="flex h-7 w-7 items-center justify-center rounded-full bg-neutral-100 text-xs font-semibold text-neutral-500 dark:bg-neutral-800">
                        {account.name?.[0]?.toUpperCase() ?? '?'}
                    </span>
                )}
                <span className="absolute -bottom-0.5 -right-0.5 flex h-3.5 w-3.5 items-center justify-center rounded-full bg-white ring-1 ring-white dark:bg-neutral-900 dark:ring-neutral-900">
                    <SocialBrandIcon network={account.network} className="h-3 w-3" />
                </span>
            </span>
            {showName && <span className="truncate text-xs font-medium text-neutral-700 dark:text-neutral-300">{account.name}</span>}
        </span>
    );
}

function isExpired(account) {
    return Boolean(account.token_expires_at && new Date(account.token_expires_at) < new Date());
}

function AccountMenu({ account, onDisconnect }) {
    const { t } = useTranslation();
    return (
        <Menu as="div" className="relative shrink-0">
            <MenuButton
                className="rounded-md p-1.5 text-neutral-400 transition hover:bg-neutral-100 hover:text-neutral-700 focus:outline-none focus:ring-2 focus:ring-brand-500/30 dark:hover:bg-neutral-800 dark:hover:text-neutral-200"
                aria-label={t('social.account_actions', { name: account.name, defaultValue: `Actions for ${account.name}` })}
            >
                <MoreVertical className="h-4 w-4" />
            </MenuButton>
            <MenuItems anchor="bottom end" className="z-50 mt-1 w-48 rounded-lg border border-neutral-200 bg-white p-1 shadow-soft-md focus:outline-none dark:border-neutral-700 dark:bg-neutral-900">
                <MenuItem>
                    <a href={route('client.social.accounts.connect', account.network)} className="flex items-center gap-2 rounded-md px-3 py-2 text-sm text-neutral-700 data-[focus]:bg-neutral-100 dark:text-neutral-300 dark:data-[focus]:bg-neutral-800">
                        <RefreshCw className="h-4 w-4" /> {t('social.reconnect', { defaultValue: 'Reconnect' })}
                    </a>
                </MenuItem>
                <div className="my-1 border-t border-neutral-100 dark:border-neutral-800" />
                <MenuItem>
                    <button onClick={() => onDisconnect(account)} className="flex w-full items-center gap-2 rounded-md px-3 py-2 text-left text-sm text-red-600 data-[focus]:bg-red-50 dark:text-red-400 dark:data-[focus]:bg-red-950/30">
                        <Trash2 className="h-4 w-4" /> {t('social.disconnect')}
                    </button>
                </MenuItem>
            </MenuItems>
        </Menu>
    );
}

function ProviderPicker({ open, onClose }) {
    const { t } = useTranslation();
    return (
        <Dialog open={open} onClose={onClose} className="relative z-50">
            <div className="fixed inset-0 bg-neutral-950/40 backdrop-blur-[2px]" aria-hidden="true" />
            <div className="fixed inset-0 flex items-center justify-center p-4">
                <DialogPanel className="w-full max-w-md rounded-xl border border-neutral-200 bg-white p-5 shadow-soft-md dark:border-neutral-800 dark:bg-neutral-900">
                    <div className="flex items-start justify-between gap-4">
                        <div>
                            <DialogTitle className="text-lg font-semibold text-neutral-900 dark:text-neutral-100">{t('social.connect_account', { defaultValue: 'Connect account' })}</DialogTitle>
                            <p className="mt-1 text-sm text-neutral-500">{t('social.choose_provider', { defaultValue: 'Choose the social platform you want to connect.' })}</p>
                        </div>
                        <button onClick={onClose} aria-label={t('common.close')} className="rounded-md p-1 text-neutral-400 hover:bg-neutral-100 dark:hover:bg-neutral-800">
                            <X className="h-5 w-5" />
                        </button>
                    </div>
                    <div className="mt-5 grid grid-cols-1 gap-2 sm:grid-cols-2">
                        {NETWORKS.map(network => (
                            <a key={network.id} href={route('client.social.accounts.connect', network.id)} className="flex items-center gap-3 rounded-lg border border-neutral-200 px-3 py-3 text-sm font-medium text-neutral-800 transition hover:border-brand-400 hover:bg-brand-50 focus:outline-none focus:ring-2 focus:ring-brand-500/30 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-brand-900/20">
                                <span className="flex h-9 w-9 items-center justify-center rounded-lg bg-neutral-50 dark:bg-neutral-800">
                                    <SocialBrandIcon network={network.id} className="h-5 w-5" />
                                </span>
                                {network.label}
                            </a>
                        ))}
                    </div>
                </DialogPanel>
            </div>
        </Dialog>
    );
}

function ConnectedAccounts({ accounts, onConnect }) {
    const { t } = useTranslation();
    const [expanded, setExpanded] = useState(null);
    const grouped = useMemo(() => Object.fromEntries(NETWORKS.map(network => [network.id, accounts.filter(account => account.network === network.id)])), [accounts]);

    const disconnect = account => {
        if (window.confirm(t('social.disconnect_confirm', { name: account.name }))) {
            router.delete(route('client.social.accounts.disconnect', account.id), { preserveScroll: true });
        }
    };

    return (
        <section className="rounded-xl border border-neutral-200 bg-white dark:border-neutral-800 dark:bg-neutral-900" aria-labelledby="connected-accounts-title">
            <div className="flex flex-wrap items-center justify-between gap-3 border-b border-neutral-100 px-4 py-3 dark:border-neutral-800">
                <div>
                    <h2 id="connected-accounts-title" className="text-base font-semibold text-neutral-900 dark:text-neutral-100">{t('social.connected_accounts', { defaultValue: 'Connected accounts' })}</h2>
                    <p className="text-xs text-neutral-500">{accounts.length > 0 ? t('social.connected_accounts_hint', { defaultValue: 'Accounts available for publishing and scheduling.' }) : t('social.connect_first_account', { defaultValue: 'Connect an account to start publishing.' })}</p>
                </div>
                <button onClick={onConnect} className="inline-flex items-center gap-1.5 rounded-lg border border-neutral-300 px-3 py-2 text-sm font-medium text-neutral-700 transition hover:border-brand-400 hover:text-brand-600 focus:outline-none focus:ring-2 focus:ring-brand-500/30 dark:border-neutral-700 dark:text-neutral-200">
                    <Plus className="h-4 w-4" /> {t('social.connect_account', { defaultValue: 'Connect account' })}
                </button>
            </div>

            {accounts.length === 0 ? (
                <div className="flex flex-wrap items-center gap-3 px-4 py-4">
                    <div className="flex -space-x-1.5" aria-hidden="true">
                        {NETWORKS.map(network => <span key={network.id} className="flex h-8 w-8 items-center justify-center rounded-full border-2 border-white bg-neutral-50 dark:border-neutral-900 dark:bg-neutral-800"><SocialBrandIcon network={network.id} className="h-4 w-4" /></span>)}
                    </div>
                    <div className="min-w-0 flex-1">
                        <p className="text-sm font-semibold text-neutral-800 dark:text-neutral-200">{t('social.no_accounts_yet', { defaultValue: 'No accounts connected' })}</p>
                        <p className="text-xs text-neutral-500">{t('social.no_accounts_yet_hint', { defaultValue: 'Connect Facebook, Instagram, LinkedIn, YouTube, or TikTok to get started.' })}</p>
                    </div>
                </div>
            ) : (
                <div className="grid grid-cols-1 divide-y divide-neutral-100 sm:grid-cols-2 sm:divide-y-0 lg:grid-cols-5 dark:divide-neutral-800">
                    {NETWORKS.map((network, index) => {
                        const providerAccounts = grouped[network.id];
                        const shown = expanded === network.id ? providerAccounts : providerAccounts.slice(0, 2);
                        return (
                            <div key={network.id} className={`min-w-0 p-3 ${index > 0 ? 'sm:border-l sm:border-neutral-100 dark:sm:border-neutral-800' : ''}`}>
                                <div className="mb-2 flex items-center gap-2">
                                    <SocialBrandIcon network={network.id} className="h-4 w-4" />
                                    <span className="truncate text-xs font-semibold text-neutral-800 dark:text-neutral-200">{network.label}</span>
                                    <span className="ml-auto rounded-full bg-neutral-100 px-1.5 py-0.5 text-[10px] text-neutral-500 dark:bg-neutral-800">{providerAccounts.length}</span>
                                </div>
                                {providerAccounts.length === 0 ? (
                                    <button onClick={onConnect} className="w-full rounded-md border border-dashed border-neutral-200 px-2 py-2 text-xs text-neutral-400 hover:border-brand-300 hover:text-brand-600 dark:border-neutral-700">{t('social.not_connected', { defaultValue: 'Not connected' })}</button>
                                ) : (
                                    <div className="space-y-1">
                                        {shown.map(account => (
                                            <div key={account.id} className="flex min-w-0 items-center gap-1 rounded-md px-1 py-1 hover:bg-neutral-50 dark:hover:bg-neutral-800/60">
                                                <div className="min-w-0 flex-1">
                                                    <AccountAvatar account={account} />
                                                    <p className={`ml-9 text-[10px] ${isExpired(account) ? 'text-amber-600' : account.active ? 'text-emerald-600' : 'text-neutral-400'}`}>
                                                        {isExpired(account) ? t('social.token_expired') : account.active ? t('common.active') : t('social.inactive', { defaultValue: 'Inactive' })}
                                                    </p>
                                                </div>
                                                <AccountMenu account={account} onDisconnect={disconnect} />
                                            </div>
                                        ))}
                                        {providerAccounts.length > 2 && (
                                            <button onClick={() => setExpanded(expanded === network.id ? null : network.id)} className="px-1 text-xs font-medium text-brand-600 hover:underline">
                                                {expanded === network.id ? t('social.show_less', { defaultValue: 'Show less' }) : t('social.more_accounts', { count: providerAccounts.length - 2, defaultValue: `+${providerAccounts.length - 2} more` })}
                                            </button>
                                        )}
                                    </div>
                                )}
                            </div>
                        );
                    })}
                </div>
            )}
        </section>
    );
}

function postLifecycle(post, accountMap) {
    if (post.remote_lifecycle) return post.remote_lifecycle;
    const results = Object.entries(post.publish_results ?? {}).filter(([, result]) => result?.status === 'published' && result?.post_id);
    const networks = results.map(([id]) => accountMap[id]?.network).filter(Boolean);
    const remote = post.status === 'published' && results.length > 0;
    if (remote) {
        return {
            has_remote_posts: true,
            can_update: networks.length > 0 && networks.every(network => network === 'facebook'),
            can_delete: networks.length > 0 && networks.every(network => ['facebook', 'instagram'].includes(network)),
            can_remove_local: false,
        };
    }
    const mutable = ['draft', 'scheduled', 'failed'].includes(post.status);
    return { has_remote_posts: false, can_update: mutable, can_delete: mutable, can_remove_local: false };
}

function PostActions({ post, accountMap }) {
    const { t } = useTranslation();
    const lifecycle = postLifecycle(post, accountMap);
    const canPublish = ['draft', 'scheduled', 'failed'].includes(post.status);
    const confirmPost = (message, url) => {
        if (window.confirm(message)) router.post(url, {}, { preserveScroll: true });
    };
    const deletePost = () => {
        const message = lifecycle.has_remote_posts
            ? t('social.confirm_delete_remote_post')
            : t('social.confirm_delete_post');
        if (window.confirm(message)) router.delete(route('client.social.posts.destroy', post.id), { preserveScroll: true });
    };
    const removeLocal = () => {
        if (window.confirm(t('social.confirm_remove_local'))) router.delete(route('client.social.posts.remove-local', post.id), { preserveScroll: true });
    };

    return (
        <div className="flex items-center justify-end gap-1">
            {lifecycle.can_update && (
                <Link href={route('client.social.posts.edit', post.id)} aria-label={t('social.edit_post')} className="rounded-md p-2 text-neutral-500 hover:bg-neutral-100 hover:text-brand-600 focus:outline-none focus:ring-2 focus:ring-brand-500/30 dark:hover:bg-neutral-800">
                    <Pencil className="h-4 w-4" />
                </Link>
            )}
            <Menu as="div" className="relative">
                <MenuButton aria-label={t('social.post_actions')} className="rounded-md p-2 text-neutral-500 hover:bg-neutral-100 hover:text-neutral-800 focus:outline-none focus:ring-2 focus:ring-brand-500/30 dark:hover:bg-neutral-800 dark:hover:text-neutral-200">
                    <MoreVertical className="h-4 w-4" />
                </MenuButton>
                <MenuItems anchor="bottom end" className="z-50 mt-1 w-52 rounded-lg border border-neutral-200 bg-white p-1 shadow-soft-md focus:outline-none dark:border-neutral-700 dark:bg-neutral-900">
                    {post.post_url && <MenuItem><a href={post.post_url} target="_blank" rel="noopener noreferrer" className="flex items-center gap-2 rounded-md px-3 py-2 text-sm text-neutral-700 data-[focus]:bg-neutral-100 dark:text-neutral-300 dark:data-[focus]:bg-neutral-800"><ExternalLink className="h-4 w-4" />{t('social.view_on_platform')}</a></MenuItem>}
                    {canPublish && <MenuItem><button onClick={() => confirmPost(t('social.confirm_publish_now'), route('client.social.posts.publish-now', post.id))} className="flex w-full items-center gap-2 rounded-md px-3 py-2 text-sm text-neutral-700 data-[focus]:bg-neutral-100 dark:text-neutral-300 dark:data-[focus]:bg-neutral-800"><Zap className="h-4 w-4" />{t('social.publish_now')}</button></MenuItem>}
                    {post.status === 'scheduled' && <MenuItem><button onClick={() => confirmPost(t('social.confirm_cancel_schedule'), route('client.social.posts.cancel', post.id))} className="flex w-full items-center gap-2 rounded-md px-3 py-2 text-sm text-neutral-700 data-[focus]:bg-neutral-100 dark:text-neutral-300 dark:data-[focus]:bg-neutral-800"><X className="h-4 w-4" />{t('social.cancel_schedule')}</button></MenuItem>}
                    {(lifecycle.can_delete || lifecycle.can_remove_local) && <div className="my-1 border-t border-neutral-100 dark:border-neutral-800" />}
                    {lifecycle.can_delete && <MenuItem><button onClick={deletePost} className="flex w-full items-center gap-2 rounded-md px-3 py-2 text-sm text-red-600 data-[focus]:bg-red-50 dark:text-red-400 dark:data-[focus]:bg-red-950/30"><Trash2 className="h-4 w-4" />{t('common.delete')}</button></MenuItem>}
                    {lifecycle.can_remove_local && <MenuItem><button onClick={removeLocal} className="flex w-full items-center gap-2 rounded-md px-3 py-2 text-sm text-red-600 data-[focus]:bg-red-50 dark:text-red-400 dark:data-[focus]:bg-red-950/30"><Trash2 className="h-4 w-4" />{t('social.remove_from_wisperbot')}</button></MenuItem>}
                </MenuItems>
            </Menu>
        </div>
    );
}

function PostDetails({ post, accountMap, timezone, onClose }) {
    const { t } = useTranslation();
    return (
        <Dialog open={Boolean(post)} onClose={onClose} className="relative z-50">
            <div className="fixed inset-0 bg-neutral-950/40 backdrop-blur-[2px]" aria-hidden="true" />
            <div className="fixed inset-0 flex items-center justify-center p-4">
                <DialogPanel className="max-h-[85vh] w-full max-w-lg overflow-y-auto rounded-xl border border-neutral-200 bg-white p-5 shadow-soft-md dark:border-neutral-800 dark:bg-neutral-900">
                    {post && <>
                        <div className="flex items-start justify-between gap-4">
                            <div><StatusBadge status={post.status} /><DialogTitle className="mt-3 text-lg font-semibold text-neutral-900 dark:text-neutral-100">{post.title || t('social.post_fallback')}</DialogTitle></div>
                            <button onClick={onClose} aria-label={t('common.close')} className="rounded-md p-1 text-neutral-400 hover:bg-neutral-100 dark:hover:bg-neutral-800"><X className="h-5 w-5" /></button>
                        </div>
                        <p className="mt-4 whitespace-pre-wrap text-sm leading-6 text-neutral-700 dark:text-neutral-300">{post.body}</p>
                        {(post.remote_lifecycle?.update_reason || post.remote_lifecycle?.reason || post.remote_lifecycle?.local_remove_reason) && (
                            <p className="mt-4 rounded-lg bg-amber-50 px-3 py-2 text-xs leading-5 text-amber-800 dark:bg-amber-900/20 dark:text-amber-300">
                                {post.remote_lifecycle.update_reason || post.remote_lifecycle.reason || post.remote_lifecycle.local_remove_reason}
                            </p>
                        )}
                        {(post.media_urls ?? []).length > 0 && <div className="mt-4 grid grid-cols-2 gap-2">{post.media_urls.filter(Boolean).map(url => <img key={url} src={url} alt="" className="max-h-52 w-full rounded-lg object-cover" />)}</div>}
                        {post.publish_results && Object.keys(post.publish_results).length > 0 && (
                            <div className="mt-5">
                                <p className="mb-2 text-xs font-semibold uppercase tracking-wider text-neutral-500">{t('social.publish_results')}</p>
                                <div className="space-y-1.5">
                                    {Object.entries(post.publish_results).map(([accountId, result]) => (
                                        <div key={accountId} className="flex items-center justify-between rounded-lg bg-neutral-50 px-3 py-2 text-xs dark:bg-neutral-800">
                                            <span className="text-neutral-600 dark:text-neutral-300">{accountMap[accountId]?.name ?? t('social.account_number', { id: accountId })}</span>
                                            <span className={result.status === 'published' ? 'font-medium text-emerald-600' : 'font-medium text-red-600'}>
                                                {result.status === 'published' ? t('social.result_published') : t('social.result_failed')}
                                            </span>
                                        </div>
                                    ))}
                                </div>
                            </div>
                        )}
                        <div className="mt-5 flex flex-wrap items-center gap-2 border-t border-neutral-100 pt-4 dark:border-neutral-800">
                            {(post.target_accounts ?? []).map(id => accountMap[id] ? <span key={id} className="rounded-full border border-neutral-200 px-2 py-1 dark:border-neutral-700"><AccountAvatar account={accountMap[id]} /></span> : null)}
                            {(post.published_at || post.scheduled_at) && <span className="ml-auto text-xs text-neutral-500">{formatInTz(post.published_at || post.scheduled_at, post.timezone || timezone)}</span>}
                        </div>
                        <div className="mt-4 flex items-center justify-between gap-3">
                            {post.post_url ? <a href={post.post_url} target="_blank" rel="noopener noreferrer" className="inline-flex items-center gap-1.5 text-sm font-medium text-brand-600 hover:text-brand-700"><ExternalLink className="h-4 w-4" />{t('social.view_on_platform')}</a> : <span />}
                            <PostActions post={post} accountMap={accountMap} />
                        </div>
                    </>}
                </DialogPanel>
            </div>
        </Dialog>
    );
}

function PostList({ posts, accountMap, timezone, onView }) {
    const { t } = useTranslation();
    return (
        <>
            <div className="hidden overflow-visible md:block">
                <table className="w-full table-fixed">
                    <thead><tr className="border-b border-neutral-100 text-left text-xs font-semibold uppercase tracking-wider text-neutral-500 dark:border-neutral-800"><th className="w-[42%] px-4 py-3">{t('social.post')}</th><th className="w-[20%] px-4 py-3">{t('social.channels')}</th><th className="w-[18%] px-4 py-3">{t('social.date')}</th><th className="w-[12%] px-4 py-3">{t('social.status')}</th><th className="w-[8%] px-4 py-3"><span className="sr-only">{t('social.actions')}</span></th></tr></thead>
                    <tbody className="divide-y divide-neutral-100 dark:divide-neutral-800">
                        {posts.data.map(post => (
                            <tr key={post.id} className="hover:bg-neutral-50/70 dark:hover:bg-neutral-800/40">
                                <td className="px-4 py-3"><button onClick={() => onView(post)} className="flex w-full min-w-0 items-center gap-3 text-left focus:outline-none focus:ring-2 focus:ring-brand-500/30">
                                    {(post.media_urls ?? []).filter(Boolean)[0] ? <img src={post.media_urls.filter(Boolean)[0]} alt="" className="h-11 w-11 shrink-0 rounded-lg object-cover" /> : <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg bg-neutral-100 text-neutral-400 dark:bg-neutral-800"><ImageIcon className="h-4 w-4" /></span>}
                                    <span className="min-w-0"><span className="block truncate text-sm font-semibold text-neutral-900 dark:text-neutral-100">{post.title || t('social.post_fallback')}</span><span className="mt-0.5 block truncate text-xs text-neutral-500">{post.body}</span></span>
                                </button></td>
                                <td className="px-4 py-3"><div className="flex -space-x-1">{(post.target_accounts ?? []).slice(0, 4).map(id => accountMap[id] ? <span key={id} title={accountMap[id].name} className="rounded-full bg-white ring-2 ring-white dark:bg-neutral-900 dark:ring-neutral-900"><AccountAvatar account={accountMap[id]} showName={false} /></span> : null)}{(post.target_accounts ?? []).length > 4 && <span className="ml-2 text-xs text-neutral-500">+{post.target_accounts.length - 4}</span>}</div></td>
                                <td className="px-4 py-3 text-xs text-neutral-500">{post.published_at || post.scheduled_at ? formatInTz(post.published_at || post.scheduled_at, post.timezone || timezone) : t('social.no_date')}</td>
                                <td className="px-4 py-3"><StatusBadge status={post.status} /></td>
                                <td className="px-4 py-3"><PostActions post={post} accountMap={accountMap} /></td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
            <div className="grid gap-3 p-3 md:hidden">{posts.data.map(post => <article key={post.id} className="rounded-lg border border-neutral-200 bg-white p-3 dark:border-neutral-800 dark:bg-neutral-900"><div className="flex items-start justify-between gap-3"><button onClick={() => onView(post)} className="min-w-0 text-left"><p className="truncate text-sm font-semibold text-neutral-900 dark:text-neutral-100">{post.title || t('social.post_fallback')}</p><p className="mt-1 line-clamp-2 text-xs text-neutral-500">{post.body}</p></button><PostActions post={post} accountMap={accountMap} /></div><div className="mt-3 flex items-center justify-between gap-2"><StatusBadge status={post.status} /><span className="text-xs text-neutral-500">{post.published_at || post.scheduled_at ? formatInTz(post.published_at || post.scheduled_at, post.timezone || timezone) : t('social.no_date')}</span></div></article>)}</div>
        </>
    );
}

function calendarGrid(year, monthIndex) {
    const first = new Date(year, monthIndex, 1).getDay();
    const count = new Date(year, monthIndex + 1, 0).getDate();
    const cells = Array(first).fill(null);
    for (let day = 1; day <= count; day += 1) cells.push(day);
    while (cells.length % 7) cells.push(null);
    return cells;
}

function CalendarView({ posts, month, timezone, onView, onMonth }) {
    const { t } = useTranslation();
    const [year, monthNumber] = month.split('-').map(Number);
    const monthIndex = monthNumber - 1;
    const byDay = posts.reduce((result, post) => {
        const day = Number(new Intl.DateTimeFormat('en-US', { timeZone: timezone, day: 'numeric' }).format(new Date(post.scheduled_at)));
        (result[day] ??= []).push(post);
        return result;
    }, {});
    const move = delta => {
        const date = new Date(year, monthIndex + delta, 1);
        onMonth(`${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}`);
    };
    return (
        <div>
            <div className="mb-3 flex items-center gap-2"><button onClick={() => move(-1)} aria-label={t('social.previous_month')} className="rounded-md p-2 hover:bg-neutral-100 dark:hover:bg-neutral-800"><ChevronLeft className="h-4 w-4" /></button><p className="min-w-40 text-center text-sm font-semibold text-neutral-900 dark:text-neutral-100">{t(MONTH_KEYS[monthIndex])} {year}</p><button onClick={() => move(1)} aria-label={t('social.next_month')} className="rounded-md p-2 hover:bg-neutral-100 dark:hover:bg-neutral-800"><ChevronRight className="h-4 w-4" /></button></div>
            <div className="overflow-x-auto rounded-xl border border-neutral-200 bg-white dark:border-neutral-800 dark:bg-neutral-900"><div className="min-w-[720px]"><div className="grid grid-cols-7 border-b border-neutral-200 dark:border-neutral-800">{DAY_KEYS.map(key => <div key={key} className="px-2 py-2 text-center text-xs font-semibold uppercase text-neutral-500">{t(key)}</div>)}</div><div className="grid grid-cols-7">{calendarGrid(year, monthIndex).map((day, index) => <div key={index} className={`min-h-28 border-b border-r border-neutral-100 p-1.5 dark:border-neutral-800 ${day ? '' : 'bg-neutral-50/60 dark:bg-neutral-800/20'}`}>{day && <><p className="mb-1 text-xs text-neutral-500">{day}</p>{(byDay[day] ?? []).map(post => <button key={post.id} onClick={() => onView(post)} className={`mb-1 block w-full truncate rounded px-2 py-1 text-left text-xs font-medium ${STATUS_META[post.status]?.classes ?? STATUS_META.draft.classes}`}>{post.title || t('social.post_fallback')}</button>)}</>}</div>)}</div></div></div>
        </div>
    );
}

export default function SocialAutomation({ accounts = [], activeAccounts = [], posts, calendarPosts = [], tabCounts = {}, filters = {} }) {
    const { t } = useTranslation();
    const { props } = usePage();
    const [providerPicker, setProviderPicker] = useState(false);
    const [plannerOpen, setPlannerOpen] = useState(false);
    const [detailPost, setDetailPost] = useState(null);
    const timezone = props.timezone || browserTz() || 'Asia/Dhaka';
    const accountMap = Object.fromEntries(accounts.map(account => [account.id, account]));

    const navigate = changes => router.get(route('client.social.automation.index'), { ...filters, ...changes }, { preserveState: true, preserveScroll: true, replace: true });
    const setSearch = event => {
        event.preventDefault();
        navigate({ search: event.currentTarget.elements.search.value || undefined, page: undefined });
    };
    const empty = filters.view === 'calendar' ? calendarPosts.length === 0 : !posts?.data?.length;
    const hasAnyPosts = Number(tabCounts.all ?? 0) > 0;
    const pageTitle = t('social.automation_title', { defaultValue: 'Social Media Automation' });

    return (
        <ClientLayout title={pageTitle}>
            <Head title={pageTitle} />
            <div className="space-y-4">
                <header className="flex flex-wrap items-start justify-between gap-4">
                    <div><h1 className="text-2xl font-bold tracking-tight text-neutral-900 dark:text-neutral-100">{pageTitle}</h1><p className="mt-1 text-sm text-neutral-500">{t('social.automation_subtitle', { defaultValue: 'Connect accounts, plan content, and manage scheduled posts in one place.' })}</p></div>
                    <div className="flex items-center gap-2">
                        <button onClick={() => setPlannerOpen(true)} disabled={activeAccounts.length === 0} className="inline-flex items-center gap-1.5 rounded-lg border border-neutral-300 px-3 py-2 text-sm font-medium text-neutral-700 hover:border-brand-400 hover:text-brand-600 disabled:cursor-not-allowed disabled:opacity-50 dark:border-neutral-700 dark:text-neutral-200"><Sparkles className="h-4 w-4" />{t('social.ai_plan')}</button>
                        {activeAccounts.length > 0 ? <Link href={route('client.social.automation.schedule')} className="inline-flex items-center gap-1.5 rounded-lg bg-brand-600 px-4 py-2 text-sm font-medium text-white shadow-soft hover:bg-brand-700"><Plus className="h-4 w-4" />{t('social.schedule_post', { defaultValue: 'Schedule post' })}</Link> : <button disabled title={t('social.connect_before_scheduling', { defaultValue: 'Connect an active account before scheduling a post.' })} className="inline-flex cursor-not-allowed items-center gap-1.5 rounded-lg bg-brand-600 px-4 py-2 text-sm font-medium text-white opacity-50"><Plus className="h-4 w-4" />{t('social.schedule_post', { defaultValue: 'Schedule post' })}</button>}
                    </div>
                </header>

                {props.flash?.success && <div role="status" className="rounded-lg bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-200">{props.flash.success}</div>}
                {props.flash?.error && <div role="alert" className="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-800 dark:bg-red-900/30 dark:text-red-200">{props.flash.error}</div>}

                <ConnectedAccounts accounts={accounts} onConnect={() => setProviderPicker(true)} />

                <section aria-labelledby="posts-title" className="overflow-visible rounded-xl border border-neutral-200 bg-white dark:border-neutral-800 dark:bg-neutral-900">
                    <div className="flex flex-wrap items-center justify-between gap-3 border-b border-neutral-100 px-4 py-3 dark:border-neutral-800">
                        <div><h2 id="posts-title" className="text-base font-semibold text-neutral-900 dark:text-neutral-100">{t('social.posts_workspace', { defaultValue: 'Posts' })}</h2><p className="text-xs text-neutral-500">{t('social.posts_workspace_hint', { defaultValue: 'Review upcoming work, drafts, published posts, and failures.' })}</p></div>
                        <div className="inline-flex rounded-lg bg-neutral-100 p-1 dark:bg-neutral-800"><button onClick={() => navigate({ view: 'list', page: undefined })} className={`inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-sm font-medium ${filters.view !== 'calendar' ? 'bg-white text-neutral-900 shadow-sm dark:bg-neutral-700 dark:text-neutral-100' : 'text-neutral-500'}`}><LayoutList className="h-4 w-4" />{t('social.list_view', { defaultValue: 'List' })}</button><button onClick={() => navigate({ view: 'calendar', page: undefined })} className={`inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-sm font-medium ${filters.view === 'calendar' ? 'bg-white text-neutral-900 shadow-sm dark:bg-neutral-700 dark:text-neutral-100' : 'text-neutral-500'}`}><CalendarDays className="h-4 w-4" />{t('social.calendar_view', { defaultValue: 'Calendar' })}</button></div>
                    </div>

                    {hasAnyPosts ? <>
                        <div className="flex overflow-x-auto border-b border-neutral-100 px-3 dark:border-neutral-800">{TABS.map(tab => <button key={tab} onClick={() => navigate({ tab, page: undefined })} className={`whitespace-nowrap border-b-2 px-3 py-2.5 text-sm font-medium ${filters.tab === tab ? 'border-brand-500 text-brand-600' : 'border-transparent text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200'}`}>{t(`social.tab_${tab}`, { defaultValue: tab.charAt(0).toUpperCase() + tab.slice(1) })} <span className="ml-1 text-xs text-neutral-400">{tabCounts[tab] ?? 0}</span></button>)}</div>
                        <div className="flex flex-wrap items-center gap-2 border-b border-neutral-100 p-3 dark:border-neutral-800">
                            <form onSubmit={setSearch} className="relative min-w-56 flex-1"><Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" /><input name="search" defaultValue={filters.search ?? ''} placeholder={t('social.search_posts', { defaultValue: 'Search posts' })} className="w-full rounded-lg border border-neutral-200 bg-neutral-50 py-2 pl-9 pr-3 text-sm focus:border-brand-400 focus:ring-brand-500/30 dark:border-neutral-700 dark:bg-neutral-800" /></form>
                            <select value={filters.network ?? ''} onChange={event => navigate({ network: event.target.value || undefined, account_id: undefined, page: undefined })} aria-label={t('social.filter_network', { defaultValue: 'Filter by platform' })} className="rounded-lg border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900"><option value="">{t('social.all_platforms')}</option>{NETWORKS.map(network => <option key={network.id} value={network.id}>{network.label}</option>)}</select>
                            <select value={filters.account_id ?? ''} onChange={event => navigate({ account_id: event.target.value || undefined, network: undefined, page: undefined })} aria-label={t('social.filter_account', { defaultValue: 'Filter by account' })} className="max-w-52 rounded-lg border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900"><option value="">{t('social.all_profiles')}</option>{accounts.map(account => <option key={account.id} value={account.id}>{account.name}</option>)}</select>
                            {(filters.search || filters.network || filters.account_id) && <button onClick={() => navigate({ search: undefined, network: undefined, account_id: undefined, page: undefined })} className="rounded-md p-2 text-neutral-400 hover:bg-neutral-100 hover:text-red-600 dark:hover:bg-neutral-800" aria-label={t('social.clear_filters', { defaultValue: 'Clear filters' })}><X className="h-4 w-4" /></button>}
                        </div>

                        {empty ? <div className="px-5 py-8 text-center"><p className="text-sm font-semibold text-neutral-800 dark:text-neutral-200">{t('social.no_posts_for_view', { defaultValue: 'No posts match this view' })}</p><p className="mt-1 text-xs text-neutral-500">{t('social.no_posts_for_view_hint', { defaultValue: 'Try another status or clear your filters.' })}</p></div> : filters.view === 'calendar' ? <div className="p-4"><CalendarView posts={calendarPosts} month={filters.month} timezone={timezone} onView={setDetailPost} onMonth={month => navigate({ month })} /></div> : <PostList posts={posts} accountMap={accountMap} timezone={timezone} onView={setDetailPost} />}

                        {filters.view !== 'calendar' && posts?.last_page > 1 && <nav aria-label={t('common.pagination')} className="flex flex-wrap gap-1 border-t border-neutral-100 px-4 py-3 dark:border-neutral-800">{posts.links.map((link, index) => link.url ? <Link key={index} href={link.url} preserveScroll className={`rounded-md border px-3 py-1.5 text-sm ${link.active ? 'border-brand-600 bg-brand-600 text-white' : 'border-neutral-200 text-neutral-600 hover:bg-neutral-50 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-800'}`} dangerouslySetInnerHTML={{ __html: link.label }} /> : <span key={index} className="rounded-md border border-neutral-200 px-3 py-1.5 text-sm text-neutral-300 dark:border-neutral-800" dangerouslySetInnerHTML={{ __html: link.label }} />)}</nav>}
                    </> : <div className="flex flex-wrap items-center gap-3 px-4 py-5"><span className="flex h-9 w-9 items-center justify-center rounded-full bg-neutral-100 text-neutral-400 dark:bg-neutral-800"><CalendarDays className="h-4 w-4" /></span><div className="min-w-0 flex-1"><p className="text-sm font-semibold text-neutral-800 dark:text-neutral-200">{t('social.no_posts_yet_compact', { defaultValue: 'No posts scheduled yet' })}</p><p className="text-xs text-neutral-500">{activeAccounts.length > 0 ? t('social.no_posts_ready_hint', { defaultValue: 'Create your first post when you are ready.' }) : t('social.no_posts_connect_hint', { defaultValue: 'Connect an account above before creating your first post.' })}</p></div>{activeAccounts.length > 0 && <Link href={route('client.social.automation.schedule')} className="inline-flex items-center gap-1.5 text-sm font-medium text-brand-600 hover:text-brand-700"><Plus className="h-4 w-4" />{t('social.schedule_post', { defaultValue: 'Schedule post' })}</Link>}</div>}
                </section>
            </div>

            <ProviderPicker open={providerPicker} onClose={() => setProviderPicker(false)} />
            <PostDetails post={detailPost} accountMap={accountMap} timezone={timezone} onClose={() => setDetailPost(null)} />
            <AiPlannerModal show={plannerOpen} onClose={() => setPlannerOpen(false)} accounts={activeAccounts} onSuccess={() => { setPlannerOpen(false); router.reload({ only: ['posts', 'tabCounts'] }); }} />
        </ClientLayout>
    );
}
