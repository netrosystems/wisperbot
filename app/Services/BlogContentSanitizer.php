<?php

namespace App\Services;

use DOMDocument;
use DOMElement;
use DOMXPath;

class BlogContentSanitizer
{
    private const ALLOWED_TAGS = '<p><br><h2><h3><h4><strong><b><em><i><u><s><blockquote><ul><ol><li><a><img><figure><figcaption><pre><code><hr><table><thead><tbody><tr><th><td>';

    public function sanitize(?string $html): string
    {
        $html = strip_tags((string) $html, self::ALLOWED_TAGS);
        if ($html === '' || ! class_exists(DOMDocument::class)) {
            return $html;
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8" ?><div id="blog-root">'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $root = $document->getElementById('blog-root');
        $xpath = new DOMXPath($document);
        foreach ($xpath->query('//*[@*]') ?: [] as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }
            if ($node === $root) {
                continue;
            }
            $attributes = [];
            foreach ($node->attributes as $attribute) {
                $attributes[] = $attribute->name;
            }
            foreach ($attributes as $attributeName) {
                $attribute = $node->getAttributeNode($attributeName);
                if (! $attribute) {
                    continue;
                }
                $name = strtolower($attribute->name);
                $allowed = match (strtolower($node->tagName)) {
                    'a' => in_array($name, ['href', 'title', 'target', 'rel'], true),
                    'img' => in_array($name, ['src', 'alt', 'title', 'width', 'height', 'loading'], true),
                    'th', 'td' => in_array($name, ['colspan', 'rowspan'], true),
                    default => false,
                };
                if (! $allowed || str_starts_with($name, 'on')) {
                    $node->removeAttribute($attribute->name);
                }
            }
            if ($node->hasAttribute('href') && ! $this->safeUrl($node->getAttribute('href'))) {
                $node->removeAttribute('href');
            }
            if ($node->hasAttribute('src') && ! $this->safeUrl($node->getAttribute('src'))) {
                $node->removeAttribute('src');
            }
            if (strtolower($node->tagName) === 'a') {
                $node->setAttribute('rel', 'noopener noreferrer');
            }
            if (strtolower($node->tagName) === 'img') {
                $node->setAttribute('loading', 'lazy');
            }
        }

        $result = '';
        foreach ($root?->childNodes ?? [] as $child) {
            $result .= $document->saveHTML($child);
        }

        return $result;
    }

    private function safeUrl(string $url): bool
    {
        return preg_match('/^(https?:\/\/|\/|#|mailto:)/i', trim($url)) === 1;
    }
}
