/**
 * Brand SVG paths are from the Simple Icons project (CC0 / public domain).
 * https://github.com/simple-icons/simple-icons — follow each vendor’s brand guidelines in production.
 */
import brandIconData from './brandIconData.json'
import { Plug } from 'lucide-react'

/** Human-readable labels for inbox / campaign channel keys */
export const CHANNEL_LABELS = {
    whatsapp: 'WhatsApp',
    instagram: 'Instagram',
    messenger: 'Messenger',
    ebay: 'eBay',
    amazon: 'Amazon Seller',
    telegram: 'Telegram',
    sms: 'SMS',
    email: 'Email',
    webchat: 'Website',
}

function SvgBrand({ name, className }) {
    const data = brandIconData[name]
    if (!data) return null
    const useCurrentColor = name === 'twitter' || name === 'tiktok'
    const fill = useCurrentColor ? 'currentColor' : `#${data.hex}`
    return (
        <svg
            role="img"
            viewBox="0 0 24 24"
            xmlns="http://www.w3.org/2000/svg"
            className={[className ?? 'h-4 w-4', useCurrentColor ? 'text-neutral-900 dark:text-neutral-100' : '']
                .filter(Boolean)
                .join(' ')}
            aria-hidden
        >
            <path fill={fill} d={data.path} />
        </svg>
    )
}

function GmailLogo({ className }) {
    return (
        <svg viewBox="0 0 24 24" className={className ?? 'h-4 w-4'} aria-hidden>
            <path fill="#4285F4" d="M2 6.2v11.2c0 .7.6 1.3 1.3 1.3h3.3v-8.5L2 6.7v-.5Z" />
            <path fill="#34A853" d="M17.4 18.7h3.3c.7 0 1.3-.6 1.3-1.3V6.7l-4.6 3.5v8.5Z" />
            <path fill="#EA4335" d="M17.4 6.2v4L22 6.7V5.5c0-1.5-1.7-2.3-2.9-1.4l-3.7 2.8 2 .3Z" />
            <path fill="#FBBC04" d="M6.6 10.2v-4L12 10.3l5.4-4.1v4L12 14.3l-5.4-4.1Z" />
            <path fill="#C5221F" d="M2 5.5v1.2l4.6 3.5v-4L4.9 4.1C3.7 3.2 2 4 2 5.5Z" />
        </svg>
    )
}

function MicrosoftLogo({ className }) {
    return (
        <svg viewBox="0 0 24 24" className={className ?? 'h-4 w-4'} aria-hidden>
            <path fill="#F25022" d="M2 2h9.5v9.5H2z" />
            <path fill="#7FBA00" d="M12.5 2H22v9.5h-9.5z" />
            <path fill="#00A4EF" d="M2 12.5h9.5V22H2z" />
            <path fill="#FFB900" d="M12.5 12.5H22V22h-9.5z" />
        </svg>
    )
}

/** WhatsApp, Instagram, Messenger, SMS (Twilio mark), Email (envelope icon), Website (chat bubble) */
export function ChannelBrandIcon({ channel, className }) {
    if (channel === 'telegram') {
        return (
            <svg viewBox="0 0 24 24" className={className ?? 'h-4 w-4'} role="img" aria-label="Telegram">
                <circle cx="12" cy="12" r="12" fill="#229ED9" />
                <path
                    fill="white"
                    d="m18.9 5.4-2.5 12c-.2.85-.7 1.05-1.42.65l-3.8-2.8-1.83 1.77c-.2.2-.37.37-.76.37l.27-3.87 7.05-6.37c.31-.27-.07-.43-.47-.16l-8.71 5.49-3.75-1.17c-.82-.26-.83-.82.17-1.21l14.66-5.65c.68-.25 1.28.16 1.09.95Z"
                />
            </svg>
        )
    }

    if (channel === 'amazon') {
        return (
            <svg viewBox="0 0 48 20" className={className ?? 'h-4 w-9'} role="img" aria-label="Amazon">
                <text x="2" y="13" fontFamily="Arial, sans-serif" fontSize="12" fontWeight="700" fill="currentColor">
                    amazon
                </text>
                <path d="M12 16c7 3 17 3 24-1" fill="none" stroke="#ff9900" strokeWidth="1.8" strokeLinecap="round" />
                <path
                    d="m33.5 13.8 3 1.2-2.4 2"
                    fill="none"
                    stroke="#ff9900"
                    strokeWidth="1.4"
                    strokeLinecap="round"
                    strokeLinejoin="round"
                />
            </svg>
        )
    }

    if (channel === 'ebay') {
        return (
            <svg viewBox="0 0 48 20" className={className ?? 'h-4 w-8'} role="img" aria-label="eBay">
                <text x="1" y="16" fontFamily="Arial, sans-serif" fontSize="18" fontWeight="700">
                    <tspan fill="#e53238">e</tspan>
                    <tspan fill="#0064d2">B</tspan>
                    <tspan fill="#f5af02">a</tspan>
                    <tspan fill="#86b817">y</tspan>
                </text>
            </svg>
        )
    }

    // The website live-chat channel has no vendor logo — render a brand-tinted
    // chat bubble so it reads as "Website" in the inbox filters and headers.
    if (channel === 'webchat') {
        return (
            <svg viewBox="0 0 24 24" className={className ?? 'h-4 w-4'} fill="#ff762e" aria-hidden>
                <path d="M12 3C6.5 3 2 6.9 2 11.7c0 2.2 1 4.3 2.6 5.8-.1 1-.5 2.4-1.4 3.4 1.5-.2 3.2-.8 4.3-1.6 1.4.6 2.9.9 4.5.9 5.5 0 10-3.9 10-8.7S17.5 3 12 3z" />
            </svg>
        )
    }

    const key =
        channel === 'whatsapp'
            ? 'whatsapp'
            : channel === 'instagram'
              ? 'instagram'
              : channel === 'messenger'
                ? 'messenger'
                : channel === 'sms'
                  ? 'sms'
                  : channel === 'email'
                    ? 'email'
                    : null
    if (!key) return null
    return <SvgBrand name={key} className={className} />
}

