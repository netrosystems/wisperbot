<?php

namespace App\Modules\AI\Services;

use App\Modules\AI\Models\AiKbDocument;

class KnowledgeQualityService
{
    public function __construct(private readonly LlmGateway $llm) {}

    /** @return array{score:int,review_status:string,language:string,findings:array<int,array<string,mixed>>} */
    public function inspect(AiKbDocument $document, string $content): array
    {
        $findings = [];
        $length = mb_strlen($content);
        if ($length < 40) {
            $findings[] = $this->finding('content_too_short', 'blocker', 'The extracted content is empty or too short to answer customers.', null, 'Add a complete answer or upload a readable source.');
        }
        if ($length > 1_000_000) {
            $findings[] = $this->finding('content_too_large', 'blocker', 'The extracted content is too large for a single source.', null, 'Split it into focused documents.');
        }
        if ($this->binaryRatio($content) > 0.05) {
            $findings[] = $this->finding('unreadable_extraction', 'blocker', 'The extracted preview contains unreadable binary data.', null, 'Export the source as PDF, DOCX, TXT, or Markdown.');
        }
        foreach ($this->secretPatterns() as $code => $pattern) {
            if (preg_match($pattern, $content, $match)) {
                $findings[] = $this->finding($code, 'blocker', 'Potential credential or secret detected.', $this->safeExcerpt($match[0]), 'Remove all credentials and rotate any exposed secret.');
            }
        }
        if (preg_match('/(?:ignore|disregard|override).{0,40}(?:previous|system|assistant|instructions)|(?:system prompt|developer message)/iu', $content, $match)) {
            $findings[] = $this->finding('prompt_injection', 'blocker', 'The source contains instructions that could manipulate the chatbot.', $this->safeExcerpt($match[0]), 'Remove chatbot-control instructions from business reference content.');
        }
        if (preg_match('/\b(?:password|passcode|api\s*key|secret)\s*[:=]\s*\S+/iu', $content, $match)) {
            $findings[] = $this->finding('sensitive_configuration', 'blocker', 'Sensitive configuration appears in the source.', $this->safeExcerpt($match[0]), 'Remove the sensitive value before publishing.');
        }
        if (preg_match_all('/\b[\w.+-]+@[\w.-]+\.[A-Za-z]{2,}\b|\b(?:\+?\d[\d\s().-]{8,}\d)\b/u', $content, $personal) > 20) {
            $findings[] = $this->finding('excessive_personal_data', 'blocker', 'This source contains an unusually large amount of personal contact data.', null, 'Remove customer records and keep only public business contact details.');
        }
        if (preg_match('/\b(?:19|20)\d{2}[-\/.](?:0?[1-9]|1[0-2])[-\/.](?:0?[1-9]|[12]\d|3[01])\b/u', $content)) {
            $findings[] = $this->finding('time_sensitive_content', 'warning', 'This source contains dated information that may become outdated.', null, 'Confirm the effective date and set a refresh schedule.');
        }
        if ($document->source_type === 'faq' && (! str_contains($content, 'Q:') || ! str_contains($content, 'A:'))) {
            $findings[] = $this->finding('incomplete_faq', 'warning', 'The FAQ does not contain clear question-and-answer pairs.', null, 'Write one complete answer for every question.');
        }
        if ($this->duplicateExists($document, $content)) {
            $findings[] = $this->finding('duplicate_content', 'warning', 'Very similar content already exists in this Knowledge Base.', null, 'Keep one authoritative source to avoid conflicting answers.');
        }
        if ($this->repetitionRatio($content) > 0.45) {
            $findings[] = $this->finding('repetitive_content', 'warning', 'A large part of the source is repeated.', null, 'Remove duplicated navigation, footer, or repeated sections.');
        }
        if (preg_match('/\b(?:maybe|probably|usually|it depends|somehow|etc\.)\b/iu', $content, $match)) {
            $findings[] = $this->finding('vague_guidance', 'warning', 'A passage uses vague wording that may produce uncertain answers.', $this->safeExcerpt($match[0]), 'Replace vague wording with the exact condition or procedure.');
        }

        $detectedLanguage = $this->detectLanguage($content);
        $expectedLanguage = (string) ($document->knowledgeBase?->language ?? $document->loadMissing('knowledgeBase')->knowledgeBase?->language ?? '');
        if ($expectedLanguage !== '' && $expectedLanguage !== 'multi' && $detectedLanguage !== $expectedLanguage) {
            $findings[] = $this->finding('language_mismatch', 'warning', 'The source language does not match the Knowledge Base language.', null, 'Use the selected Knowledge Base language or change its language setting.');
        }
        foreach ($this->conflicts($document, $content) as $conflict) {
            $findings[] = $conflict;
        }

        $hasBlocker = collect($findings)->contains(fn ($finding) => $finding['severity'] === 'blocker');
        $hasWarning = collect($findings)->contains(fn ($finding) => $finding['severity'] === 'warning');
        $score = max(0, 100 - (collect($findings)->where('severity', 'blocker')->count() * 40) - (collect($findings)->where('severity', 'warning')->count() * 12));

        return [
            'score' => $score,
            'review_status' => $hasBlocker ? 'blocked' : ($hasWarning ? 'needs_review' : 'auto_approved'),
            'language' => $detectedLanguage,
            'findings' => $findings,
        ];
    }

