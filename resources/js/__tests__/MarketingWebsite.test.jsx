import { createElement } from 'react'
import { render, screen, fireEvent, waitFor } from '@testing-library/react'
import { vi, beforeEach, describe, it, expect } from 'vitest'

const state = vi.hoisted(() => ({ props: {} }))
vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: state.props, url: '/' }),
    Link: ({ children, href, ...props }) => createElement('a', { href, ...props }, children),
    Head: () => null,
}))
vi.mock('react-i18next', () => ({ useTranslation: () => ({ t: (key, options) => options?.defaultValue || key }) }))
vi.mock('@/hooks/useLocale', () => ({ useLocale: () => ({ locale: 'en', setLocale: vi.fn() }) }))
import LandingLayout from '@/Layouts/LandingLayout'
import Integrations from '@/Pages/marketing/Integrations'
import Faq from '@/Pages/marketing/Faq'
import Pricing from '@/Pages/marketing/Pricing'

beforeEach(() => {
    state.props = {
        auth: {},
        marketingSettings: {},
        marketing: {
            pages: [
                {
                    slug: 'products/omnichannel-inbox',
                    href: '/products/omnichannel-inbox',
                    name: 'Omni Inbox',
                    tagline: 'Team conversations',
                    icon: 'inbox',
                },
            ],
            integrations: [
                {
                    key: 'whatsapp',
                    name: 'WhatsApp',
                    category: 'messaging',
                    capabilities: ['Inbox'],
                    note: 'Business messaging',
                },
                {
                    key: 'linkedin',
                    name: 'LinkedIn',
                    category: 'social',
                    capabilities: ['Publishing'],
                    note: 'Social publishing',
                },
            ],
        },
    }
})

describe('public marketing navigation', () => {
    it('allows visitors to pause decorative motion', () => {
        render(<LandingLayout />)
        fireEvent.click(screen.getByRole('button', { name: 'Pause animations' }))
        expect(screen.getByRole('button', { name: 'Resume animations' })).toHaveAttribute('aria-pressed', 'true')
        expect(document.querySelector('.marketing-site')).toHaveAttribute('data-motion-paused', 'true')
    })
    it('opens a real product menu and returns focus on Escape', () => {
        render(
            <LandingLayout>
                <p>Page content</p>
            </LandingLayout>,
        )
        const trigger = screen.getByRole('button', { name: 'Products', exact: true })
        fireEvent.click(trigger)
        expect(trigger).toHaveAttribute('aria-expanded', 'true')
        expect(document.getElementById('m-menu-products')).not.toHaveAttribute('hidden')
        fireEvent.keyDown(document, { key: 'Escape' })
        expect(trigger).toHaveAttribute('aria-expanded', 'false')
        expect(trigger).toHaveFocus()
    })
    it('changes the homepage header from dark to light after the hero', async () => {
        render(
            <LandingLayout darkHeader>
                <section className="m-hero">Hero</section>
            </LandingLayout>,
        )
        const site = document.querySelector('.marketing-site')
        const hero = document.querySelector('.m-hero')
        let heroBottom = 900
        vi.spyOn(hero, 'getBoundingClientRect').mockImplementation(() => ({
            bottom: heroBottom,
            height: 800,
            left: 0,
            right: 1000,
            top: heroBottom - 800,
            width: 1000,
            x: 0,
            y: heroBottom - 800,
            toJSON: () => ({}),
        }))

        fireEvent.scroll(window)
        await waitFor(() => expect(site).toHaveAttribute('data-header-tone', 'dark'))
        heroBottom = 40
        fireEvent.scroll(window)
        await waitFor(() => expect(site).toHaveAttribute('data-header-tone', 'light'))
    })
    it('hides absent downloads and reveals a configured SDK link', () => {
        const { unmount } = render(<LandingLayout />)
        expect(screen.queryByRole('button', { name: 'Download' })).not.toBeInTheDocument()
        unmount()
        state.props.marketingSettings['landing.chat_sdk_pubdev_url'] = 'https://pub.dev/packages/example'
        render(<LandingLayout />)
        fireEvent.click(screen.getByRole('button', { name: 'Download' }))
        const sdk = screen.getByRole('link', { name: 'Customer Chat SDK' })
        expect(sdk).toHaveAttribute('href', 'https://pub.dev/packages/example')
        expect(sdk).toHaveAttribute('rel', 'noopener noreferrer')
        expect(screen.queryByRole('link', { name: 'iOS Agent App' })).not.toBeInTheDocument()
    })
    it('filters integrations by capability and category', () => {
        render(<Integrations />)
        fireEvent.change(screen.getByRole('searchbox', { name: 'Search integrations' }), {
            target: { value: 'Publishing' },
        })
        expect(screen.getByRole('heading', { name: 'LinkedIn' })).toBeInTheDocument()
        expect(screen.queryByRole('heading', { name: 'WhatsApp' })).not.toBeInTheDocument()
        fireEvent.click(screen.getByRole('button', { name: 'Email', exact: true }))
        expect(screen.getByText(/No matching connections/)).toBeInTheDocument()
    })
    it('actually filters FAQ categories', () => {
        state.props.marketingSettings = {
            'landing.faq_1_q': 'Billing question',
            'landing.faq_1_a': 'Billing answer',
            'landing.faq_1_category': 'billing',
            'landing.faq_2_q': 'Setup question',
            'landing.faq_2_a': 'Setup answer',
            'landing.faq_2_category': 'setup',
        }
        render(<Faq />)
        fireEvent.click(screen.getByRole('button', { name: 'Billing', exact: true }))
        expect(screen.getByText('Billing question')).toBeInTheDocument()
        expect(screen.queryByText('Setup question')).not.toBeInTheDocument()
    })
    it('uses the selected billing period and actual entitlement values', () => {
        render(
            <Pricing
                plans={[
                    {
                        id: 1,
                        name: 'Pro',
                        description: 'For teams',
                        price_monthly: 29,
                        price_yearly: 290,
                        currency: 'USD',
                        features: [],
                        limits: { users: 10, ai_credits_per_month: 0 },
                        white_label: false,
                    },
                ]}
            />,
        )
        expect(screen.getByText('$29')).toBeInTheDocument()
        fireEvent.click(screen.getByRole('button', { name: 'Yearly', exact: true }))
        expect(screen.getByText('$290')).toBeInTheDocument()
        expect(screen.getByText('Not included')).toBeInTheDocument()
        expect(screen.getByText('0')).toBeInTheDocument()
    })
})