/** Facebook, Instagram, LinkedIn, X, YouTube, TikTok */
export function SocialBrandIcon({ network, className }) {
    const map = {
        facebook: 'facebook',
        instagram: 'instagram',
        linkedin: 'linkedin',
        twitter: 'twitter',
        youtube: 'youtube',
        tiktok: 'tiktok',
    }
    const name = map[network]
    if (!name) return null
    return <SvgBrand name={name} className={className} />
}

/** Original provider artwork for marketing/integration surfaces. Returns null for non-brand concepts. */
export function ProviderBrandIcon({ provider, className }) {
    const normalized = (provider || '').toLowerCase().replace(/[^a-z0-9]/g, '')
    if (normalized === 'telegram' || normalized === 'telegrambusiness') {
        return <ChannelBrandIcon channel="telegram" className={className} />
    }
    if (normalized === 'amazon' || normalized === 'amazonseller') {
        return <ChannelBrandIcon channel="amazon" className={`${className ?? ''} m-provider-logo-wide`} />
    }
    if (normalized === 'ebay') {
        return <ChannelBrandIcon channel="ebay" className={`${className ?? ''} m-provider-logo-wide`} />
    }
    if (normalized === 'gmail' || normalized === 'googlemail') return <GmailLogo className={className} />
    if (normalized === 'microsoft' || normalized === 'microsoftmail' || normalized === 'outlook') {
        return <MicrosoftLogo className={className} />
    }
    const name = resolveBrandKey(provider)
    return name ? <SvgBrand name={name} className={className} /> : null
}

function hasProviderBrand(provider) {
    const normalized = (provider || '').toLowerCase().replace(/[^a-z0-9]/g, '')
    return (
        [
            'telegram',
            'telegrambusiness',
            'amazon',
            'amazonseller',
            'ebay',
            'gmail',
            'googlemail',
            'microsoft',
            'microsoftmail',
            'outlook',
        ].includes(normalized) || Boolean(resolveBrandKey(provider))
    )
}

/**
 * Resolve a free-text integration name (e.g. "WhatsApp Business", "Google Sheets")
 * to a brand key in brandIconData.json, or null when no official logo exists.
 */
const BRAND_ALIASES = {
    whatsappbusiness: 'whatsapp',
    facebookmessenger: 'messenger',
    instagramdirect: 'instagram',
    instagramdm: 'instagram',
    smscampaigns: 'sms',
    anthropicclaude: 'anthropic',
    claude: 'anthropic',
    gemini: 'googlegemini',
    googlesheet: 'googlesheets',
    x: 'twitter',
    xtwitter: 'twitter',
    twitterx: 'twitter',
}

export function resolveBrandKey(name) {
    const norm = (name || '').toLowerCase().replace(/[^a-z0-9]/g, '')
    const key = BRAND_ALIASES[norm] || norm
    return brandIconData[key] ? key : null
}

/**
 * Logo tile for an integration. Provider identities use their brand artwork;
 * unknown functional integrations use a neutral Lucide symbol, never a letter
 * monogram that could be mistaken for a provider logo.
 */
export function BrandMark({ name, tileClassName = 'h-10 w-10 rounded-xl', glyphClassName = 'h-6 w-6' }) {
    const tile = `inline-flex items-center justify-center bg-white ring-1 ring-neutral-200 dark:ring-neutral-700 ${tileClassName}`

    return (
        <span className={tile} aria-label={name}>
            {hasProviderBrand(name) ? (
                <ProviderBrandIcon provider={name} className={glyphClassName} />
            ) : (
                <Plug className={glyphClassName} aria-hidden="true" />
            )}
        </span>
    )
}
