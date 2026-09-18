<?php

namespace App\Services;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Illuminate\Support\Str;

class BlogContentSanitizer
{
    private const ALLOWED_TAGS = [
        'p', 'br', 'h2', 'h3', 'h4', 'strong', 'b', 'em', 'i', 'u', 's',
        'blockquote', 'ul', 'ol', 'li', 'a', 'img', 'figure', 'figcaption',
        'pre', 'code', 'hr', 'table', 'thead', 'tbody', 'tr', 'th', 'td',
    ];

    private const ALLOWED_TAG_STRING = '<p><br><h2><h3><h4><strong><b><em><i><u><s><blockquote><ul><ol><li><a><img><figure><figcaption><pre><code><hr><table><thead><tbody><tr><th><td>';

    private const DANGEROUS_TAGS = [
        'script', 'style', 'iframe', 'object', 'embed', 'svg', 'math', 'form',
        'input', 'button', 'select', 'textarea', 'template', 'noscript',
    ];

    private const BLOCK_TAGS = [
        'p', 'h2', 'h3', 'h4', 'blockquote', 'ul', 'ol', 'figure', 'table', 'pre', 'hr',
    ];

    public function sanitize(?string $html): string
    {
        $html = trim((string) $html);
        if ($html === '') {
            return $html;
        }
        if (! class_exists(DOMDocument::class)) {
            return strip_tags($html, self::ALLOWED_TAG_STRING);
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8" ?><div id="blog-root">'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $root = $document->getElementById('blog-root');
        if (! $root) {
            return '';
        }

        $xpath = new DOMXPath($document);
        foreach (self::DANGEROUS_TAGS as $tag) {
            foreach ($this->nodes($xpath->query('//'.$tag)) as $node) {
                $node->parentNode?->removeChild($node);
            }
        }

        foreach ($this->nodes($xpath->query('//*')) as $node) {
            if (! $node instanceof DOMElement || $node === $root) {
                continue;
            }

            $tag = strtolower($node->tagName);
            if (! in_array($tag, self::ALLOWED_TAGS, true)) {
                $this->unwrap($node);
                continue;
            }

            $this->sanitizeAttributes($node);
        }

        // Repair invalid markup created by older editor commands. Rich block content
        // inside PRE/P/headings made an entire article look like one code sample.
        for ($pass = 0; $pass < 3; $pass++) {
            foreach (['pre', 'p', 'h2', 'h3', 'h4'] as $container) {
                foreach ($this->nodes($xpath->query('//'.$container)) as $node) {
                    if ($node instanceof DOMElement && $this->containsBlockContent($node, $container)) {
                        $this->unwrap($node);
                    }
                }
            }
        }

        $this->addHeadingIds($xpath);

        $result = '';
        foreach ($root->childNodes as $child) {
            $result .= $document->saveHTML($child);
        }

        return trim($result);
    }

    private function sanitizeAttributes(DOMElement $node): void
    {
        $attributes = [];
        foreach ($node->attributes as $attribute) {
            $attributes[] = $attribute->name;
        }

        $tag = strtolower($node->tagName);
        foreach ($attributes as $attributeName) {
            $name = strtolower($attributeName);
            $allowed = match ($tag) {
                'a' => in_array($name, ['href', 'title', 'target', 'rel'], true),
                'img' => in_array($name, ['src', 'alt', 'title', 'width', 'height', 'loading', 'decoding'], true),
                'th', 'td' => in_array($name, ['colspan', 'rowspan'], true),
                'h2', 'h3', 'h4' => $name === 'id',
                default => false,
            };
            if (! $allowed || str_starts_with($name, 'on')) {
                $node->removeAttribute($attributeName);
            }
        }

        if ($node->hasAttribute('href') && ! $this->safeUrl($node->getAttribute('href'))) {
            $node->removeAttribute('href');
        }
        if ($node->hasAttribute('src') && ! $this->safeUrl($node->getAttribute('src'))) {
            $node->removeAttribute('src');
        }
        if ($tag === 'a') {
            $node->setAttribute('rel', 'noopener noreferrer');
        }
        if ($tag === 'img') {
            $node->setAttribute('loading', 'lazy');
            $node->setAttribute('decoding', 'async');
        }
    }

    private function containsBlockContent(DOMElement $node, string $container): bool
    {
        foreach ($node->getElementsByTagName('*') as $descendant) {
            $tag = strtolower($descendant->nodeName);
            if ($container === 'pre') {
                if (in_array($tag, self::BLOCK_TAGS, true)) {
                    return true;
                }
            } elseif (in_array($tag, self::BLOCK_TAGS, true)) {
                return true;
            }
        }

        return false;
    }

    private function addHeadingIds(DOMXPath $xpath): void
    {
        $used = [];
        foreach ($this->nodes($xpath->query('//h2|//h3|//h4')) as $heading) {
            if (! $heading instanceof DOMElement) {
                continue;
            }
            $base = Str::slug(trim($heading->textContent)) ?: 'section';
            $id = $base;
            $suffix = 2;
            while (isset($used[$id])) {
                $id = $base.'-'.$suffix++;
            }
            $used[$id] = true;
            $heading->setAttribute('id', $id);
        }
    }

    /** @return list<DOMNode> */
    private function nodes(false|\DOMNodeList $nodes): array
    {
        return $nodes ? iterator_to_array($nodes) : [];
    }

    private function unwrap(DOMNode $node): void
    {
        $parent = $node->parentNode;
        if (! $parent) {
            return;
        }
        while ($node->firstChild) {
            $parent->insertBefore($node->firstChild, $node);
        }
        $parent->removeChild($node);
    }

    private function safeUrl(string $url): bool
    {
        return preg_match('/^(https?:\/\/|\/|#|mailto:)/i', trim($url)) === 1;
    }
}
