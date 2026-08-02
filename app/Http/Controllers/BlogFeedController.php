<?php

namespace App\Http\Controllers;

use App\Models\BlogPost;
use Illuminate\Http\Response;

class BlogFeedController extends Controller
{
    public function __invoke(): Response
    {
        $posts = BlogPost::publiclyVisible()->latest('published_at')->limit(50)->get();
        $escape = fn (?string $value) => htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $xml = '<?xml version="1.0" encoding="UTF-8"?>';
        $xml .= '<rss version="2.0"><channel><title>'.$escape(config('app.name').' Blog').'</title>';
        $xml .= '<link>'.$escape(route('blog.index')).'</link><description>Product updates, customer support strategies, automation and AI insights from WisperBot.</description>';
        foreach ($posts as $post) {
            $xml .= '<item><title>'.$escape($post->title).'</title><link>'.$escape(route('blog.show', $post->slug)).'</link>';
            $xml .= '<guid isPermaLink="true">'.$escape(route('blog.show', $post->slug)).'</guid>';
            $xml .= '<description>'.$escape($post->seo_description).'</description><pubDate>'.$post->published_at->toRfc2822String().'</pubDate></item>';
        }
        $xml .= '</channel></rss>';

        return response($xml)->header('Content-Type', 'application/rss+xml; charset=UTF-8');
    }
}
