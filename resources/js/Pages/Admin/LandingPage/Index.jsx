import { useState } from 'react'
import { Head, useForm } from '@inertiajs/react'
import { useTranslation } from 'react-i18next'
import { ExternalLink, Globe, Save } from 'lucide-react'
import AdminLayout from '@/Layouts/AdminLayout'
import { Button, Card } from '@/Components/ui'

const sections = [
    {
        key: 'general',
        name: 'Website & navigation',
        fields: [
            ['announcement', 'Announcement text'],
            ['announcement_url', 'Announcement destination'],
            ['signin_label', 'Log in label'],
            ['signin_link_type', 'Log in destination type', 'select'],
            ['signin_link_url', 'Custom log in URL'],
            ['getstarted_label', 'Start free label'],
            ['getstarted_link_type', 'Start free destination type', 'select'],
            ['getstarted_link_url', 'Custom registration URL'],
            ['demo_url', 'Contact or demo destination'],
            ['contact_email', 'Contact email'],
        ],
    },
    {
        key: 'hero',
        name: 'Homepage & closing CTA',
        fields: [
            ['hero_title', 'Homepage headline'],
            ['hero_subtitle', 'Homepage supporting text', 'textarea'],
            ['hero_cta_primary', 'Primary action label'],
            ['hero_cta_secondary', 'Hero Agent App button label'],
            ['cta_title', 'Closing headline'],
            ['cta_subtitle', 'Closing supporting text', 'textarea'],
            ['cta_primary', 'Closing primary action'],
            ['cta_secondary', 'Closing contact action'],
        ],
    },
    {
        key: 'downloads',
        name: 'Apps & developer links',
        fields: [
            ['agent_app_ios_url', 'iOS Agent App — apps.apple.com'],
            ['agent_app_android_url', 'Android Agent App — play.google.com'],
            ['chat_sdk_pubdev_url', 'Customer Chat SDK — pub.dev'],
            ['developer_docs_url', 'Public developer documentation'],
        ],
    },
    {
        key: 'seo',
        name: 'Search & sharing',
        fields: [
            ['seo_title', 'Default homepage title'],
            ['seo_description', 'Meta description', 'textarea'],
            ['seo_keywords', 'Keywords'],
            ['seo_og_image', 'Social sharing image URL'],
        ],
    },
    {
        key: 'faq',
        name: 'Questions & answers',
        fields: Array.from({ length: 8 }, (_, i) => i + 1).flatMap((i) => [
            ['faq_' + i + '_q', 'Question ' + i],
            ['faq_' + i + '_a', 'Answer ' + i, 'textarea'],
            ['faq_' + i + '_category', 'Category ' + i, 'category'],
        ]),
    },
]
export default function LandingPageIndex({ settings = {} }) {
    const { t } = useTranslation()
    const text = (key, fallback) => t('marketing.admin.' + key, { defaultValue: fallback })
    const [active, setActive] = useState('general')
    const { data, setData, put, processing, errors, recentlySuccessful } = useForm({ settings })
    const section = sections.find((s) => s.key === active)
    const update = (key, value) => setData('settings', { ...data.settings, ['landing.' + key]: value })
    const submit = (event) => {
        event?.preventDefault()
        put(route('admin.landing-page.update'), { preserveScroll: true })
    }
    const input =
        'mt-2 w-full rounded-lg border-neutral-300 bg-white text-sm text-neutral-800 focus:border-brand-500 focus:ring-brand-500 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100'
    return (
        <AdminLayout title={text('title', 'Public website content')}>
            <Head title={text('title', 'Public website content')} />
            <div className="space-y-6">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h1 className="text-xl font-semibold">{text('title', 'Public website content')}</h1>
                        <p className="mt-2 max-w-2xl text-sm text-neutral-500">
                            {text(
                                'description',
                                'Manage shared copy, calls to action, official app links, FAQs, and SEO. Product structure and provider capabilities follow the reviewed product catalogue.',
                            )}
                        </p>
                    </div>
                    <div className="flex gap-2">
                        <a
                            href="/"
                            target="_blank"
                            rel="noopener noreferrer"
                            className="inline-flex items-center gap-2 rounded-lg border border-neutral-200 px-4 py-2 text-sm dark:border-neutral-700"
                        >
                            {text('preview', 'Preview website')}
                            <ExternalLink size={14} />
                        </a>
                        <Button onClick={submit} disabled={processing}>
                            <Save size={15} />
                            {processing ? text('saving', 'Saving…') : text('save', 'Save changes')}
                        </Button>
                    </div>
                </div>
                {recentlySuccessful && (
                    <div role="status" className="rounded-lg bg-green-50 p-4 text-sm text-green-800">
                        {text('saved', 'Website content saved.')}
                    </div>
                )}
                {errors.settings && (
                    <p role="alert" className="text-sm text-red-600">
                        {errors.settings}
                    </p>
                )}
                <Card>
                    <Card.Body>
                        <label className="flex items-center gap-3">
                            <Globe size={21} className="text-brand-500" />
                            <span className="flex-1">
                                <strong className="text-sm">{text('enabled', 'Public marketing website')}</strong>
                                <span className="mt-1 block text-xs text-neutral-500">
                                    {text(
                                        'enabled_hint',
                                        'When disabled, marketing pages redirect visitors to log in.',
                                    )}
                                </span>
                            </span>
                            <input
                                type="checkbox"
                                checked={data.settings['landing.page_enabled'] === '1'}
                                onChange={(e) => update('page_enabled', e.target.checked ? '1' : '0')}
                                className="rounded border-neutral-300 text-brand-500 focus:ring-brand-500"
                            />
                        </label>
                    </Card.Body>
                </Card>
                <div className="flex flex-wrap gap-2">
                    {sections.map((item) => (
                        <button
                            key={item.key}
                            type="button"
                            onClick={() => setActive(item.key)}
                            aria-pressed={active === item.key}
                            className={
                                'rounded-full px-4 py-2 text-sm ' +
                                (active === item.key
                                    ? 'bg-brand-500 text-white'
                                    : 'bg-neutral-100 text-neutral-600 dark:bg-neutral-800 dark:text-neutral-300')
                            }
                        >
                            {text('section.' + item.key, item.name)}
                        </button>
                    ))}
                </div>
                <form onSubmit={submit}>
                    <Card>
                        <Card.Body>
                            <h2 className="mb-5 font-semibold">{text('section.' + section.key, section.name)}</h2>
                            {active === 'downloads' && (
                                <p className="mb-6 rounded-lg bg-orange-50 p-4 text-sm text-orange-900 dark:bg-orange-950 dark:text-orange-200">
                                    {text(
                                        'download_hint',
                                        'Only official HTTPS store/package URLs are accepted. Leave a link blank to hide that destination. Add the pub.dev URL whenever the SDK package is ready.',
                                    )}
                                </p>
                            )}
                            <div className="grid gap-6 md:grid-cols-2">
                                {section.fields.map(([key, label, type]) => (
                                    <label
                                        key={key}
                                        className={
                                            'block text-sm font-medium ' + (type === 'textarea' ? 'md:col-span-2' : '')
                                        }
                                        htmlFor={'site-' + key}
                                    >
                                        {text('field.' + key, label)}
                                        {type === 'textarea' ? (
                                            <textarea
                                                id={'site-' + key}
                                                className={input}
                                                rows={3}
                                                maxLength={5000}
                                                value={data.settings['landing.' + key] || ''}
                                                onChange={(e) => update(key, e.target.value)}
                                            />
                                        ) : type === 'select' || type === 'category' ? (
                                            <select
                                                id={'site-' + key}
                                                className={input}
                                                value={
                                                    data.settings['landing.' + key] ||
                                                    (type === 'category' ? 'product' : 'dynamic')
                                                }
                                                onChange={(e) => update(key, e.target.value)}
                                            >
                                                {(type === 'select'
                                                    ? [
                                                          ['dynamic', 'Built-in page'],
                                                          ['static', 'Custom URL'],
                                                      ]
                                                    : [
                                                          ['product', 'Product'],
                                                          ['setup', 'Setup'],
                                                          ['ai', 'AI'],
                                                          ['billing', 'Billing'],
                                                          ['security', 'Access & security'],
                                                      ]
                                                ).map(([value, name]) => (
                                                    <option key={value} value={value}>
                                                        {text('option.' + value, name)}
                                                    </option>
                                                ))}
                                            </select>
                                        ) : (
                                            <input
                                                id={'site-' + key}
                                                className={input}
                                                type={key === 'contact_email' ? 'email' : 'text'}
                                                maxLength={5000}
                                                value={data.settings['landing.' + key] || ''}
                                                onChange={(e) => update(key, e.target.value)}
                                            />
                                        )}{' '}
                                        {errors['settings.landing.' + key] && (
                                            <span role="alert" className="mt-2 block text-xs text-red-600">
                                                {errors['settings.landing.' + key]}
                                            </span>
                                        )}
                                    </label>
                                ))}
                            </div>
                        </Card.Body>
                    </Card>
                    <div className="mt-6 flex justify-end">
                        <Button type="submit" disabled={processing}>
                            {processing ? text('saving', 'Saving…') : text('save', 'Save changes')}
                        </Button>
                    </div>
                </form>
            </div>
        </AdminLayout>
    )
}
