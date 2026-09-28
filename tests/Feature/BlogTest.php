<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\BlogCategory;
use App\Models\BlogPost;
use App\Services\BlogContentSanitizer;
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

    public function test_homepage_surfaces_only_the_latest_public_articles(): void
    {
        $older = $this->makePost([
            'title' => 'Older public article',
            'slug' => 'older-public-article',
            'published_at' => now()->subDays(2),
        ]);
        $latest = $this->makePost([
            'title' => 'Latest public article',
            'slug' => 'latest-public-article',
            'published_at' => now()->subMinute(),
        ]);
        $this->makePost([
            'title' => 'Private draft',
            'slug' => 'private-homepage-draft',
            'status' => 'draft',
            'published_at' => null,
        ]);

        $this->get(route('home'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Welcome')
            ->has('latestPosts', 2)
            ->where('latestPosts.0.id', $latest->id)
            ->where('latestPosts.1.id', $older->id));
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

    public function test_sanitizer_repairs_legacy_nested_code_blocks_and_adds_unique_heading_anchors(): void
    {
        $content = '<pre><pre><h2>Choose a channel</h2><p>Compare the options.</p><ul><li>WhatsApp</li></ul><h2>Choose a channel</h2></pre></pre>';

        $sanitized = app(BlogContentSanitizer::class)->sanitize($content);

        $this->assertStringNotContainsString('<pre', $sanitized);
        $this->assertStringContainsString('<h2 id="choose-a-channel">Choose a channel</h2>', $sanitized);
        $this->assertStringContainsString('<h2 id="choose-a-channel-2">Choose a channel</h2>', $sanitized);
        $this->assertStringContainsString('<ul><li>WhatsApp</li></ul>', $sanitized);
    }

    public function test_sanitizer_preserves_real_code_and_tables_while_removing_dangerous_markup(): void
    {
        $content = '<pre><code>const safe = true;</code></pre><table><tbody><tr><td colspan="2" onclick="bad()">Value</td></tr></tbody></table><script>alert(1)</script>';

        $sanitized = app(BlogContentSanitizer::class)->sanitize($content);

        $this->assertStringContainsString('<pre><code>const safe = true;</code></pre>', $sanitized);
        $this->assertStringContainsString('<table>', $sanitized);
        $this->assertStringContainsString('colspan="2"', $sanitized);
        $this->assertStringNotContainsString('onclick', $sanitized);
        $this->assertStringNotContainsString('alert(1)', $sanitized);
    }

    public function test_public_article_exposes_outline_faq_schema_data_and_repaired_content(): void
    {
        $post = $this->makePost([
            'content' => '<pre><h2>Overview</h2><p>Start here.</p><h3>What is WisperBot?</h3><p>WisperBot is a customer messaging platform.</p></pre>',
        ]);

        $this->get(route('blog.show', $post->slug))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('post.content', '<h2 id="overview">Overview</h2><p>Start here.</p><h3 id="what-is-wisperbot">What is WisperBot?</h3><p>WisperBot is a customer messaging platform.</p>')
            ->has('post.outline', 2)
            ->where('post.outline.0.id', 'overview')
            ->has('post.faqs', 1)
            ->where('post.faqs.0.question', 'What is WisperBot?'));
    }

    public function test_public_article_extracts_faq_answers_from_legacy_text_nodes(): void
    {
        $post = $this->makePost([
            'content' => '<h3>Can I keep my number?</h3>Yes. Eligibility depends on the provider.<h3>Another section</h3>',
        ]);

        $this->get(route('blog.show', $post->slug))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('post.faqs', 1)
            ->where('post.faqs.0.question', 'Can I keep my number?')
            ->where('post.faqs.0.answer', 'Yes. Eligibility depends on the provider.'));
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
