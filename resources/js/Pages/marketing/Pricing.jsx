import { useState } from 'react'
import LandingLayout from '@/Layouts/LandingLayout'
import SeoHead from '@/Components/SeoHead'
import {
    PageHero,
    MButton,
    CheckList,
    SectionHeading,
    FAQList,
    FinalCTA,
    getFaqs,
    useMarketing,
} from '@/Components/marketing/MarketingUI'
function featuresFor(plan) {
    if (Array.isArray(plan.features)) return plan.features.filter((item) => typeof item === 'string')
    return Object.entries(plan.features || {})
        .filter(([, value]) => typeof value === 'string')
        .map(([, value]) => value)
}
export default function Pricing({ plans = [] }) {
    const { text: t, startHref, settings, freeWhiteLabel } = useMarketing()
    const [yearly, setYearly] = useState(false)
    const displayPlans = plans.length
        ? plans
        : [
              {
                  id: 'free',
                  name: 'Free',
                  description: 'A useful first step for customer conversations.',
                  price_monthly: 0,
                  price_yearly: 0,
                  currency: 'USD',
                  features: ['1 connected channel', '100 AI credits per month', freeWhiteLabel ? 'White-label website chatbot' : 'Customizable website chatbot'],
                  limits: { users: 2, ai_credits_per_month: 100, knowledge_bases: 1, chatbots: 1, automations: 3 },
                  white_label: Boolean(freeWhiteLabel),
              },
          ]
    const features = [
        ['users', t('pricing.row.users', 'Team members')],
        ['ai_credits_per_month', t('pricing.row.credits', 'Monthly AI credits')],
        ['knowledge_bases', t('pricing.row.knowledge', 'Knowledge Bases')],
        ['chatbots', t('pricing.row.bots', 'Smart Bots')],
        ['social_accounts', t('pricing.row.social', 'Social accounts')],
        ['automations', t('pricing.row.automations', 'Automations')],
        ['white_label', t('pricing.row.branding', 'Remove WisperBot branding')],
    ].filter(([key]) => key === 'white_label' || displayPlans.some(plan => Object.hasOwn(plan.limits || {}, key)))
    const limitLabel = (plan, key) => {
        if (key === 'white_label') return plan.white_label ? t('pricing.included', 'Included') : t('pricing.not_included', 'Not included')
        if (!Object.hasOwn(plan.limits || {}, key)) return t('pricing.not_listed', 'Not listed')
        const value = plan.limits[key]
        return value === null ? t('pricing.unlimited', 'Unlimited') : Number(value).toLocaleString()
    }
    const money = (price, currency) =>
        new Intl.NumberFormat(undefined, {
            style: 'currency',
            currency: currency || 'USD',
            maximumFractionDigits: price % 1 ? 2 : 0,
        }).format(price)
    const registrationHref = plan => {
        if (!Number.isInteger(Number(plan.id))) return startHref
        const cycle = yearly ? 'year' : 'month'
        return `/register?plan_id=${encodeURIComponent(plan.id)}&cycle=${cycle}`
    }
    return (
        <LandingLayout>
            <SeoHead
                title={t('pricing.seo', 'Pricing — Start Free with WisperBot')}
                description={t(
                    'pricing.description',
                    'Start with one channel, 100 monthly AI credits, and a website chatbot. Compare available WisperBot plans.',
                )}
            />
            <PageHero
                eyebrow={t('nav.pricing', 'Pricing')}
                title={t('pricing.title', 'Start free. Grow when you are ready.')}
                description={t(
                    'pricing.body',
                    'Get your first channel, 100 monthly AI credits, and a chatbot with your own branding. Add capacity as your team needs it.',
                )}
            >
                <div className="m-filter-row" style={{ marginTop: 28, marginBottom: 0 }}>
                    <button aria-pressed={!yearly} onClick={() => setYearly(false)}>
                        {t('pricing.monthly', 'Monthly')}
                    </button>
                    <button aria-pressed={yearly} onClick={() => setYearly(true)}>
                        {t('pricing.yearly', 'Yearly')}
                    </button>
                </div>
            </PageHero>
            <section className="m-section-sm m-container">
                <div className="m-price-grid">
                    {displayPlans.map((plan) => (
                        <article key={plan.id} className={'m-price-card ' + (plan.is_featured ? 'featured' : '')}>
                            <small>
                                {plan.is_featured
                                    ? t('pricing.featured', 'Featured plan')
                                    : t('pricing.plan', 'WisperBot plan')}
                            </small>
                            <h2>{plan.name}</h2>
                            <p>{plan.description}</p>
                            <div className="m-price-amount">
                                {money(yearly ? plan.price_yearly : plan.price_monthly, plan.currency)}
                                <span> / {yearly ? t('pricing.year', 'year') : t('pricing.month', 'month')}</span>
                            </div>
                            <CheckList items={featuresFor(plan)} />
                            <MButton href={registrationHref(plan)}>
                                {(yearly ? plan.price_yearly : plan.price_monthly) === 0
                                    ? t('pricing.start_free', 'Start free')
                                    : t('pricing.choose', 'Choose this plan')}
                            </MButton>
                        </article>
                    ))}
                </div>
                <p className="m-price-note">
                    {t(
                        'pricing.provider_note',
                        'Prices follow the selected billing period. Provider messaging, API access, and gateway charges can apply separately. Check your workspace for plan limits and available add-ons.',
                    )}
                </p>
            </section>
            {features.length > 0 && (
                <section className="m-section-sm m-container">
                    <SectionHeading
                        title={t('pricing.compare', 'Compare what is included.')}
                        eyebrow={t('pricing.details', 'Plan details')}
                    />
                    <div className="m-capability-wrap">
                        <table className="m-capability-table">
                            <thead>
                                <tr>
                                    <th scope="col">{t('pricing.feature', 'Feature')}</th>
                                    {displayPlans.map((plan) => (
                                        <th key={plan.id} scope="col">
                                            {plan.name}
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody>
                                {features.map(([key, label]) => (
                                    <tr key={key}>
                                        <td>{label}</td>
                                        {displayPlans.map((plan) => (
                                            <td key={plan.id}>
                                                {limitLabel(plan, key)}
                                            </td>
                                        ))}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </section>
            )}
            <section className="m-section m-container">
                <div className="m-pricing-explainer">
                    <div>
                        <h3>{t('pricing.credits_title', 'Know where your AI credits go.')}</h3>
                        <p>
                            {t(
                                'pricing.credits_body',
                                'Your workspace shows the credit rate for each managed AI action, along with usage and your reset date. The free plan includes 100 credits each month.',
                            )}
                        </p>
                    </div>
                    <div>
                        <h3>{t('pricing.provider_title', 'Your channels have their own rules.')}</h3>
                        <p>
                            {t(
                                'pricing.provider_body',
                                'WhatsApp, SMS, social APIs, and other providers may have separate account requirements and sending costs. Connecting a provider does not remove its policies or charges.',
                            )}
                        </p>
                    </div>
                </div>
            </section>
            <section className="m-section-sm m-container">
                <div className="m-pillar-faq">
                    <SectionHeading
                        title={t('pricing.faq', 'Before you choose.')}
                        eyebrow={t('nav.faq', 'Help & FAQ')}
                    />
                    <FAQList
                        items={getFaqs(settings).filter(
                            (item) => item.category === 'billing' || item.category === 'product',
                        )}
                    />
                </div>
            </section>
            <FinalCTA />
        </LandingLayout>
    )
}
