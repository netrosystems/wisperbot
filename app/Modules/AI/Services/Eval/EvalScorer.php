<?php

namespace App\Modules\AI\Services\Eval;

use App\Modules\AI\Models\AiEvalCase;
use App\Modules\AI\Models\AiEvalResult;
use App\Modules\AI\Services\Agent\FigureCheck;

/**
 * Scores one test answer, and a run against the Phase 1 targets (Smart Bot
 * 2.0, Phase 1.6; after Cerqle's EvalScorer). Deterministic: the same answer
 * always gets the same verdict, so a run can gate a release.
 *
 * - An answerable question passes when the bot answered it, every expected
 *   figure is in the reply (compared by digits: "$40" = "40 USD") and at
 *   least half of the expected word facts are mentioned. A translated
 *   question is checked on figures only: its reply is in another language
 *   than the facts (2026-10-04; requiring every phrase failed correct answers).
 * - An unanswerable one passes when the bot declined, offered a person, asked
 *   a question, or gave general guidance that states no figures.
 * - Any figure that is in neither the knowledge, the business details, the
 *   bot's instructions nor the conversation fails the answer, whatever was
 *   expected.
 */
class EvalScorer
{
    /** Turns that did not answer: the bot asked, offered a person or fell back. */
    private const NOT_ANSWERED_MODES = ['fallback', 'clarification', 'handoff'];

    private const NOT_ANSWERED_KINDS = ['handoff', 'clarification'];

    private const COMMON_WORDS = ['the', 'and', 'for', 'with', 'your', 'you', 'our', 'are', 'can', 'from', 'that', 'this', 'per'];

    public function __construct(private readonly FigureCheck $figures) {}

    /**
     * @param  array<string,mixed>  $answer  ChatbotRunner::runForApi() output
     * @param  array<string,mixed>  $context  ChatbotRunner::lastTurnContext()
     * @param  string  $supporting  Everything the bot may state figures from
     * @return array<string,mixed> AiEvalResult attributes
     */
    public function score(AiEvalCase $case, array $answer, array $context, string $supporting): array
    {
        $reply = trim((string) ($answer['reply'] ?? ''));
        $kind = data_get($context, 'trace.answer_kind');
        $answered = $this->answered($answer, $kind, $reply);
        $conversation = $case->question."\n".collect($case->history ?? [])->where('role', 'user')->pluck('content')->implode("\n");
        $invented = $reply === '' ? [] : $this->figures->unsupportedFigures($reply, $supporting."\n".$conversation);
        $missing = $case->expected === AiEvalCase::EXPECT_ANSWER
            ? $this->missingFacts((array) ($case->expected_facts ?? []), $reply, $case->source === AiEvalCase::SOURCE_TRANSLATION)
            : [];

        $failure = match (true) {
            $invented !== [] => 'invented_figures',
            $case->expected === AiEvalCase::EXPECT_ANSWER && ! $answered => 'not_answered',
            $case->expected === AiEvalCase::EXPECT_ANSWER && $missing !== [] => 'missing_facts',
            $case->expected === AiEvalCase::EXPECT_DECLINE && $answered && ! $this->safeGuidance($kind, $answer, $reply) => 'answered_unanswerable',
            default => null,
        };

        return [
            'expected' => $case->expected,
            'reply' => $reply === '' ? null : $reply,
            'response_mode' => $answer['response_mode'] ?? null,
            'answer_origin' => $answer['answer_origin'] ?? null,
            'reason_code' => $context['reason_code'] ?? null,
            'verdict' => $failure === null ? AiEvalResult::PASS : AiEvalResult::FAIL,
            'failure' => $failure,
            'facts_missing' => $missing ?: null,
            'invented_figures' => $invented ?: null,
            'tokens' => (int) ($answer['tokens_used'] ?? 0),
            'latency_ms' => (int) ($context['latency_ms'] ?? 0),
            'trace' => array_filter([
                'engine' => $context['engine'] ?? null,
                'answer_kind' => $kind,
                'best_score' => $context['best_score'] ?? null,
                'planner' => data_get($context, 'trace.planner'),
                'used_sources' => data_get($context, 'trace.used_sources'),
                'validator_shadow' => data_get($context, 'trace.validator_shadow'),
                'passages' => data_get($context, 'trace.passages'),
                'second_look' => data_get($context, 'trace.second_look'),
                'regenerated_because' => data_get($context, 'trace.regenerated_because'),
                'support_check' => data_get($context, 'trace.support_check'),
                'rejected_because' => data_get($context, 'trace.rejected_because'),
                'model' => $context['model'] ?? null,
            ], fn ($value) => $value !== null && $value !== []),
        ];
    }

