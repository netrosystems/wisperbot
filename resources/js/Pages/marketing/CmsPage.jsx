import { useMemo } from 'react'
import LandingLayout from '@/Layouts/LandingLayout'
import SeoHead from '@/Components/SeoHead'
import { PageHero, useMarketing } from '@/Components/marketing/MarketingUI'

/** Slugify a heading string for use as an anchor id. */
function slugify(text, fallback) {
    const base = text
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-|-$/g, '')
    return base || fallback
}

export default function CmsPage({ page }) {
    const { text: t } = useMarketing()
    // Extract h2 headings from the seeded/admin HTML to power both the
    // left-rail ToC and the on-page anchor ids. Cheap and works for the
    // current content shape (h2 sections).
    const headings = useMemo(() => {
        if (!page.content) return []
        return [...page.content.matchAll(/<h2[^>]*>(.*?)<\/h2>/gi)].map((m, i) => {
            const text = m[1].replace(/<[^>]+>/g, '').trim()
            return { id: slugify(text, `section-${i}`), text }
        })
    }, [page.content])

    // Inject `id="..."` onto every h2 in the rendered HTML so the ToC anchors
    // resolve. Dedupe ids by suffixing -2, -3 on collisions.
    const annotatedContent = useMemo(() => {
        const seen = new Map()
        return (page.content ?? '').replace(/<h2(?![^>]*\sid=)([^>]*)>(.*?)<\/h2>/gi, (_, attrs, inner) => {
            const text = inner.replace(/<[^>]+>/g, '')
            const base = slugify(text, 'section')
            const n = (seen.get(base) ?? 0) + 1
            seen.set(base, n)
            const id = n === 1 ? base : `${base}-${n}`
            return `<h2 id="${id}"${attrs}>${inner}</h2>`
        })
    }, [page.content])

    return (
        <LandingLayout>
            <SeoHead title={page.meta_title ?? page.title} description={page.meta_description} />

            <PageHero
                eyebrow={t('cms.eyebrow', 'Policies & information')}
                title={page.title}
                description={page.last_updated ? t('cms.updated', 'Last updated') + ' ' + page.last_updated : ''}
            />

            {/* Cream article body */}
            <section className="m-cms-body">
                <div className="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-16 lg:py-24">
                    <div className="lg:grid lg:grid-cols-[16rem_minmax(0,1fr)] lg:gap-12">
                        {headings.length > 1 && (
                            <aside className="hidden lg:block">
                                <div className="sticky top-28">
                                    <p className="text-xs font-semibold uppercase tracking-wider text-neutral-500 mb-3">
                                        {t('cms.contents', 'On this page')}
                                    </p>
                                    <nav>
                                        <ul className="space-y-2 text-sm">
                                            {headings.map((h) => (
                                                <li key={h.id}>
                                                    <a
                                                        href={`#${h.id}`}
                                                        className="text-neutral-600 hover:text-brand-600 transition"
                                                    >
                                                        {h.text}
                                                    </a>
                                                </li>
                                            ))}
                                        </ul>
                                    </nav>
                                </div>
                            </aside>
                        )}

                        <article className="cms-prose max-w-none">
                            <div dangerouslySetInnerHTML={{ __html: annotatedContent }} />
                        </article>
                    </div>
                </div>
            </section>
        </LandingLayout>
    )
}
