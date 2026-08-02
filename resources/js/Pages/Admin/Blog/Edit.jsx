import { Link, router, useForm } from '@inertiajs/react';
import axios from 'axios';
import { useRef, useState } from 'react';
import { ArrowLeft, Eye, History, ImagePlus, Save } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import BlogRichEditor from '@/Components/BlogRichEditor';

const inputClass = 'w-full rounded-lg border-neutral-300 text-sm dark:border-neutral-700 dark:bg-neutral-800';
const localDateTime = (value) => value ? new Date(value).toISOString().slice(0, 16) : '';

function ErrorText({ children }) {
    return children ? <p className="mt-1 text-xs text-red-600">{children}</p> : null;
}

export default function BlogEdit({ post, categories, tags }) {
    const isEditing = Boolean(post?.id);
    const imageInput = useRef(null);
    const [uploading, setUploading] = useState(false);
    const [seoOpen, setSeoOpen] = useState(true);
    const { data, setData, post: create, put, processing, errors } = useForm({
        title: post?.title || '', slug: post?.slug || '', excerpt: post?.excerpt || '', content: post?.content || '',
        category_id: post?.category_id || '', tag_ids: post?.tags?.map((tag) => tag.id) || [],
        featured_image_url: post?.featured_image_url || '', featured_image_alt: post?.featured_image_alt || '',
        status: post?.status || 'draft', published_at: localDateTime(post?.published_at),
        is_featured: Boolean(post?.is_featured), allow_indexing: post?.allow_indexing ?? true, show_author: post?.show_author ?? true,
        meta_title: post?.meta_title || '', meta_description: post?.meta_description || '', meta_keywords: post?.meta_keywords || '',
        canonical_url: post?.canonical_url || '', og_image_url: post?.og_image_url || '', schema_type: post?.schema_type || 'BlogPosting',
    });

    const submit = (event) => {
        event.preventDefault();
        const options = { preserveScroll: true };
        if (isEditing) put(route('admin.blog.update', post.id), options);
        else create(route('admin.blog.store'), options);
    };
    const uploadImage = async (file) => {
        if (!file) return;
        setUploading(true);
        const payload = new FormData(); payload.append('image', file);
        try {
            const response = await axios.post(route('admin.blog.upload'), payload, { headers: { 'Content-Type': 'multipart/form-data' } });
            setData('featured_image_url', response.data.url);
        } finally { setUploading(false); if (imageInput.current) imageInput.current.value = ''; }
    };
    const preview = async () => {
        if (!isEditing) return;
        const { data: response } = await axios.get(route('admin.blog.preview-url', post.id));
        window.open(response.url, '_blank', 'noopener,noreferrer');
    };
    const toggleTag = (id) => setData('tag_ids', data.tag_ids.includes(id) ? data.tag_ids.filter((tagId) => tagId !== id) : [...data.tag_ids, id]);

    return <AdminLayout title={isEditing ? 'Edit blog post' : 'New blog post'}>
        <form onSubmit={submit} className="mx-auto max-w-7xl space-y-6">
            <div className="flex flex-wrap items-center gap-3">
                <Link href={route('admin.blog.index')} className="rounded-lg border border-neutral-200 p-2 dark:border-neutral-700"><ArrowLeft className="h-5 w-5" /></Link>
                <div className="mr-auto"><h1 className="text-2xl font-bold">{isEditing ? 'Edit article' : 'Create article'}</h1><p className="text-sm text-neutral-500">Write for readers first, then optimize the search presentation.</p></div>
                {isEditing && <button type="button" onClick={preview} className="inline-flex items-center gap-2 rounded-lg border border-neutral-200 px-4 py-2 text-sm font-semibold dark:border-neutral-700"><Eye className="h-4 w-4" /> Preview</button>}
                <button disabled={processing || uploading} className="inline-flex items-center gap-2 rounded-lg bg-brand-500 px-5 py-2 text-sm font-semibold text-white disabled:opacity-50"><Save className="h-4 w-4" /> Save article</button>
            </div>

            <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_340px]">
                <div className="space-y-6">
                    <section className="rounded-xl border border-neutral-200 bg-white p-5 dark:border-neutral-800 dark:bg-neutral-900">
                        <label className="text-sm font-semibold">Article title</label><input value={data.title} onChange={(event) => setData('title', event.target.value)} className={`${inputClass} mt-2 text-lg font-semibold`} required maxLength="255" placeholder="A clear, benefit-led title" /><ErrorText>{errors.title}</ErrorText>
                        <div className="mt-4 grid gap-4 md:grid-cols-2"><div><label className="text-sm font-semibold">URL slug</label><input value={data.slug} onChange={(event) => setData('slug', event.target.value)} className={`${inputClass} mt-2`} placeholder="generated-from-title" /><ErrorText>{errors.slug}</ErrorText></div><div><label className="text-sm font-semibold">Category</label><select value={data.category_id} onChange={(event) => setData('category_id', event.target.value)} className={`${inputClass} mt-2`}><option value="">Uncategorized</option>{categories.map((category) => <option key={category.id} value={category.id}>{category.name}</option>)}</select></div></div>
                        <label className="mt-4 block text-sm font-semibold">Excerpt</label><textarea value={data.excerpt} onChange={(event) => setData('excerpt', event.target.value)} className={`${inputClass} mt-2`} rows="3" maxLength="1000" placeholder="A concise summary used on cards and as the default meta description." /><div className="mt-1 text-right text-xs text-neutral-400">{data.excerpt.length}/1000</div>
                    </section>

                    <section><div className="mb-2 flex items-center justify-between"><label className="text-sm font-semibold">Article content</label><span className="text-xs text-neutral-400">Images up to 5 MB</span></div><BlogRichEditor value={data.content} onChange={(value) => setData('content', value)} onUploading={setUploading} /><ErrorText>{errors.content}</ErrorText></section>

                    <section className="rounded-xl border border-neutral-200 bg-white p-5 dark:border-neutral-800 dark:bg-neutral-900">
                        <button type="button" onClick={() => setSeoOpen(!seoOpen)} className="flex w-full items-center justify-between font-bold"><span>Search and social preview</span><span className="text-sm text-brand-600">{seoOpen ? 'Hide' : 'Optimize'}</span></button>
                        {seoOpen && <div className="mt-5 space-y-4">
                            <div className="rounded-xl border border-neutral-200 p-4 dark:border-neutral-700"><p className="text-sm text-emerald-700">wisperbot.com › blog › {data.slug || 'article-slug'}</p><p className="mt-1 text-xl text-blue-700">{data.meta_title || data.title || 'Article title'}</p><p className="mt-1 line-clamp-2 text-sm text-neutral-600">{data.meta_description || data.excerpt || 'Your article description will appear here.'}</p></div>
                            <div><label className="text-sm font-semibold">SEO title <span className="font-normal text-neutral-400">({data.meta_title.length}/60 recommended)</span></label><input value={data.meta_title} onChange={(event) => setData('meta_title', event.target.value)} className={`${inputClass} mt-2`} maxLength="70" /></div>
                            <div><label className="text-sm font-semibold">Meta description <span className="font-normal text-neutral-400">({data.meta_description.length}/160 recommended)</span></label><textarea value={data.meta_description} onChange={(event) => setData('meta_description', event.target.value)} className={`${inputClass} mt-2`} rows="3" maxLength="170" /></div>
                            <div className="grid gap-4 md:grid-cols-2"><div><label className="text-sm font-semibold">Canonical URL</label><input type="url" value={data.canonical_url} onChange={(event) => setData('canonical_url', event.target.value)} className={`${inputClass} mt-2`} placeholder="Leave blank for automatic" /><ErrorText>{errors.canonical_url}</ErrorText></div><div><label className="text-sm font-semibold">Social image URL</label><input type="url" value={data.og_image_url} onChange={(event) => setData('og_image_url', event.target.value)} className={`${inputClass} mt-2`} placeholder="Defaults to featured image" /></div></div>
                            <div className="grid gap-4 md:grid-cols-2"><div><label className="text-sm font-semibold">Keywords</label><input value={data.meta_keywords} onChange={(event) => setData('meta_keywords', event.target.value)} className={`${inputClass} mt-2`} placeholder="support, AI, automation" /></div><div><label className="text-sm font-semibold">Schema type</label><select value={data.schema_type} onChange={(event) => setData('schema_type', event.target.value)} className={`${inputClass} mt-2`}><option>BlogPosting</option><option>Article</option><option>NewsArticle</option></select></div></div>
                        </div>}
                    </section>
                </div>

                <aside className="space-y-6">
                    <section className="rounded-xl border border-neutral-200 bg-white p-5 dark:border-neutral-800 dark:bg-neutral-900"><h2 className="font-bold">Publishing</h2><label className="mt-4 block text-sm font-semibold">Status</label><select value={data.status} onChange={(event) => setData('status', event.target.value)} className={`${inputClass} mt-2`}><option value="draft">Draft</option><option value="published">Published</option><option value="scheduled">Scheduled</option></select>{(data.status === 'scheduled' || data.status === 'published') && <><label className="mt-4 block text-sm font-semibold">Publication date</label><input type="datetime-local" value={data.published_at} onChange={(event) => setData('published_at', event.target.value)} className={`${inputClass} mt-2`} /><ErrorText>{errors.published_at}</ErrorText></>}<div className="mt-5 space-y-3 text-sm">{[['is_featured', 'Feature on blog home'], ['allow_indexing', 'Allow search engine indexing'], ['show_author', 'Show author byline']].map(([key, label]) => <label key={key} className="flex items-center gap-2"><input type="checkbox" checked={data[key]} onChange={(event) => setData(key, event.target.checked)} className="rounded border-neutral-300 text-brand-500" />{label}</label>)}</div></section>

                    <section className="rounded-xl border border-neutral-200 bg-white p-5 dark:border-neutral-800 dark:bg-neutral-900"><h2 className="font-bold">Featured image</h2><button type="button" onClick={() => imageInput.current?.click()} className="mt-4 flex aspect-video w-full items-center justify-center overflow-hidden rounded-xl border border-dashed border-neutral-300 bg-neutral-50 dark:border-neutral-700 dark:bg-neutral-800">{data.featured_image_url ? <img src={data.featured_image_url} alt="Preview" className="h-full w-full object-cover" /> : <span className="flex items-center gap-2 text-sm text-neutral-500"><ImagePlus className="h-5 w-5" /> Upload image</span>}</button><input ref={imageInput} type="file" accept="image/jpeg,image/png,image/webp,image/gif" className="hidden" onChange={(event) => uploadImage(event.target.files?.[0])} /><input type="url" value={data.featured_image_url} onChange={(event) => setData('featured_image_url', event.target.value)} className={`${inputClass} mt-3`} placeholder="Or paste image URL" /><input value={data.featured_image_alt} onChange={(event) => setData('featured_image_alt', event.target.value)} className={`${inputClass} mt-3`} placeholder="Descriptive image alt text" /></section>

                    <section className="rounded-xl border border-neutral-200 bg-white p-5 dark:border-neutral-800 dark:bg-neutral-900"><h2 className="font-bold">Tags</h2><div className="mt-4 flex flex-wrap gap-2">{tags.map((tag) => <button key={tag.id} type="button" onClick={() => toggleTag(tag.id)} className={`rounded-full px-3 py-1.5 text-xs ${data.tag_ids.includes(tag.id) ? 'bg-brand-500 text-white' : 'bg-neutral-100 text-neutral-600 dark:bg-neutral-800 dark:text-neutral-300'}`}>#{tag.name}</button>)}</div>{!tags.length && <p className="mt-3 text-sm text-neutral-400">Create tags from the blog overview.</p>}</section>

                    {isEditing && post.revisions?.length > 0 && <section className="rounded-xl border border-neutral-200 bg-white p-5 dark:border-neutral-800 dark:bg-neutral-900"><div className="flex items-center gap-2"><History className="h-5 w-5 text-brand-500" /><h2 className="font-bold">Revision history</h2></div><div className="mt-4 space-y-3">{post.revisions.map((revision) => <div key={revision.id} className="rounded-lg bg-neutral-50 p-3 text-xs dark:bg-neutral-800"><p className="font-semibold">{revision.title}</p><p className="mt-1 text-neutral-400">{new Date(revision.created_at).toLocaleString()} {revision.author?.name ? `· ${revision.author.name}` : ''}</p><button type="button" onClick={() => confirm('Restore this revision? The current version will be saved first.') && router.post(route('admin.blog.revisions.restore', [post.id, revision.id]))} className="mt-2 font-semibold text-brand-600">Restore</button></div>)}</div></section>}
                </aside>
            </div>
        </form>
    </AdminLayout>;
}
