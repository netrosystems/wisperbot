import { Link, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import { useTheme } from '@/context/ThemeContext';
import { useLocale } from '@/hooks/useLocale';
import { ProviderBrandIcon } from '@/Components/BrandIcons';
import { ArrowLeft, Bot, Check, Inbox, Moon, ShieldCheck, Smartphone, Sun } from 'lucide-react';

const CLIENT_CAPABILITIES = [
    { icon: Inbox, key: 'auth.omnichannel_inbox', fallback: 'Bring customer conversations into one workspace' },
    { icon: Bot, key: 'auth.smart_ai_support', fallback: 'Ground AI answers in your approved business knowledge' },
    { icon: Smartphone, key: 'auth.agent_apps', fallback: 'Let your team take over from web, Android, or iOS' },
];

const ADMIN_CAPABILITIES = [
    { icon: ShieldCheck, key: 'auth.admin_access_control', fallback: 'Secure role-based access for platform operations' },
    { icon: Inbox, key: 'auth.admin_workspace_management', fallback: 'Manage workspaces, subscriptions, and integrations' },
    { icon: Bot, key: 'auth.admin_service_visibility', fallback: 'Review AI, messaging, and worker health in one place' },
];

const CHANNELS = ['whatsapp', 'facebook', 'instagram', 'messenger', 'telegram', 'linkedin'];

function ThemeToggle() {
    const { t } = useTranslation();
    const { theme, setTheme } = useTheme();

    return (
        <button
            type="button"
            onClick={() => setTheme(theme === 'dark' ? 'light' : 'dark')}
            className="inline-flex h-10 w-10 items-center justify-center rounded-full border border-neutral-200 bg-white/80 text-neutral-600 transition hover:border-brand-300 hover:text-neutral-950 focus:outline-none focus:ring-2 focus:ring-brand-500/30 dark:border-neutral-700 dark:bg-neutral-900/80 dark:text-neutral-300 dark:hover:text-white"
            aria-label={t('topbar.switch_theme')}
        >
            {theme === 'dark' ? <Sun className="h-4 w-4" /> : <Moon className="h-4 w-4" />}
        </button>
    );
}

function LocaleToggle() {
    const { t } = useTranslation();
    const { locale, locales, setLocale } = useLocale();
    if (locales.length <= 1) return null;

    return (
        <select
            value={locale}
            onChange={(event) => setLocale(event.target.value)}
            aria-label={t('common.language', { defaultValue: 'Language' })}
            className="h-10 rounded-full border border-neutral-200 bg-white/80 px-3 text-xs font-semibold text-neutral-600 transition focus:border-brand-400 focus:outline-none focus:ring-2 focus:ring-brand-500/20 dark:border-neutral-700 dark:bg-neutral-900/80 dark:text-neutral-300"
        >
            {locales.map((item) => (
                <option key={item.code} value={item.code}>
                    {item.native_name || item.name || item.code.toUpperCase()}
                </option>
            ))}
        </select>
    );
}

function ProductStory({ variant, logoUrl, appName }) {
    const { t } = useTranslation();
    const isAdmin = variant === 'admin';
    const capabilities = isAdmin ? ADMIN_CAPABILITIES : CLIENT_CAPABILITIES;

    return (
        <aside className="relative hidden min-h-screen w-[42%] max-w-[640px] overflow-hidden border-r border-neutral-200/80 bg-white text-neutral-950 dark:border-neutral-800 dark:bg-neutral-950 dark:text-white lg:flex lg:flex-col">
            <div
                aria-hidden="true"
                className="pointer-events-none absolute inset-0 opacity-[0.06] dark:opacity-35"
                style={{
                    backgroundImage: 'radial-gradient(circle at center, rgba(32,36,31,.45) 1px, transparent 1.4px)',
                    backgroundSize: '26px 26px',
                    maskImage: 'linear-gradient(to bottom, black, transparent 82%)',
                }}
            />
            <div
                aria-hidden="true"
                className="pointer-events-none absolute -bottom-36 left-1/2 h-[430px] w-[620px] -translate-x-1/2 rounded-full blur-3xl"
                style={{ background: 'radial-gradient(circle, rgba(255,118,46,.34), rgba(255,191,0,.1) 42%, transparent 70%)' }}
            />

            <div className="relative flex h-full flex-1 flex-col px-10 py-9 xl:px-14 xl:py-11">
                <Link href={route('home')} className="inline-flex w-fit items-center focus:outline-none focus:ring-2 focus:ring-brand-400/60 focus:ring-offset-4 dark:focus:ring-offset-neutral-950">
                    <img src={logoUrl || '/wisperbot-logo-with-title.svg'} alt={appName} className="h-9 w-auto max-w-[220px] dark:brightness-0 dark:invert" />
                </Link>

                <div className="my-auto max-w-xl py-12">
                    <span className="inline-flex items-center gap-2 rounded-full border border-neutral-200 bg-neutral-50 px-3 py-1.5 text-xs font-semibold text-neutral-600 backdrop-blur dark:border-white/10 dark:bg-white/[0.06] dark:text-white/75">
                        <span className="h-1.5 w-1.5 rounded-full bg-brand-500 shadow-[0_0_12px_rgba(255,118,46,.8)]" />
                        {isAdmin
                            ? t('auth.platform_operations', { defaultValue: 'Platform operations' })
                            : t('auth.omnichannel_ai_automation', { defaultValue: 'Omnichannel AI automation' })}
                    </span>

                    <h2 className="mt-6 max-w-lg text-4xl font-semibold leading-[1.05] tracking-[-0.04em] text-neutral-950 dark:text-white xl:text-[3.1rem]">
                        {isAdmin ? (
                            t('auth.admin_story_title', { defaultValue: 'Operate WisperBot with clarity.' })
                        ) : (
                            <>
                                {t('auth.client_story_title_lead', { defaultValue: 'Every customer channel.' })}{' '}
                                <span className="italic text-brand-400">
                                    {t('auth.client_story_title_accent', { defaultValue: 'One AI support team.' })}
                                </span>
                            </>
                        )}
                    </h2>
                    <p className="mt-5 max-w-lg text-[15px] leading-7 text-neutral-500 dark:text-white/60">
                        {isAdmin
                            ? t('auth.admin_story_body', { defaultValue: 'Use protected access to manage the platform and keep client workspaces running.' })
                            : t('auth.client_story_body', { defaultValue: 'Connect customer conversations, answer with trusted knowledge, and hand over to a person when it matters.' })}
                    </p>

                    {!isAdmin && (
                        <div className="mt-7 flex items-center" aria-label={t('auth.supported_channels', { defaultValue: 'Supported customer channels' })}>
                            {CHANNELS.map((channel, index) => (
                                <span
                                    key={channel}
                                    className="flex h-10 w-10 items-center justify-center rounded-full border border-white/10 bg-white shadow-sm"
                                    style={{ marginLeft: index === 0 ? 0 : -8, zIndex: CHANNELS.length - index }}
                                >
                                    <ProviderBrandIcon provider={channel} className="h-[18px] w-[18px]" />
                                </span>
                            ))}
                            <span className="ml-3 text-xs font-medium text-neutral-500 dark:text-white/55">
                                {t('auth.and_more_channels', { defaultValue: 'and more' })}
                            </span>
                        </div>
                    )}

                    <ul className="mt-9 space-y-3.5">
                        {capabilities.map(({ icon: Icon, key, fallback }) => (
                            <li key={key} className="flex items-center gap-3 text-sm text-neutral-600 dark:text-white/75">
                                <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-soft border border-brand-100 bg-brand-50 text-brand-600 dark:border-white/10 dark:bg-white/[0.06] dark:text-brand-400">
                                    <Icon className="h-4 w-4" aria-hidden="true" />
                                </span>
                                <span>{t(key, { defaultValue: fallback })}</span>
                                <Check className="ml-auto h-4 w-4 shrink-0 text-neutral-300 dark:text-white/30" aria-hidden="true" />
                            </li>
                        ))}
                    </ul>
                </div>

                <div className="flex items-center justify-between border-t border-neutral-200 pt-5 text-xs text-neutral-400 dark:border-white/10 dark:text-white/40">
                    <span>&copy; {new Date().getFullYear()} {appName}</span>
                    <span>{t('auth.secure_workspace_access', { defaultValue: 'Secure workspace access' })}</span>
                </div>
            </div>
        </aside>
    );
}

export default function AuthLayout({
    variant = 'client', eyebrow, title, subtitle, icon, status, error, children, contentClassName = 'max-w-md',
}) {
    const { t } = useTranslation();
    const { branding } = usePage().props;
    const appName = branding?.app_name || import.meta.env.VITE_APP_NAME || 'WisperBot';

    return (
        <main className="flex min-h-screen bg-[#f4f5f7] font-sans text-[#20241f] dark:bg-neutral-950 dark:text-neutral-100">
            <ProductStory variant={variant} logoUrl={branding?.logo_url} appName={appName} />

            <section className="relative flex min-w-0 flex-1 flex-col overflow-hidden">
                <div aria-hidden="true" className="pointer-events-none absolute inset-0 opacity-[0.035] dark:opacity-[0.06]" style={{ backgroundImage: 'radial-gradient(circle, #20241f 1px, transparent 1px)', backgroundSize: '28px 28px' }} />
                <div aria-hidden="true" className="pointer-events-none absolute -right-32 -top-32 h-96 w-96 rounded-full bg-brand-200/30 blur-3xl dark:bg-brand-700/10" />

                <header className="relative flex items-center justify-between px-5 py-5 sm:px-8 lg:px-10">
                    <Link href={route('home')} className="flex items-center gap-2 text-sm font-semibold text-neutral-600 transition hover:text-neutral-950 focus:outline-none focus:ring-2 focus:ring-brand-500/30 dark:text-neutral-300 dark:hover:text-white lg:hidden">
                        <img src={branding?.logo_url || '/wisperbot-logo-with-title.svg'} alt={appName} className="h-7 w-auto max-w-[168px] dark:brightness-0 dark:invert" />
                    </Link>
                    <Link href={route('home')} className="hidden items-center gap-2 text-sm font-semibold text-neutral-500 transition hover:text-neutral-950 focus:outline-none focus:ring-2 focus:ring-brand-500/30 dark:text-neutral-400 dark:hover:text-white lg:inline-flex">
                        <ArrowLeft className="h-4 w-4" aria-hidden="true" />
                        {t('auth.back_to_website', { defaultValue: 'Back to website' })}
                    </Link>
                    <div className="flex items-center gap-2"><LocaleToggle /><ThemeToggle /></div>
                </header>

                <div className="relative flex flex-1 items-center justify-center px-4 pb-10 pt-2 sm:px-8 lg:px-10 lg:pb-12">
                    <div className={`w-full ${contentClassName}`}>
                        <div className="mb-6">
                            {icon && <div className="mb-4">{icon}</div>}
                            {eyebrow && <p className="mb-2 text-xs font-bold uppercase tracking-[0.16em] text-brand-600 dark:text-brand-400">{eyebrow}</p>}
                            {title && <h1 className="text-[2rem] font-semibold leading-[1.06] tracking-[-0.04em] text-[#20241f] dark:text-white sm:text-[2.3rem]">{title}</h1>}
                            {subtitle && <p className="mt-3 max-w-lg text-sm leading-6 text-[#686c66] dark:text-neutral-400">{subtitle}</p>}
                        </div>

                        {status && <div role="status" className="mb-4 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-200">{status}</div>}
                        {error && <div role="alert" className="mb-4 rounded-2xl border border-coral-200 bg-coral-50 px-4 py-3 text-sm text-coral-800 dark:border-coral-800 dark:bg-coral-950/40 dark:text-coral-200">{error}</div>}

                        <div className="rounded-soft-xl border border-neutral-200/80 bg-white/95 p-5 shadow-soft-lg backdrop-blur-sm dark:border-neutral-800 dark:bg-neutral-900/95 sm:p-7">
                            {children}
                        </div>
                    </div>
                </div>
            </section>
        </main>
    );
}