    public function reviewAmbiguity(int $workspaceId, AiKbDocument $document, string $content, array $inspection): array
    {
        if ($inspection['review_status'] !== 'needs_review') {
            return $inspection;
        }
        try {
            $response = $this->llm->chat($workspaceId, [[
                'role' => 'system',
                'content' => 'Review business knowledge text for ambiguity, incomplete procedures, broken tables, missing conditions, and undated time-sensitive claims. Never rewrite facts or choose between conflicts. Return only JSON: {"findings":[{"code":"stable_snake_case","severity":"warning","location":"short heading or passage","explanation":"plain language","suggestion":"non-factual correction guidance"}]}.',
            ], [
                'role' => 'user',
                'content' => mb_substr($content, 0, 12_000),
            ]], [
                'feature' => 'kb_quality_review',
                'idempotency_key' => 'kb-quality:'.$document->id.':'.hash('sha256', $content),
                'max_tokens' => 500,
            ]);
            $json = json_decode(trim((string) preg_replace('/^```(?:json)?|```$/m', '', $response->content)), true);
            foreach (($json['findings'] ?? []) as $finding) {
                if (! is_array($finding) || ! preg_match('/^[a-z0-9_]{3,64}$/', (string) ($finding['code'] ?? ''))) {
                    continue;
                }
                $inspection['findings'][] = [
                    'code' => 'ai_'.(string) $finding['code'],
                    'severity' => 'warning',
                    'location' => mb_substr(strip_tags((string) ($finding['location'] ?? '')), 0, 200),
                    'message' => mb_substr(strip_tags((string) ($finding['explanation'] ?? 'Review this passage for clarity.')), 0, 500),
                    'excerpt' => null,
                    'suggestion' => mb_substr(strip_tags((string) ($finding['suggestion'] ?? 'Clarify the source without changing facts.')), 0, 500),
                ];
            }
            $inspection['findings'] = collect($inspection['findings'])->unique('code')->values()->all();
            $inspection['score'] = max(0, 100 - (collect($inspection['findings'])->where('severity', 'blocker')->count() * 40) - (collect($inspection['findings'])->where('severity', 'warning')->count() * 12));
        } catch (\Throwable) {
            // Deterministic findings remain authoritative when optional AI review is unavailable.
        }

        return $inspection;
    }

