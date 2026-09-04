<?php

namespace App\Modules\AI\Services;

use App\Modules\AI\Models\AiKbRevision;
use App\Modules\AI\Models\AiKnowledgeBase;

class KnowledgeBaseTestService
{
    public function __construct(
        private readonly LlmGateway $llm,
        private readonly EmbeddingStore $embeddings,
    ) {}

    public function test(AiKnowledgeBase $kb, string $question, ?AiKbRevision $revision = null): array
    {
        // Management tests must evaluate unpublished source changes, not the
        // previous live revision. First-time setup also uses the draft.
        $revision ??= $kb->draftRevision ?? $kb->publishedRevision;
        if (! $revision) {
            return $this->emptyResult();
        }
        $vector = $this->llm->embed((int) $kb->workspace_id, [$question])[0] ?? [];
        if ($vector === []) {
            return $this->emptyResult();
        }
        $results = $this->embeddings->search((int) $kb->id, $vector, 12, $revision->id);
        $queryTerms = $this->terms($question);
        foreach ($results as &$result) {
            $contentTerms = $this->terms((string) $result['chunk']->content);
            $overlap = $queryTerms === [] ? 0 : count(array_intersect($queryTerms, $contentTerms)) / count($queryTerms);
            $result['rank_score'] = ((float) ($result['score'] ?? 0) * 0.78) + ($overlap * 0.22);
        }
        unset($result);
        usort($results, fn ($a, $b) => $b['rank_score'] <=> $a['rank_score']);
        $threshold = (float) config('knowledge_base.retrieval_match_threshold', 0.60);
        $selected = array_values(array_filter($results, fn ($result) => $result['rank_score'] >= $threshold));
        $selected = array_slice($selected, 0, 3);
        $best = (float) ($selected[0]['rank_score'] ?? 0);

        return [
            'answer' => $selected === [] ? null : mb_substr((string) $selected[0]['chunk']->content, 0, 1200),
            'decision' => $selected === [] ? 'handoff' : 'answer',
            'confidence' => round($best, 4),
            'estimated_prompt_tokens' => (int) ceil(array_sum(array_map(fn ($result) => mb_strlen((string) $result['chunk']->content), $selected)) / 4),
            'sources' => array_map(function ($result) {
                $document = $result['chunk']->loadMissing('document')->document;

                return [
                    'document_id' => $document?->id,
                    'title' => $document?->title,
                    'score' => round((float) $result['rank_score'], 4),
                    'excerpt' => mb_substr((string) $result['chunk']->content, 0, 400),
                ];
            }, $selected),
            'warnings' => $selected === [] ? ['No published source confidently answers this question.'] : [],
        ];
    }

    public function runRevision(AiKnowledgeBase $kb, AiKbRevision $revision): array
    {
        $cases = $kb->testCases()->get();
        if ($cases->isEmpty()) {
            return ['passed' => true, 'status' => 'passed', 'critical_percent' => 100, 'normal_percent' => 100];
        }
        foreach ($cases as $case) {
            try {
                $result = $this->test($kb, $case->question, $revision);
                $facts = array_values(array_filter(array_map('trim', preg_split('/\R|,/', (string) $case->expected_facts) ?: [])));
                $haystack = mb_strtolower(implode(' ', array_column($result['sources'], 'excerpt')));
                $factsPresent = collect($facts)->every(fn ($fact) => str_contains($haystack, mb_strtolower($fact)));
                $sourcePresent = ! $case->expected_document_id || collect($result['sources'])->contains('document_id', $case->expected_document_id);
                $passed = $result['decision'] === 'answer' && $factsPresent && $sourcePresent;
            } catch (\Throwable $exception) {
                $result = ['warnings' => ['Test could not run: '.$exception::class]];
                $passed = false;
            }
            $case->update(['last_status' => $passed ? 'passed' : 'failed', 'last_result' => $result, 'last_run_at' => now()]);
        }
        $cases = $kb->testCases()->get();
        $critical = $cases->where('critical', true);
        $normal = $cases->where('critical', false);
        $criticalPercent = $critical->isEmpty() ? 100 : (int) round($critical->where('last_status', 'passed')->count() / $critical->count() * 100);
        $normalPercent = $normal->isEmpty() ? 100 : (int) round($normal->where('last_status', 'passed')->count() / $normal->count() * 100);
        $passed = $criticalPercent >= (int) config('knowledge_base.critical_test_pass_percent', 100)
            && $normalPercent >= (int) config('knowledge_base.normal_test_pass_percent', 80);
        $revision->update(['regression_status' => $passed ? 'passed' : 'failed']);
        $kb->update(['regression_status' => $passed ? 'passed' : 'failed']);

        return ['passed' => $passed, 'status' => $passed ? 'passed' : 'failed', 'critical_percent' => $criticalPercent, 'normal_percent' => $normalPercent];
    }

    private function emptyResult(): array
    {
        return ['answer' => null, 'decision' => 'handoff', 'confidence' => 0, 'estimated_prompt_tokens' => 0, 'sources' => [], 'warnings' => ['No ready source is available.']];
    }

    private function terms(string $text): array
    {
        preg_match_all('/[\p{L}\p{N}]{3,}/u', mb_strtolower(strip_tags($text)), $matches);

        return array_values(array_unique($matches[0] ?? []));
    }
}
