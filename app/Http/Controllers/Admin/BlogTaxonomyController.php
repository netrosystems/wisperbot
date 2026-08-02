<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BlogCategory;
use App\Models\BlogTag;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class BlogTaxonomyController extends Controller
{
    public function storeCategory(Request $request): RedirectResponse
    {
        $request->merge(['slug' => Str::slug((string) ($request->input('slug') ?: $request->input('name')))]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['nullable', 'string', 'max:140', 'unique:blog_categories,slug'],
            'description' => ['nullable', 'string', 'max:1000'],
            'meta_title' => ['nullable', 'string', 'max:70'],
            'meta_description' => ['nullable', 'string', 'max:170'],
            'is_active' => ['boolean'],
        ]);
        BlogCategory::create($data);

        return back()->with('success', 'Category created.');
    }

    public function updateCategory(Request $request, BlogCategory $category): RedirectResponse
    {
        $request->merge(['slug' => Str::slug((string) ($request->input('slug') ?: $request->input('name')))]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['nullable', 'string', 'max:140', Rule::unique('blog_categories')->ignore($category->id)],
            'description' => ['nullable', 'string', 'max:1000'],
            'meta_title' => ['nullable', 'string', 'max:70'],
            'meta_description' => ['nullable', 'string', 'max:170'],
            'is_active' => ['boolean'],
        ]);
        $category->update($data);

        return back()->with('success', 'Category updated.');
    }

    public function destroyCategory(BlogCategory $category): RedirectResponse
    {
        $category->delete();

        return back()->with('success', 'Category deleted; its posts are now uncategorized.');
    }

    public function storeTag(Request $request): RedirectResponse
    {
        $request->merge(['slug' => Str::slug((string) ($request->input('slug') ?: $request->input('name')))]);
        $data = $request->validate(['name' => ['required', 'string', 'max:80'], 'slug' => ['nullable', 'string', 'max:100', 'unique:blog_tags,slug']]);
        BlogTag::create($data);

        return back()->with('success', 'Tag created.');
    }

    public function destroyTag(BlogTag $tag): RedirectResponse
    {
        $tag->delete();

        return back()->with('success', 'Tag deleted.');
    }
}
