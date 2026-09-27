import { ArrowDown, BookOpen, Headphones, Mail, MessageSquare, Package, Share2, Smartphone, Workflow } from 'lucide-react'
import { BrandMark } from './MarketingDemos'
import { useMarketing } from './MarketingUI'

const root = '/images/marketing/product-ui/'
const captures = {
    widget: ['website-widget.png', 386, 570, 'Actual WisperBot website widget showing fictional onboarding messages and suggested replies.'],
    knowledge: ['knowledge-source.png', 671, 566, 'Actual Knowledge Base source form with website, file, and specific-page options.'],
    filters: ['inbox-filters.png', 189, 554, 'Actual Omni Inbox view and channel filters.'],
    composer: ['inbox-composer.png', 940, 92, 'Actual inbox composer with attachment, image, and voice controls.'],
    social: ['social-composer.png', 575, 267, 'Actual social post composer with unsaved demonstration copy.'],
    commerce: ['store-connections.png', 464, 241, 'Actual store connection form for Shopify, WooCommerce, and BigCommerce.'],
    email: ['email-connections.png', 758, 104, 'Actual Email Setup connection choices.'],
    aiNode: ['automation-ai.png', 278, 71, 'Actual AI Reply component from the workflow builder.'],
    conditionNode: ['automation-condition.png', 288, 98, 'Actual If/Else component from the workflow builder.'],
    appInbox: ['agent-app-inbox.webp', 600, 1300, 'Official WisperBot App Store artwork showing the Agent App inbox.'],
    appChat: ['agent-app-conversation.webp', 600, 1300, 'Official WisperBot App Store artwork showing an Agent App conversation.'],
}

function Capture({ name, className = '', eager = false }) {
    const { text: t } = useMarketing()
    const [file, width, height, alt] = captures[name]
    return <img className={`m-real-capture ${className}`} src={root + file} width={width} height={height} loading={eager ? 'eager' : 'lazy'} decoding="async" alt={t(`product_art.${name}.alt`, alt)} />
}

function Wordmark() {
    return <span className="m-art-wordmark"><img src="/wisperbot-icon-512.png" alt="" width="32" height="32" />wisperbot<span>.</span></span>
}

const details = {
    overview: [MessageSquare, 'The product. In focus.', 'Actual product interfaces · demonstration content'],
    inbox: [MessageSquare, 'Every channel. A clear next step.', 'Actual inbox controls and website widget'],
    knowledge: [BookOpen, 'Built on your business knowledge.', 'Actual Knowledge Base source interface'],
    ai: [BookOpen, 'Built on your business knowledge.', 'Actual Knowledge Base source interface'],
    team: [Headphones, 'People at the heart of support.', 'Illustrative team photography · actual inbox composer'],
    mobile: [Smartphone, 'Your inbox goes with you.', 'Official WisperBot Agent App imagery'],
    automation: [Workflow, 'Build the next step.', 'Actual workflow components · example arrangement'],
    social: [Share2, 'Plan with purpose. Publish with confidence.', 'Actual post composer · demonstration copy'],
    commerce: [Package, 'Connect the context behind each order.', 'Actual store connection interface'],
    chat: [MessageSquare, 'A conversation that feels like your brand.', 'Actual website widget · demonstration conversation'],
    email: [Mail, 'A dedicated home for email.', 'Actual Email Setup interface'],
}

