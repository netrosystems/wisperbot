<?php

namespace App\Modules\Inbox\Services;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

class EmailContentSanitizer
{
    private const ALLOWED_TAGS = [
        'a', 'b', 'blockquote', 'br', 'code', 'div', 'em', 'h1', 'h2', 'h3', 'h4',
        'h5', 'h6', 'hr', 'i', 'li', 'ol', 'p', 'pre', 's', 'span', 'strong',
        'table', 'tbody', 'td', 'tfoot', 'th', 'thead', 'tr', 'u', 'ul',
    ];

    private const ALLOWED_TAG_STRING = '<a><b><blockquote><br><code><div><em><h1><h2><h3><h4><h5><h6><hr><i><li><ol><p><pre><s><span><strong><table><tbody><td><tfoot><th><thead><tr><u><ul>';

    private const DANGEROUS_TAGS = [
        'script', 'style', 'iframe', 'object', 'embed', 'svg', 'math', 'form',
        'input', 'button', 'select', 'textarea', 'template', 'noscript', 'img',
    ];

    private const ALLOWED_STYLE_PROPERTIES = [
        'background-color', 'color', 'font-style', 'font-weight', 'text-align',
        'text-decoration', 'vertical-align', 'white-space',
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
            $allowed = $name === 'style'
                || ($tag === 'a' && in_array($name, ['href', 'title'], true))
                || (in_array($tag, ['th', 'td'], true) && in_array($name, ['colspan', 'rowspan'], true));

            if (! $allowed || str_starts_with($name, 'on')) {
                $node->removeAttribute($attributeName);
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

        if ($node->hasAttribute('style')) {
            $safe = [];
            foreach (explode(';', $node->getAttribute('style')) as $declaration) {
                [$property, $value] = array_pad(explode(':', $declaration, 2), 2, '');
                $property = strtolower(trim($property));
                $value = trim($value);
                if (in_array($property, self::ALLOWED_STYLE_PROPERTIES, true)
                    && $value !== ''
                    && ! preg_match('/url\s*\(|expression\s*\(|javascript:/i', $value)) {
                    $safe[] = $property.': '.$value;
                }
            }
            if ($safe === []) {
                $node->removeAttribute('style');
            } else {
                $node->setAttribute('style', implode('; ', $safe));
            }
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
}
