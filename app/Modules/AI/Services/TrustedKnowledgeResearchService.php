<?php

namespace App\Modules\AI\Services;

use App\Modules\AI\Models\AiKnowledgeBase;
use Illuminate\Support\Facades\Cache;

class TrustedKnowledgeResearchService
{
    public function __construct(
        private readonly KnowledgeSourceExtractor $extractor,
        private readonly KnowledgeSourceUrlResolver $resolver,
    ) {}

    /**
     * Fetch only already-configured URL sources from this exact Knowledge Base.
     * The fetched text is ephemeral and is never written back to the KB.
     *
     * @return array{context:string,citations:array<int,array{title:string,url:string}>,outcome:string,latency_ms:int}
     */
    public function research(AiKnowledgeBase $knowledgeBase, string $question): array
    {
        $started = hrtime(true);
        $documents = $knowledgeBase->documents()
            ->whereIn('source_type', ['url', 'sitemap'])
            ->get(['id', 'source_type', 'source_ref', 'canonical_url', 'title', 'enabled', 'status', 'extracted_content']);
        $approvedHosts = $documents
            ->flatMap(fn ($document) => $this->hostAliases($document->canonical_url ?: $document->source_ref))
            ->filter()->unique()->values()->all();

        $candidates = $documents
            ->filter(fn ($document) => $document->source_type === 'url' && $document->enabled && $document->status !== 'error')
            ->map(function ($document) use ($question): array {
                $haystack = implode(' ', [
                    (string) $document->title,
                    (string) $document->canonical_url,
                    (string) $document->source_ref,
                    mb_substr((string) $document->extracted_content, 0, 6000),
                ]);

                return ['document' => $document, 'score' => $this->termScore($question, $haystack)];
            })
            ->filter(fn (array $candidate) => $candidate['score'] > 0)
            ->sortByDesc('score')
            ->take(max(1, min(3, (int) config('chatbot.trusted_research_max_pages', 2))))
            ->values();

        if ($approvedHosts === [] || $candidates->isEmpty()) {
            return $this->result([], [], 'no_candidate', $started);
        }

        $passages = [];
        $citations = [];
        $blocked = 0;
        $failed = 0;
        foreach ($candidates as $candidate) {
            $document = $candidate['document'];
            $url = (string) ($document->canonical_url ?: $document->source_ref);
            if (! $this->isApprovedUrl($url, $approvedHosts) || ! $this->robotsAllowed($url)) {
                $blocked++;

                continue;
            }

            try {
                $snapshot = Cache::remember(
                    'smart-bot-research:'.hash('sha256', $url),
                    now()->addMinutes(max(1, (int) config('chatbot.trusted_research_cache_minutes', 10))),
                    fn () => $this->extractor->fetchUrlSnapshot($url, [
                        'connect_timeout' => 3,
                        'timeout' => 6,
                        'attempts' => 1,
                        'max_redirects' => 4,
                        'user_agent' => 'WisperBotTrustedResearch/1.0 (+https://wisperbot.com)',
                    ]),
                );
            } catch (\Throwable) {
                $failed++;

                continue;
            }

            $canonical = $snapshot['canonical_url'];
            if (! $this->isApprovedUrl($canonical, $approvedHosts)) {
                $blocked++;

                continue;
            }
            $excerpt = $this->relevantExcerpt($snapshot['text'], $question);
            if ($excerpt === '') {
                continue;
            }

            $title = trim((string) $document->title) ?: (string) parse_url($canonical, PHP_URL_HOST);
            $passages[] = '[Approved source: '.$title.' ('.$canonical.")]\n".$excerpt;
            $citations[] = ['title' => mb_substr($title, 0, 160), 'url' => $canonical];
        }

        $outcome = match (true) {
            $passages !== [] => 'supported',
            $blocked > 0 && $failed === 0 => 'blocked',
            $failed > 0 => 'failed',
            default => 'insufficient_evidence',
        };

        return $this->result($passages, $citations, $outcome, $started);
    }

    /**
     * @param  array<int,string>  $passages
     * @param  array<int,array{title:string,url:string}>  $citations
     * @return array{context:string,citations:array<int,array{title:string,url:string}>,outcome:string,latency_ms:int}
     */
    private function result(array $passages, array $citations, string $outcome, int $started): array
    {
        return [
            'context' => implode("\n\n---\n\n", $passages),
            'citations' => array_values($citations),
            'outcome' => $outcome,
            'latency_ms' => (int) round((hrtime(true) - $started) / 1_000_000),
        ];
    }

