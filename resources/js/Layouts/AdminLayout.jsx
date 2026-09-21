import { Link, usePage } from '@inertiajs/react';
import { useState, useMemo } from 'react';
import { useTranslation } from 'react-i18next';
import { Toaster } from 'sonner';
import Sidebar from '@/Components/Sidebar';
import Topbar from '@/Components/Topbar';
import CommandPalette from '@/Components/CommandPalette';
import {
    LayoutDashboard,
    Users,
    Package,
    CreditCard,
    Globe,
    Banknote,
    Settings,
    FileText,
    ShieldCheck,
    UserCog,
    Tag,
    Receipt,
    Percent,
    Server,
    LifeBuoy,
    Plug,
    Radio,
    Brain,
    Clock,
    LogOut,
    Newspaper,
    Coins,
} from 'lucide-react';

/** Nav item: { labelKey, route, href, icon, permission } - show only if user has permission (or no permission required). Order follows typical admin usage frequency. */
const ADMIN_NAV_ITEMS = [
    { labelKey: 'admin.dashboard', route: 'admin.dashboard', href: () => route('admin.dashboard'), icon: LayoutDashboard },
    { labelKey: 'admin.client_management', route: 'admin.clients.index', href: () => route('admin.clients.index'), icon: Users, permission: 'view_clients' },
    { labelKey: 'admin.nav.subscriptions', route: 'admin.subscriptions.index', href: () => route('admin.subscriptions.index'), icon: CreditCard, permission: 'view_subscriptions' },
    { label: 'AI Credits', route: 'admin.ai-credits.report', href: () => route('admin.ai-credits.report'), icon: Coins, permission: 'view_subscriptions' },
    { labelKey: 'admin.nav.support', route: 'admin.support.index', href: () => route('admin.support.index'), icon: LifeBuoy, permission: 'view_settings' },
    { labelKey: 'admin.nav.payments', route: 'admin.payments.index', href: () => route('admin.payments.index'), icon: Receipt, permission: 'view_payment_gateways' },
    { labelKey: 'admin.nav.plans', route: 'admin.plans.index', href: () => route('admin.plans.index'), icon: Package, permission: 'view_plans' },
    { labelKey: 'admin.nav.coupons', route: 'admin.coupons.index', href: () => route('admin.coupons.index'), icon: Tag, permission: 'view_plans' },
    { labelKey: 'admin.tax_rates', route: 'admin.tax-rates.index', href: () => route('admin.tax-rates.index'), icon: Percent, permission: 'view_plans' },
    { labelKey: 'admin.payment_gateways', route: 'admin.payment-gateways.index', href: () => route('admin.payment-gateways.index'), icon: CreditCard, permission: 'view_payment_gateways' },
    { labelKey: 'admin.email', route: 'admin.email-system.index', href: () => route('admin.email-system.index'), icon: FileText, permission: 'view_email_settings' },
    { labelKey: 'admin.nav.currencies', route: 'admin.currencies.index', href: () => route('admin.currencies.index'), icon: Banknote, permission: 'view_currencies' },
    { labelKey: 'admin.languages', route: 'admin.locales.index', href: () => route('admin.locales.index'), icon: Globe, permission: 'view_languages' },
    { labelKey: 'admin.roles_permissions', route: 'admin.roles-permissions.index', href: () => route('admin.roles-permissions.index'), icon: ShieldCheck, permission: 'view_admin_roles', permissionAlt: 'manage_admin_roles' },
    { labelKey: 'admin.nav.admins', route: 'admin.admins.index', href: () => route('admin.admins.index'), icon: UserCog, permission: 'view_admins' },
    { labelKey: 'admin.landing_page', route: 'admin.landing-page.index', href: () => route('admin.landing-page.index'), icon: FileText, permission: 'view_settings' },
    { labelKey: 'admin.cms_pages', route: 'admin.cms-pages.index', href: () => route('admin.cms-pages.index'), icon: FileText, permission: 'view_settings' },
    { label: 'Blog', route: 'admin.blog.index', href: () => route('admin.blog.index'), icon: Newspaper, permission: 'view_settings' },
    { labelKey: 'admin.nav.queue', route: 'admin.queue.index', href: () => route('admin.queue.index'), icon: Server, permission: 'view_settings' },
    { labelKey: 'admin.cron_setup', route: 'admin.cron-setup.index', href: () => route('admin.cron-setup.index'), icon: Clock, permission: 'view_settings' },
    { labelKey: 'admin.pusher_settings', route: 'admin.pusher-settings.index', href: () => route('admin.pusher-settings.index'), icon: Radio, permission: 'manage_settings' },
    { labelKey: 'admin.nav.settings', route: 'admin.settings.index', href: () => route('admin.settings.index'), icon: Settings, permission: 'view_settings' },
    { labelKey: 'admin.audit_log', route: 'admin.audit-log.index', href: () => route('admin.audit-log.index'), icon: FileText, permission: 'view_settings' },
    { labelKey: 'admin.nav.integrations', route: 'admin.integrations.index', href: () => route('admin.integrations.index'), icon: Plug, permission: 'manage_integrations' },
    { labelKey: 'admin.nav.ai', route: 'admin.ai.index', href: () => route('admin.ai.index'), icon: Brain, permission: 'view_settings' },
];

