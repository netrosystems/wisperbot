import { useEffect, useRef, useState } from 'react'
import {
    ArrowUpRight,
    Check,
    CheckCheck,
    ChevronRight,
    Code2,
    Globe2,
    MessageCircle,
    Smartphone,
    ShieldCheck,
    Sparkles,
    Tag,
    Users,
    Workflow,
    Zap,
} from 'lucide-react'
import LandingLayout from '@/Layouts/LandingLayout'
import SeoHead from '@/Components/SeoHead'
import { Reveal } from '@/Components/Reveal'
import HeroBackdrop from '@/Components/marketing/HeroBackdrop'
import HeroHeadline, { HeroLaunch } from '@/Components/marketing/HeroHeadline'
import {
    MButton,
    MLink,
    Eyebrow,
    SectionHeading,
    CheckList,
    FAQList,
    FinalCTA,
    DownloadLinks,
    ProductIcon,
    getFaqs,
    useMarketing,
} from '@/Components/marketing/MarketingUI'
import {
    MotionStage,
    BrandMark,
    ProductDemo,
    InboxDemo,
    ChatDemo,
    AIDemo,
    AutomationDemo,
    MobileDemo,
    EmailDemo,
    SocialDemo,
    CommerceDemo,
    DeveloperDemo,
} from '@/Components/marketing/MarketingDemos'

function GooglePlayIcon({ className = '' }) {
    return (
        <span className={`m-store-mark is-google-play ${className}`.trim()} aria-hidden="true">
            <svg viewBox="0 0 24 24">
                <path d="M22.018 13.298 18.1 15.516l-3.516-3.493 3.543-3.521 3.891 2.202a1.49 1.49 0 0 1 0 2.594ZM1.337.924a1.486 1.486 0 0 0-.112.568v21.017c0 .217.045.419.124.6l11.155-11.087L1.337.924Zm12.207 10.065 3.258-3.238L3.45.195a1.466 1.466 0 0 0-.946-.179l11.04 10.973Zm0 2.067-11 10.933c.298.036.612-.016.906-.183l13.324-7.54-3.23-3.21Z" />
            </svg>
        </span>
    )
}

function AppStoreIcon({ className = '' }) {
    return (
        <span className={`m-store-mark is-app-store ${className}`.trim()} aria-hidden="true">
            <svg viewBox="0 0 24 24">
                <path d="m8.81 14.92 6.11-11.04c.08-.15.17-.3.24-.46.07-.14.13-.29.17-.44.08-.33.06-.67-.07-.97a1.48 1.48 0 0 0-.62-.74 1.42 1.42 0 0 0-.92-.19c-.32.04-.61.2-.84.43-.11.11-.2.24-.29.37-.09.15-.17.3-.26.45l-.38.7-.39-.7c-.08-.15-.17-.3-.26-.45a2.1 2.1 0 0 0-.28-.37c-.23-.23-.52-.39-.84-.43a1.42 1.42 0 0 0-.92.19c-.28.17-.5.43-.62.74-.13.3-.15.64-.07.97.04.15.1.3.17.44.07.16.16.31.24.46l1.25 2.25-4.86 8.79H2.03c-.17 0-.34 0-.5.01-.16.01-.3.03-.45.07-.31.09-.58.28-.78.55-.2.27-.3.59-.3.93s.1.66.3.93c.2.27.47.45.78.54.15.05.3.07.45.08.16.01.33 0 .5 0H15.13c.02-.04.06-.13.1-.27.42-1.42-.61-2.84-2.03-2.84H8.81Zm-5.7 3.62-.79 1.5c-.08.16-.16.31-.24.47-.06.15-.12.3-.16.45-.08.34-.06.69.06 1.01.12.32.34.58.61.75.27.18.59.24.9.2.32-.04.6-.2.83-.44.1-.11.2-.24.28-.38.09-.15.17-.3.25-.46L6 19.46c-.09-.15-.95-1.47-2.89-.92Zm20.59-3a1.47 1.47 0 0 0-.78-.54c-.15-.05-.3-.07-.45-.08-.17-.01-.34 0-.5 0h-3.32l-4.39-7.82c-.67.7-.96 1.49-1.08 2.2-.16 1.03.04 2.09.55 3l5.27 9.39c.09.15.17.3.26.44.09.13.18.26.29.37.23.23.52.38.85.42.32.05.64-.02.92-.19.28-.16.5-.42.62-.72.13-.31.15-.65.07-.97-.04-.15-.1-.29-.17-.43-.07-.16-.16-.31-.24-.46l-1.22-2.16h1.6c.16 0 .33 0 .5-.01.15-.01.3-.03.45-.07.31-.09.58-.28.78-.54.2-.27.3-.59.3-.93s-.1-.66-.3-.93Z" />
            </svg>
        </span>
    )
}

