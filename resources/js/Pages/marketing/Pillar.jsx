import { Check, ChevronRight } from 'lucide-react'
import LandingLayout from '@/Layouts/LandingLayout'
import SeoHead from '@/Components/SeoHead'
import {
    MButton,
    MLink,
    SectionHeading,
    Eyebrow,
    CheckList,
    FAQList,
    FinalCTA,
    ProductCard,
    DownloadLinks,
    useMarketing,
} from '@/Components/marketing/MarketingUI'
import { ProductDemo } from '@/Components/marketing/MarketingDemos'

export default function Pillar({ page }) {
    const { pages = [], startHref, setting, text: t } = useMarketing()
    const base = 'catalog.' + page.slug.replaceAll('/', '.')
    const copy = (key, fallback) => t(base + '.' + key, fallback)
    const related = page.related.map((slug) => pages.find((item) => item.slug === slug)).filter(Boolean)
    const faq = page.faq.map((item, i) => ({ q: copy('faq.' + i + '.q', item.q), a: copy('faq.' + i + '.a', item.a) }))
    const origin = typeof window !== 'undefined' ? window.location.origin : ''
    const types = { products: 'Products', solutions: 'Solutions', channels: 'Channels' }
    return (
        <LandingLayout>
            <SeoHead
                title={copy('name', page.name) + ' — WisperBot'}
                description={copy('description', page.description)}
                canonical={origin + page.href}
                jsonLd={[
                    {
                        '@context': 'https://schema.org',
                        '@type': 'WebPage',
                        name: copy('name', page.name),
                        description: copy('description', page.description),
                        url: origin + page.href,
                    },
                    {
                        '@context': 'https://schema.org',
                        '@type': 'BreadcrumbList',
                        itemListElement: [
                            { '@type': 'ListItem', position: 1, name: 'WisperBot', item: origin + '/' },
                            {
                                '@type': 'ListItem',
                                position: 2,
                                name: copy('name', page.name),
                                item: origin + page.href,
                            },
                        ],
                    },
                    {
                        '@context': 'https://schema.org',
                        '@type': 'FAQPage',
                        mainEntity: faq.map((item) => ({
                            '@type': 'Question',
                            name: item.q,
                            acceptedAnswer: { '@type': 'Answer', text: item.a },
                        })),
                    },
                ]}
            />
            <section className="m-page-hero m-container">
                <div className="m-breadcrumb">
                    <MLink href="/">WisperBot</MLink>
                    <ChevronRight size={11} />
                    <span>
                        {t('pillar.group.' + page.slug.split('/')[0], types[page.slug.split('/')[0]] || 'Developers')}
                    </span>
                    <ChevronRight size={11} />
                    <span>{copy('name', page.name)}</span>
                </div>
                <Eyebrow>{copy('name', page.name)}</Eyebrow>
                <h1>{copy('tagline', page.tagline)}</h1>
                <p>{copy('description', page.description)}</p>
                <div className="m-actions">
                    <MButton href={startHref}>{t('actions.start', 'Start free')}</MButton>
                    <MButton href={setting('demo_url', '/contact')} secondary>
                        {t('actions.talk', 'Talk to us')}
                    </MButton>
                </div>
                <div className="m-pillar-benefits">
                    {page.benefits.map((benefit, i) => (
                        <span key={benefit}>
                            <Check size={13} />
                            {copy('benefits.' + i, benefit)}
                        </span>
                    ))}
                </div>
            </section>
            <div className="m-container">
                <div className="m-pillar-visual">
                    <ProductDemo type={page.demo} />
                    <p className="m-stage-note">
                        {t(
                            'pillar.illustration',
                            'Illustrative product workflow. Available features depend on your connected accounts and plan.',
                        )}
                    </p>
                </div>
            </div>
            <div className="m-container">
                {page.sections.map((section, i) => (
                    <section className={'m-pillar-section m-split ' + (i % 2 ? 'reverse' : '')} key={section.title}>
                        <div className="m-split-copy">
                            <div className="m-pillar-number">
                                0{i + 1} / {copy('name', page.name)}
                            </div>
                            <SectionHeading
                                title={copy('sections.' + i + '.title', section.title)}
                                description={copy('sections.' + i + '.body', section.body)}
                            />
                            <CheckList
                                items={section.points.map((point, j) => copy('sections.' + i + '.points.' + j, point))}
                            />
                        </div>
                        <ProductDemo
                            type={
                                i === 0
                                    ? page.demo
                                    : i === 1
                                      ? page.demo === 'inbox'
                                          ? 'chat'
                                          : page.demo === 'social'
                                            ? 'social'
                                            : page.demo === 'developer'
                                              ? 'developer'
                                              : 'ai'
                                      : page.demo === 'mobile'
                                        ? 'chat'
                                        : page.demo === 'email'
                                          ? 'automation'
                                          : page.demo === 'commerce'
                                            ? 'commerce'
                                            : 'mobile'
                            }
                            compact
                        />
                    </section>
                ))}
            </div>
            {['developers', 'products/mobile-agent-app'].includes(page.slug) && (
                <section className="m-section-sm m-container">
                    <SectionHeading
                        eyebrow={t('downloads.heading', 'Keep the conversation with you')}
                        title={
                            page.slug === 'developers'
                                ? t('pillar.sdk_title', 'Build with the right tools.')
                                : t('pillar.app_title', 'Find your official app.')
                        }
                    />
                    <DownloadLinks />
                    {setting('developer_docs_url') && page.slug === 'developers' && (
                        <div className="m-actions">
                            <MButton href={setting('developer_docs_url')} secondary external>
                                {t('pillar.docs', 'Read developer documentation')}
                            </MButton>
                        </div>
                    )}
                    <p className="m-capability-note">
                        {t(
                            'pillar.download_note',
                            'Official download and documentation links appear here when available. Contact us for help with access.',
                        )}
                    </p>
                </section>
            )}
            <section className="m-section m-soft">
                <div className="m-container">
                    <div className="m-pillar-faq">
                        <SectionHeading
                            title={t('pillar.faq', 'A few useful answers.')}
                            eyebrow={copy('name', page.name)}
                        />
                        <FAQList items={faq} />
                    </div>
                    {related.length > 0 && (
                        <div className="m-related">
                            <SectionHeading
                                eyebrow={t('pillar.related', 'Better together')}
                                title={t('pillar.related_title', 'Connect the next part of your workflow.')}
                            />
                            <div className="m-card-grid">
                                {related.map((item, i) => (
                                    <ProductCard key={item.slug} page={item} index={i} />
                                ))}
                            </div>
                        </div>
                    )}
                </div>
            </section>
            <FinalCTA />
        </LandingLayout>
    )
}
