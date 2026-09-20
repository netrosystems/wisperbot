import { useForm, usePage } from '@inertiajs/react'
import { Mail, Send } from 'lucide-react'
import LandingLayout from '@/Layouts/LandingLayout'
import SeoHead from '@/Components/SeoHead'
import { PageHero, MLink, useMarketing } from '@/Components/marketing/MarketingUI'
export default function Contact() {
    const { text: t, setting } = useMarketing()
    const { flash } = usePage().props
    const { data, setData, post, processing, errors, recentlySuccessful, reset } = useForm({
        name: '',
        email: '',
        subject: 'Sales & product questions',
        message: '',
    })
    const submit = (e) => {
        e.preventDefault()
        post('/contact', { preserveScroll: true, onSuccess: () => reset('name', 'email', 'message') })
    }
    return (
        <LandingLayout>
            <SeoHead
                title={t('contact.seo', 'Contact WisperBot')}
                description={t(
                    'contact.description',
                    'Talk to WisperBot about customer support, product setup, integrations, or your next step.',
                )}
            />
            <PageHero
                eyebrow={t('nav.contact', 'Contact us')}
                title={t('contact.title', 'Let us talk about your next conversation.')}
                description={t(
                    'contact.body',
                    'Choosing a channel, planning your rollout, or need a hand? Tell us a little about what you are working on.',
                )}
            />
            <section className="m-section-sm m-container">
                <div className="m-contact-grid">
                    <div className="m-contact-options">
                        <div className="m-contact-option">
                            <h2>{t('contact.sales', 'Find the right fit.')}</h2>
                            <p>
                                {t(
                                    'contact.sales_body',
                                    'Talk through your customer channels, team workflow, and AI support goals.',
                                )}
                            </p>
                            <button
                                className="m-text-link"
                                onClick={() => {
                                    setData('subject', 'Sales & product questions')
                                    document.getElementById('contact-message')?.focus()
                                }}
                            >
                                {t('contact.sales_action', 'Ask about WisperBot')}
                                <Send size={14} />
                            </button>
                        </div>
                        <div className="m-contact-option">
                            <h2>{t('contact.support', 'Already with us?')}</h2>
                            <p>
                                {t(
                                    'contact.support_body',
                                    'Share the workspace or feature you need help with. Keep passwords and access tokens out of your message.',
                                )}
                            </p>
                            <MLink
                                href={'mailto:' + setting('contact_email', 'support@wisperbot.com')}
                                className="m-text-link"
                            >
                                <Mail size={14} />
                                {setting('contact_email', 'support@wisperbot.com')}
                            </MLink>
                        </div>
                    </div>
                    <form className="m-contact-form" onSubmit={submit}>
                        {(flash?.success || recentlySuccessful) && (
                            <div role="status" className="m-form-success">
                                {flash?.success || t('contact.sent', 'Your message has been received.')}
                            </div>
                        )}
                        <div className="m-contact-fields">
                            {[
                                ['name', 'Your name', 'text'],
                                ['email', 'Work email', 'email'],
                            ].map(([key, label, type]) => (
                                <label key={key}>
                                    {t('contact.' + key, label)}
                                    <input
                                        name={key}
                                        type={type}
                                        value={data[key]}
                                        onChange={(e) => setData(key, e.target.value)}
                                        required
                                        maxLength={255}
                                        autoComplete={key === 'name' ? 'name' : 'email'}
                                        aria-invalid={Boolean(errors[key])}
                                    />
                                    {errors[key] && <span className="m-error">{errors[key]}</span>}
                                </label>
                            ))}
                        </div>
                        <label>
                            {t('contact.subject', 'What can we help with?')}
                            <select value={data.subject} onChange={(e) => setData('subject', e.target.value)}>
                                {[
                                    'Sales & product questions',
                                    'Product support',
                                    'Integration & SDK help',
                                    'Something else',
                                ].map((label, i) => (
                                    <option key={label} value={label}>
                                        {t('contact.reason_' + i, label)}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label>
                            {t('contact.message', 'Tell us a little more')}
                            <textarea
                                id="contact-message"
                                name="message"
                                rows={6}
                                maxLength={5000}
                                required
                                value={data.message}
                                onChange={(e) => setData('message', e.target.value)}
                                aria-invalid={Boolean(errors.message)}
                            />
                            {errors.message && <span className="m-error">{errors.message}</span>}
                        </label>
                        <button disabled={processing} className="m-button" type="submit">
                            {processing ? t('contact.sending', 'Sending…') : t('contact.send', 'Send message')}
                            <Send size={16} />
                        </button>
                    </form>
                </div>
            </section>
        </LandingLayout>
    )
}