const ADMIN_NAV_GROUPS = [
    { key: 'overview', label: 'Overview', routes: ['admin.dashboard'] },
    { key: 'clients', label: 'Clients & subscriptions', routes: ['admin.clients.index', 'admin.subscriptions.index', 'admin.ai-credits.report', 'admin.support.index'] },
    { key: 'revenue', label: 'Revenue & plans', routes: ['admin.payments.index', 'admin.plans.index', 'admin.coupons.index', 'admin.tax-rates.index', 'admin.payment-gateways.index', 'admin.currencies.index'] },
    { key: 'content', label: 'Content & localization', routes: ['admin.landing-page.index', 'admin.cms-pages.index', 'admin.blog.index', 'admin.locales.index', 'admin.email-system.index'] },
    { key: 'platform', label: 'Platform services', routes: ['admin.integrations.index', 'admin.ai.index', 'admin.queue.index', 'admin.cron-setup.index', 'admin.pusher-settings.index'] },
    { key: 'access', label: 'Access & security', routes: ['admin.admins.index', 'admin.roles-permissions.index', 'admin.audit-log.index'] },
    { key: 'settings', label: 'Settings', routes: ['admin.settings.index'] },
];

/** Dedupe, permission-filter, and group without changing any destination. */
function useAdminNav() {
    const { t } = useTranslation();
    const { auth } = usePage().props;
    const permissions = auth?.permissions;

    return useMemo(() => {
        const hasPermission = (key) => (permissions ?? []).includes(key);
        const items = ADMIN_NAV_ITEMS.filter((item) => {
            const perm = item.permission;
            const alt = item.permissionAlt;
            if (perm && !hasPermission(perm) && (!alt || !hasPermission(alt))) return false;
            return true;
        }).map((item) => ({
            label: item.label ?? t(item.labelKey),
            route: item.route,
            href: typeof item.href === 'function' ? item.href() : item.href,
            icon: item.icon ? <item.icon className="h-5 w-5" /> : null,
        }));
        return ADMIN_NAV_GROUPS.map((group) => ({
            key: group.key,
            label: group.label,
            items: group.routes.map((routeName) => items.find((item) => item.route === routeName)).filter(Boolean),
        })).filter((group) => group.items.length > 0);
    }, [t, permissions]);
}

