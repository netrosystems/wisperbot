import { createElement, useState } from 'react'
import { render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'

const submitted = vi.hoisted(() => vi.fn())

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ children, href, ...props }) => createElement('a', { href, ...props }, children),
    useForm: (initial) => {
        const [data, updateData] = useState(initial)
        return {
            data,
            setData: (key, value) => updateData((current) => ({ ...current, [key]: value })),
            post: submitted,
            processing: false,
            errors: {},
            reset: vi.fn(),
        }
    },
}))
vi.mock('react-i18next', () => ({
    useTranslation: () => ({ t: (_key, fallback) => fallback?.defaultValue || fallback || '' }),
}))
vi.mock('@/Layouts/AuthLayout', () => ({
    default: ({ children }) => createElement('main', null, children),
}))

import Register from '@/Pages/Auth/Register'

describe('initial Free signup journey', () => {
    it('keeps direct signup focused and activates Free without a plan picker', () => {
        render(<Register signup_available />)

        expect(screen.getByText('Start on Free — no card required')).toBeInTheDocument()
        expect(screen.getByRole('button', { name: 'Create account' })).toBeEnabled()
        expect(screen.queryByText('Choose your plan')).not.toBeInTheDocument()
    })

    it('shows a compact paid intent and explains the post-signup checkout', () => {
        render(<Register
            plan_id={2}
            cycle="year"
            selected_plan={{ id: 2, name: 'Pro', is_free: false, price_cents: 29000, currency: 'USD' }}
            signup_available
        />)

        expect(screen.getByText('Pro selected · $290 / year')).toBeInTheDocument()
        expect(screen.getByText(/continue to secure checkout next/i)).toBeInTheDocument()
        expect(screen.getByRole('link', { name: 'Change' })).toHaveAttribute('href', '/pricing')
    })

    it('fails closed when the initial Free plan is unavailable', () => {
        render(<Register signup_available={false} />)

        expect(screen.getByText(/Account creation is temporarily unavailable/)).toBeInTheDocument()
        expect(screen.getByRole('button', { name: 'Create account' })).toBeDisabled()
    })
})