/** Captured product pixels, never AI-generated UI. All records are excluded or fictional. */
export default function MarketingArtwork({ type = 'overview', className = '', compact = false }) {
    const { text: t } = useMarketing()
    const resolved = details[type] ? type : 'overview'
    const [Icon, title, provenance] = details[resolved]
    const label = (key, fallback) => t(`product_art.${key}`, fallback)
    const inbox = resolved === 'overview' || resolved === 'inbox'
    return (
        <figure className={`m-product-art is-${resolved} ${compact ? 'is-compact' : ''} ${className}`.trim()}>
            <div className="m-product-art-stage">
                {inbox ? (
                    <div className="m-brand-board">
                        <div className="m-board-identity">
                            <Wordmark />
                            <strong>{label('brand_statement', 'Connected by conversation.')}</strong>
                            <div className="m-art-channel-row"><BrandMark name="whatsapp" /><BrandMark name="instagram" /><BrandMark name="facebook" /><BrandMark name="telegram" /></div>
                        </div>
                        <div className="m-board-filters"><Capture name="filters" eager={resolved === 'overview'} /></div>
                        <div className="m-board-widget"><Capture name="widget" eager={resolved === 'overview'} /></div>
                        <div className="m-board-detail"><span>{label('inbox_label', '01 / OMNI INBOX')}</span><MessageSquare size={28} /><p>{label('shared_context', 'One workspace. Shared context.')}</p></div>
                    </div>
                ) : resolved === 'knowledge' || resolved === 'ai' ? (
                    <>
                        <div className="m-art-kicker"><BookOpen size={16} /><span>{label('knowledge_label', 'YOUR KNOWLEDGE, CONNECTED')}</span></div>
                        <div className="m-capture-frame m-knowledge-frame"><Capture name="knowledge" /></div>
                    </>
                ) : resolved === 'team' ? (
                    <>
                        <img className="m-team-photo" src={root + 'vibrant-support-team.webp'} srcSet={`${root}vibrant-support-team-768.webp 768w, ${root}vibrant-support-team.webp 1536w`} sizes="(max-width: 767px) 90vw, 640px" width="1536" height="1024" loading="lazy" decoding="async" alt={label('team_alt', 'Fictional American-European customer-support colleagues at work in a modern office.')} />
                        <div className="m-team-interface"><Capture name="composer" /></div>
                    </>
                ) : resolved === 'mobile' ? (
                    <div className="m-app-artwork-pair"><Capture name="appInbox" /><Capture name="appChat" /></div>
                ) : resolved === 'automation' ? (
                    <>
                        <div className="m-art-kicker"><Workflow size={16} /><span>{label('workflow_label', 'DESIGNED AROUND YOUR WORKFLOW')}</span></div>
                        <div className="m-real-flow"><Capture name="aiNode" /><span className="m-real-flow-line"><ArrowDown size={17} /></span><Capture name="conditionNode" /></div>
                        <div className="m-flow-outcomes"><span>{label('workflow_yes', 'Follow the matching path')}</span><span>{label('workflow_no', 'Handle the alternative')}</span></div>
                    </>
                ) : resolved === 'social' ? (
                    <>
                        <div className="m-art-kicker"><Share2 size={16} /><span>{label('social_label', 'FROM FIRST DRAFT TO PUBLISH')}</span></div>
                        <div className="m-art-channel-row"><BrandMark name="facebook" /><BrandMark name="instagram" /><BrandMark name="linkedin" /><BrandMark name="x" /></div>
                        <div className="m-capture-frame m-social-frame"><Capture name="social" /></div>
                    </>
                ) : resolved === 'commerce' ? (
                    <>
                        <div className="m-art-kicker"><Package size={16} /><span>{label('commerce_label', 'YOUR STORE. YOUR CUSTOMER CONTEXT.')}</span></div>
                        <div className="m-commerce-wordmark"><Wordmark /><span className="m-connection-rule" /><BrandMark name="shopify" /></div>
                        <div className="m-capture-frame m-commerce-frame"><Capture name="commerce" /></div>
                    </>
                ) : resolved === 'email' ? (
                    <>
                        <div className="m-art-kicker"><Mail size={16} /><span>{label('email_label', 'EMAIL, IN ITS OWN WORKSPACE')}</span></div>
                        <div className="m-email-symbols"><BrandMark name="gmail" /><span>+</span><BrandMark name="microsoft" /></div>
                        <div className="m-capture-frame m-email-frame"><Capture name="email" /></div>
                    </>
                ) : (
                    <><div className="m-art-kicker"><MessageSquare size={16} /><span>{label('widget_label', 'A SMALL WINDOW. A REAL CONNECTION.')}</span></div><Capture name="widget" className="m-widget-artwork" /></>
                )}
            </div>
            <figcaption><Icon size={17} aria-hidden="true" /><span><strong>{label(`${resolved}_title`, title)}</strong><small>{label(`${resolved}_provenance`, provenance)}</small></span></figcaption>
        </figure>
    )
}
