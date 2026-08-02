<?php

namespace App\Http\Controllers;

use App\Models\BlogCategory;
use App\Models\BlogPost;
use App\Models\BlogPostRedirect;
use App\Models\BlogTag;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class BlogController extends Controller
{
    public function index(Request $request): Response
    {
        $category = null;
        $tag = null;
        $query = BlogPost::query()->publiclyVisible()->with(['category:id,name,slug', 'author:id,name', 'tags:id,name,slug']);
        if ($request->filled('search')) {
            $search = trim((string) $request->string('search'));
            $query->where(fn ($q) => $q->where('title', 'like', "%{$search}%")
                ->orWhere('excerpt', 'like', "%{$search}%"));
        }
        if ($request->filled('category')) {
            $category = BlogCategory::where('slug', (string) $request->string('category'))->where('is_active', true)->first();
            $query->whereHas('category', fn ($q) => $q->where('slug', (string) $request->string('category'))->where('is_active', true));
        }
        if ($request->filled('tag')) {
            $tag = BlogTag::where('slug', (string) $request->string('tag'))->first();
            $query->whereHas('tags', fn ($q) => $q->where('slug', (string) $request->string('tag')));
        }

        $posts = $query->orderByDesc('published_at')->paginate(9)->withQueryString();
        $posts->through(fn (BlogPost $post) => $this->summary($post));

        $featured = BlogPost::publiclyVisible()->where('is_featured', true)
            ->with(['category:id,name,slug', 'author:id,name', 'tags:id,name,slug'])
            ->latest('published_at')->first();

        return Inertia::render('marketing/Blog/Index', [
            'posts' => $posts,
            'featured' => $featured ? $this->summary($featured) : null,
            'categories' => BlogCategory::where('is_active', true)->whereHas('posts', fn ($q) => $q->publiclyVisible())->withCount(['posts' => fn ($q) => $q->publiclyVisible()])->orderBy('name')->get(),
            'filters' => $request->only(['search', 'category', 'tag']),
            'seo' => [
                'title' => $category?->meta_title ?: ($category ? $category->name.' articles' : ($tag ? $tag->name.' articles' : 'WisperBot Blog')),
                'description' => $category?->meta_description ?: $category?->description ?: 'Practical guides on AI-powered customer support, automation, messaging, and business growth.',
                'canonical' => route('blog.index', array_filter(['category' => $category?->slug, 'tag' => $tag?->slug])),
                'noindex' => $request->filled('search'),
            ],
        ]);
    }

    public function show(string $slug): Response|RedirectResponse
    {
        $post = BlogPost::publiclyVisible()->where('slug', $slug)->with(['category:id,name,slug', 'author:id,name', 'tags:id,name,slug'])->first();
        if (! $post) {
            $redirect = BlogPostRedirect::where('old_slug', $slug)->with('post')->first();
            if ($redirect?->post && BlogPost::publiclyVisible()->whereKey($redirect->post->id)->exists()) {
                return redirect()->route('blog.show', $redirect->post->slug, 301);
            }
            abort(404);
        }
        $related = BlogPost::publiclyVisible()->whereKeyNot($post->id)
            ->when($post->category_id, fn ($q) => $q->where('category_id', $post->category_id))
            ->latest('published_at')->limit(3)->get()->map(fn ($item) => $this->summary($item));

        return Inertia::render('marketing/Blog/Show', [
            'post' => array_merge($post->toArray(), [
                'seo_title' => $post->seo_title,
                'seo_description' => $post->seo_description,
                'canonical' => $post->canonical_url ?: route('blog.show', $post->slug),
                'url' => route('blog.show', $post->slug),
                'updated_at_iso' => $post->updated_at->toIso8601String(),
                'published_at_iso' => $post->published_at?->toIso8601String(),
            ]),
            'related' => $related,
        ]);
    }

    public function preview(Request $request, BlogPost $blogPost): Response
    {
        abort_unless($request->hasValidSignature(), 403);
        $blogPost->load(['category:id,name,slug', 'author:id,name', 'tags:id,name,slug']);

        return Inertia::render('marketing/Blog/Show', [
            'post' => array_merge($blogPost->toArray(), [
                'seo_title' => $blogPost->seo_title,
                'seo_description' => $blogPost->seo_description,
                'canonical' => route('blog.show', $blogPost->slug),
                'url' => route('blog.show', $blogPost->slug),
                'updated_at_iso' => $blogPost->updated_at->toIso8601String(),
                'published_at_iso' => $blogPost->published_at?->toIso8601String(),
            ]),
            'related' => [],
            'preview' => true,
        ]);
    }

    private function summary(BlogPost $post): array
    {
        return [
            'id' => $post->id,
            'title' => $post->title,
            'slug' => $post->slug,
            'excerpt' => $post->excerpt ?: Str::limit(strip_tags($post->content), 180),
            'featured_image_url' => $post->featured_image_url,
            'featured_image_alt' => $post->featured_image_alt,
            'published_at' => $post->published_at,
            'reading_minutes' => $post->reading_minutes,
            'category' => $post->category,
            'author' => $post->show_author ? $post->author : null,
            'tags' => $post->tags,
        ];
    }
}