export function AgentAppMenu({ iosUrl, androidUrl, label = 'Get Agent App' }) {
    const { text: t } = useMarketing()
    const [open, setOpen] = useState(false)
    const root = useRef(null)
    const trigger = useRef(null)

    useEffect(() => {
        if (!open) return undefined
        const dismiss = (event) => {
            if (event.type === 'keydown' && event.key === 'Escape') {
                setOpen(false)
                trigger.current?.focus()
            }
            if (event.type === 'pointerdown' && !root.current?.contains(event.target)) setOpen(false)
        }
        document.addEventListener('keydown', dismiss)
        document.addEventListener('pointerdown', dismiss)
        return () => {
            document.removeEventListener('keydown', dismiss)
            document.removeEventListener('pointerdown', dismiss)
        }
    }, [open])

    return (
        <div className="m-agent-app-menu" ref={root}>
            <button
                ref={trigger}
                type="button"
                className="m-button m-button-secondary"
                onClick={() => setOpen((current) => !current)}
                aria-expanded={open}
                aria-controls="hero-agent-app-menu"
                aria-haspopup="menu"
            >
                <Smartphone size={17} aria-hidden="true" />
                {t('home.get_agent_app', label)}
                <ChevronRight className={open ? 'is-open' : ''} size={14} aria-hidden="true" />
            </button>
            <div id="hero-agent-app-menu" role="menu" hidden={!open} className="m-agent-app-popover">
                <a href={androidUrl} target="_blank" rel="noopener noreferrer" role="menuitem" onClick={() => setOpen(false)}>
                    <GooglePlayIcon />
                    <strong>Android</strong>
                    <small>{t('downloads.google_play', 'Google Play')}</small>
                </a>
                <a href={iosUrl} target="_blank" rel="noopener noreferrer" role="menuitem" onClick={() => setOpen(false)}>
                    <AppStoreIcon />
                    <strong>iOS</strong>
                    <small>{t('downloads.app_store', 'App Store')}</small>
                </a>
            </div>
        </div>
    )
}

