<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BlogCategory;
use App\Models\BlogPost;
use App\Models\BlogPostRedirect;
use App\Models\BlogPostRevision;
use App\Models\BlogTag;
use App\Services\BlogContentSanitizer;
use App\Services\StorageManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class BlogPostController extends Controller
{
    public function __construct(private BlogContentSanitizer $sanitizer, private StorageManager $storage) {}

    public function index(Request $request): Response
    {
        $query = BlogPost::with(['category:id,name,slug', 'author:id,name', 'tags:id,name']);
        if ($request->filled('search')) {
            $search = trim((string) $request->string('search'));
            $query->where(fn ($q) => $q->where('title', 'like', "%{$search}%")->orWhere('slug', 'like', "%{$search}%"));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }
        if ($request->filled('category')) {
            $query->where('category_id', $request->integer('category'));
        }

        return Inertia::render('Admin/Blog/Index', [
            'posts' => $query->latest('updated_at')->paginate(15)->withQueryString(),
            'categories' => BlogCategory::withCount('posts')->orderBy('name')->get(),
            'tags' => BlogTag::withCount('posts')->orderBy('name')->get(),
            'filters' => $request->only(['search', 'status', 'category']),
            'stats' => [
                'total' => BlogPost::count(),
                'published' => BlogPost::publiclyVisible()->count(),
                'draft' => BlogPost::where('status', 'draft')->count(),
                'scheduled' => BlogPost::where('status', 'scheduled')->where('published_at', '>', now())->count(),
            ],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Admin/Blog/Edit', $this->editorProps());
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validated($request);
        $validated['author_id'] = $request->user('admin')->id;
        $validated['content'] = $this->sanitizer->sanitize($validated['content']);
        $validated['reading_minutes'] = $this->readingMinutes($validated['content']);

        $post = DB::transaction(function () use ($validated) {
            $tagIds = $validated['tag_ids'] ?? [];
            unset($validated['tag_ids']);
            $post = BlogPost::create($validated);
            $post->tags()->sync($tagIds);

            return $post;
        });

        return redirect()->route('admin.blog.edit', $post)->with('success', 'Blog post created.');
    }

    public function edit(BlogPost $blogPost): Response
    {
        $blogPost->load(['tags:id,name,slug', 'revisions.author:id,name']);

        return Inertia::render('Admin/Blog/Edit', $this->editorProps($blogPost));
    }

    public function update(Request $request, BlogPost $blogPost): RedirectResponse
    {
        $validated = $this->validated($request, $blogPost);
        $validated['content'] = $this->sanitizer->sanitize($validated['content']);
        $validated['reading_minutes'] = $this->readingMinutes($validated['content']);

        DB::transaction(function () use ($request, $validated, $blogPost): void {
            $oldSlug = $blogPost->slug;
            BlogPostRevision::create([
                'blog_post_id' => $blogPost->id,
                'admin_user_id' => $request->user('admin')->id,
                'title' => $blogPost->title,
                'content' => $blogPost->content,
                'snapshot' => $blogPost->only($blogPost->getFillable()),
            ]);
            $tagIds = $validated['tag_ids'] ?? [];
            unset($validated['tag_ids']);
            $blogPost->update($validated);
            $blogPost->tags()->sync($tagIds);
            if ($oldSlug !== $blogPost->slug) {
                BlogPostRedirect::where('old_slug', $blogPost->slug)->delete();
                BlogPostRedirect::updateOrCreate(['old_slug' => $oldSlug], ['blog_post_id' => $blogPost->id]);
            }
            $oldRevisionIds = $blogPost->revisions()->orderByDesc('id')->pluck('id')->slice(20);
            BlogPostRevision::whereKey($oldRevisionIds)->delete();
        });

        return back()->with('success', 'Blog post updated.');
    }

    public function destroy(BlogPost $blogPost): RedirectResponse
    {
        $blogPost->delete();

        return redirect()->route('admin.blog.index')->with('success', 'Blog post moved to trash.');
    }

    public function restoreRevision(Request $request, BlogPost $blogPost, BlogPostRevision $revision): RedirectResponse
    {
        abort_unless($revision->blog_post_id === $blogPost->id, 404);
        BlogPostRevision::create([
            'blog_post_id' => $blogPost->id,
            'admin_user_id' => $request->user('admin')->id,
            'title' => $blogPost->title,
            'content' => $blogPost->content,
            'snapshot' => $blogPost->only($blogPost->getFillable()),
        ]);
        $snapshot = array_intersect_key($revision->snapshot, array_flip($blogPost->getFillable()));
        unset($snapshot['author_id']);
        $blogPost->update($snapshot);

        return back()->with('success', 'Revision restored.');
    }

    public function bulk(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'max:100'],
            'ids.*' => ['integer', 'exists:blog_posts,id'],
            'action' => ['required', Rule::in(['publish', 'draft', 'delete'])],
        ]);
        $query = BlogPost::whereKey($validated['ids']);
        match ($validated['action']) {
            'publish' => $query->update(['status' => 'published', 'published_at' => now()]),
            'draft' => $query->update(['status' => 'draft']),
            'delete' => $query->delete(),
        };

        return back()->with('success', 'Selected posts updated.');
    }

    public function upload(Request $request): JsonResponse
    {
        $request->validate(['image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:5120']]);
        $file = $request->file('image');
        $path = $this->storage->prefixedPath('blog/'.now()->format('Y/m').'/'.Str::uuid().'.'.$file->extension());
        $this->storage->disk()->putFileAs(dirname($path), $file, basename($path), ['visibility' => 'public']);

        return response()->json(['url' => $this->storage->disk()->url($path)]);
    }

    public function previewUrl(BlogPost $blogPost): JsonResponse
    {
        return response()->json(['url' => URL::temporarySignedRoute('blog.preview', now()->addMinutes(30), $blogPost)]);
    }

    private function validated(Request $request, ?BlogPost $post = null): array
    {
        $request->merge([
            'slug' => Str::slug((string) ($request->input('slug') ?: $request->input('title'))),
        ]);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', Rule::unique('blog_posts', 'slug')->ignore($post?->id)],
            'excerpt' => ['nullable', 'string', 'max:1000'],
            'content' => ['required', 'string', 'max:2000000'],
            'category_id' => ['nullable', 'integer', 'exists:blog_categories,id'],
            'tag_ids' => ['nullable', 'array', 'max:20'],
            'tag_ids.*' => ['integer', 'exists:blog_tags,id'],
            'featured_image_url' => $this->imageUrlRules(),
            'featured_image_alt' => ['nullable', 'string', 'max:255'],
            'status' => ['required', Rule::in(['draft', 'scheduled', 'published'])],
            'published_at' => ['nullable', 'date'],
            'is_featured' => ['boolean'],
            'allow_indexing' => ['boolean'],
            'show_author' => ['boolean'],
            'meta_title' => ['nullable', 'string', 'max:70'],
            'meta_description' => ['nullable', 'string', 'max:170'],
            'meta_keywords' => ['nullable', 'string', 'max:500'],
            'canonical_url' => ['nullable', 'url', 'max:2048'],
            'og_image_url' => $this->imageUrlRules(),
            'schema_type' => ['required', Rule::in(['BlogPosting', 'Article', 'NewsArticle'])],
        ]);
        $data['published_at'] = match ($data['status']) {
            'published' => $data['published_at'] ?? now(),
            'scheduled' => $data['published_at'] ?? null,
            default => null,
        };
        if ($data['status'] === 'scheduled' && empty($data['published_at'])) {
            throw ValidationException::withMessages([
                'published_at' => 'Choose a publication date and time for a scheduled post.',
            ]);
        }

        return $data;
    }

    private function editorProps(?BlogPost $post = null): array
    {
        return [
            'post' => $post,
            'categories' => BlogCategory::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'tags' => BlogTag::orderBy('name')->get(['id', 'name']),
        ];
    }

    private function readingMinutes(string $content): int
    {
        return max(1, (int) ceil(str_word_count(strip_tags($content)) / 220));
    }

    private function imageUrlRules(): array
    {
        return ['nullable', 'string', 'max:2048', function (string $attribute, mixed $value, \Closure $fail): void {
            $isAbsolute = filter_var($value, FILTER_VALIDATE_URL) && in_array(parse_url($value, PHP_URL_SCHEME), ['http', 'https'], true);
            $isLocal = str_starts_with($value, '/') && ! str_starts_with($value, '//');
            if (! $isAbsolute && ! $isLocal) {
                $fail('The '.$attribute.' must be a secure web URL or a local site path.');
            }
        }];
    }
}
