import { useState } from 'react'
import { Search } from 'lucide-react'
import LandingLayout from '@/Layouts/LandingLayout'
import SeoHead from '@/Components/SeoHead'
import { PageHero, FAQList, FinalCTA, MButton, getFaqs, useMarketing } from '@/Components/marketing/MarketingUI'
export default function Faq() {
    const { settings, text: t } = useMarketing()
    const [category, setCategory] = useState('all')
    const [query, setQuery] = useState('')
    const faqs = getFaqs(settings)
    const filtered = faqs.filter(
        (item) =>
            (category === 'all' || item.category === category) &&
            (item.q + ' ' + item.a).toLowerCase().includes(query.toLowerCase()),
    )
    return (
        <LandingLayout>
            <SeoHead
                title={t('faq.seo', 'Help & Frequently Asked Questions — WisperBot')}
                description={t(
                    'faq.description',
                    'Answers about WisperBot setup, AI, billing, mobile apps, and workspace access.',
                )}
                jsonLd={{
                    '@context': 'https://schema.org',
                    '@type': 'FAQPage',
                    mainEntity: faqs.map((item) => ({
                        '@type': 'Question',
                        name: item.q,
                        acceptedAnswer: { '@type': 'Answer', text: item.a },
                    })),
                }}
            />
            <PageHero
                eyebrow={t('nav.faq', 'Help & FAQ')}
                title={t('faq.title', 'A good place to find your next answer.')}
                description={t(
                    'faq.body',
                    'From your first connection to your next AI credit. Here are the things worth knowing.',
                )}
            >
                <label className="m-search">
                    <Search size={17} />
                    <span className="sr-only">{t('faq.search_label', 'Search frequently asked questions')}</span>
                    <input
                        type="search"
                        value={query}
                        onChange={(e) => setQuery(e.target.value)}
                        placeholder={t('faq.search', 'Search questions…')}
                    />
                </label>
            </PageHero>
            <section className="m-section-sm m-container">
                <div className="m-filter-row">
                    {[
                        ['all', 'All questions'],
                        ['product', 'Product'],
                        ['setup', 'Setup'],
                        ['ai', 'AI'],
                        ['billing', 'Billing'],
                        ['security', 'Access & security'],
                    ].map(([id, label]) => (
                        <button key={id} aria-pressed={category === id} onClick={() => setCategory(id)}>
                            {t('faq.category.' + id, label)}
                        </button>
                    ))}
                </div>
                <div className="m-pillar-faq">
                    {filtered.length ? (
                        <FAQList items={filtered} />
                    ) : (
                        <div className="m-empty">
                            {t('faq.empty', 'No questions match. Try another search or category.')}
                        </div>
                    )}
                    <div className="m-actions" style={{ justifyContent: 'center', marginTop: 35 }}>
                        <MButton href="/contact" secondary>
                            {t('faq.contact', 'Still wondering? Talk to us')}
                        </MButton>
                    </div>
                </div>
            </section>
            <FinalCTA />
        </LandingLayout>
    )
}
