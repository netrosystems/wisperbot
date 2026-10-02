<?php

namespace App\Console\Commands;

use App\Modules\AI\Models\AiChatbot;
use Illuminate\Console\Command;

/**
 * Moves one Smart Bot between answer engines (Smart Bot 2.0 canary).
 *
 * Engine v2 answers only while SMART_BOT_ENGINE_V2 is on as well, so a bot can
 * be moved ahead of the switch, and the switch turns every moved bot back to
 * v1 at once.
 *
 *   php artisan ai:engine 12          show bot 12's engine
 *   php artisan ai:engine 12 v2       move bot 12 to engine v2
 *   php artisan ai:engine --list      every bot on engine v2
 */
class SmartBotEngineCommand extends Command
{
    protected $signature = 'ai:engine
        {bot? : Smart Bot ID or UUID}
        {engine? : v1 or v2}
        {--list : List the bots on engine v2}';

    protected $description = 'Show or change which answer engine a Smart Bot uses';

    public function handle(): int
    {
        if ($this->option('list')) {
            $bots = AiChatbot::where('engine', 'v2')->get(['id', 'workspace_id', 'name', 'answer_scope', 'reply_length']);
            $this->table(['ID', 'Workspace', 'Name', 'Answer scope', 'Reply length'], $bots->map(fn (AiChatbot $bot) => [
                $bot->id, $bot->workspace_id, $bot->name, $bot->answer_scope, $bot->reply_length,
            ])->all());
            $this->line('SMART_BOT_ENGINE_V2 is '.(config('chatbot.engine_v2_enabled') ? 'on' : 'off').'.');

            return self::SUCCESS;
        }

        $key = (string) $this->argument('bot');
        $bot = AiChatbot::query()->where(is_numeric($key) ? 'id' : 'uuid', $key)->first();
        if (! $bot) {
            $this->error('No Smart Bot found for '.$key.'.');

            return self::FAILURE;
        }

        $engine = $this->argument('engine');
        if ($engine === null) {
            $this->line("Bot {$bot->id} ({$bot->name}, workspace {$bot->workspace_id}) uses engine {$bot->engine}.");
            $this->line('SMART_BOT_ENGINE_V2 is '.(config('chatbot.engine_v2_enabled') ? 'on' : 'off').'; it answers with '.($bot->usesEngineV2() ? 'v2' : 'v1').' now.');

            return self::SUCCESS;
        }
        if (! in_array($engine, ['v1', 'v2'], true)) {
            $this->error('The engine must be v1 or v2.');

            return self::FAILURE;
        }

        $bot->forceFill(['engine' => $engine])->save();
        $this->info("Bot {$bot->id} ({$bot->name}) now uses engine {$engine}.");
        if ($engine === 'v2' && ! config('chatbot.engine_v2_enabled')) {
            $this->warn('SMART_BOT_ENGINE_V2 is off, so it keeps answering with v1 until the switch is on.');
        }

        return self::SUCCESS;
    }
}
