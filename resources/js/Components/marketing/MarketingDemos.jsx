import { useEffect, useRef, useState } from 'react'
import {
    ArrowUpRight,
    ArrowRight,
    Check,
    CheckCheck,
    ChevronDown,
    CircleHelp,
    Clock3,
    Braces,
    Code2,
    FileText,
    GitBranch,
    Inbox,
    Mail,
    MessageCircle,
    MessageSquareText,
    MoreHorizontal,
    Paperclip,
    Search,
    Send,
    ShieldCheck,
    ShoppingBag,
    Smartphone,
    Sparkles,
    Users,
    Zap,
} from 'lucide-react'
import { ProviderBrandIcon } from '@/Components/BrandIcons'
import { useMarketing } from './MarketingUI'

export function MotionStage({ children, className = '', label }) {
    const ref = useRef(null)
    const [playing, setPlaying] = useState(false)
    useEffect(() => {
        const node = ref.current
        let visible = false
        const media = window.matchMedia('(prefers-reduced-motion: reduce)')
        const update = () => setPlaying(visible && !document.hidden && !media.matches)
        const observer =
            typeof window.IntersectionObserver === 'undefined'
                ? null
                : new window.IntersectionObserver(
                      ([entry]) => {
                          visible = entry.isIntersecting
                          update()
                      },
                      { rootMargin: '80px' },
                  )
        if (observer) observer.observe(node)
        else {
            visible = true
            update()
        }
        document.addEventListener('visibilitychange', update)
        media.addEventListener('change', update)
        return () => {
            observer?.disconnect()
            document.removeEventListener('visibilitychange', update)
            media.removeEventListener('change', update)
        }
    }, [])
    return (
        <div
            ref={ref}
            className={`m-motion-stage ${className}`}
            data-playing={playing}
            role={label ? 'img' : undefined}
            aria-label={label}
        >
            {children}
        </div>
    )
}

const genericMarks = {
    website: MessageCircle,
    email: Mail,
    sms: MessageSquareText,
    api: Braces,
    sdk: Code2,
}
export function BrandMark({ name, small = false }) {
    const GenericIcon = genericMarks[name]
    return (
        <span aria-hidden="true" data-provider={name} className={`m-brand-mark ${small ? 'small' : ''}`}>
            {GenericIcon ? (
                <GenericIcon className="m-generic-lucide-icon" />
            ) : (
                <ProviderBrandIcon provider={name} className="m-provider-brand-logo" />
            )}
        </span>
    )
}