    /**
     * Rates and counts for a run, and whether it meets the Phase 1 targets.
     *
     * @param  iterable<AiEvalResult>  $results
     * @return array{summary:array<string,mixed>,passed:bool}
     */
    public function summarise(iterable $results): array
    {
        $results = collect($results);
        $answerable = $results->where('expected', AiEvalCase::EXPECT_ANSWER);
        $unanswerable = $results->where('expected', AiEvalCase::EXPECT_DECLINE);
        $rate = fn ($set) => $set->count() === 0 ? null : round($set->where('verdict', AiEvalResult::PASS)->count() / $set->count(), 4);
        $latencies = $results->where('failure', '!=', 'error')->pluck('latency_ms')->filter()->sort()->values();
        $percentile = fn (float $p) => $latencies->isEmpty() ? null : (int) $latencies->get((int) floor(($latencies->count() - 1) * $p));
        $judged = $results->whereNotNull('judge_score');

        $summary = [
            'cases' => $results->count(),
            'answerable' => $answerable->count(),
            'unanswerable' => $unanswerable->count(),
            'answered_rate' => $rate($answerable),
            'declined_rate' => $rate($unanswerable),
            'invented_figures' => $results->where('failure', 'invented_figures')->count(),
            'fallbacks' => $results->where('answer_origin', 'fallback')->count(),
            'errors' => $results->where('failure', 'error')->count(),
            'failures' => $results->whereNotNull('failure')->countBy('failure')->sortDesc()->all(),
            'by_language' => $results->filter(fn (AiEvalResult $result) => (string) $result->evalCase?->language !== '')
                ->groupBy(fn (AiEvalResult $result) => (string) $result->evalCase?->language)
                ->map(fn ($set) => ['cases' => $set->count(), 'pass_rate' => $rate($set)])->all(),
            'latency_p50_ms' => $percentile(0.5),
            'latency_p95_ms' => $percentile(0.95),
            'tokens' => (int) $results->sum('tokens'),
            'judge_average' => $judged->isEmpty() ? null : round((float) $judged->avg('judge_score'), 2),
        ];

        $targets = (array) config('chatbot.eval.targets');
        // A category with no cases proves nothing, so it cannot pass.
        $passed = $summary['answered_rate'] !== null && $summary['declined_rate'] !== null
            && $summary['answered_rate'] >= (float) $targets['answered_rate']
            && $summary['declined_rate'] >= (float) $targets['declined_rate']
            && $summary['invented_figures'] <= (int) $targets['invented_figures'];

        return ['summary' => $summary, 'passed' => $passed];
    }

    /**
     * @param  list<string>  $facts
     * @return list<string>
     */
    public function missingFacts(array $facts, string $reply, bool $figuresOnly = false): array
    {
        $text = $this->normalise($reply);
        $replyNumbers = $this->numbers($reply);
        $missingFigures = [];
        $words = [];
        foreach ($facts as $fact) {
            $numbers = $this->numbers($fact);
            if ($numbers !== []) {
                if (array_diff($numbers, $replyNumbers) !== []) {
                    $missingFigures[] = $fact;
                }
            } elseif (! $figuresOnly) {
                $words[] = $fact;
            }
        }
        // Every figure, and at least half of the word facts.
        $missingWords = array_values(array_filter($words, fn (string $fact): bool => ! $this->mentions($text, $fact)));
        $enough = count($words) - count($missingWords) >= (int) ceil(count($words) / 2);

        return [...$missingFigures, ...($enough ? [] : $missingWords)];
    }

    /** @param array<string,mixed> $answer */
    private function answered(array $answer, mixed $kind, string $reply): bool
    {
        return $reply !== ''
            && ! in_array($answer['response_mode'] ?? null, self::NOT_ANSWERED_MODES, true)
            && ! in_array($kind, self::NOT_ANSWERED_KINDS, true)
            && ($answer['answer_origin'] ?? null) !== 'fallback';
    }

    /**
     * General guidance or a partial answer is a fair reply to a question the
     * knowledge does not cover, as long as it claims nothing specific.
     *
     * @param  array<string,mixed>  $answer
     */
    private function safeGuidance(mixed $kind, array $answer, string $reply): bool
    {
        $guidance = in_array($kind, ['guidance', 'partial'], true)
            || ($kind === null && ($answer['answer_origin'] ?? null) !== 'knowledge_base');

        return $guidance && $this->figures->unsupportedFigures($reply, '') === [];
    }

    /**
     * A word fact is mentioned when its phrase is in the reply, or at least
     * 80% of its key words are, in any order. Short and common words do not count.
     */
    private function mentions(string $text, string $fact): bool
    {
        $phrase = $this->normalise($fact);
        if (str_contains($text, $phrase)) {
            return true;
        }
        $words = array_values(array_filter(preg_split('/[^\p{L}\p{M}\p{N}.]+/u', $phrase) ?: [], fn (string $word) => mb_strlen($word) >= 3 && ! in_array($word, self::COMMON_WORDS, true)));
        if ($words === []) {
            return false;
        }
        $found = count(array_filter($words, fn (string $word) => str_contains($text, $word)));

        return $found / count($words) >= 0.8;
    }

    private function normalise(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', mb_strtolower($text)));
    }

    /** @return list<string> "1,500.00" and "1500" are the same number. */
    private function numbers(string $text): array
    {
        preg_match_all('/\d[\d,]*(?:\.\d+)?/u', FigureCheck::toLatin($text), $matches);

        return array_values(array_unique(array_map(function (string $number): string {
            $number = str_replace(',', '', $number);

            return str_contains($number, '.') ? rtrim(rtrim($number, '0'), '.') : $number;
        }, $matches[0])));
    }
}
