import { Link, usePage } from '@inertiajs/react'
import { useTranslation } from 'react-i18next'
import {
    ArrowUpRight,
    Check,
    Sparkles,
    Inbox,
    Mail,
    Workflow,
    Share2,
    Smartphone,
    MessagesSquare,
    Headphones,
    Target,
    Store,
    Megaphone,
    Globe2,
    Network,
    Code2,
    ShieldCheck,
    BookOpen,
    Download,
} from 'lucide-react'
import { Reveal } from '@/Components/Reveal'

export const icons = {
    inbox: Inbox,
    sparkles: Sparkles,
    mail: Mail,
    workflow: Workflow,
    share: Share2,
    phone: Smartphone,
    messages: MessagesSquare,
    headphones: Headphones,
    target: Target,
    store: Store,
    megaphone: Megaphone,
    globe: Globe2,
    network: Network,
    code: Code2,
    shield: ShieldCheck,
    book: BookOpen,
}
export function ProductIcon({ name, ...props }) {
    const Icon = icons[name] || Sparkles
    return <Icon {...props} />
}
export function useMarketing() {
    const { props } = usePage()
    const { t } = useTranslation()
    const settings = props.landing || props.marketingSettings || {}
    const text = (key, fallback) => t(`marketing.${key}`, { defaultValue: fallback })
    const setting = (key, fallback = '') => settings[`landing.${key}`] || fallback
    const startHref =
        setting('getstarted_link_type') === 'static' ? setting('getstarted_link_url', '/register') : '/register'
    return { ...props.marketing, auth: props.auth, text, setting, settings, startHref }
}
export function MLink({ href, children, className = '', external = false, ...props }) {
    const remote = /^https:\/\//.test(href || '')
    return remote || href?.startsWith('#') || href?.startsWith('mailto:') ? (
        <a
            href={href}
            className={className}
            {...(external ? { target: '_blank', rel: 'noopener noreferrer' } : {})}
            {...props}
        >
            {children}
        </a>
    ) : (
        <Link href={href || '/contact'} className={className} {...props}>
            {children}
        </Link>
    )
}
export function MButton({ href, children, secondary = false, light = false, arrow = true, ...props }) {
    return (
        <MLink
            href={href}
            className={`m-button ${secondary ? 'm-button-secondary' : ''} ${light ? 'm-button-light' : ''}`}
            {...props}
        >
            {children}
            {arrow && <ArrowUpRight size={17} />}
        </MLink>
    )
}
export function Eyebrow({ children }) {
    return (
        <span className="m-eyebrow">
            <span />
            {children}
        </span>
    )
}
export function SectionHeading({ eyebrow, title, description, centered = false, children }) {
    return (
        <Reveal className={`m-section-heading ${centered ? 'is-centered' : ''}`}>
            {eyebrow && <Eyebrow>{eyebrow}</Eyebrow>}
            <h2>{title}</h2>
            {description && <p>{description}</p>}
            {children}
        </Reveal>
    )
}
export function PageHero({ eyebrow, title, description, children }) {
    return (
        <section className="m-page-hero m-container">
            <Eyebrow>{eyebrow}</Eyebrow>
            <h1>{title}</h1>
            <p>{description}</p>
            {children}
        </section>
    )
}
export function CheckList({ items }) {
    return (
        <ul className="m-check-list">
            {items.map((item) => (
                <li key={item}>
                    <Check size={16} />
                    {item}
                </li>
            ))}
        </ul>
    )
}
export function FAQList({ items }) {
    return (
        <div className="m-faq-list">
            {items.map((item, i) => (
                <details key={`${item.q}-${i}`}>
                    <summary>
                        {item.q}
                        <span aria-hidden="true">+</span>
                    </summary>
                    <p>{item.a}</p>
                </details>
            ))}
        </div>
    )
}
export function getFaqs(settings) {
    return Array.from({ length: 8 }, (_, index) => index + 1)
        .map((i) => ({
            q: settings[`landing.faq_${i}_q`],
            a: settings[`landing.faq_${i}_a`],
            category: settings[`landing.faq_${i}_category`] || 'product',
        }))
        .filter((item) => item.q && item.a)
}
export function FinalCTA() {
    const { setting, startHref, text } = useMarketing()
    return (
        <section className="m-container m-final-wrap">
            <div className="m-final-cta">
                <div className="m-cta-orbit" aria-hidden="true">
                    <i />
                    <i />
                    <i />
                    <Sparkles />
                </div>
                <Eyebrow>{text('cta.eyebrow', 'Good conversations start here')}</Eyebrow>
                <h2>{setting('cta_title', 'Your next great customer experience starts here.')}</h2>
                <p>
                    {setting(
                        'cta_subtitle',
                        'One connected channel. 100 monthly AI credits. A white-label chatbot that feels like you.',
                    )}
                </p>
                <div className="m-actions">
                    <MButton href={startHref}>{setting('cta_primary', 'Start free')}</MButton>
                    <MButton href={setting('demo_url', '/contact')} secondary light>
                        {setting('cta_secondary', 'Talk to us')}
                    </MButton>
                </div>
                <span className="m-cta-footnote">
                    {text('cta.note', 'Start small. Keep your brand. Grow on your terms.')}
                </span>
            </div>
        </section>
    )
}
export function ProductCard({ page, index = 0 }) {
    const { text } = useMarketing()
    const key = page.slug.replaceAll('/', '.')
    return (
        <MLink href={page.href} className="m-product-card">
            <span className="m-card-number">{String(index + 1).padStart(2, '0')}</span>
            <span className="m-icon-tile">
                <ProductIcon name={page.icon} size={24} />
            </span>
            <h3>{text(`catalog.${key}.name`, page.name)}</h3>
            <p>{text(`catalog.${key}.tagline`, page.tagline)}</p>
            <span className="m-text-link">
                {text('actions.explore', 'Explore product')}
                <ArrowUpRight size={17} />
            </span>
        </MLink>
    )
}
export function DownloadLinks({ compact = false }) {
    const { setting, text } = useMarketing()
    const links = [
        ['agent_app_ios_url', 'iOS Agent App', 'ios'],
        ['agent_app_android_url', 'Android Agent App', 'android'],
        ['chat_sdk_pubdev_url', 'Customer Chat SDK', 'sdk'],
    ].filter(([key]) => setting(key))
    if (!links.length) return null
    return (
        <div className={compact ? 'm-download-links compact' : 'm-download-links'}>
            {links.map(([key, label, id]) => (
                <MLink key={key} href={setting(key)} external>
                    <Download size={16} />
                    <span>{text(`downloads.${id}`, label)}</span>
                    <ArrowUpRight size={14} />
                </MLink>
            ))}
        </div>
    )
}
