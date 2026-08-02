import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { ArrowRight, BookOpen, Clock, Search } from 'lucide-react';
import LandingLayout from '@/Layouts/LandingLayout';
import SeoHead from '@/Components/SeoHead';

const dateLabel = (date) => date ? new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' }).format(new Date(date)) : '';

function PostCard({ post }) {
    return (
        <article className="group overflow-hidden rounded-2xl border border-neutral-200 bg-white shadow-sm transition hover:-translate-y-1 hover:shadow-xl dark:border-neutral-800 dark:bg-neutral-900">
            <Link href={route('blog.show', post.slug)} className="block aspect-[16/9] overflow-hidden bg-gradient-to-br from-orange-100 to-amber-50 dark:from-orange-950 dark:to-neutral-900">
                {post.featured_image_url ? <img src={post.featured_image_url} alt={post.featured_image_alt || post.title} className="h-full w-full object-cover transition duration-500 group-hover:scale-105" loading="lazy" /> : <div className="flex h-full items-center justify-center"><BookOpen className="h-12 w-12 text-brand-400" /></div>}
            </Link>
            <div className="p-6">
                <div className="mb-3 flex flex-wrap items-center gap-2 text-xs text-neutral-500">
                    {post.category && <Link href={route('blog.index', { category: post.category.slug })} className="rounded-full bg-brand-50 px-2.5 py-1 font-semibold text-brand-700 dark:bg-brand-950 dark:text-brand-300">{post.category.name}</Link>}
                    <span>{dateLabel(post.published_at)}</span><span>·</span><span>{post.reading_minutes} min read</span>
                </div>
                <h2 className="text-xl font-bold leading-snug text-neutral-900 transition group-hover:text-brand-600 dark:text-white"><Link href={route('blog.show', post.slug)}>{post.title}</Link></h2>
                <p className="mt-3 line-clamp-3 text-sm leading-6 text-neutral-600 dark:text-neutral-400">{post.excerpt}</p>
                <Link href={route('blog.show', post.slug)} className="mt-5 inline-flex items-center gap-1 text-sm font-semibold text-brand-600">Read article <ArrowRight className="h-4 w-4" /></Link>
            </div>
        </article>
    );
}

