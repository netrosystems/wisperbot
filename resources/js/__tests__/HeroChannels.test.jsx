import { fireEvent, render, screen } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import HeroBackdrop from '@/Components/marketing/HeroBackdrop'
import HeroHeadline, { HeroLaunch } from '@/Components/marketing/HeroHeadline'
import { BrandMark } from '@/Components/marketing/MarketingDemos'
import { AgentAppMenu } from '@/Pages/Welcome'

vi.mock('react-i18next', () => ({
    useTranslation: () => ({ t: (key, options) => options?.defaultValue || key }),
}))

afterEach(() => {
    vi.unstubAllGlobals()
    vi.restoreAllMocks()
})

function motionPreference(reduced, finePointer = true) {
    vi.stubGlobal(
        'matchMedia',
        vi.fn((query) => ({
            matches: query.includes('prefers-reduced-motion') ? reduced : finePointer,
            addEventListener: vi.fn(),
            removeEventListener: vi.fn(),
        })),
    )
    vi.stubGlobal('IntersectionObserver', undefined)
    vi.spyOn(document, 'hidden', 'get').mockReturnValue(false)
}

describe('homepage channel signal', () => {
    it('keeps one rotating channel position inside an accessible headline', () => {
        const { container } = render(<HeroHeadline title="Every customer channel. One AI support team." />)
        expect(screen.getByRole('heading', { name: 'Every customer channel. One AI support team.' })).toBeVisible()
        expect(container.querySelectorAll('.m-hero-signal')).toHaveLength(1)
        expect(container.querySelectorAll('.m-hero-signal-icon svg')).toHaveLength(9)
        expect(container.querySelector('.m-hero-signal').style.getPropertyValue('--signal-duration')).toBe('18s')
        expect([...container.querySelectorAll('.m-hero-signal-icon')].map((icon) => icon.dataset.channel)).toEqual([
            'whatsapp',
            'facebook',
            'instagram',
            'messenger',
            'telegram',
            'linkedin',
            'twitter',
            'tiktok',
            'youtube',
        ])
        expect(container.querySelector('.m-hero-heading-primary')).toHaveTextContent('Everycustomerchannel.')
        expect(container.querySelectorAll('.m-hero-channel-badge')).toHaveLength(0)
    })

    it('preserves custom client-authored hero copy', () => {
        render(<HeroHeadline title="Support that moves with you. Built for every team." />)
        expect(screen.getByRole('heading')).toHaveTextContent('Support that moves with you.Built for every team.')
    })

    it('renders a decorative, noninteractive launch sequence', () => {
        const { container } = render(<HeroLaunch />)
        expect(container.querySelector('.m-hero-launch')).toHaveAttribute('aria-hidden', 'true')
        expect(container.querySelectorAll('a, button, input')).toHaveLength(0)
    })
})

describe('hero Agent App chooser', () => {
    it('opens a compact iOS and Android download menu', () => {
        render(
            <AgentAppMenu
                iosUrl="https://apps.apple.com/app/id123"
                androidUrl="https://play.google.com/store/apps/details?id=example"
            />,
        )

        const trigger = screen.getByRole('button', { name: /Get Agent App/i })
        expect(trigger).toHaveAttribute('aria-expanded', 'false')
        fireEvent.click(trigger)
        expect(trigger).toHaveAttribute('aria-expanded', 'true')
        expect(screen.getByRole('menuitem', { name: /Android\s*Google Play/i })).toHaveAttribute(
            'href',
            'https://play.google.com/store/apps/details?id=example',
        )
        expect(screen.getByRole('menuitem', { name: /iOS\s*App Store/i })).toHaveAttribute('href', 'https://apps.apple.com/app/id123')
        expect(document.querySelector('.m-store-mark.is-google-play')).toBeInTheDocument()
        expect(document.querySelector('.m-store-mark.is-app-store')).toBeInTheDocument()
        fireEvent.keyDown(document, { key: 'Escape' })
        expect(trigger).toHaveAttribute('aria-expanded', 'false')
        expect(trigger).toHaveFocus()
    })
})

describe('marketing icon system', () => {
    it('renders provider SVG artwork instead of text monograms', () => {
        const providers = [
            'whatsapp',
            'facebook',
            'instagram',
            'messenger',
            'telegram',
            'gmail',
            'microsoft',
            'linkedin',
            'x',
            'tiktok',
            'youtube',
            'shopify',
            'woocommerce',
            'bigcommerce',
            'ebay',
            'amazon',
        ]
        const { container } = render(
            <div>
                {providers.map((provider) => (
                    <BrandMark name={provider} key={provider} />
                ))}
            </div>,
        )
        expect(container.querySelectorAll('.m-brand-mark')).toHaveLength(providers.length)
        expect(container.querySelectorAll('.m-brand-mark > svg')).toHaveLength(providers.length)
        expect(container.querySelector('.m-brand-mark')?.textContent).toBe('')
    })

    it('uses Lucide for generic product concepts', () => {
        const { container } = render(
            <div>
                {['website', 'email', 'sms', 'api', 'sdk'].map((name) => (
                    <BrandMark name={name} key={name} />
                ))}
            </div>,
        )
        expect(container.querySelectorAll('.m-brand-mark > svg.lucide')).toHaveLength(5)
        expect(container.querySelectorAll('.m-generic-lucide-icon')).toHaveLength(5)
    })
})

describe('hero atmosphere', () => {
    it('uses a bounded decorative SVG without external media or interactive elements', () => {
        motionPreference(false)
        const { container } = render(<HeroBackdrop />)
        expect(container.querySelector('.m-hero-backdrop')).toHaveAttribute('aria-hidden', 'true')
        const dots = container.querySelectorAll('circle')
        expect(dots.length).toBeGreaterThan(200)
        expect(dots.length).toBeLessThan(720)
        expect(container.querySelectorAll('img, video, canvas, a, button')).toHaveLength(0)
        expect(container.querySelector('.m-hero-atmosphere')).toHaveAttribute('data-playing', 'true')
    })

    it('retains its static background when reduced motion is requested', () => {
        motionPreference(true)
        const { container } = render(<HeroBackdrop />)
        expect(container.querySelector('.m-hero-atmosphere')).toHaveAttribute('data-playing', 'false')
        expect(container.querySelector('.m-hero-sphere')).toBeInTheDocument()
    })

    it('responds to a fine pointer without becoming an interactive control', () => {
        motionPreference(false, true)
        vi.stubGlobal(
            'requestAnimationFrame',
            vi.fn((callback) => {
                callback()
                return 1
            }),
        )
        vi.stubGlobal('cancelAnimationFrame', vi.fn())
        const { container } = render(
            <section className="m-hero">
                <HeroBackdrop />
            </section>,
        )
        const hero = container.querySelector('.m-hero')
        vi.spyOn(hero, 'getBoundingClientRect').mockReturnValue({
            left: 0,
            top: 0,
            width: 1000,
            height: 800,
            right: 1000,
            bottom: 800,
            x: 0,
            y: 0,
            toJSON: () => ({}),
        })
        fireEvent.pointerMove(hero, { clientX: 750, clientY: 200 })
        const backdrop = container.querySelector('.m-hero-backdrop')
        expect(backdrop.style.getPropertyValue('--hero-pointer-x')).toBe('75%')
        expect(backdrop.style.getPropertyValue('--hero-tilt-y')).toBe('3.25deg')
        expect(backdrop).toHaveAttribute('aria-hidden', 'true')
    })
})
