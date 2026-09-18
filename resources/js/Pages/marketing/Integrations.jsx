import { useState } from 'react'
import { Search } from 'lucide-react'
import LandingLayout from '@/Layouts/LandingLayout'
import SeoHead from '@/Components/SeoHead'
import { PageHero, SectionHeading, MButton, FinalCTA, useMarketing } from '@/Components/marketing/MarketingUI'
import { BrandMark } from '@/Components/marketing/MarketingDemos'
export default function Integrations() {
    const { integrations = [], text: t } = useMarketing()
    const [query, setQuery] = useState('')
    const [category, setCategory] = useState('all')
    const filtered = integrations.filter(
        (item) =>
            (category === 'all' || item.category === category) &&
            [item.name, ...item.capabilities].join(' ').toLowerCase().includes(query.toLowerCase().trim()),
    )
    const groups = [
        ['all', 'All connections'],
        ['messaging', 'Messaging'],
        ['email', 'Email'],
        ['social', 'Social'],
        ['commerce', 'Commerce'],
        ['developer', 'Developer'],
    ]
    return (
        <LandingLayout>
            <SeoHead
                title={t('integrations.seo', 'Integrations & Channel Capabilities — WisperBot')}
                description={t(
                    'integrations.description',
                    'Find supported messaging, email, publishing, commerce, and developer connections. Compare what each provider can do in WisperBot.',
                )}
            />
            <PageHero
                eyebrow={t('nav.integrations', 'Integrations')}
                title={t('integrations.title', 'Your world, a little more connected.')}
                description={t(
                    'integrations.body',
                    'Find the channels and tools your customers already use. See exactly where they fit in your WisperBot workspace.',
                )}
            >
                <label className="m-search">
                    <Search size={18} />
                    <span className="sr-only">{t('integrations.search_label', 'Search integrations')}</span>
                    <input
                        type="search"
                        value={query}
                        onChange={(e) => setQuery(e.target.value)}
                        placeholder={t('integrations.search', 'Search a platform or capability…')}
                    />
                </label>
            </PageHero>
            <section className="m-section-sm m-container">
                <div className="m-filter-row" aria-label={t('integrations.filters', 'Filter by purpose')}>
                    {groups.map(([id, label]) => (
                        <button key={id} aria-pressed={category === id} onClick={() => setCategory(id)}>
                            {t('integrations.group.' + id, label)}
                        </button>
                    ))}
                </div>
                <p className="sr-only" aria-live="polite">
                    {filtered.length}{' '}
                    {filtered.length === 1
                        ? t('integrations.result_one', 'connection found')
                        : t('integrations.results', 'connections found')}
                </p>
                {filtered.length ? (
                    <div className="m-card-grid">
                        {filtered.map((item) => (
                            <article className="m-integration-card" key={item.key}>
                                <div>
                                    <BrandMark name={item.key} />
                                    <h2>{item.name}</h2>
                                </div>
                                <div className="m-capability-badges">
                                    {item.capabilities.map((cap) => (
                                        <span key={cap}>
                                            {t('capabilities.' + cap.toLowerCase().replaceAll(' ', '_'), cap)}
                                        </span>
                                    ))}
                                </div>
                                <p>{t('integrations.notes.' + item.key, item.note)}</p>
                            </article>
                        ))}
                    </div>
                ) : (
                    <div className="m-empty">
                        {t(
                            'integrations.empty',
                            'No matching connections. Try another platform or clear your filters.',
                        )}
                    </div>
                )}
            </section>
            <section className="m-section m-container">
                <SectionHeading
                    centered
                    eyebrow={t('integrations.custom', 'A connection of your own')}
                    title={t('integrations.custom_title', 'Build around the way you work.')}
                    description={t(
                        'integrations.custom_body',
                        'Use REST APIs and event webhooks for supported workspace integrations. Developer access depends on your account entitlement.',
                    )}
                />
                <div className="m-actions" style={{ justifyContent: 'center' }}>
                    <MButton href="/developers" secondary>
                        {t('integrations.developers', 'Explore developer tools')}
                    </MButton>
                    <MButton href="/contact">{t('actions.talk', 'Talk to us')}</MButton>
                </div>
            </section>
            <FinalCTA />
        </LandingLayout>
    )
}
