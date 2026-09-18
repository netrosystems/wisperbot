<?php

namespace App\Services;

use DOMDocument;
use DOMElement;
use DOMNode;
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
            $answer = $this->nextParagraph($heading);
            if ($answer !== '') {
                $faqs[] = ['question' => $text, 'answer' => $answer];
            }
        }

        return ['outline' => $outline, 'faqs' => $faqs];
    }

    private function nextParagraph(DOMNode $heading): string
    {
        $node = $heading->nextSibling;
        while ($node) {
            if ($node instanceof DOMElement && in_array(strtolower($node->tagName), ['h2', 'h3', 'h4'], true)) {
                return '';
            }
            if ($node instanceof DOMElement && strtolower($node->tagName) === 'p') {
                return trim(preg_replace('/\s+/', ' ', $node->textContent) ?? '');
            }
            if ($node instanceof DOMElement) {
                return '';
            }
            $node = $node->nextSibling;
        }
        return '';
    }
}
