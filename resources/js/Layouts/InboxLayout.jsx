import { Link, router, usePage } from '@inertiajs/react';
import { useState, useEffect } from 'react';
import { useTranslation } from 'react-i18next';
import { Toaster, toast } from 'sonner';
import Sidebar from '@/Components/Sidebar';
import UpgradeModal from '@/Components/UpgradeModal';
import useClientNav from '@/Layouts/useClientNav';
import { isNotificationForWorkspace } from '@/Utils/workspaceNotifications';

export default function InboxLayout({ children, mobileTitle, mobileBackHref }) {
    const { t } = useTranslation();
    const [sidebarOpen, setSidebarOpen] = useState(false);
    const { auth, impersonation, branding, demo_mode, currentWorkspace } = usePage().props;
    const logoUrl = branding?.logo_url;
    const clientNavGroups = useClientNav();

    useEffect(() => {
        if (!window.Echo || !auth?.user?.id) return;
        window.Echo.private(`App.Models.User.${auth.user.id}`)
            .notification((notification) => {
                if (!isNotificationForWorkspace(notification, currentWorkspace?.id)) return;
                const msg = notification.snippet ?? notification.name ?? notification.automation ?? notification.error ?? 'New notification';
                const title = {
                    new_message: 'New message',
                    mention: '@ You were mentioned',
                    conversation_assigned: 'Conversation assigned',
                    campaign_completed: 'Campaign completed',
                    automation_failed: 'Automation failed',
                    billing_failed: 'Payment failed',
                }[notification.type] ?? 'Notification';
                toast(title, {
                    description: msg,
                    action: notification.url ? { label: 'View', onClick: () => router.visit(notification.url) } : undefined,
                });
            });
        return () => { window.Echo.leave(`App.Models.User.${auth.user.id}`); };
    }, [auth?.user?.id, currentWorkspace?.id]);

    const returnToAdmin = () => {
        router.post(impersonation?.returnUrl ?? route('admin.impersonation.stop'));
    };

    return (
        <div style={{ height: '100dvh' }} className="app-shell h-screen overflow-hidden bg-[#f4f5f7] dark:bg-neutral-950 flex flex-col">
            {impersonation?.active && (
                <div className="flex items-center justify-between gap-4 bg-amber-500/90 text-white px-4 py-2 text-sm font-medium shrink-0">
                    <span>{t('impersonation.impersonating', { name: impersonation.clientName })}</span>
                    <button type="button" onClick={returnToAdmin} className="rounded-soft bg-white/20 px-3 py-1.5 font-medium hover:bg-white/30 transition">
                        {t('impersonation.return_to_admin')}
                    </button>
                </div>
            )}

            <div className="flex min-h-0 flex-1 overflow-hidden">
                <Sidebar
                    scrollKey="client"
                    open={sidebarOpen}
                    onClose={() => setSidebarOpen(false)}
                    title={t('client.panel') || 'Client Panel'}
                    logo={logoUrl ? <img src={logoUrl} alt="Logo" className="h-8 max-w-[160px] object-contain" /> : null}
                    showCreateButton={false}
                    navGroups={clientNavGroups.map(group => ({
                        ...group,
                        items: group.items.map(item => ({
                            ...item,
                            key: item.activePattern || item.label,
                            active: () => item.activePattern ? route().current(item.activePattern) : false,
                        }))
                    }))}
                />

                <div className="lg:pl-[236px] rtl:lg:pl-0 rtl:lg:pr-[236px] min-h-0 min-w-0 flex-1 overflow-hidden flex flex-col">
                    <header className="flex shrink-0 items-center gap-2 border-b border-neutral-200 bg-white px-2 py-1 dark:border-neutral-800 dark:bg-neutral-900 lg:hidden">
                        <button
                            type="button"
                            onClick={() => setSidebarOpen(true)}
                            className="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg text-neutral-600 transition hover:bg-neutral-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 dark:text-neutral-300 dark:hover:bg-neutral-800"
                            aria-label={t('open_menu')}
                            aria-expanded={sidebarOpen}
                        >
                            <svg aria-hidden="true" className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 6h16M4 12h16M4 18h16" />
                            </svg>
                        </button>
                        {mobileBackHref ? (
                            <Link href={mobileBackHref} className="flex min-h-11 min-w-0 items-center gap-1.5 rounded-lg px-2 text-sm font-semibold text-neutral-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 dark:text-neutral-100">
                                <svg aria-hidden="true" className="h-4 w-4 shrink-0 rtl:rotate-180" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="m15 18-6-6 6-6" />
                                </svg>
                                <span className="truncate">{mobileTitle || t('inbox.title')}</span>
                            </Link>
                        ) : <span className="truncate text-sm font-semibold text-neutral-800 dark:text-neutral-100">{mobileTitle || t('inbox.title')}</span>}
                    </header>
                    {children}
                </div>
            </div>

            {/* Demo notice — pinned to the bottom of the viewport */}
            {demo_mode && (
                <div className="flex items-center justify-center gap-2 bg-amber-500/90 text-amber-950 px-4 py-2 text-sm font-medium shrink-0">
                    <span>{t('demo.banner') || 'Demo mode: changes are disabled.'}</span>
                </div>
            )}

            <UpgradeModal />
            <Toaster richColors position="top-right" />
        </div>
    );
}
