import { Link, usePage } from '@inertiajs/react';
import { useLayoutEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { ChevronDown, Plus, X } from 'lucide-react';

const SIDEBAR_SCROLL_STORAGE_PREFIX = 'wisperbot.sidebar.scroll.';

function readScrollPosition(storageKey) {
    if (typeof window === 'undefined') return 0;

    try {
        const value = Number.parseInt(window.sessionStorage.getItem(storageKey) ?? '0', 10);
        return Number.isFinite(value) && value >= 0 ? value : 0;
    } catch {
        return 0;
    }
}

function writeScrollPosition(storageKey, position) {
    if (typeof window === 'undefined') return;

    try {
        window.sessionStorage.setItem(storageKey, String(Math.max(0, Math.round(position))));
    } catch {
        // Storage may be unavailable in privacy-restricted browser contexts.
    }
}

function readGroupPreference(storageKey) {
    if (typeof window === 'undefined') return null;

    try {
        return window.sessionStorage.getItem(storageKey);
    } catch {
        return null;
    }
}

function itemIsActive(item) {
    return typeof item.active === 'function'
        ? item.active()
        : item.active ?? (item.route ? route().current(item.route) : false);
}

function NavGroup({ label, items, onClose, groupKey, defaultOpen = false }) {
    const storageKey = `wisperbot.sidebar.group.${groupKey}`;
    const hasActiveItem = items.some(itemIsActive);
    const [open, setOpen] = useState(() => {
        const saved = readGroupPreference(storageKey);
        return saved === null ? defaultOpen || hasActiveItem : saved === '1' || hasActiveItem;
    });

    const toggle = () => {
        setOpen((current) => {
            const next = !current;
            try { window.sessionStorage.setItem(storageKey, next ? '1' : '0'); } catch { /* optional preference */ }
            return next;
        });
    };

    return (
        <div className="mb-1">
            <button
                type="button"
                onClick={toggle}
                aria-expanded={open}
                aria-controls={`nav-group-${label.replace(/\s+/g, '-').toLowerCase()}`}
                className="mt-3 flex w-full select-none items-center justify-between rounded-lg px-3 py-1.5 text-[10px] font-bold uppercase tracking-[0.14em] text-neutral-400 transition-colors duration-150 hover:text-neutral-700 dark:text-neutral-500 dark:hover:text-neutral-200"
            >
                <span>{label}</span>
                <ChevronDown
                    className={[
                        'h-3 w-3 transition-transform duration-200',
                        open ? 'rotate-0' : '-rotate-90',
                    ].join(' ')}
                />
            </button>

            {open && (
                <div id={`nav-group-${label.replace(/\s+/g, '-').toLowerCase()}`} className="mt-0.5 space-y-0.5">
                    {items.map((item, i) => {
                        const isActive = itemIsActive(item);
                        return (
                            <Link
                                key={item.key ?? item.route ?? item.href ?? i}
                                href={item.href ?? (item.route ? route(item.route) : '#')}
                                onClick={onClose}
                                target={item.external ? '_blank' : undefined}
                                rel={item.external ? 'noopener noreferrer' : undefined}
                                aria-current={isActive ? 'page' : undefined}
                                className={[
                                    'group relative flex min-h-10 items-center gap-2.5 rounded-[10px] px-3 py-2 text-[13px] font-medium transition-all duration-150 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/30',
                                    isActive
                                        ? 'bg-brand-50 text-brand-700 shadow-[inset_0_0_0_1px_rgba(255,118,46,.12)] dark:bg-brand-900/25 dark:text-brand-300'
                                        : 'text-neutral-600 hover:bg-neutral-100 hover:text-neutral-950 dark:text-neutral-400 dark:hover:bg-neutral-800 dark:hover:text-white',
                                ].join(' ')}
                            >
                                {isActive && <span className="absolute inset-y-2 left-0 w-0.5 rounded-full bg-brand-500 rtl:left-auto rtl:right-0" aria-hidden="true" />}
                                {item.icon && (
                                    <span className={[
                                        'shrink-0 transition-colors duration-150',
                                        isActive ? 'text-brand-600 dark:text-brand-400' : 'text-neutral-400 group-hover:text-neutral-700 dark:text-neutral-500 dark:group-hover:text-neutral-200',
                                    ].join(' ')}>
                                        {item.icon}
                                    </span>
                                )}
                                <span className="truncate">{item.label}</span>
                            </Link>
                        );
                    })}
                </div>
            )}
        </div>
    );
}

export default function Sidebar({
    navItems = [],
    navGroups = [],
    open = false,
    onClose,
    footer,
    title: _title,
    logo,
    showCreateButton = true,
    scrollKey = 'default',
}) {
    const { t } = useTranslation();
    const appName = import.meta.env.VITE_APP_NAME || 'WisperBot';
    const { branding } = usePage().props;
    const logoUrl = branding?.logo_url;
    const desktopNavRef = useRef(null);
    const mobileNavRef = useRef(null);
    const scrollStorageKey = `${SIDEBAR_SCROLL_STORAGE_PREFIX}${scrollKey}`;

    // Inertia swaps page components, which recreates the layout and this sidebar.
    // Restore the menu's own scroll position before paint so lower navigation
    // items stay where the user left them instead of jumping back to the top.
    useLayoutEffect(() => {
        const position = readScrollPosition(scrollStorageKey);
        if (desktopNavRef.current) desktopNavRef.current.scrollTop = position;
        if (mobileNavRef.current) mobileNavRef.current.scrollTop = position;
    }, [open, scrollStorageKey]);

    const rememberScrollPosition = (event) => {
        writeScrollPosition(scrollStorageKey, event.currentTarget.scrollTop);
    };

    const renderContent = (surface) => (
        <aside className="flex h-full w-full flex-col border-r border-neutral-200/80 bg-white text-neutral-900 dark:border-neutral-800 dark:bg-neutral-950 dark:text-neutral-100">
            {/* Brand header */}
            <div className="flex h-16 shrink-0 items-center gap-2.5 border-b border-neutral-100 px-4 dark:border-neutral-800">
                {logoUrl ? (
                    <img src={logoUrl} alt={appName} className="h-7 max-w-[140px] object-contain" />
                ) : logo ? (
                    logo
                ) : (
                    <>
                        <img src="/wisperbot-logo-with-title.svg" alt={appName} className="h-9 w-auto max-w-[180px] object-contain dark:hidden" />
                        <img src="/wisperbot-logo-white.svg" alt={appName} className="hidden h-9 w-auto max-w-[180px] object-contain dark:block" />
                    </>
                )}
            </div>

            {showCreateButton && (
                <div className="shrink-0 border-b border-neutral-100 p-3 dark:border-neutral-800">
                    <button
                        type="button"
                        className="flex w-full items-center justify-center gap-2 rounded-lg px-3 py-2 text-sm font-semibold text-white bg-brand-600 hover:bg-brand-700 transition duration-150"
                    >
                        <Plus className="h-4 w-4" />
                        {t('common.create')}
                    </button>
                </div>
            )}

            <nav
                ref={surface === 'desktop' ? desktopNavRef : mobileNavRef}
                onScroll={rememberScrollPosition}
                data-testid={`sidebar-scroll-${surface}`}
                className="flex-1 overflow-y-auto px-2.5 py-2 scrollbar-thin"
            >
                {navGroups.length > 0 &&
                    navGroups.map((group, gi) => (
                        <NavGroup
                            // Index-prefixed: group labels are not guaranteed unique
                            // (e.g. two "Account" groups), and a duplicate React key
                            // makes React omit/duplicate siblings, corrupting the
                            // sidebar across SPA navigations.
                            key={`${gi}-${group.key ?? group.label ?? ''}`}
                            groupKey={`${scrollKey}.${group.key ?? group.label ?? gi}`}
                            label={group.label}
                            items={group.items ?? []}
                            onClose={onClose}
                            defaultOpen={gi === 0}
                        />
                    ))}

                {navGroups.length === 0 &&
                    navItems.map((item, i) => {
                        if (item.type === 'divider') {
                            return <hr key={`div-${i}`} className="my-2 border-white/10" />;
                        }
                        const isActive = itemIsActive(item);
                        return (
                            <Link
                                key={item.key ?? item.route ?? item.href ?? i}
                                href={item.href ?? (item.route ? route(item.route) : '#')}
                                onClick={onClose}
                                className={[
                                    'group relative flex min-h-10 items-center gap-2.5 rounded-[10px] px-3 py-2 text-[13px] font-medium transition-all duration-150 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/30',
                                    isActive
                                        ? 'bg-brand-50 text-brand-700 dark:bg-brand-900/25 dark:text-brand-300'
                                        : 'text-neutral-600 hover:bg-neutral-100 hover:text-neutral-950 dark:text-neutral-400 dark:hover:bg-neutral-800 dark:hover:text-white',
                                ].join(' ')}
                            >
                                {isActive && <span className="absolute inset-y-2 left-0 w-0.5 rounded-full bg-brand-500 rtl:left-auto rtl:right-0" aria-hidden="true" />}
                                {item.icon && (
                                    <span className={isActive ? 'text-brand-600 dark:text-brand-400' : 'text-neutral-400 group-hover:text-neutral-700 dark:text-neutral-500 dark:group-hover:text-neutral-200'}>
                                        {item.icon}
                                    </span>
                                )}
                                <span className="truncate">{item.label}</span>
                            </Link>
                        );
                    })}
            </nav>

            {footer && (
                <div className="shrink-0 border-t border-neutral-100 p-3 dark:border-neutral-800">
                    <div className="text-neutral-500 dark:text-neutral-400">
                        {footer}
                    </div>
                </div>
            )}
        </aside>
    );

    return (
        <>
            {/* Desktop: always visible */}
            <div className="hidden lg:fixed lg:inset-y-0 lg:z-20 lg:flex lg:w-[236px] lg:flex-col lg:left-0 rtl:lg:left-auto rtl:lg:right-0">
                {renderContent('desktop')}
            </div>

            {/* Mobile: overlay + drawer */}
            {open && (
                <div className="fixed inset-0 z-40 lg:hidden">
                    <div className="fixed inset-0 bg-black/60 backdrop-blur-sm" onClick={onClose} aria-hidden="true" />
                    <div className="fixed inset-y-0 left-0 w-[min(86vw,296px)] shadow-2xl rtl:left-auto rtl:right-0">
                        <button
                            type="button"
                            onClick={onClose}
                            className="absolute top-4 right-3 z-10 flex h-8 w-8 items-center justify-center rounded-full bg-neutral-100 text-neutral-500 transition hover:bg-neutral-200 hover:text-neutral-900 dark:bg-neutral-800 dark:text-neutral-400 dark:hover:bg-neutral-700 dark:hover:text-white"
                            aria-label={t('ui.close_menu')}
                        >
                            <X className="h-4 w-4" />
                        </button>
                        {renderContent('mobile')}
                    </div>
                </div>
            )}
        </>
    );
}
