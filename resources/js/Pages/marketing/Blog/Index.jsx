import { Link, router } from '@inertiajs/react'
import { useState } from 'react'
import { ArrowUpRight, BookOpen, Search } from 'lucide-react'
import LandingLayout from '@/Layouts/LandingLayout'
import SeoHead from '@/Components/SeoHead'
import { PageHero, FinalCTA, useMarketing } from '@/Components/marketing/MarketingUI'
function PostCard({ post }) {
    const { text: t } = useMarketing()
    return (
        <article className="m-guide-card">
            <Link href={'/blog/' + post.slug} className="m-guide-art">
                {post.featured_image_url ? (
                    <img src={post.featured_image_url} alt={post.featured_image_alt || post.title} loading="lazy" />
                ) : (
                    <BookOpen />
                )}
            </Link>
            <div className="m-guide-copy">
                <span>
                    {post.category?.name || t('blog.guide', 'Guide')} · {post.reading_minutes}{' '}
                    {t('blog.minutes', 'min read')}
                </span>
                <h2>
                    <Link href={'/blog/' + post.slug}>{post.title}</Link>
                </h2>
                <p>{post.excerpt}</p>
                <Link href={'/blog/' + post.slug} className="m-text-link">
                    {t('blog.read', 'Read article')}
                    <ArrowUpRight size={14} />
                </Link>
            </div>
        </article>
    )
}
export default function BlogIndex({ posts, featured, categories, filters, seo }) {
    const { text: t } = useMarketing()
    const [search, setSearch] = useState(filters?.search || '')
    const submit = (e) => {
        e.preventDefault()
        router.get('/blog', { ...filters, search }, { preserveState: true, replace: true })
    }
    return (
        <LandingLayout>
            <SeoHead
                title={seo?.title || 'WisperBot Blog'}
                description={seo?.description}
                canonical={seo?.canonical}
                noindex={seo?.noindex}
                jsonLd={{
                    '@context': 'https://schema.org',
                    '@type': 'Blog',
                    name: 'WisperBot Blog',
                    url: route('blog.index'),
                }}
            />
            <PageHero
                eyebrow={t('blog.eyebrow', 'The WisperBot journal')}
                title={t('blog.title', 'A little knowledge. A better conversation.')}
                description={t(
                    'blog.body',
                    'Ideas, guides, and practical thinking for customer support, AI, and connected teams.',
                )}
            >
                <form className="m-search" onSubmit={submit}>
                    <Search size={18} />
                    <label className="sr-only" htmlFor="blog-search">
                        {t('blog.search_label', 'Search articles')}
                    </label>
                    <input
                        id="blog-search"
                        type="search"
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        placeholder={t('blog.search', 'Search articles…')}
                    />
                    <button type="submit" aria-label={t('blog.submit', 'Search')}>
                        <ArrowUpRight size={18} />
                    </button>
                </form>
            </PageHero>
            <section className="m-section-sm m-container">
                {featured && !filters?.search && !filters?.category && !filters?.tag && (
                    <article className="m-blog-featured">
                        <Link href={'/blog/' + featured.slug} className="m-guide-art">
                            {featured.featured_image_url ? (
                                <img
                                    src={featured.featured_image_url}
                                    alt={featured.featured_image_alt || featured.title}
                                />
                            ) : (
                                <BookOpen />
                            )}
                        </Link>
                        <div>
                            <span className="m-eyebrow">{t('blog.featured', 'Featured insight')}</span>
                            <h2>{featured.title}</h2>
                            <p>{featured.excerpt}</p>
                            <Link href={'/blog/' + featured.slug} className="m-text-link">
                                {t('blog.read', 'Read article')}
                                <ArrowUpRight size={16} />
                            </Link>
                        </div>
                    </article>
                )}
                <nav className="m-blog-categories" aria-label={t('blog.categories', 'Article categories')}>
                    <Link href="/blog" aria-current={!filters?.category ? 'page' : undefined}>
                        {t('blog.all', 'All topics')}
                    </Link>
                    {categories.map((category) => (
                        <Link
                            key={category.id}
                            href={route('blog.index', { category: category.slug })}
                            aria-current={filters?.category === category.slug ? 'page' : undefined}
                        >
                            {category.name}
                            <span>{category.posts_count}</span>
                        </Link>
                    ))}
                </nav>
                {posts.data.length ? (
                    <div className="m-guide-grid">
                        {posts.data.map((post) => (
                            <PostCard post={post} key={post.id} />
                        ))}
                    </div>
                ) : (
                    <div className="m-empty">
                        <BookOpen size={25} style={{ margin: '0 auto 15px' }} />
                        {t('blog.empty', 'No articles found. Try another topic or search term.')}
                    </div>
                )}
                {posts.links?.length > 3 && (
                    <nav className="m-blog-pagination" aria-label={t('blog.pagination', 'Blog pagination')}>
                        {posts.links.map((link, index) =>
                            link.url ? (
                                <Link
                                    key={index}
                                    href={link.url}
                                    preserveScroll
                                    aria-current={link.active ? 'page' : undefined}
                                    dangerouslySetInnerHTML={{ __html: link.label }}
                                />
                            ) : (
                                <span key={index} dangerouslySetInnerHTML={{ __html: link.label }} />
                            ),
                        )}
                    </nav>
                )}
            </section>
            <FinalCTA />
        </LandingLayout>
    )
}