export default function BlogIndex({ posts, featured, categories, filters, seo }) {
    const [search, setSearch] = useState(filters?.search || '');
    const submit = (event) => { event.preventDefault(); router.get(route('blog.index'), { ...filters, search }, { preserveState: true, replace: true }); };
    return (
        <LandingLayout>
            <SeoHead title={seo?.title || 'WisperBot Blog — Customer Support, AI & Automation'} description={seo?.description} canonical={seo?.canonical || route('blog.index')} noindex={seo?.noindex} jsonLd={{ '@context': 'https://schema.org', '@type': 'Blog', name: 'WisperBot Blog', url: route('blog.index') }} />
            <section className="border-b border-neutral-200 bg-gradient-to-b from-orange-50 to-white py-20 dark:border-neutral-800 dark:from-orange-950/30 dark:to-neutral-950">
                <div className="mx-auto max-w-6xl px-4 text-center sm:px-6">
                    <span className="rounded-full border border-brand-200 bg-white px-3 py-1 text-xs font-semibold uppercase tracking-wider text-brand-600 dark:border-brand-900 dark:bg-neutral-900">WisperBot resources</span>
                    <h1 className="mt-6 text-4xl font-black tracking-tight text-neutral-950 sm:text-6xl dark:text-white">Better conversations.<br /><span className="text-brand-500">Smarter growth.</span></h1>
                    <p className="mx-auto mt-5 max-w-2xl text-lg leading-8 text-neutral-600 dark:text-neutral-300">Actionable customer support, omnichannel messaging, automation and AI strategies for modern teams.</p>
                    <form onSubmit={submit} className="mx-auto mt-8 flex max-w-xl items-center rounded-xl border border-neutral-200 bg-white p-1.5 shadow-lg dark:border-neutral-700 dark:bg-neutral-900"><Search className="ml-3 h-5 w-5 text-neutral-400" /><input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search articles…" className="min-w-0 flex-1 border-0 bg-transparent px-3 py-2 text-sm focus:ring-0" /><button className="rounded-lg bg-brand-500 px-5 py-2.5 text-sm font-semibold text-white">Search</button></form>
                </div>
            </section>

            <div className="mx-auto max-w-6xl px-4 py-14 sm:px-6">
                {featured && !filters?.search && !filters?.category && !filters?.tag && (
                    <article className="mb-14 grid overflow-hidden rounded-3xl border border-neutral-200 bg-neutral-950 text-white shadow-xl md:grid-cols-2 dark:border-neutral-800">
                        <div className="aspect-[16/10] bg-neutral-800">{featured.featured_image_url ? <img src={featured.featured_image_url} alt={featured.featured_image_alt || featured.title} className="h-full w-full object-cover" /> : <div className="flex h-full items-center justify-center"><BookOpen className="h-16 w-16 text-brand-400" /></div>}</div>
                        <div className="flex flex-col justify-center p-8 lg:p-12"><span className="text-xs font-bold uppercase tracking-widest text-brand-400">Featured insight</span><h2 className="mt-4 text-3xl font-bold leading-tight">{featured.title}</h2><p className="mt-4 text-neutral-300">{featured.excerpt}</p><div className="mt-6 flex items-center gap-3 text-sm text-neutral-400"><Clock className="h-4 w-4" /> {featured.reading_minutes} min read</div><Link href={route('blog.show', featured.slug)} className="mt-7 inline-flex items-center gap-2 font-semibold text-brand-400">Read featured article <ArrowRight className="h-4 w-4" /></Link></div>
                    </article>
                )}
                <div className="mb-10 flex flex-wrap gap-2"><Link href={route('blog.index')} className={`rounded-full px-4 py-2 text-sm font-medium ${!filters?.category ? 'bg-neutral-900 text-white dark:bg-white dark:text-neutral-900' : 'bg-neutral-100 text-neutral-600 dark:bg-neutral-900 dark:text-neutral-300'}`}>All topics</Link>{categories.map((category) => <Link key={category.id} href={route('blog.index', { category: category.slug })} className={`rounded-full px-4 py-2 text-sm font-medium ${filters?.category === category.slug ? 'bg-neutral-900 text-white dark:bg-white dark:text-neutral-900' : 'bg-neutral-100 text-neutral-600 dark:bg-neutral-900 dark:text-neutral-300'}`}>{category.name} <span className="opacity-60">{category.posts_count}</span></Link>)}</div>
                {posts.data.length ? <div className="grid gap-7 md:grid-cols-2 lg:grid-cols-3">{posts.data.map((post) => <PostCard key={post.id} post={post} />)}</div> : <div className="rounded-2xl border border-dashed border-neutral-300 py-20 text-center dark:border-neutral-700"><BookOpen className="mx-auto h-10 w-10 text-neutral-400" /><h2 className="mt-4 text-xl font-semibold">No articles found</h2><p className="mt-2 text-neutral-500">Try another topic or search term.</p></div>}
                {posts.links?.length > 3 && <nav className="mt-12 flex flex-wrap justify-center gap-2" aria-label="Blog pagination">{posts.links.map((link, index) => link.url ? <Link key={index} href={link.url} preserveScroll className={`rounded-lg border px-3 py-2 text-sm ${link.active ? 'border-brand-500 bg-brand-500 text-white' : 'border-neutral-200 dark:border-neutral-700'}`} dangerouslySetInnerHTML={{ __html: link.label }} /> : <span key={index} className="rounded-lg border border-neutral-100 px-3 py-2 text-sm text-neutral-300" dangerouslySetInnerHTML={{ __html: link.label }} />)}</nav>}
            </div>
        </LandingLayout>
    );
}