export function InboxDemo({ compact = false }) {
    const { text: t } = useMarketing()
    return (
        <MotionStage
            className={`m-inbox-demo ${compact ? 'compact' : ''}`}
            label={t('demo.inbox.label', 'Illustrative shared inbox showing an AI answer and a teammate handoff')}
        >
            <div className="m-demo-toolbar">
                <span className="m-window-dots">
                    <i />
                    <i />
                    <i />
                </span>
                <span>wisperbot / {t('demo.workspace', 'your workspace')}</span>
                <span className="m-demo-label">{t('demo.illustration', 'Product illustration')}</span>
            </div>
            <div className="m-inbox-grid">
                <aside className="m-demo-sidebar">
                    <div className="m-demo-brand">
                        <img src="/wisperbot-icon-512.png" alt="" />
                        wisperbot
                    </div>
                    <span className="m-demo-nav active">
                        <Inbox size={15} />
                        {t('demo.inbox.name', 'Omni Inbox')}
                        <b>4</b>
                    </span>
                    <span className="m-demo-nav">
                        <Sparkles size={15} />
                        {t('demo.smartbots', 'Smart Bots')}
                    </span>
                    <span className="m-demo-nav">
                        <Users size={15} />
                        {t('demo.contacts', 'Contacts')}
                    </span>
                    <span className="m-demo-nav">
                        <Zap size={15} />
                        {t('demo.automations', 'Automations')}
                    </span>
                    <div className="m-demo-sidebar-bottom">
                        <span className="m-avatar">Y</span>
                        {t('demo.team', 'Your team')}
                        <ChevronDown size={13} />
                    </div>
                </aside>
                <div className="m-demo-threads">
                    <div className="m-demo-thread-title">
                        {t('demo.inbox.name', 'Omni Inbox')}
                        <span>4</span>
                    </div>
                    <div className="m-demo-search">
                        <Search size={13} />
                        {t('demo.search', 'Search conversations')}
                    </div>
                    {[
                        ['AM', 'Alex Morgan', 'Can you help me choose?', 'whatsapp'],
                        ['JL', 'Jamie Lee', 'Thank you, that helps!', 'instagram'],
                        ['RK', 'Riley Kim', 'A question about delivery', 'website'],
                        ['TS', 'Taylor Smith', 'Looking for more details', 'facebook'],
                    ].map(([initial, name, body, channel], i) => (
                        <div className={`m-demo-thread ${i === 0 ? 'selected' : ''}`} key={initial}>
                            <span className={`m-avatar avatar-${i}`}>{initial}</span>
                            <div>
                                <strong>{name}</strong>
                                <span>{t(`demo.thread.${i}`, body)}</span>
                            </div>
                            <BrandMark name={channel} small />
                        </div>
                    ))}
                </div>
                <div className="m-demo-conversation">
                    <div className="m-demo-conversation-head">
                        <span className="m-avatar">AM</span>
                        <div>
                            <strong>Alex Morgan</strong>
                            <span>
                                <BrandMark name="whatsapp" small />
                                WhatsApp
                            </span>
                        </div>
                        <span className="m-demo-pill">
                            <Sparkles size={11} />
                            {t('demo.ai_assisting', 'AI assisting')}
                        </span>
                        <MoreHorizontal size={17} />
                    </div>
                    <div className="m-demo-messages">
                        <span className="m-demo-date">{t('demo.today', 'Today')}</span>
                        <div className="m-demo-bubble customer">
                            {t('demo.question', 'Hi! Can you help me find the right plan?')}
                        </div>
                        <div className="m-demo-evidence">
                            <FileText size={13} />
                            {t('demo.evidence', 'Answering from your Knowledge Base')}
                            <Check size={12} />
                        </div>
                        <div className="m-demo-bubble agent">
                            <Sparkles size={14} />
                            {t(
                                'demo.answer',
                                'Of course. Are you getting started on your own, or choosing for a team?',
                            )}
                        </div>
                        <div className="m-demo-choices">
                            <span>{t('demo.solo', 'Just me')}</span>
                            <span>{t('demo.for_team', 'For my team')}</span>
                        </div>
                        <div className="m-demo-bubble customer m-sequence-1">
                            {t('demo.team_response', 'For our team. Could I speak to someone?')}
                        </div>
                        <div className="m-demo-handoff m-sequence-2">
                            <span className="m-avatar tiny">JS</span>
                            {t('demo.joined', 'Jordan joined the conversation')}
                            <CheckCheck size={14} />
                        </div>
                    </div>
                    <div className="m-demo-composer">
                        <Paperclip size={16} />
                        <span>{t('demo.reply', 'Write a helpful reply…')}</span>
                        <span className="m-demo-send">
                            <Send size={15} />
                        </span>
                    </div>
                </div>
            </div>
        </MotionStage>
    )
}
export function ChatDemo() {
    const { text: t, freeWhiteLabel } = useMarketing()
    return (
        <MotionStage
            className="m-chat-stage"
            label={t('demo.chat.label', 'Illustrative branded chat with a Smart Bot question and suggested replies')}
        >
            <div className="m-chat-orbit" />
            <div className="m-mini-chat">
                <div className="m-chat-top">
                    <span className="m-ai-avatar">
                        <Sparkles size={20} />
                    </span>
                    <div>
                        <strong>{t('demo.assistant', 'Your brand assistant')}</strong>
                        <small>
                            <i />
                            {t('demo.team_available', 'Team available now')}
                        </small>
                    </div>
                    <MoreHorizontal size={18} />
                </div>
                <div className="m-chat-human">
                    {t('demo.need_person', 'Need a person?')}
                    <span>{t('demo.talk_agent', 'Talk to an agent')}</span>
                </div>
                <div className="m-chat-body">
                    <div className="m-demo-bubble agent">
                        {t('demo.chat_welcome', 'Hi there! What can I help you find today?')}
                    </div>
                    <div className="m-demo-bubble customer">{t('demo.chat_ask', 'I need a little help choosing.')}</div>
                    <div className="m-demo-bubble agent m-sequence-1">
                        {t('demo.chat_followup', 'Happy to help. What matters most to you?')}
                    </div>
                    <div className="m-demo-choices m-sequence-2">
                        <span>{t('demo.choice_features', 'Compare features')}</span>
                        <span>{t('demo.choice_price', 'Find my plan')}</span>
                    </div>
                </div>
                <div className="m-demo-composer">
                    <Paperclip size={15} />
                    <span>{t('demo.message', 'Write a message…')}</span>
                    <span className="m-demo-send">
                        <Send size={15} />
                    </span>
                </div>
                <div className="m-chat-branding">{t('demo.your_brand', 'Your brand. Your customer experience.')}</div>
            </div>
            <span className="m-floating-note">
                <ShieldCheck size={15} />
                {freeWhiteLabel
                    ? t('demo.white_label', 'White-label, even on free')
                    : t('demo.brand_controls', 'Made to match your brand')}
            </span>
        </MotionStage>
    )
}
export function AIDemo() {
    const { text: t } = useMarketing()
    return (
        <MotionStage
            className="m-ai-demo"
            label={t('demo.ai.label', 'Illustration of knowledge retrieval, a supported answer, and human handoff')}
        >
            <div className="m-ai-sources">
                {[
                    ['book', 'Product guide'],
                    ['policy', 'Help center'],
                    ['file', 'Your documents'],
                ].map(([key, label], i) => (
                    <div key={key} style={{ '--delay': `${i * 150}ms` }}>
                        <FileText size={19} />
                        <span>{t(`demo.sources.${key}`, label)}</span>
                        <Check size={13} />
                    </div>
                ))}
            </div>
            <div className="m-ai-connector">
                <i />
                <i />
                <i />
            </div>
            <div className="m-ai-brain">
                <Sparkles size={32} />
                <span>{t('demo.smartbot', 'Smart Bot')}</span>
                <div className="m-brain-ring" />
            </div>
            <div className="m-ai-answer">
                <span className="m-ai-answer-label">
                    <span className="m-status-dot" />
                    {t('demo.grounded', 'Grounded in your knowledge')}
                </span>
                <p>
                    {t(
                        'demo.ai_answer',
                        'Here is how it works, based on your product guide. Would you like help getting started?',
                    )}
                </p>
                <div className="m-source-tag">
                    <FileText size={12} />
                    {t('demo.product_guide', 'Product guide')}
                    <ArrowUpRight size={12} />
                </div>
            </div>
            <div className="m-ai-outcomes">
                <span>
                    <Check size={13} />
                    {t('demo.answer_outcome', 'Answer with context')}
                </span>
                <span>
                    <CircleHelp size={13} />
                    {t('demo.clarify', 'Ask a useful question')}
                </span>
                <span>
                    <Users size={13} />
                    {t('demo.handoff', 'Bring in a person')}
                </span>
            </div>
        </MotionStage>
    )
}
export function AutomationDemo() {
    const { text: t } = useMarketing()
    return (
        <MotionStage
            className="m-flow-demo"
            label={t('demo.flow.label', 'Example workflow from a new customer message to AI or human support')}
        >
            <div className="m-flow-grid" />
            <div className="m-flow-node first">
                <span className="m-node-icon">
                    <MessageCircle size={19} />
                </span>
                <div>
                    <small>{t('demo.trigger', 'TRIGGER')}</small>
                    <strong>{t('demo.new_message', 'New customer message')}</strong>
                </div>
                <span className="m-node-status" />
            </div>
            <div className="m-flow-line" />
            <div className="m-flow-node">
                <span className="m-node-icon violet">
                    <GitBranch size={19} />
                </span>
                <div>
                    <small>{t('demo.condition', 'CONDITION')}</small>
                    <strong>{t('demo.known_question', 'Can our knowledge help?')}</strong>
                </div>
            </div>
            <div className="m-flow-branches">
                <span>{t('demo.yes', 'Yes')}</span>
                <span>{t('demo.needs_person', 'Needs a person')}</span>
            </div>
            <div className="m-flow-end">
                <div className="m-flow-node">
                    <Sparkles size={21} />
                    <strong>{t('demo.ai_reply', 'Smart Bot reply')}</strong>
                </div>
                <div className="m-flow-node">
                    <Users size={21} />
                    <strong>{t('demo.assign', 'Assign to team')}</strong>
                </div>
            </div>
            <span className="m-flow-caption">
                <CheckCheck size={15} />
                {t('demo.flow_caption', 'A repeatable process. A personal experience.')}
            </span>
        </MotionStage>
    )
}
export function MobileDemo() {
    const { text: t } = useMarketing()
    return (
        <MotionStage
            className="m-mobile-demo"
            label={t('demo.mobile.label', 'Illustrative mobile agent inbox and notification')}
        >
            <div className="m-phone-back">
                <Sparkles size={35} />
                <span>{t('demo.mobile_away', 'Good support goes with you.')}</span>
            </div>
            <div className="m-phone">
                <div className="m-phone-status">
                    9:41<span>● ▰</span>
                </div>
                <div className="m-phone-title">
                    <strong>{t('demo.inbox.name', 'Omni Inbox')}</strong>
                    <span className="m-avatar tiny">Y</span>
                </div>
                <div className="m-phone-tabs">
                    <b>{t('demo.all', 'All')}</b>
                    <span>{t('demo.mine', 'Mine')}</span>
                    <span>{t('demo.unassigned', 'Unassigned')}</span>
                </div>
                {[
                    ['AM', 'Alex Morgan', 'I have a question about my order.', 'whatsapp'],
                    ['JL', 'Jamie Lee', 'Can you help me get started?', 'instagram'],
                    ['RK', 'Riley Kim', 'Thanks for your help!', 'website'],
                ].map(([initial, name, message, brand], i) => (
                    <div className="m-phone-thread" key={initial}>
                        <span className={`m-avatar avatar-${i}`}>{initial}</span>
                        <div>
                            <strong>{name}</strong>
                            <p>{t(`demo.mobile_thread.${i}`, message)}</p>
                            <BrandMark name={brand} small />
                        </div>
                        <small>{i + 1}m</small>
                    </div>
                ))}
                <div className="m-phone-bottom">
                    <Inbox size={20} />
                    <Users size={20} />
                    <MessageCircle size={20} />
                </div>
            </div>
            <div className="m-mobile-notification">
                <img src="/wisperbot-icon-512.png" alt="" />
                <div>
                    <strong>{t('demo.new_conversation', 'New conversation')}</strong>
                    <span>{t('demo.mobile_notification', 'Your team has a customer to help.')}</span>
                </div>
                <span>{t('demo.now', 'now')}</span>
            </div>
        </MotionStage>
    )
}
export function EmailDemo() {
    const { text: t } = useMarketing()
    return (
        <MotionStage
            className="m-email-demo"
            label={t('demo.email.label', 'Illustrative Email MasterBox with multiple mailboxes')}
        >
            <div className="m-email-heading">
                <Mail size={22} />
                <strong>{t('demo.masterbox', 'Email MasterBox')}</strong>
                <span>3 {t('demo.mailboxes', 'mailboxes')}</span>
            </div>
            <div className="m-email-accounts">
                <span>
                    <BrandMark name="gmail" small />
                    Gmail
                </span>
                <span>
                    <BrandMark name="microsoft" small />
                    Microsoft
                </span>
                <span>
                    <BrandMark name="email" small />
                    IMAP
                </span>
            </div>
            {[
                ['A quick question about your service', 'Hello team, I would love to know more…'],
                ['Re: Getting started together', 'Thanks for the helpful explanation.'],
                ['Your next step', 'Here is the information you requested.'],
            ].map(([title, excerpt], i) => (
                <div className="m-email-row" key={title}>
                    <span className={`m-avatar avatar-${i}`}>
                        <Mail size={16} />
                    </span>
                    <div>
                        <strong>{t(`demo.email_title.${i}`, title)}</strong>
                        <span>{t(`demo.email_excerpt.${i}`, excerpt)}</span>
                    </div>
                    <small>{10 + i}:24</small>
                </div>
            ))}
            <div className="m-email-compose">
                <span>{t('demo.email_reply', 'Reply with your whole team in the loop.')}</span>
                <Send size={19} />
            </div>
        </MotionStage>
    )
}
export function SocialDemo() {
    const { text: t } = useMarketing()
    return (
        <MotionStage
            className="m-social-demo"
            label={t('demo.social.label', 'Illustrative social publishing calendar')}
        >
            <div className="m-social-heading">
                <strong>{t('demo.content_calendar', 'Your content calendar')}</strong>
                <span>
                    <Clock3 size={13} />
                    {t('demo.scheduled', 'Scheduled')}
                </span>
            </div>
            <div className="m-social-week">
                {['MON', 'TUE', 'WED', 'THU', 'FRI'].map((day, i) => (
                    <div key={day}>
                        <small>{t(`demo.days.${i}`, day)}</small>
                        <b>{12 + i}</b>
                    </div>
                ))}
            </div>
            <div className="m-social-post">
                <div className="m-social-art">
                    <span>{t('demo.launch', 'Something good is coming.')}</span>
                    <span className="m-art-sun" />
                </div>
                <div className="m-social-post-bottom">
                    <div>
                        <BrandMark name="instagram" small />
                        <BrandMark name="facebook" small />
                        <BrandMark name="linkedin" small />
                    </div>
                    <span>
                        <Check size={13} />
                        {t('demo.ready_publish', 'Ready to publish')}
                    </span>
                </div>
            </div>
            <div className="m-social-queue">
                <span>
                    <FileText size={15} />
                    {t('demo.draft', 'Product story')}
                </span>
                <span>{t('demo.tomorrow', 'Tomorrow, 10:00')}</span>
                <ArrowUpRight size={15} />
            </div>
        </MotionStage>
    )
}
export function CommerceDemo() {
    const { text: t } = useMarketing()
    return (
        <MotionStage
            className="m-commerce-demo"
            label={t('demo.commerce.label', 'Illustrative connected store and order context')}
        >
            <div className="m-commerce-product">
                <div className="m-product-object">
                    <ShoppingBag size={75} strokeWidth={1} />
                </div>
                <span>{t('demo.product_collection', 'Everyday essentials')}</span>
            </div>
            <div className="m-order-card">
                <span className="m-order-label">
                    <ShoppingBag size={15} />
                    {t('demo.order_context', 'Order context')}
                </span>
                <strong>#1048</strong>
                <div>
                    <span>{t('demo.order_items', '2 items')}</span>
                    <span className="m-demo-pill">{t('demo.fulfilled', 'Fulfilled')}</span>
                </div>
                <hr />
                <p>{t('demo.order_help', 'The details your team needs, alongside the conversation.')}</p>
                <div className="m-commerce-brands">
                    <BrandMark name="shopify" />
                    <BrandMark name="woocommerce" />
                    <BrandMark name="bigcommerce" />
                </div>
            </div>
        </MotionStage>
    )
}
export function DeveloperDemo() {
    const { text: t } = useMarketing()
    return (
        <MotionStage
            className="m-code-demo"
            label={t('demo.developer.label', 'Illustration of SDK, API, and webhook connections')}
        >
            <div className="m-code-top">
                <span className="m-window-dots">
                    <i />
                    <i />
                    <i />
                </span>
                <span>{t('demo.integration', 'your integration')}</span>
                <Code2 size={16} />
            </div>
            <div className="m-code-body">
                <span className="m-code-comment">
                    {'// '}
                    {t('demo.code_comment', 'Make conversations part of your product')}
                </span>
                <p>
                    <b>connect</b>({'{'}
                </p>
                <p>
                    &nbsp; workspace: <em>&quot;your-workspace&quot;</em>,
                </p>
                <p>
                    &nbsp; channels: [<em>&quot;chat&quot;</em>, <em>&quot;webhooks&quot;</em>],
                </p>
                <p>
                    &nbsp; experience: <em>&quot;your-brand&quot;</em>
                </p>
                <p>{'}'});</p>
                <span className="m-code-comment">
                    {'// '}
                    {t('demo.pseudocode', 'Illustrative pseudocode — see API documentation')}
                </span>
            </div>
            <div className="m-code-connections">
                <span>
                    <Smartphone size={16} />
                    SDK
                </span>
                <ArrowRight size={15} />
                <span>
                    <Code2 size={16} />
                    API
                </span>
                <ArrowRight size={15} />
                <span>
                    <Zap size={16} />
                    Webhooks
                </span>
            </div>
        </MotionStage>
    )
}
export function ProductDemo({ type = 'inbox', compact = false }) {
    const components = {
        chat: ChatDemo,
        ai: AIDemo,
        automation: AutomationDemo,
        mobile: MobileDemo,
        email: EmailDemo,
        social: SocialDemo,
        commerce: CommerceDemo,
        developer: DeveloperDemo,
    }
    const Component = components[type]
    return Component ? <Component /> : <InboxDemo compact={compact} />
}