    private function conflicts(AiKbDocument $document, string $content): array
    {
        preg_match_all('/(?:[$£€৳]\s?\d[\d,.]*|https:\/\/[^\s)]+|\b\d{1,2}\s+(?:day|days|hour|hours|month|months)\b)/iu', $content, $current);
        $facts = array_values(array_unique(array_map('mb_strtolower', $current[0] ?? [])));
        if ($facts === []) {
            return [];
        }
        $others = AiKbDocument::where('kb_id', $document->kb_id)->whereKeyNot($document->id)
            ->whereNotNull('extracted_content')->limit(100)->get(['id', 'title', 'extracted_content']);
        foreach ($others as $other) {
            preg_match_all('/(?:[$£€৳]\s?\d[\d,.]*|https:\/\/[^\s)]+|\b\d{1,2}\s+(?:day|days|hour|hours|month|months)\b)/iu', (string) $other->extracted_content, $matches);
            $otherFacts = array_values(array_unique(array_map('mb_strtolower', $matches[0] ?? [])));
            if ($otherFacts !== [] && array_diff($facts, $otherFacts) !== [] && $this->topicOverlap($content, (string) $other->extracted_content) >= 0.25) {
                return [$this->finding('conflicting_facts', 'warning', 'A related source contains different prices, dates, durations, or URLs.', null, 'Compare this source with “'.($other->title ?: 'another source').'” and choose the authoritative facts.')];
            }
        }

        return [];
    }

    private function topicOverlap(string $left, string $right): float
    {
        preg_match_all('/[\p{L}\p{N}]{4,}/u', mb_strtolower($left), $a);
        preg_match_all('/[\p{L}\p{N}]{4,}/u', mb_strtolower($right), $b);
        $terms = array_values(array_unique($a[0] ?? []));

        return $terms === [] ? 0 : count(array_intersect($terms, array_unique($b[0] ?? []))) / count($terms);
    }

    private function duplicateExists(AiKbDocument $document, string $content): bool
    {
        $hash = hash('sha256', mb_strtolower((string) preg_replace('/\s+/u', ' ', trim($content))));
        $query = AiKbDocument::where('kb_id', $document->kb_id)->whereKeyNot($document->id);
        if ((clone $query)->where('content_hash', $hash)->exists()) {
            return true;
        }
        $current = $this->shingles($content);
        if ($current === []) {
            return false;
        }

        return $query->whereNotNull('extracted_content')->limit(100)->get(['extracted_content'])
            ->contains(function ($other) use ($current): bool {
                $candidate = $this->shingles((string) $other->extracted_content);
                $union = count(array_unique(array_merge($current, $candidate)));

                return $union > 0 && count(array_intersect($current, $candidate)) / $union >= 0.88;
            });
    }

    private function shingles(string $content): array
    {
        $words = preg_split('/\s+/u', mb_strtolower(strip_tags($content))) ?: [];
        $shingles = [];
        for ($index = 0; $index <= count($words) - 5; $index++) {
            $shingles[] = sha1(implode(' ', array_slice($words, $index, 5)));
        }

        return array_values(array_unique($shingles));
    }

    private function binaryRatio(string $content): float
    {
        if ($content === '') {
            return 0;
        }
        preg_match_all('/[^\P{C}\n\r\t]/u', $content, $matches);

        return count($matches[0] ?? []) / max(1, mb_strlen($content));
    }

    private function repetitionRatio(string $content): float
    {
        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/u', $content) ?: []), fn ($line) => mb_strlen($line) > 20));
        if (count($lines) < 4) {
            return 0;
        }

        return 1 - (count(array_unique(array_map('mb_strtolower', $lines))) / count($lines));
    }

    private function detectLanguage(string $content): string
    {
        if (preg_match('/[\x{0980}-\x{09FF}]/u', $content)) {
            return 'bn';
        }
        if (preg_match('/[\x{0600}-\x{06FF}]/u', $content)) {
            return 'ar';
        }

        return 'en';
    }

    private function secretPatterns(): array
    {
        return [
            'private_key' => '/-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----/u',
            'openai_key' => '/\bsk-[A-Za-z0-9_-]{20,}\b/u',
            'aws_key' => '/\bAKIA[0-9A-Z]{16}\b/u',
        ];
    }

    private function safeExcerpt(string $text): string
    {
        return mb_substr(preg_replace('/\S/', '•', $text) ?? '', 0, 80);
    }

    private function finding(string $code, string $severity, string $message, ?string $excerpt, string $suggestion): array
    {
        return array_merge(compact('code', 'severity', 'message', 'excerpt', 'suggestion'), ['location' => $excerpt]);
    }
}
