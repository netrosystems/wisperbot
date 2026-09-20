import { ProviderBrandIcon } from '@/Components/BrandIcons'

const channels = [
    'whatsapp',
    'facebook',
    'instagram',
    'messenger',
    'telegram',
    'linkedin',
    'twitter',
    'tiktok',
    'youtube',
]

function ChannelSignal() {
    return (
        <span className="m-hero-signal" aria-hidden="true" style={{ '--signal-duration': `${channels.length * 2}s` }}>
            <span className="m-hero-signal-radar" />
            {channels.map((channel, index) => (
                <span
                    className="m-hero-signal-icon"
                    style={{ '--signal-index': index }}
                    data-channel={channel}
                    key={channel}
                >
                    <ProviderBrandIcon provider={channel} className="m-hero-signal-logo" />
                </span>
            ))}
        </span>
    )
}

export default function HeroHeadline({ title }) {
    const split = title.indexOf('. ')
    const first = split > 0 ? title.slice(0, split + 1) : title
    const last = split > 0 ? title.slice(split + 2) : ''
    const defaultShape = /^Every\s+customer\s+channel\.?$/i.test(first.trim())

    if (!defaultShape) {
        return (
            <h1>
                <span className="m-hero-title-reveal">{first}</span>
                {last && <em className="m-hero-title-reveal m-hero-title-reveal-late">{last}</em>}
            </h1>
        )
    }

    return (
        <h1>
            <span className="sr-only">{title}</span>
            <span className="m-hero-heading-primary" aria-hidden="true">
                <span>Every</span>
                <ChannelSignal />
                <span>customer</span>
                <span className="m-hero-channel-word">channel.</span>
            </span>
            {last && (
                <em className="m-hero-title-reveal m-hero-title-reveal-late" aria-hidden="true">
                    {last}
                </em>
            )}
        </h1>
    )
}

export function HeroLaunch() {
    return (
        <div className="m-hero-launch" aria-hidden="true">
            <div className="m-hero-launch-core">
                <span className="m-hero-launch-mark">
                    <img src="/wisperbot-icon-512.png" alt="" width="46" height="46" />
                </span>
                <span className="m-hero-launch-copy">EVERY CHANNEL · ONE AI TEAM</span>
                <span className="m-hero-launch-line" />
            </div>
        </div>
    )
}
