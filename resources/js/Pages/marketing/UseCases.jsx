import LandingLayout from '@/Layouts/LandingLayout'
import SeoHead from '@/Components/SeoHead'
import { PageHero, ProductCard, FinalCTA, useMarketing } from '@/Components/marketing/MarketingUI'
export default function UseCases() {
    const { pages = [], text: t } = useMarketing()
    return (
        <LandingLayout>
            <SeoHead
                title={t('solutions.seo', 'Solutions for Support, Sales & Customer Engagement — WisperBot')}
                description={t(
                    'solutions.description',
                    'Explore connected customer support, sales, ecommerce, and marketing workflows with WisperBot.',
                )}
            />
            <PageHero
                eyebrow={t('nav.solutions', 'Solutions')}
                title={t('solutions.title', 'Built around the work you do.')}
                description={t(
                    'solutions.body',
                    'Start with the customer journey that matters most to your team. Bring the rest together as you grow.',
                )}
            />
            <section className="m-section-sm m-container">
                <div className="m-card-grid four">
                    {pages
                        .filter((page) => page.slug.startsWith('solutions/'))
                        .map((page, i) => (
                            <ProductCard key={page.slug} page={page} index={i} />
                        ))}
                </div>
            </section>
            <FinalCTA />
        </LandingLayout>
    )
}