function AdminLayoutFooter() {
    const { t } = useTranslation();
    const { auth, app_version: appVersion } = usePage().props;
    const adminUser = auth?.adminUser;
    const version = typeof appVersion === 'string' && appVersion.trim() !== ''
        ? appVersion.trim().replace(/^v/i, '')
        : '1.0.0';
    const initials = (() => {
        if (adminUser?.name) return adminUser.name.split(' ').filter(Boolean).map((n) => n[0]).join('').slice(0, 2).toUpperCase();
        if (adminUser?.email) return adminUser.email[0].toUpperCase();
        return 'A';
    })();
    return (
        <div className="border-t border-neutral-200 dark:border-neutral-800 pt-3 space-y-1">
            {adminUser && (
                <div className="flex items-center gap-3 px-3 py-2 rounded-soft">
                    <div className="flex-shrink-0 h-8 w-8 rounded-full bg-primary-100 dark:bg-primary-900/40 flex items-center justify-center text-xs font-semibold text-primary-700 dark:text-primary-300">
                        {initials}
                    </div>
                    <div className="min-w-0 flex-1">
                        {adminUser.name && (
                            <p className="text-xs font-medium text-neutral-700 dark:text-neutral-200 truncate">{adminUser.name}</p>
                        )}
                        <p className="text-xs text-neutral-400 dark:text-neutral-500 truncate" title={adminUser.email}>
                            {adminUser.email}
                        </p>
                    </div>
                </div>
            )}
            <Link
                href={route('admin.logout')}
                method="post"
                as="button"
                className="flex items-center gap-2 w-full text-left rtl:text-right rounded-soft px-3 py-2 text-sm text-neutral-500 hover:bg-red-50 hover:text-red-600 dark:text-neutral-400 dark:hover:bg-red-950/40 dark:hover:text-red-400 transition duration-150"
            >
                <LogOut className="h-4 w-4 flex-shrink-0" />
                {t('nav.logout')}
            </Link>
            <p className="px-3 pt-1 text-left text-[11px] tabular-nums text-neutral-400 dark:text-neutral-600">
                v{version}
            </p>
        </div>
    );
}

export default function AdminLayout({ title = 'Admin', header, children }) {
    const { t } = useTranslation();
    const [sidebarOpen, setSidebarOpen] = useState(false);
    const adminNavGroups = useAdminNav();
    const { demo_mode: demoMode, branding } = usePage().props;
    const logoUrl = branding?.logo_url;

    return (
        <div className="app-shell min-h-screen bg-[#f4f5f7] dark:bg-neutral-950">
            <Sidebar
                scrollKey="admin"
                open={sidebarOpen}
                onClose={() => setSidebarOpen(false)}
                title={t('nav.admin')}
                logo={logoUrl ? <img src={logoUrl} alt="Logo" className="h-8 max-w-[160px] object-contain" /> : null}
                showCreateButton={false}
                navGroups={adminNavGroups.map((group) => ({
                    ...group,
                    items: group.items.map((item, i) => ({
                        ...item,
                        key: `${item.route}-${item.label}-${i}`,
                        active: () => route().current(item.route),
                    })),
                }))}
                footer={<AdminLayoutFooter />}
            />

            <div className="lg:pl-[236px] rtl:lg:pl-0 rtl:lg:pr-[236px]">
                <Topbar
                    showLogo={false}
                    title={title}
                    showWorkspace={false}
                    showLocale={false}
                    showCurrency={false}
                    showAccount={false}
                    showAdminSearch
                    onOpenNavigation={() => setSidebarOpen(true)}
                />

                <main className={`app-shell-content p-4 sm:p-6 lg:p-7 ${demoMode ? 'pb-16' : ''}`}>
                    {header && typeof header === 'object' && (
                        <div className="mb-6">
                            {header}
                        </div>
                    )}
                    {children}
                </main>
            </div>

            <CommandPalette searchRoute={route('admin.search')} />
            <Toaster richColors position="top-right" />
            {/* Demo notice — pinned to the bottom of the viewport */}
            {demoMode && (
                <div className="fixed inset-x-0 bottom-0 z-20 flex items-center justify-center gap-2 bg-amber-500/90 text-amber-950 px-4 py-2 text-sm font-medium pointer-events-none">
                    <span>{t('demo.banner') || 'Demo mode: changes are disabled.'}</span>
                </div>
            )}
        </div>
    );
}
