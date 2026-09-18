import { createElement } from 'react'
import { render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'

vi.mock('react-i18next', () => ({
    useTranslation: () => ({ t: (_key, options) => options?.defaultValue || '' }),
}))
vi.mock('@/context/ThemeContext', () => ({
    useTheme: () => ({ theme: 'light', setTheme: vi.fn() }),
}))
vi.mock('@/hooks/useLocale', () => ({
    useLocale: () => ({ locale: 'en', locales: [{ code: 'en', name: 'English' }], setLocale: vi.fn() }),
}))
vi.mock('@/Components/BrandIcons', () => ({
    ProviderBrandIcon: ({ provider }) => createElement('span', { 'data-provider': provider }),
}))

import AuthLayout from '@/Layouts/AuthLayout'

describe('product-led authentication layout', () => {
    it('shows truthful product context without fabricated social proof', () => {
        render(
            <AuthLayout eyebrow="Workspace access" title="Sign in" subtitle="Continue to your workspace">
                <form><button type="submit">Continue</button></form>
            </AuthLayout>,
        )

        expect(screen.getByRole('heading', { name: /Every customer channel/i })).toBeInTheDocument()
        expect(screen.getByText('Ground AI answers in your approved business knowledge')).toBeInTheDocument()
        expect(screen.getByRole('heading', { name: 'Sign in' })).toBeInTheDocument()
        expect(screen.queryByText(/10,000\+|50M\+|99\.9%|3×|10\+ hours/i)).not.toBeInTheDocument()
    })

    it('gives platform administrators a distinct operational identity', () => {
        render(
            <AuthLayout variant="admin" eyebrow="Platform Administration" title="Admin login">
                <form><button type="submit">Continue</button></form>
            </AuthLayout>,
        )

        expect(screen.getByRole('heading', { name: 'Operate WisperBot with clarity.' })).toBeInTheDocument()
        expect(screen.getByText('Secure role-based access for platform operations')).toBeInTheDocument()
        expect(screen.getByText('Platform Administration')).toBeInTheDocument()
    })
})
