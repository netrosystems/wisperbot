import { Link } from '@inertiajs/react'
import { useEffect, useRef, useState } from 'react'
import { ArrowLeft, ArrowUpRight, BookOpen, ListTree, Share2 } from 'lucide-react'
import LandingLayout from '@/Layouts/LandingLayout'
import SeoHead from '@/Components/SeoHead'
import { useMarketing, SectionHeading } from '@/Components/marketing/MarketingUI'
const formatDate = (date) =>
    date ? new Intl.DateTimeFormat(undefined, { dateStyle: 'long' }).format(new Date(date)) : ''
const wasUpdated = (post) =>
    post.updated_at_iso &&
    post.published_at_iso &&
    new Date(post.updated_at_iso) - new Date(post.published_at_iso) > 86_400_000

function TableOfContents({ outline, label, compact = false }) {
    if (!outline?.length) return null
    const links = (
        <nav aria-label={label}>
            <ol className="space-y-2.5">
                {outline.map((item) => (
                    <li key={`${item.id}-${item.level}`} className={item.level === 3 ? 'pl-3' : ''}>
                        <a
                            href={`#${item.id}`}
                            className="block text-sm leading-5 text-neutral-500 transition hover:text-brand-600 dark:text-neutral-400"
                        >
                            {item.text}
                        </a>
                    </li>
                ))}
            </ol>
        </nav>
    )
    if (compact)
        return (
            <details className="m-blog-toc-compact">
                <summary>
                    <ListTree size={16} /> {label}
                </summary>
                <div>{links}</div>
            </details>
        )
    return (
        <aside className="m-blog-toc">
            <div className="m-blog-toc-title">
                <ListTree size={16} /> {label}
            </div>
            {links}
        </aside>
    )
}
export default function BlogShow({ post, related, preview = false }) {
    const { text: t } = useMarketing()
    const [shareStatus, setShareStatus] = useState('')
    const [progress, setProgress] = useState(0)
    const articleRef = useRef(null)
    useEffect(() => {
        const updateProgress = () => {
            const article = articleRef.current
            if (!article) return
            const rect = article.getBoundingClientRect()
            const readable = Math.max(article.offsetHeight - window.innerHeight, 1)
            setProgress(Math.min(100, Math.max(0, (-rect.top / readable) * 100)))
        }
        updateProgress()
        window.addEventListener('scroll', updateProgress, { passive: true })
        window.addEventListener('resize', updateProgress)
        return () => {
            window.removeEventListener('scroll', updateProgress)
            window.removeEventListener('resize', updateProgress)
        }
    }, [])
    const origin = typeof window !== 'undefined' ? window.location.origin : ''
    const share = async () => {
        try {
            if (navigator.share) await navigator.share({ title: post.title, url: post.url })
            else {
                await navigator.clipboard.writeText(post.url)
                setShareStatus(t('blog.copied', 'Link copied'))
            }
        } catch (error) {
            if (error.name !== 'AbortError')
                setShareStatus(t('blog.copy_failed', 'Use the address bar to copy this article link.'))
        }
    }
    const articleSchema = {
        '@context': 'https://schema.org',
        '@type': post.schema_type || 'BlogPosting',
        headline: post.title,
        description: post.seo_description,
        image: post.og_image_url || post.featured_image_url || undefined,
        datePublished: post.published_at_iso,
        dateModified: post.updated_at_iso,
        author:
            post.show_author && post.author
                ? { '@type': 'Person', name: post.author.name }
                : { '@type': 'Organization', name: 'WisperBot' },
        publisher: {
            '@type': 'Organization',
            name: 'WisperBot',
            logo: { '@type': 'ImageObject', url: origin + '/wisperbot-icon-512.png' },
        },
        mainEntityOfPage: post.url,
    }
    const jsonLd = [
        articleSchema,
        {
            '@context': 'https://schema.org',
            '@type': 'BreadcrumbList',
            itemListElement: [
                { '@type': 'ListItem', position: 1, name: 'Blog', item: origin + '/blog' },
                { '@type': 'ListItem', position: 2, name: post.title, item: post.url },
            ],
        },
    ]
    if (post.faqs?.length)
        jsonLd.push({
            '@context': 'https://schema.org',
            '@type': 'FAQPage',
            mainEntity: post.faqs.map((faq) => ({
                '@type': 'Question',
                name: faq.question,
                acceptedAnswer: { '@type': 'Answer', text: faq.answer },
            })),
        })
    const tocLabel = t('blog.contents', 'In this article')
    return (
        <LandingLayout>
            <SeoHead
                title={post.seo_title}
                description={post.seo_description}
                keywords={post.meta_keywords}
                image={post.og_image_url || post.featured_image_url}
                canonical={post.canonical}
                jsonLd={jsonLd}
                type="article"
                noindex={preview || !post.allow_indexing}
                article={{
                    publishedTime: post.published_at_iso,
                    modifiedTime: post.updated_at_iso,
                    author: post.show_author ? post.author?.name : undefined,
                    section: post.category?.name,
                }}
            />
            <div className="fixed inset-x-0 top-0 z-[60] h-1 bg-transparent" aria-hidden="true">
                <div
                    className="h-full bg-brand-500 transition-[width] duration-150"
                    style={{ width: `${progress}%` }}
                />
            </div>
            {preview && (
                <div className="m-announcement">{t('blog.preview', 'Preview — this article may not be public.')}</div>
            )}
            <article ref={articleRef}>
                <header className="m-page-hero m-container">
                    <div className="m-breadcrumb">
                        <Link href="/blog" className="m-text-link">
                            <ArrowLeft size={13} />
                            {t('blog.back', 'Back to the journal')}
                        </Link>
                    </div>
                    {post.category && (
                        <Link className="m-eyebrow" href={route('blog.index', { category: post.category.slug })}>
                            {post.category.name}
                        </Link>
                    )}
                    <h1>{post.title}</h1>
                    <p>{post.excerpt}</p>
                    <div className="m-article-meta">
                        <span>{formatDate(post.published_at)}</span>
                        {wasUpdated(post) && (
                            <span>
                                {t('blog.updated', 'Updated')} {formatDate(post.updated_at_iso)}
                            </span>
                        )}
                        <span>
                            {post.reading_minutes} {t('blog.minutes', 'min read')}
                        </span>
                        {post.show_author && post.author && (
                            <span>
                                {t('blog.by', 'By')} {post.author.name}
                            </span>
                        )}
                        <button onClick={share}>
                            <Share2 size={14} />
                            {t('blog.share', 'Share')}
                        </button>
                        <span role="status">{shareStatus}</span>
                    </div>
                </header>
                {post.featured_image_url && (
                    <div className="m-container m-article-image">
                        <img src={post.featured_image_url} alt={post.featured_image_alt || post.title} />
                    </div>
                )}
                <div className="m-section-sm m-container">
                    <div className={post.outline?.length ? 'm-blog-layout' : undefined}>
                        <TableOfContents outline={post.outline} label={tocLabel} />
                        <div className="m-blog-reading">
                            <TableOfContents outline={post.outline} label={tocLabel} compact />
                            <div className="cms-prose blog-prose" dangerouslySetInnerHTML={{ __html: post.content }} />
                            {post.tags?.length > 0 && (
                                <div className="m-blog-categories">
                                    {post.tags.map((tag) => (
                                        <Link key={tag.id} href={route('blog.index', { tag: tag.slug })}>
                                            #{tag.name}
                                        </Link>
                                    ))}
                                </div>
                            )}
                        </div>
                    </div>
                </div>
            </article>
            {related?.length > 0 && (
                <section className="m-section m-soft">
                    <div className="m-container">
                        <SectionHeading
                            eyebrow={t('blog.keep_reading', 'Keep exploring')}
                            title={t('blog.more', 'More food for thought.')}
                        />
                        <div className="m-guide-grid">
                            {related.map((item) => (
                                <Link className="m-guide-card" key={item.id} href={'/blog/' + item.slug}>
                                    <div className="m-guide-art">
                                        {item.featured_image_url ? (
                                            <img
                                                src={item.featured_image_url}
                                                alt={item.featured_image_alt || item.title}
                                                loading="lazy"
                                            />
                                        ) : (
                                            <BookOpen />
                                        )}
                                    </div>
                                    <div className="m-guide-copy">
                                        <h3>{item.title}</h3>
                                        <p>{item.excerpt}</p>
                                        <span className="m-text-link">
                                            {t('blog.read', 'Read article')}
                                            <ArrowUpRight size={14} />
                                        </span>
                                    </div>
                                </Link>
                            ))}
                        </div>
                    </div>
                </section>
            )}
        </LandingLayout>
    )
}