function PlatformTabs() {
    const { text: t } = useMarketing()
    const [selected, setSelected] = useState(0)
    const items = [
        [
            'inbox',
            'Omni Inbox',
            'A clear home for every customer conversation.',
            'Read the context, take ownership, and keep the reply moving. Your channels and your team, working together.',
            '/products/omnichannel-inbox',
            'inbox',
        ],
        [
            'ai',
            'Smart AI Agent',
            'Your business knowledge, ready to help.',
            'Connect a Knowledge Base, test the answers, and let your Smart Bot guide customers toward a useful next step.',
            '/products/smart-ai-agent',
            'sparkles',
        ],
        [
            'automation',
            'Automations',
            'Make the next step happen naturally.',
            'Build visual workflows around the way your team works. Route, respond, and follow up with supported actions.',
            '/products/automation',
            'workflow',
        ],
        [
            'social',
            'Social Media',
            'A shared rhythm for your social presence.',
            'Prepare and schedule content for supported accounts, with publishing status and provider-aware controls.',
            '/products/social-media',
            'share',
        ],
        [
            'commerce',
            'Commerce',
            'The context behind the conversation.',
            'Bring supported store products, orders, and seller workflows closer to the people helping your customers.',
            '/solutions/ecommerce-marketplaces',
            'store',
        ],
    ]
    const [demo, , title, body, href] = items[selected]
    return (
        <>
            <div role="tablist" aria-label={t('home.product_tabs', 'Explore the platform')} className="m-tabs">
                {items.map(([id, label, , , , icon], i) => (
                    <button
                        key={id}
                        role="tab"
                        id={'product-tab-' + id}
                        aria-selected={selected === i}
                        aria-controls="product-panel"
                        tabIndex={selected === i ? 0 : -1}
                        onClick={() => setSelected(i)}
                        onKeyDown={(e) => {
                            if (['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(e.key)) {
                                e.preventDefault()
                                const next =
                                    e.key === 'Home'
                                        ? 0
                                        : e.key === 'End'
                                          ? items.length - 1
                                          : (i + (e.key === 'ArrowRight' ? 1 : -1) + items.length) % items.length
                                setSelected(next)
                                document.getElementById('product-tab-' + items[next][0])?.focus()
                            }
                        }}
                    >
                        <ProductIcon name={icon} size={15} />
                        {t('home.tabs.' + id, label)}
                    </button>
                ))}
            </div>
            <div
                role="tabpanel"
                id="product-panel"
                aria-labelledby={'product-tab-' + demo}
                className="m-product-switcher"
            >
                <div className="m-switcher-copy">
                    <h3>{t('home.tabs.' + demo + '_title', title)}</h3>
                    <p>{t('home.tabs.' + demo + '_body', body)}</p>
                    <MLink href={href} className="m-text-link">
                        {t('actions.take_look', 'Take a closer look')}
                        <ArrowUpRight size={16} />
                    </MLink>
                </div>
                <div key={demo}>
                    <ProductDemo type={demo} compact />
                </div>
            </div>
        </>
    )
}
function Lifecycle() {
    const { text: t } = useMarketing()
    const ref = useRef(null)
    const [active, setActive] = useState(0)
    const steps = [
        [
            'connect',
            'Connect the places customers already are.',
            'Bring supported messaging channels, email, and commerce accounts into your workspace.',
            'inbox',
        ],
        [
            'understand',
            'Give AI your business knowledge.',
            'Add your sources, review what matters, and test how your Smart Bot answers.',
            'ai',
        ],
        [
            'respond',
            'Make the next question feel easy.',
            'Help customers in their own words, with meaningful reply choices when useful.',
            'chat',
        ],
        [
            'handoff',
            'Bring in the right person.',
            'Keep the conversation history and give a teammate clear ownership.',
            'mobile',
        ],
        [
            'improve',
            'See what needs your attention.',
            'Review conversation activity, AI usage, campaign status, and automation runs.',
            'automation',
        ],
    ]
    useEffect(() => {
        if (typeof window.IntersectionObserver === 'undefined') return
        const io = new window.IntersectionObserver(
            (entries) => {
                const current = entries
                    .filter((e) => e.isIntersecting)
                    .sort((a, b) => b.intersectionRatio - a.intersectionRatio)[0]
                if (current) setActive(Number(current.target.dataset.step))
            },
            { rootMargin: '-20% 0px -35% 0px', threshold: [0, 0.3, 0.7] },
        )
        ref.current?.querySelectorAll('[data-step]').forEach((node) => io.observe(node))
        return () => io.disconnect()
    }, [])
    return (
        <section className="m-section m-container">
            <div className="m-lifecycle" ref={ref}>
                <div className="m-lifecycle-copy">
                    <SectionHeading
                        eyebrow={t('home.lifecycle.eyebrow', 'Connected from hello to handled')}
                        title={t('home.lifecycle.title', 'One conversation. A whole team behind it.')}
                        description={t(
                            'home.lifecycle.description',
                            'Give every message a path forward, with AI and people working from the same context.',
                        )}
                    />
                    <div className="m-lifecycle-steps">
                        {steps.map(([id, title, body], i) => (
                            <div
                                key={id}
                                data-step={i}
                                className={'m-lifecycle-step ' + (active === i ? 'is-active' : '')}
                            >
                                <small>0{i + 1}</small>
                                <h3>{t('home.lifecycle.' + id, title)}</h3>
                                <p>{t('home.lifecycle.' + id + '_body', body)}</p>
                            </div>
                        ))}
                    </div>
                </div>
                <div className="m-lifecycle-stage">
                    <ProductDemo type={steps[active][3]} compact />
                    <p className="m-stage-note">
                        {t('home.lifecycle.note', 'Illustrative workflow · your channels, your knowledge, your team')}
                    </p>
                </div>
            </div>
        </section>
    )
}
function CapabilityTable() {
    const { commentsEnabled, text: t } = useMarketing()
    const rows = [
        ['website', 'Website chat', true, false, false, 'Website embed'],
        ['whatsapp', 'WhatsApp', true, false, false, 'Templates & business messaging'],
        ['facebook', 'Facebook / Instagram', true, true, Boolean(commentsEnabled), 'Access depends on permissions'],
        ['linkedin', 'LinkedIn / X / TikTok / YouTube', false, true, false, 'Supported publishing formats'],
        ['gmail', 'Gmail / Microsoft / IMAP', false, false, false, 'Dedicated Email MasterBox'],
        ['shopify', 'Shopify / WooCommerce / BigCommerce', false, false, false, 'Product & order context'],
        ['amazon', 'Amazon / eBay', false, false, false, 'Provider-specific seller workflows'],
    ]
    return (
        <>
            <div className="m-capability-wrap">
                <table className="m-capability-table">
                    <thead>
                        <tr>
                            {['Connection', 'Omni Inbox', 'Publishing', 'Comments', 'Purpose'].map((label, i) => (
                                <th key={label} scope="col">
                                    {t('home.matrix.head_' + i, label)}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        {rows.map(([key, name, inbox, publishing, comments, purpose], i) => (
                            <tr key={key}>
                                <td>
                                    <BrandMark name={key} small />
                                    {name}
                                </td>
                                {[inbox, publishing, comments].map((available, j) => (
                                    <td key={j}>
                                        {available ? (
                                            <>
                                                <Check size={15} />
                                                <span className="sr-only">{t('common.supported', 'Supported')}</span>
                                            </>
                                        ) : (
                                            <span
                                                aria-label={t('common.not_listed', 'Not included in this capability')}
                                            >
                                                —
                                            </span>
                                        )}
                                    </td>
                                ))}
                                <td>{t('home.matrix.purpose_' + i, purpose)}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
            <p className="m-capability-note">
                {t(
                    'home.matrix.note',
                    'Capabilities vary by account, provider permissions, region, and plan. Publishing access does not automatically include direct messages or comments.',
                )}
            </p>
        </>
    )
}
function RoleTabs() {
    const { pages = [], text: t } = useMarketing()
    const [selected, setSelected] = useState(0)
    const roles = pages.filter((p) => p.slug.startsWith('solutions/'))
    const page = roles[selected]
    if (!page) return null
    return (
        <div className="m-roles">
            <div
                className="m-role-nav"
                role="tablist"
                aria-label={t('home.roles.label', 'Choose your team')}
                aria-orientation="vertical"
            >
                {roles.map((role, i) => (
                    <button
                        role="tab"
                        id={'role-tab-' + i}
                        aria-controls="role-panel"
                        aria-selected={selected === i}
                        tabIndex={selected === i ? 0 : -1}
                        key={role.slug}
                        onClick={() => setSelected(i)}
                        onKeyDown={(e) => {
                            if (['ArrowDown', 'ArrowUp', 'ArrowRight', 'ArrowLeft'].includes(e.key)) {
                                e.preventDefault()
                                const next =
                                    (i + (['ArrowDown', 'ArrowRight'].includes(e.key) ? 1 : -1) + roles.length) %
                                    roles.length
                                setSelected(next)
                                document.getElementById('role-tab-' + next)?.focus()
                            }
                        }}
                    >
                        <ProductIcon name={role.icon} size={19} />
                        {t('catalog.' + role.slug.replaceAll('/', '.') + '.name', role.name)}
                        <ChevronRight size={16} />
                    </button>
                ))}
            </div>
            <div role="tabpanel" id="role-panel" aria-labelledby={'role-tab-' + selected} className="m-role-detail">
                <h3>{t('catalog.' + page.slug.replaceAll('/', '.') + '.tagline', page.tagline)}</h3>
                <p>{t('catalog.' + page.slug.replaceAll('/', '.') + '.description', page.description)}</p>
                <MLink href={page.href} className="m-text-link">
                    {t('home.roles.explore', 'Explore this solution')}
                    <ArrowUpRight size={16} />
                </MLink>
            </div>
        </div>
    )
}
export default function Welcome({ latestPosts = [] }) {
    const { setting, startHref, settings, integrations = [], text: t, commentsEnabled, freeWhiteLabel } = useMarketing()
    const title = setting('hero_title', 'Every customer channel. One AI support team.')
    const configuredSecondaryLabel = setting('hero_cta_secondary', 'Get Agent App')
    const agentAppLabel = configuredSecondaryLabel === 'Explore the platform' ? 'Get Agent App' : configuredSecondaryLabel
    const faqs = getFaqs(settings)
    const proofItems = integrations.filter((i) =>
        [
            'whatsapp',
            'facebook',
            'instagram',
            'telegram',
            'gmail',
            'shopify',
            'linkedin',
            'x',
            'tiktok',
            'youtube',
            'microsoft',
            'amazon',
        ].includes(i.key),
    )
    const guides = latestPosts.length
        ? latestPosts.slice(0, 3).map((p) => ({
              href: '/blog/' + p.slug,
              title: p.title,
              body: p.excerpt,
              image: p.featured_image_url,
              category: p.category?.name || 'Guide',
              icon: 'book',
          }))
        : [
              {
                  href: '/products/smart-ai-agent',
                  title: 'Give your AI a business to understand.',
                  body: 'Explore the path from your knowledge to a useful customer answer.',
                  category: 'Smart AI',
                  icon: 'sparkles',
              },
              {
                  href: '/channels/customer-messaging',
                  title: 'Choose the right channels for your team.',
                  body: 'See how messaging, email, and provider permissions fit together.',
                  category: 'Getting connected',
                  icon: 'network',
              },
              {
                  href: '/developers',
                  title: 'Put a conversation inside your product.',
                  body: 'Explore the Customer Chat SDK, authenticated APIs, and webhooks.',
                  category: 'For developers',
                  icon: 'code',
              },
          ]
    return (
        <LandingLayout darkHeader>
            <SeoHead
                title={setting('seo_title')}
                description={setting('seo_description')}
                keywords={setting('seo_keywords')}
                image={setting('seo_og_image') || undefined}
                jsonLd={{
                    '@context': 'https://schema.org',
                    '@type': 'SoftwareApplication',
                    name: 'WisperBot',
                    applicationCategory: 'BusinessApplication',
                    operatingSystem: 'Web, Android, iOS',
                    description: setting('seo_description'),
                }}
            />
            <section className="m-hero">
                <HeroLaunch />
                <HeroBackdrop />
                <div className="m-container">
                    <div className="m-hero-intro">
                        <span className="m-hero-badge">
                            <Sparkles size={12} />
                            <span>{t('home.free', 'FREE')}</span>
                            {t('home.hero_badge', 'Your brand. Your chatbot. 100 AI credits every month.')}
                        </span>
                        <HeroHeadline title={title} />
                        <p className="m-hero-lead">
                            {setting(
                                'hero_subtitle',
                                'Bring conversations, knowledge, and your people together. Let AI handle the first hello. Give your team everything they need for what comes next.',
                            )}
                        </p>
                        <div className="m-actions">
                            <MButton href={startHref}>{setting('hero_cta_primary', 'Start free')}</MButton>
                            <AgentAppMenu
                                label={agentAppLabel}
                                androidUrl={setting('agent_app_android_url', 'https://play.google.com/store/apps/details?id=com.wisperbot.app&pcampaignid=web_share')}
                                iosUrl={setting('agent_app_ios_url', 'https://apps.apple.com/ng/app/wisperbot/id6797157205')}
                            />
                        </div>
                        <div className="m-hero-footnote">
                            <span>
                                <Check size={10} />
                                {t('home.no_card', 'No credit card')}
                            </span>
                            <span>
                                <Check size={10} />
                                {t('home.one_channel', '1 channel included')}
                            </span>
                            <span>
                                <Check size={10} />
                                {freeWhiteLabel
                                    ? t('home.white_label', 'White-label chatbot')
                                    : t('home.branded_chat', 'Customizable website chat')}
                            </span>
                        </div>
                    </div>
                    <MotionStage className="m-hero-stage">
                        <InboxDemo />
                        <p className="m-hero-caption">
                            {t(
                                'home.hero_caption',
                                'AI when it helps. Your people when it matters. All in one workspace.',
                            )}
                        </p>
                    </MotionStage>
                </div>
            </section>
            <section className="m-channel-strip">
                <p>{t('home.channels_caption', 'Connect the places your customers already call home.')}</p>
                <MotionStage className="m-marquee">
                    <div className="m-marquee-track">
                        {[0, 1].map((copy) => (
                            <div
                                key={copy}
                                aria-hidden={copy === 1 ? true : undefined}
                                style={{ display: 'flex', gap: 38 }}
                            >
                                {proofItems.map((item) => (
                                    <span className="m-marquee-item" key={item.key}>
                                        <BrandMark name={item.key} />
                                        {item.name}
                                    </span>
                                ))}
                            </div>
                        ))}
                    </div>
                </MotionStage>
            </section>
            <section id="platform" className="m-section m-container">
                <SectionHeading
                    centered
                    eyebrow={t('home.platform.eyebrow', 'The whole customer conversation')}
                    title={t('home.platform.title', 'A lot less switching. A lot more connecting.')}
                    description={t(
                        'home.platform.body',
                        'Support, AI, email, social, and automations—connected around the people on the other side of the screen.',
                    )}
                />
                <PlatformTabs />
            </section>
            <Lifecycle />
            <section className="m-section m-soft">
                <div className="m-container m-split">
                    <div className="m-split-copy">
                        <SectionHeading
                            eyebrow={t('home.ai.eyebrow', 'Meet your Smart AI Agent')}
                            title={t('home.ai.title', 'An answer that knows where it came from.')}
                            description={t(
                                'home.ai.body',
                                'Turn your website and documents into useful customer support. Your Smart Bot works with the knowledge you give it, asks follow-up questions, and brings in a person when needed.',
                            )}
                        />
                        <CheckList
                            items={[
                                t('home.ai.point1', 'Train on your business Knowledge Base'),
                                t('home.ai.point2', 'Guide the conversation with dynamic reply choices'),
                                t('home.ai.point3', 'Set answering hours, scope, and fallback behavior'),
                            ]}
                        />
                        <MLink href="/products/smart-ai-agent" className="m-text-link">
                            {t('home.ai.link', 'Get to know your AI teammate')}
                            <ArrowUpRight size={16} />
                        </MLink>
                    </div>
                    <AIDemo />
                </div>
            </section>
            <section className="m-section m-container">
                <SectionHeading
                    centered
                    eyebrow={t('home.inboxes.eyebrow', 'A calmer place to work')}
                    title={t('home.inboxes.title', 'Different channels. The same sense of clarity.')}
                    description={t(
                        'home.inboxes.body',
                        'A focused place for instant conversations, and a dedicated space for email. Your team gets the right tools for both.',
                    )}
                />
                <div className="m-product-bento">
                    <Reveal className="m-bento-card">
                        <h3>{t('home.inboxes.omni', 'One team. One Omni Inbox.')}</h3>
                        <p>
                            {t(
                                'home.inboxes.omni_body',
                                'Customer context, team ownership, notes, and replies. Keep the conversation moving without losing the thread.',
                            )}
                        </p>
                        <MLink href="/products/omnichannel-inbox" className="m-text-link">
                            {t('home.inboxes.omni_link', 'Explore Omni Inbox')}
                            <ArrowUpRight size={16} />
                        </MLink>
                        <InboxDemo compact />
                    </Reveal>
                    <Reveal className="m-bento-card email" delay={90}>
                        <h3>{t('home.inboxes.email', 'Email, with room to breathe.')}</h3>
                        <p>
                            {t(
                                'home.inboxes.email_body',
                                'Connect Gmail, Microsoft, and IMAP mailboxes. Read and reply in Email MasterBox, built around the way email works.',
                            )}
                        </p>
                        <MLink href="/products/email-masterbox" className="m-text-link">
                            {t('home.inboxes.email_link', 'Explore Email MasterBox')}
                            <ArrowUpRight size={16} />
                        </MLink>
                        <EmailDemo />
                    </Reveal>
                </div>
            </section>
            <section className="m-section m-container m-border-top">
                <SectionHeading
                    eyebrow={t('home.surfaces.eyebrow', 'Your brand, in the conversation')}
                    title={t('home.surfaces.title', 'Meet them on your website. Stay with them in your app.')}
                    description={t(
                        'home.surfaces.body',
                        'A small, thoughtfully designed chat experience can make your entire business feel easier to reach.',
                    )}
                />
                <div className="m-surface-grid">
                    <ChatDemo />
                    <div className="m-surface-list">
                        {[
                            [
                                'messages',
                                'Website chatbot',
                                'A branded welcome, AI answers, and a clear path to a person.',
                                '/products/chatbots',
                            ],
                            [
                                'phone',
                                'WhatsApp chatbot',
                                'Make WhatsApp easy to reach and automate eligible connected conversations.',
                                '/products/chatbots',
                            ],
                            [
                                'code',
                                'Customer Chat SDK',
                                'Give your app team a Flutter integration for customer conversations.',
                                '/developers',
                            ],
                        ].map(([icon, name, body, href], i) => (
                            <MLink href={href} className="m-surface-item" key={name}>
                                <span className="m-icon-tile">
                                    <ProductIcon name={icon} size={23} />
                                </span>
                                <div>
                                    <h3>{t('home.surfaces.name_' + i, name)}</h3>
                                    <p>{t('home.surfaces.body_' + i, body)}</p>
                                </div>
                                <ArrowUpRight size={17} />
                            </MLink>
                        ))}
                    </div>
                </div>
            </section>
            <section className="m-section m-soft">
                <div className="m-container m-split reverse">
                    <div className="m-split-copy">
                        <SectionHeading
                            eyebrow={t('home.mobile.eyebrow', 'Good support goes with you')}
                            title={t('home.mobile.title', 'Be there, even when you are not at your desk.')}
                            description={t(
                                'home.mobile.body',
                                'The WisperBot Agent App gives your people a dedicated Android and iOS experience for customer conversations. See the right workspace, get notified, and keep helping.',
                            )}
                        />
                        <CheckList
                            items={[
                                t('home.mobile.point1', 'Workspace-aware notifications'),
                                t('home.mobile.point2', 'Customer conversation context'),
                                t('home.mobile.point3', 'Agent access on Android and iOS'),
                            ]}
                        />
                        <div className="m-actions">
                            <DownloadLinks />
                        </div>
                        <MLink href="/products/mobile-agent-app" className="m-text-link">
                            {t('home.mobile.link', 'Explore the Agent App')}
                            <ArrowUpRight size={16} />
                        </MLink>
                    </div>
                    <MobileDemo />
                </div>
            </section>
            <section className="m-section m-container">
                <div className="m-split">
                    <div className="m-split-copy">
                        <SectionHeading
                            eyebrow={t('home.flows.eyebrow', 'Build the follow-through')}
                            title={t('home.flows.title', 'Your best process. On repeat.')}
                            description={t(
                                'home.flows.body',
                                'Connect triggers, conditions, and actions on a visual canvas. Let repeatable work take care of itself while your team takes care of people.',
                            )}
                        />
                        <MLink href="/products/automation" className="m-text-link">
                            {t('home.flows.link', 'Explore visual automations')}
                            <ArrowUpRight size={16} />
                        </MLink>
                    </div>
                    <AutomationDemo />
                </div>
                <div className="m-mini-features">
                    {[
                        [Workflow, 'Visual workflows', 'See the path from event to action.'],
                        [Tag, 'Contacts & segments', 'Organize the people you need to reach.'],
                        [CheckCheck, 'Run visibility', 'Review what happened and what needs attention.'],
                    ].map(([Icon, title, body], i) => (
                        <div key={title}>
                            <Icon size={21} />
                            <h3>{t('home.flows.feature_' + i, title)}</h3>
                            <p>{t('home.flows.feature_body_' + i, body)}</p>
                        </div>
                    ))}
                </div>
            </section>
            <section className="m-section m-container m-border-top">
                <div className="m-split reverse">
                    <div className="m-split-copy">
                        <SectionHeading
                            eyebrow={t('home.social.eyebrow', 'Stay part of the conversation')}
                            title={t('home.social.title', 'From the first post to the next customer.')}
                            description={t(
                                'home.social.body',
                                'Plan your content, schedule supported posts, and keep a shared view of your social presence. Provider-aware controls keep the right actions within reach.',
                            )}
                        />
                        {commentsEnabled && (
                            <p className="m-capability-note">
                                {t(
                                    'home.social.comments',
                                    'Manage supported Meta comments with additional account permissions. Public replies stay separate from private inbox conversations.',
                                )}
                            </p>
                        )}
                        <MLink href="/products/social-media" className="m-text-link">
                            {t('home.social.link', 'Explore social media automation')}
                            <ArrowUpRight size={16} />
                        </MLink>
                    </div>
                    <SocialDemo />
                </div>
            </section>
            <section className="m-section m-soft">
                <div className="m-container m-split">
                    <div className="m-split-copy">
                        <SectionHeading
                            eyebrow={t('home.commerce.eyebrow', 'Know the story behind the order')}
                            title={t('home.commerce.title', 'A better answer starts with better context.')}
                            description={t(
                                'home.commerce.body',
                                'Connect supported stores and seller accounts. Give your team product and order context for more useful shopping conversations, with each marketplace handled on its own terms.',
                            )}
                        />
                        <MLink href="/solutions/ecommerce-marketplaces" className="m-text-link">
                            {t('home.commerce.link', 'Explore commerce connections')}
                            <ArrowUpRight size={16} />
                        </MLink>
                    </div>
                    <CommerceDemo />
                </div>
            </section>
            <section className="m-section m-container">
                <div className="m-dark-section">
                    <div className="m-engagement-grid">
                        <div>
                            <SectionHeading
                                eyebrow={t('home.engagement.eyebrow', 'Keep the relationship growing')}
                                title={t('home.engagement.title', 'Reach the right people. With a reason to reply.')}
                                description={t(
                                    'home.engagement.body',
                                    'Build useful audience segments and connect them to supported WhatsApp templates, SMS campaigns, and workflow follow-ups.',
                                )}
                            />
                            <MLink href="/solutions/marketing-engagement" className="m-text-link">
                                {t('home.engagement.link', 'Explore marketing & engagement')}
                                <ArrowUpRight size={16} />
                            </MLink>
                        </div>
                        <MotionStage className="m-segment-card">
                            <span>
                                <Users size={17} />
                                {t('home.engagement.audience', 'YOUR AUDIENCE')}
                            </span>
                            <h3>{t('home.engagement.card_title', 'A more thoughtful follow-up.')}</h3>
                            <div className="m-segment-rows">
                                <div>
                                    <Tag size={15} />
                                    {t('home.engagement.row1', 'Choose a contact segment')}
                                    <span>01</span>
                                </div>
                                <div>
                                    <MessageCircle size={15} />
                                    {t('home.engagement.row2', 'Use an approved template')}
                                    <span>02</span>
                                </div>
                                <div>
                                    <Zap size={15} />
                                    {t('home.engagement.row3', 'Connect the next action')}
                                    <span>03</span>
                                </div>
                            </div>
                            <div className="m-segment-tags">
                                <span>WhatsApp</span>
                                <span>SMS</span>
                                <span>{t('home.engagement.workflows', 'Workflows')}</span>
                            </div>
                        </MotionStage>
                    </div>
                </div>
            </section>
            <section className="m-section m-container">
                <SectionHeading
                    centered
                    eyebrow={t('home.matrix.eyebrow', 'The right connection for the job')}
                    title={t('home.matrix.title', 'One platform does not mean one-size-fits-all.')}
                    description={t(
                        'home.matrix.body',
                        'Messaging, publishing, email, and commerce each do something different. Here is how they fit into WisperBot.',
                    )}
                />
                <CapabilityTable />
                <div className="m-actions" style={{ justifyContent: 'center' }}>
                    <MButton href="/integrations" secondary>
                        {t('home.matrix.link', 'Find your integrations')}
                    </MButton>
                </div>
            </section>
            <section className="m-section m-soft">
                <div className="m-container">
                    <SectionHeading
                        eyebrow={t('home.roles.eyebrow', 'Built around your team')}
                        title={t('home.roles.title', 'Different goals. Better conversations.')}
                        description={t(
                            'home.roles.body',
                            'Choose the workflow that fits your work today, with room to connect the rest tomorrow.',
                        )}
                    />
                    <RoleTabs />
                </div>
            </section>
            <section className="m-section m-container">
                <div className="m-split">
                    <div className="m-split-copy">
                        <SectionHeading
                            eyebrow={t('home.connect.eyebrow', 'Fits into your world')}
                            title={t('home.connect.title', 'Keep your stack. Connect the conversation.')}
                            description={t(
                                'home.connect.body',
                                'Bring supported channels and stores together, then extend your workspace through APIs, event webhooks, and the Customer Chat SDK.',
                            )}
                        />
                        <div className="m-actions">
                            <MButton href="/integrations" secondary>
                                {t('home.connect.integrations', 'Explore integrations')}
                            </MButton>
                            <MLink href="/developers" className="m-text-link">
                                {t('home.connect.developers', 'For developers')}
                                <ArrowUpRight size={16} />
                            </MLink>
                        </div>
                    </div>
                    <MotionStage
                        className="m-integrations-art"
                        label={t('home.connect.art', 'Supported integration families')}
                    >
                        {integrations.slice(0, 20).map((item) => (
                            <BrandMark key={item.key} name={item.key} />
                        ))}
                    </MotionStage>
                </div>
                <div className="m-split m-section-sm">
                    <DeveloperDemo />
                    <div className="m-split-copy">
                        <SectionHeading
                            eyebrow={t('home.build.eyebrow', 'Built to build on')}
                            title={t('home.build.title', 'Your product. Your customer experience.')}
                            description={t(
                                'home.build.body',
                                'Add chat to your app. Connect a workflow to your systems. Use authenticated APIs and event webhooks to make WisperBot part of your product.',
                            )}
                        />
                        <MLink href="/developers" className="m-text-link">
                            {t('home.build.link', 'Explore the developer platform')}
                            <ArrowUpRight size={16} />
                        </MLink>
                    </div>
                </div>
            </section>
            <section className="m-section m-container m-border-top">
                <SectionHeading
                    centered
                    eyebrow={t('home.trust.eyebrow', 'Trust starts with the foundations')}
                    title={t('home.trust.title', 'Your workspace. Your people. Clear boundaries.')}
                />
                <div className="m-trust-grid">
                    {[
                        [
                            ShieldCheck,
                            'Workspace isolation',
                            'Customer records and permissions stay within authorized workspaces.',
                        ],
                        [Users, 'Team access controls', 'Give teammates access through defined roles and ownership.'],
                        [
                            Globe2,
                            'Official connections',
                            'Connect accounts through supported provider authorization paths.',
                        ],
                        [Code2, 'Authenticated APIs', 'Use workspace-scoped tokens and explicit integration access.'],
                    ].map(([Icon, title, body], i) => (
                        <Reveal key={title} delay={i * 70}>
                            <Icon size={25} />
                            <h3>{t('home.trust.title_' + i, title)}</h3>
                            <p>{t('home.trust.body_' + i, body)}</p>
                        </Reveal>
                    ))}
                </div>
            </section>
            <section className="m-section m-container">
                <SectionHeading
                    centered
                    eyebrow={t('home.pricing.eyebrow', 'A useful place to start')}
                    title={t('home.pricing.title', 'Your brand. Your first channel. On us.')}
                    description={t(
                        'home.pricing.body',
                        'Get a real feel for AI-powered support before your business needs more.',
                    )}
                />
                <div className="m-free-panel">
                    <div>
                        <Eyebrow>{t('home.pricing.free', 'Free plan')}</Eyebrow>
                        <div className="m-free-price">
                            $0<span> / {t('home.pricing.month', 'month')}</span>
                        </div>
                        <h3>{t('home.pricing.card_title', 'A small start, with a lot of possibility.')}</h3>
                        <p>
                            {t(
                                'home.pricing.card_body',
                                'Connect your first channel and give your customers a helpful place to begin.',
                            )}
                        </p>
                    </div>
                    <div>
                        <CheckList
                            items={[
                                t('home.pricing.point1', '1 connected channel'),
                                t('home.pricing.point2', '100 AI credits every month'),
                                freeWhiteLabel
                                    ? t('home.pricing.point3', 'White-label website chatbot')
                                    : t('home.pricing.branded', 'Customizable website chatbot'),
                                t('home.pricing.point4', 'A shared workspace for customer conversations'),
                            ]}
                        />
                        <div className="m-actions">
                            <MButton href={startHref}>{t('home.pricing.start', 'Start free')}</MButton>
                            <MLink href="/pricing" className="m-text-link">
                                {t('home.pricing.compare', 'Compare plans')}
                                <ArrowUpRight size={16} />
                            </MLink>
                        </div>
                        <p className="m-free-note">
                            {t('home.pricing.note', 'Provider messaging and API charges may apply separately.')}
                        </p>
                    </div>
                </div>
            </section>
            <section className="m-section m-container">
                <div className="m-inline-heading">
                    <SectionHeading
                        eyebrow={t('home.resources.eyebrow', 'A little guidance goes a long way')}
                        title={t('home.resources.title', 'Build your next good conversation.')}
                    />
                    <MLink href="/blog" className="m-text-link">
                        {t('home.resources.all', 'All guides & articles')}
                        <ArrowUpRight size={16} />
                    </MLink>
                </div>
                <div className="m-guide-grid">
                    {guides.map((guide, i) => (
                        <MLink href={guide.href} key={guide.href} className="m-guide-card">
                            <div className="m-guide-art">
                                {guide.image ? (
                                    <img src={guide.image} alt="" loading="lazy" />
                                ) : (
                                    <ProductIcon name={guide.icon} />
                                )}
                            </div>
                            <div className="m-guide-copy">
                                <span>
                                    {latestPosts.length
                                        ? guide.category
                                        : t('home.guides.category_' + i, guide.category)}
                                </span>
                                <h3>{latestPosts.length ? guide.title : t('home.guides.title_' + i, guide.title)}</h3>
                                <p>{latestPosts.length ? guide.body : t('home.guides.body_' + i, guide.body)}</p>
                                <span className="m-text-link">
                                    {t('home.resources.read', 'Take a look')}
                                    <ArrowUpRight size={14} />
                                </span>
                            </div>
                        </MLink>
                    ))}
                </div>
            </section>
            <section className="m-section m-container m-border-top">
                <div className="m-faq-grid">
                    <SectionHeading
                        eyebrow={t('home.faq.eyebrow', 'A few things you might be wondering')}
                        title={t('home.faq.title', 'Good questions. Clear answers.')}
                    >
                        <MLink href="/faq" className="m-text-link" style={{ marginTop: 25 }}>
                            {t('home.faq.link', 'Visit the help & FAQ page')}
                            <ArrowUpRight size={16} />
                        </MLink>
                    </SectionHeading>
                    <FAQList items={faqs.slice(0, 6)} />
                </div>
            </section>
            <FinalCTA />
        </LandingLayout>
    )
}
