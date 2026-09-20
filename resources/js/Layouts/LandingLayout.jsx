import { useEffect, useRef, useState } from 'react'
import { usePage } from '@inertiajs/react'
import { Dialog, DialogPanel, DialogTitle } from '@headlessui/react'
import { ArrowUpRight, ChevronDown, Download, Menu, X, Sparkles, Globe2 } from 'lucide-react'
import { MButton, MLink, ProductIcon, DownloadLinks, useMarketing } from '@/Components/marketing/MarketingUI'
import { useLocale } from '@/hooks/useLocale'
import '../../css/marketing.css'

export default function LandingLayout({ children, darkHeader = false }) {
    const { pages = [], setting, startHref, auth, text: t } = useMarketing()
    const { props } = usePage()
    const { locale, setLocale } = useLocale()
    const [open, setOpen] = useState(null)
    const [mobileOpen, setMobileOpen] = useState(false)
    const [motionPaused, setMotionPaused] = useState(false)
    const [headerOnDark, setHeaderOnDark] = useState(darkHeader)
    const header = useRef(null)
    const triggers = useRef({})
    const products = pages.filter((page) => page.slug.startsWith('products/'))
    const solutions = pages.filter((page) => page.slug.startsWith('solutions/'))
    const channels = pages.filter((page) => page.slug.startsWith('channels/'))
    const resources = [
        {
            name: t('nav.integrations', 'Integrations'),
            href: '/integrations',
            icon: 'network',
            tagline: t('nav.integrations_desc', 'Find the right connections.'),
        },
        {
            name: t('nav.blog', 'Guides & articles'),
            href: '/blog',
            icon: 'book',
            tagline: t('nav.blog_desc', 'Ideas for better conversations.'),
        },
        {
            name: t('nav.faq', 'Help & FAQ'),
            href: '/faq',
            icon: 'messages',
            tagline: t('nav.faq_desc', 'Get your questions answered.'),
        },
        {
            name: t('nav.about', 'About WisperBot'),
            href: '/about',
            icon: 'globe',
            tagline: t('nav.about_desc', 'Why we are building this.'),
        },
        {
            name: t('nav.contact', 'Contact us'),
            href: '/contact',
            icon: 'headphones',
            tagline: t('nav.contact_desc', 'Talk through your next step.'),
        },
    ]
    const groups = { products, channels, solutions, resources }
    const labels = {
        products: t('nav.products', 'Products'),
        channels: t('nav.channels', 'Channels'),
        solutions: t('nav.solutions', 'Solutions'),
        resources: t('nav.resources', 'Resources'),
    }
    const hasDownloads = ['agent_app_ios_url', 'agent_app_android_url', 'chat_sdk_pubdev_url'].some((key) =>
        setting(key),
    )
    const loginHref = setting('signin_link_type') === 'static' ? setting('signin_link_url', '/login') : '/login'
    const locales = Object.entries(props.supportedLocales || { en: 'English' })
    const pageName = (page) =>
        page.slug ? t('catalog.' + page.slug.replaceAll('/', '.') + '.name', page.name) : page.name
    const pageTagline = (page) =>
        page.slug ? t('catalog.' + page.slug.replaceAll('/', '.') + '.tagline', page.tagline) : page.tagline

    useEffect(() => {
        const dismiss = () => {
            setOpen(null)
            setMobileOpen(false)
        }
        document.addEventListener('inertia:navigate', dismiss)
        return () => document.removeEventListener('inertia:navigate', dismiss)
    }, [])
    useEffect(() => {
        const dismiss = (event) => {
            if (event.key === 'Escape') {
                const key = open
                setOpen(null)
                triggers.current[key]?.focus()
            }
            if (event.type === 'pointerdown' && !header.current?.contains(event.target)) setOpen(null)
        }
        document.addEventListener('keydown', dismiss)
        document.addEventListener('pointerdown', dismiss)
        return () => {
            document.removeEventListener('keydown', dismiss)
            document.removeEventListener('pointerdown', dismiss)
        }
    }, [open])
    useEffect(() => {
        if (!darkHeader) return

        let frame = null
        const update = () => {
            if (frame !== null) return
            frame = window.requestAnimationFrame(() => {
                frame = null
                const hero = document.querySelector('.m-hero')
                const headerHeight = header.current?.getBoundingClientRect().height || 80
                const overDarkHero = Boolean(hero && hero.getBoundingClientRect().bottom > headerHeight)
                setHeaderOnDark((current) => (current === overDarkHero ? current : overDarkHero))
            })
        }

        update()
        window.addEventListener('scroll', update, { passive: true })
        window.addEventListener('resize', update, { passive: true })
        return () => {
            window.removeEventListener('scroll', update)
            window.removeEventListener('resize', update)
            if (frame !== null) window.cancelAnimationFrame(frame)
        }
    }, [darkHeader])

    return (
        <div
            className={`marketing-site ${darkHeader && headerOnDark ? 'm-site-hero-dark' : ''}`}
            data-motion-paused={motionPaused}
            data-header-tone={darkHeader && headerOnDark ? 'dark' : 'light'}
        >
            <a href="#main-content" className="m-skip-link">
                {t('nav.skip', 'Skip to content')}
            </a>
            {setting('announcement') && (
                <div className="m-announcement">
                    <MLink href={setting('announcement_url', '/pricing')}>
                        <Sparkles size={13} />
                        <span>{setting('announcement')}</span>
                        <ArrowUpRight size={14} />
                    </MLink>
                </div>
            )}
            <header
                ref={header}
                className="m-header"
                onBlur={(event) => {
                    if (!event.currentTarget.contains(event.relatedTarget)) setOpen(null)
                }}
            >
                <div className="m-header-row m-container">
                    <MLink href="/" className="m-logo" aria-label="WisperBot home">
                        <img src="/wisperbot-icon-512.png" width="34" height="34" alt="" />
                        <span>
                            wisperbot<span className="m-logo-dot">.</span>
                        </span>
                    </MLink>
                    <nav className="m-desktop-nav" aria-label={t('nav.primary', 'Main navigation')}>
                        {Object.keys(groups).map((key) => (
                            <button
                                key={key}
                                ref={(node) => {
                                    triggers.current[key] = node
                                }}
                                aria-expanded={open === key}
                                aria-controls={'m-menu-' + key}
                                onClick={() => setOpen(open === key ? null : key)}
                                className={open === key ? 'is-open' : ''}
                            >
                                {labels[key]}
                                <ChevronDown size={12} />
                            </button>
                        ))}
                        <MLink href="/developers">{t('nav.developers', 'Developers')}</MLink>
                        <MLink href="/pricing">{t('nav.pricing', 'Pricing')}</MLink>
                    </nav>
                    <div className="m-header-actions">
                        {hasDownloads && (
                            <button
                                ref={(node) => {
                                    triggers.current.download = node
                                }}
                                className="m-download-trigger"
                                aria-expanded={open === 'download'}
                                aria-controls="m-menu-download"
                                onClick={() => setOpen(open === 'download' ? null : 'download')}
                            >
                                <Download size={15} />
                                <span>{t('nav.download', 'Download')}</span>
                                <ChevronDown size={12} />
                            </button>
                        )}
                        <MLink href={auth?.user ? '/app/dashboard' : loginHref} className="m-login">
                            {auth?.user ? t('nav.workspace', 'Workspace') : setting('signin_label', 'Log in')}
                        </MLink>
                        <MButton href={startHref}>{setting('getstarted_label', 'Start free')}</MButton>
                        <button
                            className="m-mobile-trigger"
                            onClick={() => setMobileOpen(true)}
                            aria-label={t('nav.open', 'Open navigation')}
                        >
                            <Menu size={22} />
                        </button>
                    </div>
                </div>
                {Object.keys(groups).map((key) => (
                    <div
                        key={key}
                        id={'m-menu-' + key}
                        hidden={open !== key}
                        className={'m-mega-menu m-container ' + (key === 'channels' ? 'm-mega-channels' : '')}
                    >
                        <div className="m-mega-inner">
                            <div className="m-mega-links">
                                <span className="m-menu-heading">{labels[key]}</span>
                                <div className="m-mega-grid">
                                    {groups[key].map((page) => (
                                        <MLink
                                            key={page.href}
                                            href={page.href}
                                            className="m-mega-item"
                                            onClick={() => setOpen(null)}
                                        >
                                            <span className="m-icon-tile">
                                                <ProductIcon name={page.icon} size={20} />
                                            </span>
                                            <span>
                                                <strong>{pageName(page)}</strong>
                                                <small>{pageTagline(page)}</small>
                                            </span>
                                            <ArrowUpRight size={15} />
                                        </MLink>
                                    ))}
                                </div>
                                {key === 'channels' && (
                                    <div className="m-menu-capabilities">
                                        <span>WhatsApp</span>
                                        <span>Instagram</span>
                                        <span>Gmail</span>
                                        <span>Shopify</span>
                                        <MLink href="/integrations">
                                            {t('nav.all_integrations', 'See all integrations')}
                                            <ArrowUpRight size={14} />
                                        </MLink>
                                    </div>
                                )}
                            </div>
                            <aside className="m-mega-feature">
                                <span className="m-mega-spark">
                                    <Sparkles size={32} />
                                </span>
                                <strong>
                                    {t('nav.feature_title', 'A helpful first answer. A human when it matters.')}
                                </strong>
                                <p>{t('nav.feature_body', 'See how your knowledge, AI, and team work together.')}</p>
                                <MLink href="/products/smart-ai-agent" onClick={() => setOpen(null)}>
                                    {t('nav.feature_cta', 'Meet your Smart Bot')}
                                    <ArrowUpRight size={15} />
                                </MLink>
                            </aside>
                        </div>
                    </div>
                ))}
                {hasDownloads && (
                    <div id="m-menu-download" hidden={open !== 'download'} className="m-download-menu">
                        <span className="m-menu-heading">
                            {t('downloads.heading', 'Keep the conversation with you')}
                        </span>
                        <DownloadLinks />
                        <p>{t('downloads.note', 'Agent apps for your team. Chat SDK for your app.')}</p>
                    </div>
                )}
            </header>
            <Dialog open={mobileOpen} onClose={setMobileOpen} className="m-mobile-dialog">
                <div className="m-mobile-backdrop" aria-hidden="true" />
                <DialogPanel className="m-mobile-panel">
                    <div className="m-mobile-title">
                        <DialogTitle>{t('nav.menu', 'Explore WisperBot')}</DialogTitle>
                        <button onClick={() => setMobileOpen(false)} aria-label={t('nav.close', 'Close navigation')}>
                            <X size={22} />
                        </button>
                    </div>
                    {Object.entries(groups).map(([key, entries]) => (
                        <details key={key}>
                            <summary>
                                {labels[key]}
                                <ChevronDown size={16} />
                            </summary>
                            {entries.map((page) => (
                                <MLink key={page.href} href={page.href} onClick={() => setMobileOpen(false)}>
                                    <ProductIcon name={page.icon} size={17} />
                                    {pageName(page)}
                                </MLink>
                            ))}
                        </details>
                    ))}
                    <MLink href="/developers">{t('nav.developers', 'Developers')}</MLink>
                    <MLink href="/pricing">{t('nav.pricing', 'Pricing')}</MLink>
                    <DownloadLinks compact />
                    <MLink href={auth?.user ? '/app/dashboard' : loginHref}>
                        {auth?.user ? t('nav.workspace', 'Workspace') : setting('signin_label', 'Log in')}
                    </MLink>
                    <MButton href={startHref}>{setting('getstarted_label', 'Start free')}</MButton>
                </DialogPanel>
            </Dialog>
            <main id="main-content">{children}</main>
            <footer className="m-footer">
                <div className="m-container">
                    <div className="m-footer-top">
                        <div className="m-footer-brand">
                            <MLink href="/" className="m-logo">
                                <img src="/wisperbot-icon-512.png" width="34" height="34" alt="" />
                                <span>
                                    wisperbot<span className="m-logo-dot">.</span>
                                </span>
                            </MLink>
                            <p>{t('footer.promise', 'Every customer channel. One AI support team.')}</p>
                            <MButton href={startHref}>
                                {t('footer.start', 'Make room for better conversations')}
                            </MButton>
                        </div>
                        {[
                            [t('nav.products', 'Products'), products],
                            [t('nav.solutions', 'Solutions'), solutions],
                            [
                                t('nav.resources', 'Resources'),
                                [
                                    ...resources,
                                    { href: '/developers', name: t('nav.developers', 'Developers') },
                                    { href: '/pricing', name: t('nav.pricing', 'Pricing') },
                                ],
                            ],
                        ].map(([name, entries]) => (
                            <div className="m-footer-column" key={name}>
                                <h2>{name}</h2>
                                {entries.map((entry) => (
                                    <MLink key={entry.href} href={entry.href}>
                                        {pageName(entry)}
                                    </MLink>
                                ))}
                            </div>
                        ))}
                    </div>
                    <div className="m-footer-bottom">
                        <span>© {new Date().getFullYear()} WisperBot.</span>
                        <div>
                            <MLink href="/p/privacy">{t('footer.privacy', 'Privacy')}</MLink>
                            <MLink href="/p/terms">{t('footer.terms', 'Terms')}</MLink>
                            <MLink href="/about#trust">{t('footer.trust', 'Trust & access')}</MLink>
                            <button
                                type="button"
                                className="m-motion-toggle"
                                aria-pressed={motionPaused}
                                onClick={() => setMotionPaused((paused) => !paused)}
                            >
                                {motionPaused
                                    ? t('footer.resume_motion', 'Resume animations')
                                    : t('footer.pause_motion', 'Pause animations')}
                            </button>
                            {locales.length > 1 && (
                                <label className="m-locale">
                                    <Globe2 size={14} />
                                    <span className="sr-only">{t('footer.language', 'Language')}</span>
                                    <select value={locale} onChange={(e) => setLocale(e.target.value)}>
                                        {locales.map(([code, label]) => (
                                            <option key={code} value={code}>
                                                {label}
                                            </option>
                                        ))}
                                    </select>
                                </label>
                            )}
                        </div>
                    </div>
                </div>
            </footer>
        </div>
    )
}
