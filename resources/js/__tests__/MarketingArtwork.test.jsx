import React from 'react'
import { render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import MarketingArtwork from '../Components/marketing/MarketingArtwork'

vi.mock('../Components/marketing/MarketingUI', () => ({ useMarketing: () => ({ text: (_key, fallback) => fallback }) }))
vi.mock('../Components/marketing/MarketingDemos', () => ({ BrandMark: ({ name }) => <span>{name}</span> }))

describe('authentic marketing artwork', () => {
    it('uses actual widget captures in the hero, loaded eagerly', () => {
        render(<MarketingArtwork />)
        const widget = screen.getByAltText(/Actual WisperBot website widget/)
        expect(widget.getAttribute('src')).toBe('/images/marketing/product-ui/website-widget.png')
        expect(widget.getAttribute('loading')).toBe('eager')
    })

    it('uses official app images instead of generated phone interfaces', () => {
        render(<MarketingArtwork type="mobile" />)
        expect(screen.getAllByAltText(/Official WisperBot App Store artwork/)).toHaveLength(2)
        expect(screen.getByText('Official WisperBot Agent App imagery')).toBeTruthy()
    })

    it('discloses fictional people and retains responsive photography', () => {
        render(<MarketingArtwork type="team" />)
        const photo = screen.getByAltText(/Fictional American-European/)
        expect(photo.getAttribute('srcset')).toContain('768w')
        expect(screen.getByText(/Illustrative team photography/)).toBeTruthy()
    })

    it('discloses unsaved demonstration content in real social UI', () => {
        render(<MarketingArtwork type="social" />)
        const composer = screen.getByAltText(/Actual social post composer/)
        expect(composer.getAttribute('src')).toContain('/product-ui/social-composer.png')
        expect(composer.getAttribute('loading')).toBe('lazy')
    })
})
