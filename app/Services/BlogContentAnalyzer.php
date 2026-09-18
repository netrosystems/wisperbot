<?php

namespace App\Services;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use DOMXPath;

class BlogContentAnalyzer
{
    /** @return array{outline: list<array{id: string, text: string, level: int}>, faqs: list<array{question: string, answer: string}>} */
    public function analyze(string $html): array
    {
        if ($html === '' || ! class_exists(DOMDocument::class)) {
            return ['outline' => [], 'faqs' => []];
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8" ?><div id="blog-analysis-root">'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        $xpath = new DOMXPath($document);
        $outline = [];
        $faqs = [];

        foreach ($xpath->query('//h2|//h3') ?: [] as $heading) {
            if (! $heading instanceof DOMElement) {
                continue;
            }
            $text = trim(preg_replace('/\s+/', ' ', $heading->textContent) ?? '');
            $outline[] = ['id' => $heading->getAttribute('id'), 'text' => $text, 'level' => (int) substr($heading->tagName, 1)];
            if (count($faqs) >= 12 || ! str_ends_with($text, '?')) {
                continue;
            }
            $answer = $this->nextAnswer($heading);
            if ($answer !== '') {
                $faqs[] = ['question' => $text, 'answer' => $answer];
            }
        }

        return ['outline' => $outline, 'faqs' => $faqs];
    }

    private function nextAnswer(DOMNode $heading): string
    {
        $node = $heading->nextSibling;
        $text = [];
        while ($node) {
            if ($node instanceof DOMElement && in_array(strtolower($node->tagName), ['h2', 'h3', 'h4'], true)) {
                break;
            }
            if ($node instanceof DOMElement && strtolower($node->tagName) === 'p') {
                $text[] = $node->textContent;
                break;
            }
            if ($node instanceof DOMElement) {
                return '';
            }
            if ($node instanceof DOMText && trim($node->textContent) !== '') {
                $text[] = $node->textContent;
            }
            $node = $node->nextSibling;
        }

        return mb_substr(trim(preg_replace('/\s+/', ' ', implode(' ', $text)) ?? ''), 0, 1000);
    }
}
