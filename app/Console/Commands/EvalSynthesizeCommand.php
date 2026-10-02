<?php

namespace App\Console\Commands;

use App\Modules\AI\Models\AiChatbot;
use App\Modules\AI\Services\Eval\EvalCaseSynthesizer;
use Illuminate\Console\Command;

/**
 * Writes a Smart Bot's answer-quality test set (Smart Bot 2.0, Phase 1.6)
 * from its Knowledge Base tests, real unanswered questions and its own
 * knowledge. Platform-billed: no client credits.
 *
 *   php artisan ai:eval:synthesize 12 --count=60
 *   php artisan ai:eval:synthesize 12 --dry-run
 */
class EvalSynthesizeCommand extends Command
{
    protected $signature = 'ai:eval:synthesize
        {bot : Smart Bot ID or UUID}
        {--count= : How many questions to write (default 60, at most 100)}
        {--languages= : Comma-separated languages for translated questions (default bn,bn-Latn,ar; "none" for none)}
        {--fresh : Retire the current questions first}
        {--dry-run : Show the questions without saving them}';

    protected $description = 'Write a Smart Bot\'s answer-quality test questions (platform-billed)';

    public function handle(EvalCaseSynthesizer $synthesizer): int
    {
        $key = (string) $this->argument('bot');
        $bot = AiChatbot::query()->where(ctype_digit($key) ? 'id' : 'uuid', $key)->first();
        if (! $bot) {
            $this->error("No Smart Bot {$key}.");

            return self::FAILURE;
        }
        $option = $this->option('languages');
        $languages = $option === 'none' ? [] : ($option !== null
            ? array_values(array_filter(array_map('trim', explode(',', (string) $option))))
            : (array) config('chatbot.eval.languages', []));

        $this->info("Writing test questions for {$bot->name} (#{$bot->id}, workspace {$bot->workspace_id})…");
        try {
            $stats = $synthesizer->synthesize($bot, (int) ($this->option('count') ?: config('chatbot.eval.default_cases', 60)), $languages, (bool) $this->option('fresh'), (bool) $this->option('dry-run'));
        } catch (\Throwable $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }

        $this->table(['Expected', 'Source', 'Lang', 'Question', 'Facts'], collect($stats['cases'])->take(25)->map(fn (array $case) => [
            $case['expected'], $case['source'], $case['language'] ?? '', mb_strimwidth((empty($case['history']) ? '' : '↳ ').$case['question'], 0, 70, '…'), mb_strimwidth(implode('; ', $case['facts'] ?? []), 0, 40, '…'),
        ])->all());
        $this->line('By source: '.collect($stats['by_source'])->map(fn ($n, $source) => "{$source} {$n}")->implode(', '));
        if ($this->option('dry-run')) {
            $this->warn('Dry run: nothing saved.');
        } else {
            $this->info("Saved: {$stats['created']} new, {$stats['reactivated']} brought back, {$stats['skipped']} already in the set.");
        }

        return self::SUCCESS;
    }
}
