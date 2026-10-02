<?php

namespace App\Console\Commands;

use App\Modules\AI\Models\AiChatbot;
use App\Modules\AI\Models\AiEvalResult;
use App\Modules\AI\Services\Eval\EvalRunner;
use Illuminate\Console\Command;

/**
 * Runs a Smart Bot against its test set and scores it against the Phase 1
 * targets (Smart Bot 2.0, Phase 1.6). Exits non-zero when the bot misses a
 * target, so it can gate a release. Platform-billed: no client credits.
 *
 *   php artisan ai:eval 12
 *   php artisan ai:eval 12 --judge --json
 */
class EvalRunCommand extends Command
{
    protected $signature = 'ai:eval
        {bot : Smart Bot ID or UUID}
        {--judge : Also grade each answer with a model (platform-billed)}
        {--byok : Allow a run on a workspace that answers with its own AI key (bills the client\'s provider)}
        {--limit= : Run only the first N questions}
        {--json : Print the run summary as JSON}';

    protected $description = 'Score a Smart Bot against its answer-quality test set';

    public function handle(EvalRunner $runner): int
    {
        $key = (string) $this->argument('bot');
        $bot = AiChatbot::query()->where(ctype_digit($key) ? 'id' : 'uuid', $key)->first();
        if (! $bot) {
            $this->error("No Smart Bot {$key}.");

            return self::FAILURE;
        }

        $json = (bool) $this->option('json');
        try {
            $run = $runner->run($bot, (bool) $this->option('judge'), (bool) $this->option('byok'), $this->option('limit') ? (int) $this->option('limit') : null,
                $json ? null : fn (AiEvalResult $result) => $this->output->write($result->verdict === AiEvalResult::PASS ? '.' : 'F'));
        } catch (\Throwable $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }

        $summary = (array) $run->summary;
        if ($json) {
            $this->line((string) json_encode(['run' => $run->id, 'bot' => $bot->id, 'engine' => $run->engine, 'passed' => $run->passed] + $summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return $run->passed ? self::SUCCESS : self::FAILURE;
        }

        $targets = (array) config('chatbot.eval.targets');
        $percent = fn ($rate) => $rate === null ? 'no cases' : round($rate * 100, 1).'%';
        $rows = [
            ['Answerable questions answered', $percent($summary['answered_rate'])." of {$summary['answerable']}", '≥ '.($targets['answered_rate'] * 100).'%'],
            ['Unanswerable questions declined', $percent($summary['declined_rate'])." of {$summary['unanswerable']}", '≥ '.($targets['declined_rate'] * 100).'%'],
            ['Answers with invented figures', $summary['invented_figures'], (string) $targets['invented_figures']],
            ['Fallbacks', $summary['fallbacks'], '—'],
            ['Errors', $summary['errors'], '—'],
            ['Latency p50 / p95', ($summary['latency_p50_ms'] ?? '—').' / '.($summary['latency_p95_ms'] ?? '—').' ms', 'p50 ≤ 5000 ms'],
            ['Tokens (platform)', $summary['tokens'], '—'],
        ];
        if ($summary['judge_average'] !== null) {
            $rows[] = ['Judge average (1–5)', $summary['judge_average'], '—'];
        }
        $this->newLine(2);
        $this->info("{$bot->name} (#{$bot->id}) on engine {$run->engine}: run #{$run->id}, {$summary['cases']} questions");
        $this->table(['Measure', 'Result', 'Target'], $rows);
        foreach ((array) $summary['by_language'] as $language => $row) {
            $this->line("  {$language}: {$percent($row['pass_rate'])} of {$row['cases']}");
        }

        $failures = $run->results()->with('evalCase')->where('verdict', AiEvalResult::FAIL)->limit(20)->get();
        if ($failures->isNotEmpty()) {
            $this->table(['Failure', 'Question', 'Reply', 'Missing / invented'], $failures->map(fn (AiEvalResult $result) => [
                $result->failure,
                mb_strimwidth((string) $result->evalCase?->question, 0, 50, '…'),
                mb_strimwidth((string) ($result->reply ?? data_get($result->trace, 'error', '')), 0, 60, '…'),
                mb_strimwidth(implode('; ', array_merge((array) $result->facts_missing, (array) $result->invented_figures)), 0, 30, '…'),
            ])->all());
        }

        $run->passed ? $this->info('PASSED: meets the Phase 1 targets.') : $this->error('NOT YET: below at least one Phase 1 target.');

        return $run->passed ? self::SUCCESS : self::FAILURE;
    }
}