    private function robotsAllowed(string $url): bool
    {
        $parts = parse_url($url);
        $origin = 'https://'.($parts['host'] ?? '').(isset($parts['port']) ? ':'.$parts['port'] : '');
        $path = '/'.ltrim((string) ($parts['path'] ?? '/'), '/');

        try {
            $body = Cache::remember(
                'smart-bot-robots:'.hash('sha256', $origin),
                now()->addHour(),
                function () use ($origin): string {
                    $result = $this->resolver->fetch($origin.'/robots.txt', [
                        'connect_timeout' => 2, 'timeout' => 4, 'attempts' => 1, 'max_redirects' => 2,
                        'user_agent' => 'WisperBotTrustedResearch/1.0 (+https://wisperbot.com)',
                    ]);
                    if ($result['response']->status() === 404) {
                        return '';
                    }
                    if (! $result['response']->successful()) {
                        return "User-agent: *\nDisallow: /";
                    }

                    return mb_substr($result['response']->body(), 0, 250_000);
                },
            );
        } catch (\Throwable) {
            return false;
        }

        return $this->pathAllowedByRobots($path, $body);
    }

    private function pathAllowedByRobots(string $path, string $robots): bool
    {
        if (trim($robots) === '') {
            return true;
        }
        $applies = false;
        $rules = [];
        foreach (preg_split('/\R/u', $robots) ?: [] as $line) {
            $line = trim((string) preg_replace('/\s*#.*$/u', '', $line));
            if ($line === '' || ! str_contains($line, ':')) {
                continue;
            }
            [$field, $value] = array_map('trim', explode(':', $line, 2));
            $field = strtolower($field);
            if ($field === 'user-agent') {
                $applies = in_array(strtolower($value), ['*', 'wisperbottrustedresearch'], true);

                continue;
            }
            if ($applies && in_array($field, ['allow', 'disallow'], true) && $value !== '') {
                $rules[] = ['allow' => $field === 'allow', 'path' => $value];
            }
        }
        usort($rules, fn (array $a, array $b) => strlen($b['path']) <=> strlen($a['path']));
        foreach ($rules as $rule) {
            if (str_starts_with($path, $rule['path'])) {
                return $rule['allow'];
            }
        }

        return true;
    }

    /** @return array<int,string> */
    private function hostAliases(?string $url): array
    {
        $host = strtolower((string) parse_url((string) $url, PHP_URL_HOST));
        if ($host === '') {
            return [];
        }

        return array_values(array_unique([$host, str_starts_with($host, 'www.') ? substr($host, 4) : 'www.'.$host]));
    }

    /** @param array<int,string> $approvedHosts */
    private function isApprovedUrl(string $url, array $approvedHosts): bool
    {
        return str_starts_with(strtolower($url), 'https://')
            && in_array(strtolower((string) parse_url($url, PHP_URL_HOST)), $approvedHosts, true);
    }

    private function termScore(string $needle, string $haystack): float
    {
        $terms = $this->terms($needle);
        if ($terms === []) {
            return 0;
        }
        $candidate = $this->terms($haystack);

        return count(array_intersect($terms, $candidate)) / count($terms);
    }

    private function relevantExcerpt(string $text, string $question): string
    {
        $blocks = preg_split('/(?:\R\s*){2,}/u', trim($text)) ?: [];
        $ranked = collect($blocks)
            ->map(fn (string $block): array => ['text' => trim($block), 'score' => $this->termScore($question, $block)])
            ->filter(fn (array $block) => $block['text'] !== '')
            ->sortByDesc('score')
            ->take(5)
            ->pluck('text')
            ->implode("\n\n");

        return mb_substr(trim($ranked), 0, 5000);
    }

    /** @return array<int,string> */
    private function terms(string $text): array
    {
        preg_match_all('/[\p{L}\p{N}]{3,}/u', mb_strtolower($text), $matches);
        $stop = ['the', 'and', 'for', 'with', 'that', 'this', 'what', 'when', 'where', 'which', 'your', 'you', 'are', 'can', 'how', 'from'];

        return array_values(array_unique(array_diff($matches[0], $stop)));
    }
}
