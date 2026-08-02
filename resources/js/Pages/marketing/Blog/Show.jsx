import { Link } from '@inertiajs/react';
import { ArrowLeft, BookOpen, Calendar, Clock, Share2 } from 'lucide-react';
import LandingLayout from '@/Layouts/LandingLayout';
import SeoHead from '@/Components/SeoHead';

const formatDate = (date) => date ? new Intl.DateTimeFormat(undefined, { dateStyle: 'long' }).format(new Date(date)) : '';

export default function BlogShow({ post, related, preview = false }) {
    const origin = typeof window !== 'undefined' ? window.location.origin : '';
    const jsonLd = {
        '@context': 'https://schema.org', '@type': post.schema_type || 'BlogPosting', headline: post.title,
        description: post.seo_description, image: post.og_image_url || post.featured_image_url || undefined,
        datePublished: post.published_at_iso, dateModified: post.updated_at_iso,
        author: post.author ? { '@type': 'Person', name: post.author.name } : { '@type': 'Organization', name: 'WisperBot' },
        publisher: { '@type': 'Organization', name: 'WisperBot', logo: { '@type': 'ImageObject', url: `${origin}/wisperbot-icon-512.png` } },
        mainEntityOfPage: post.url,
    };
    const share = async () => { if (navigator.share) await navigator.share({ title: post.title, url: post.url }); else await navigator.clipboard.writeText(post.url); };
    return (
        <LandingLayout>
            <SeoHead title={post.seo_title} description={post.seo_description} keywords={post.meta_keywords} image={post.og_image_url || post.featured_image_url} canonical={post.canonical} jsonLd={jsonLd} type="article" noindex={preview || !post.allow_indexing} article={{ publishedTime: post.published_at_iso, modifiedTime: post.updated_at_iso, author: post.author?.name, section: post.category?.name }} />
            {preview && <div className="bg-amber-400 px-4 py-2 text-center text-sm font-semibold text-amber-950">Preview mode — this article is not necessarily public.</div>}
            <article>
                <header className="border-b border-neutral-200 bg-gradient-to-b from-orange-50 to-white py-16 dark:border-neutral-800 dark:from-orange-950/30 dark:to-neutral-950">
                    <div className="mx-auto max-w-4xl px-4 sm:px-6"><Link href={route('blog.index')} className="inline-flex items-center gap-2 text-sm font-medium text-neutral-500 hover:text-brand-600"><ArrowLeft className="h-4 w-4" /> Back to the blog</Link>{post.category && <Link href={route('blog.index', { category: post.category.slug })} className="mt-8 block text-sm font-bold uppercase tracking-widest text-brand-600">{post.category.name}</Link>}<h1 className="mt-4 text-4xl font-black leading-tight tracking-tight text-neutral-950 sm:text-6xl dark:text-white">{post.title}</h1><p className="mt-6 text-xl leading-8 text-neutral-600 dark:text-neutral-300">{post.excerpt}</p><div className="mt-8 flex flex-wrap items-center gap-5 text-sm text-neutral-500"><span className="inline-flex items-center gap-2"><Calendar className="h-4 w-4" />{formatDate(post.published_at)}</span><span className="inline-flex items-center gap-2"><Clock className="h-4 w-4" />{post.reading_minutes} min read</span>{post.show_author && post.author && <span>By {post.author.name}</span>}<button onClick={share} className="ml-auto inline-flex items-center gap-2 rounded-lg border border-neutral-200 px-3 py-2 hover:bg-white dark:border-neutral-700"><Share2 className="h-4 w-4" /> Share</button></div></div>
                </header>
                {post.featured_image_url && <div className="mx-auto -mb-4 mt-12 max-w-5xl px-4 sm:px-6"><img src={post.featured_image_url} alt={post.featured_image_alt || post.title} className="aspect-[16/8] w-full rounded-3xl object-cover shadow-xl" /></div>}
                <div className="mx-auto max-w-3xl px-4 py-16 sm:px-6"><div className="cms-prose blog-prose max-w-none" dangerouslySetInnerHTML={{ __html: post.content }} />{post.tags?.length > 0 && <div className="mt-12 flex flex-wrap gap-2 border-t border-neutral-200 pt-8 dark:border-neutral-800">{post.tags.map((tag) => <Link key={tag.id} href={route('blog.index', { tag: tag.slug })} className="rounded-full bg-neutral-100 px-3 py-1.5 text-sm text-neutral-600 hover:bg-brand-50 hover:text-brand-700 dark:bg-neutral-900 dark:text-neutral-300">#{tag.name}</Link>)}</div>}</div>
            </article>
            {related?.length > 0 && <section className="border-t border-neutral-200 bg-neutral-50 py-16 dark:border-neutral-800 dark:bg-neutral-900/50"><div className="mx-auto max-w-6xl px-4 sm:px-6"><h2 className="text-2xl font-bold">Continue reading</h2><div className="mt-7 grid gap-6 md:grid-cols-3">{related.map((item) => <Link key={item.id} href={route('blog.show', item.slug)} className="group rounded-2xl border border-neutral-200 bg-white p-5 dark:border-neutral-800 dark:bg-neutral-900"><BookOpen className="h-6 w-6 text-brand-500" /><h3 className="mt-5 font-bold leading-snug group-hover:text-brand-600">{item.title}</h3><p className="mt-2 line-clamp-2 text-sm text-neutral-500">{item.excerpt}</p></Link>)}</div></div></section>}
        </LandingLayout>
    );
}
