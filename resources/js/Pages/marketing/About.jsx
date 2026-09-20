import { ShieldCheck, Users, BookOpen, Globe2 } from 'lucide-react'
import LandingLayout from '@/Layouts/LandingLayout'
import SeoHead from '@/Components/SeoHead'
import { PageHero, SectionHeading, FinalCTA, useMarketing } from '@/Components/marketing/MarketingUI'
import { InboxDemo } from '@/Components/marketing/MarketingDemos'
export default function About() {
    const { text: t } = useMarketing()
    return (
        <LandingLayout>
            <SeoHead
                title={t('about.seo', 'About WisperBot — Better Customer Conversations')}
                description={t(
                    'about.description',
                    'WisperBot brings AI, customer channels, and human teams into one connected platform.',
                )}
            />
            <PageHero
                eyebrow={t('about.eyebrow', 'Why WisperBot')}
                title={t('about.title', 'Businesses grow through conversations.')}
                description={t(
                    'about.body',
                    'We are building a connected place for those conversations—with your business knowledge, your team, and your brand at the center.',
                )}
            />
            <section className="m-section-sm m-container">
                <p className="m-about-statement">
                    {t(
                        'about.statement',
                        'A customer should not need to know which app your team uses, who is at their desk, or where the answer is stored.',
                    )}{' '}
                    <em>{t('about.statement_end', 'They should just have a clear way to get help.')}</em>
                </p>
            </section>
            <section className="m-section m-container">
                <InboxDemo />
            </section>
            <section className="m-section m-soft">
                <div className="m-container m-split">
                    <SectionHeading
                        eyebrow={t('about.approach', 'Our approach')}
                        title={t('about.approach_title', 'Connect the tools. Keep the human part.')}
                        description={t(
                            'about.approach_body',
                            'WisperBot brings together an omnichannel inbox, Email MasterBox, Smart Bots, website chat, mobile agents, social publishing, automations, and developer tools.',
                        )}
                    />
                    <div>
                        <p className="m-about-statement" style={{ fontSize: 26 }}>
                            {t(
                                'about.approach_statement',
                                'Start with one channel and a useful free plan. Build the workflow that fits your business, then connect more as your needs grow.',
                            )}
                        </p>
                    </div>
                </div>
            </section>
            <section id="trust" className="m-section m-container">
                <SectionHeading
                    eyebrow={t('about.trust', 'Trust & access')}
                    title={t('about.trust_title', 'Practical foundations for shared work.')}
                    description={t(
                        'about.trust_body',
                        'Customer support brings people, business knowledge, and provider accounts together. Access boundaries are part of the product.',
                    )}
                />
                <div className="m-trust-grid">
                    {[
                        [
                            ShieldCheck,
                            'Workspace boundaries',
                            'Customer records and actions are scoped to authorized workspaces.',
                        ],
                        [
                            Users,
                            'Roles and authentication',
                            'Team permissions, authenticated APIs, and two-factor authentication support controlled access.',
                        ],
                        [
                            BookOpen,
                            'Knowledge you review',
                            'Build and test your business knowledge before using it in customer-facing answers.',
                        ],
                        [
                            Globe2,
                            'Provider authorization',
                            'Connect supported accounts through official provider flows with encrypted stored credentials.',
                        ],
                    ].map(([Icon, title, body], i) => (
                        <div key={title}>
                            <Icon size={25} />
                            <h3>{t('about.trust_title_' + i, title)}</h3>
                            <p>{t('about.trust_body_' + i, body)}</p>
                        </div>
                    ))}
                </div>
            </section>
            <FinalCTA />
        </LandingLayout>
    )
}
