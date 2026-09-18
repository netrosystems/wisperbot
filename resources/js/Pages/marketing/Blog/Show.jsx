import { Link } from '@inertiajs/react'
import { useState } from 'react'
import { ArrowLeft, ArrowUpRight, BookOpen, Share2 } from 'lucide-react'
import LandingLayout from '@/Layouts/LandingLayout'
import SeoHead from '@/Components/SeoHead'
import { useMarketing, SectionHeading } from '@/Components/marketing/MarketingUI'
const formatDate = (date) =>
    date ? new Intl.DateTimeFormat(undefined, { dateStyle: 'long' }).format(new Date(date)) : ''
export default function BlogShow({ post, related, preview = false }) {
    const { text: t } = useMarketing()
    const [shareStatus, setShareStatus] = useState('')
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
    const jsonLd = {
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
            {preview && (
                <div className="m-announcement">{t('blog.preview', 'Preview — this article may not be public.')}</div>
            )}
            <article>
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
                    <div className="m-blog-reading">
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
