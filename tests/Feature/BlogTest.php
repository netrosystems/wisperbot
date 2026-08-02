<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\BlogCategory;
use App\Models\BlogPost;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BlogTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): AdminUser
    {
        return $this->createSuperAdmin();
    }

    private function makePost(array $overrides = []): BlogPost
    {
        return BlogPost::create(array_merge([
            'title' => 'AI support guide',
            'slug' => 'ai-support-guide',
            'excerpt' => 'A practical guide to AI customer support.',
            'content' => '<p>Useful article content.</p>',
            'status' => 'published',
            'published_at' => now()->subHour(),
            'allow_indexing' => true,
            'show_author' => true,
            'reading_minutes' => 1,
            'schema_type' => 'BlogPosting',
        ], $overrides));
    }

    public function test_only_due_published_posts_are_public(): void
    {
        $published = $this->makePost();
        $draft = $this->makePost(['title' => 'Draft', 'slug' => 'draft', 'status' => 'draft', 'published_at' => null]);
        $future = $this->makePost(['title' => 'Future', 'slug' => 'future', 'status' => 'scheduled', 'published_at' => now()->addDay()]);

        $this->get(route('blog.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('posts.data', 1)
            ->where('posts.data.0.title', $published->title));
        $this->get(route('blog.show', $published->slug))->assertOk()->assertSee($published->title);
        $this->get(route('blog.show', $draft->slug))->assertNotFound();
        $this->get(route('blog.show', $future->slug))->assertNotFound();
    }

    public function test_blog_can_be_filtered_by_category(): void
    {
        $category = BlogCategory::create(['name' => 'Automation', 'slug' => 'automation', 'is_active' => true]);
        $matching = $this->makePost(['category_id' => $category->id]);
        $other = $this->makePost(['title' => 'Other article', 'slug' => 'other-article']);

        $this->get(route('blog.index', ['category' => 'automation']))
            ->assertOk()->assertSee($matching->title)->assertDontSee($other->title);
    }

    public function test_admin_can_create_a_sanitized_article_with_normalized_slug(): void
    {
        $this->actingAs($this->admin(), 'admin')->post(route('admin.blog.store'), [
            'title' => 'A Secure Blog Post',
            'slug' => 'A Secure Blog Post',
            'excerpt' => 'Short summary.',
            'content' => '<p>Safe</p><script>alert(1)</script><img src="javascript:alert(1)" onerror="alert(1)">',
            'featured_image_url' => '/storage/blog/secure-post.webp',
            'featured_image_alt' => 'Secure support workflow',
            'status' => 'published',
            'published_at' => now()->toDateTimeString(),
            'is_featured' => false,
            'allow_indexing' => true,
            'show_author' => true,
            'schema_type' => 'BlogPosting',
        ])->assertRedirect();

        $post = BlogPost::where('slug', 'a-secure-blog-post')->firstOrFail();
        $this->assertStringContainsString('<p>Safe</p>', $post->content);
        $this->assertStringNotContainsString('<script', $post->content);
        $this->assertStringNotContainsString('javascript:', $post->content);
        $this->assertStringNotContainsString('onerror', $post->content);
        $this->assertSame('/storage/blog/secure-post.webp', $post->featured_image_url);
    }

    public function test_scheduled_post_requires_a_publication_date(): void
    {
        $this->actingAs($this->admin(), 'admin')->from(route('admin.blog.create'))->post(route('admin.blog.store'), [
            'title' => 'Scheduled article', 'content' => '<p>Body</p>', 'status' => 'scheduled',
            'schema_type' => 'BlogPosting', 'allow_indexing' => true, 'show_author' => true, 'is_featured' => false,
        ])->assertRedirect(route('admin.blog.create'))->assertSessionHasErrors('published_at');
    }

    public function test_updating_an_article_creates_a_revision(): void
    {
        $post = $this->makePost(['author_id' => $this->admin()->id]);
        $this->actingAs(AdminUser::first(), 'admin')->put(route('admin.blog.update', $post), [
            'title' => 'Updated title', 'slug' => $post->slug, 'content' => '<p>Updated body</p>',
            'status' => 'published', 'published_at' => now()->toDateTimeString(), 'schema_type' => 'Article',
            'allow_indexing' => true, 'show_author' => true, 'is_featured' => false,
        ])->assertRedirect();

        $this->assertDatabaseHas('blog_post_revisions', ['blog_post_id' => $post->id, 'title' => 'AI support guide']);
        $this->assertDatabaseHas('blog_posts', ['id' => $post->id, 'title' => 'Updated title']);
    }

    public function test_old_slug_redirects_to_updated_article(): void
    {
        $admin = $this->admin();
        $post = $this->makePost(['author_id' => $admin->id]);
        $this->actingAs($admin, 'admin')->put(route('admin.blog.update', $post), [
            'title' => 'Updated title', 'slug' => 'updated-guide', 'content' => '<p>Updated body</p>',
            'status' => 'published', 'published_at' => now()->toDateTimeString(), 'schema_type' => 'Article',
            'allow_indexing' => true, 'show_author' => true, 'is_featured' => false,
        ])->assertRedirect();

        $this->get('/blog/ai-support-guide')->assertRedirect('/blog/updated-guide', 301);
    }

    public function test_signed_preview_can_show_a_draft(): void
    {
        $draft = $this->makePost(['slug' => 'private-preview', 'status' => 'draft', 'published_at' => null]);
        $url = URL::temporarySignedRoute('blog.preview', now()->addMinute(), ['blogPost' => $draft->id]);

        $this->get($url)->assertOk()->assertSee($draft->title);
    }

    public function test_sitemap_and_feed_include_only_public_indexable_posts(): void
    {
        $public = $this->makePost();
        $hidden = $this->makePost(['title' => 'Hidden', 'slug' => 'hidden', 'allow_indexing' => false]);
        $draft = $this->makePost(['title' => 'Draft', 'slug' => 'draft-feed', 'status' => 'draft', 'published_at' => null]);

        $this->get(route('sitemap'))->assertOk()->assertSee(route('blog.show', $public->slug), false)->assertDontSee(route('blog.show', $hidden->slug), false)->assertDontSee(route('blog.show', $draft->slug), false);
        $this->get(route('blog.feed'))->assertOk()->assertSee($public->title)->assertSee($hidden->title)->assertDontSee($draft->title);
    }
}
