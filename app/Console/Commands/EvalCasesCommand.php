<?php

namespace App\Console\Commands;

use App\Modules\AI\Models\AiChatbot;
use App\Modules\AI\Models\AiEvalCase;
use Illuminate\Console\Command;

/**
 * Lists a Smart Bot's test questions for review, and retires bad ones
 * (Smart Bot 2.0, Phase 1.6).
 *
 *   php artisan ai:eval:cases 12
 *   php artisan ai:eval:cases 12 --retire=40 --retire=41
 */
class EvalCasesCommand extends Command
{
    protected $signature = 'ai:eval:cases
        {bot : Smart Bot ID or UUID}
        {--retire=* : Retire these question IDs}
        {--all : Include retired questions}';

    protected $description = 'List or retire a Smart Bot\'s answer-quality test questions';

    public function handle(): int
    {
        $key = (string) $this->argument('bot');
        $bot = AiChatbot::query()->where(ctype_digit($key) ? 'id' : 'uuid', $key)->first();
        if (! $bot) {
            $this->error("No Smart Bot {$key}.");

            return self::FAILURE;
        }

        $retire = array_map('intval', (array) $this->option('retire'));
        if ($retire !== []) {
            $retired = AiEvalCase::where('chatbot_id', $bot->id)->whereIn('id', $retire)->update(['status' => AiEvalCase::RETIRED]);
            $this->info("Retired {$retired} question(s).");
        }

        $cases = AiEvalCase::where('chatbot_id', $bot->id)->when(! $this->option('all'), fn ($query) => $query->active())->orderBy('id')->get();
        $this->table(['ID', 'Status', 'Expected', 'Source', 'Lang', 'Question', 'Facts'], $cases->map(fn (AiEvalCase $case) => [
            $case->id, $case->status, $case->expected, $case->source, $case->language,
            mb_strimwidth(($case->history ? '↳ ' : '').$case->question, 0, 70, '…'),
            mb_strimwidth(implode('; ', (array) $case->expected_facts), 0, 40, '…'),
        ])->all());
        $this->line($cases->count().' question(s).');

        return self::SUCCESS;
    }
}
