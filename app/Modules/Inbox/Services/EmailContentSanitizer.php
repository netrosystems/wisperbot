<?php

namespace App\Modules\Inbox\Services;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

class EmailContentSanitizer
{
    private const ALLOWED_TAGS = [
        'a', 'address', 'b', 'blockquote', 'br', 'caption', 'center', 'code', 'col',
        'colgroup', 'dd', 'del', 'div', 'dl', 'dt', 'em', 'font', 'h1', 'h2', 'h3',
        'h4', 'h5', 'h6', 'hr', 'i', 'img', 'ins', 'li', 'ol', 'p', 'pre', 's',
        'small', 'span', 'strong', 'style', 'sub', 'sup', 'table', 'tbody', 'td',
        'tfoot', 'th', 'thead', 'tr', 'u', 'ul',
    ];

    private const ALLOWED_TAG_STRING = '<a><address><b><blockquote><br><caption><center><code><col><colgroup><dd><del><div><dl><dt><em><font><h1><h2><h3><h4><h5><h6><hr><i><img><ins><li><ol><p><pre><s><small><span><strong><style><sub><sup><table><tbody><td><tfoot><th><thead><tr><u><ul>';

    private const DANGEROUS_TAGS = [
        'script', 'iframe', 'object', 'embed', 'svg', 'math', 'form',
        'input', 'button', 'select', 'textarea', 'template', 'noscript', 'link',
    ];

    public function sanitize(?string $html): string
    {
        $html = trim((string) $html);
        if ($html === '') {
            return '';
        }

        if (! class_exists(DOMDocument::class)) {
            return strip_tags($html, self::ALLOWED_TAG_STRING);
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8" ?><div id="email-root">'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $root = $document->getElementById('email-root');
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

            if (! in_array(strtolower($node->tagName), self::ALLOWED_TAGS, true)) {
                $this->unwrap($node);

                continue;
            }

            if (strtolower($node->tagName) === 'style') {
                $node->nodeValue = $this->sanitizeCss($node->textContent);
            }

            $this->sanitizeAttributes($node);
        }

        $result = '';
        foreach ($root->childNodes as $child) {
            $result .= $document->saveHTML($child);
        }

        return trim($result);
    }

    public function plainText(?string $html): string
    {
        $value = preg_replace('/<(br|\/p|\/div|\/li|\/tr|\/h[1-6])\b[^>]*>/i', "$0\n", (string) $html);
        $value = html_entity_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace("/\r\n?|\n{3,}/", "\n", $value);

        return trim((string) $value);
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
            $allowed = in_array($name, ['class', 'dir', 'id', 'lang', 'style', 'title'], true)
                || ($tag === 'a' && in_array($name, ['href', 'title'], true))
                || ($tag === 'font' && in_array($name, ['color', 'face', 'size'], true))
                || ($tag === 'img' && in_array($name, ['alt', 'height', 'src', 'width'], true))
                || (in_array($tag, ['table', 'tbody', 'td', 'tfoot', 'th', 'thead', 'tr', 'col', 'colgroup'], true)
                    && in_array($name, ['align', 'bgcolor', 'border', 'cellpadding', 'cellspacing', 'colspan', 'height', 'rowspan', 'valign', 'width'], true));

            if (! $allowed || str_starts_with($name, 'on')) {
                $node->removeAttribute($attributeName);
            }
        }

        foreach (['class', 'id'] as $attribute) {
            if ($node->hasAttribute($attribute)
                && ! preg_match('/^[\p{L}\p{N}\s_:.-]{1,500}$/u', $node->getAttribute($attribute))) {
                $node->removeAttribute($attribute);
            }
        }

        if ($tag === 'font') {
            if ($node->hasAttribute('color') && ! preg_match('/^(#[0-9a-f]{3,8}|rgba?\([\d\s.,%]+\)|[a-z]+)$/i', trim($node->getAttribute('color')))) {
                $node->removeAttribute('color');
            }
            if ($node->hasAttribute('size') && ! preg_match('/^[1-7]$/', trim($node->getAttribute('size')))) {
                $node->removeAttribute('size');
            }
            if ($node->hasAttribute('face') && ! preg_match('/^[\p{L}\p{N}\s,._\-"\']{1,100}$/u', trim($node->getAttribute('face')))) {
                $node->removeAttribute('face');
            }
        }

        if ($node->hasAttribute('href')) {
            if (! preg_match('/^(https?:\/\/|mailto:|#)/i', trim($node->getAttribute('href')))) {
                $node->removeAttribute('href');
            } else {
                $node->setAttribute('target', '_blank');
                $node->setAttribute('rel', 'noopener noreferrer');
            }
        }

        if ($node->hasAttribute('src')) {
            $src = trim($node->getAttribute('src'));
            if (! preg_match('/^(https?:\/\/|data:image\/(?:png|jpe?g|gif|webp);base64,)/i', $src)) {
                $node->removeAttribute('src');
            } elseif ($tag === 'img') {
                $node->setAttribute('loading', 'lazy');
                $node->setAttribute('referrerpolicy', 'no-referrer');
            }
        }

        if ($node->hasAttribute('style')) {
            $style = $this->sanitizeDeclarations($node->getAttribute('style'));
            if ($style === '') {
                $node->removeAttribute('style');
            } else {
                $node->setAttribute('style', $style);
            }
        }
    }

    private function sanitizeCss(string $css): string
    {
        $css = (string) preg_replace('/\/\*.*?\*\//s', '', $css);
        $css = (string) preg_replace('/@import\s+[^;]+;?/i', '', $css);
        $css = (string) preg_replace('/@charset\s+[^;]+;?/i', '', $css);
        $css = (string) preg_replace('/(?:expression\s*\(|javascript:|vbscript:|-moz-binding\s*:|behavior\s*:)/i', '', $css);

        return trim($css);
    }

    private function sanitizeDeclarations(string $style): string
    {
        $safe = [];
        foreach (explode(';', $style) as $declaration) {
            [$property, $value] = array_pad(explode(':', $declaration, 2), 2, '');
            $property = strtolower(trim($property));
            $value = trim($value);
            if ($property !== ''
                && preg_match('/^(?:--)?[a-z][a-z0-9-]*$/i', $property)
                && ! in_array($property, ['behavior', '-moz-binding'], true)
                && $value !== ''
                && ! preg_match('/expression\s*\(|javascript:|vbscript:/i', $value)) {
                $safe[] = $property.': '.$value;
            }
        }

        return implode('; ', $safe);
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
}
