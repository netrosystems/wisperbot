<?php

namespace App\Console\Commands;

use App\Modules\AI\Models\AiAnswerFeedback;
use App\Modules\AI\Models\AiCreditLedger;
use App\Modules\AI\Models\AiKbRetrievalDiagnostic;
use App\Modules\AI\Services\SmartBotRetrievalPolicy;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * How the Smart Bot is doing, read from the records it already keeps.
 *
 * Read-only. Every answer turn writes one `ai_kb_retrieval_diagnostics` row
 * with a reason code; the credit ledger says what was charged and refunded.
 * Run it before and after a change to compare fallback rates.
 *
 *   php artisan ai:smart-bot-report
 *   php artisan ai:smart-bot-report --days=7 --workspace=2 --json
 */
class SmartBotReportCommand extends Command
{
    /** Reason codes that mean the customer received a real answer. */
    private const ANSWERED = ['answered', 'answered_regenerated', 'answered_guidance', 'answered_cached', 'live_product', 'conversation', 'offer', 'replayed', 'model_clarification'];

    protected $signature = 'ai:smart-bot-report
        {--days=14 : How many days back to look}
        {--workspace= : Limit to one workspace ID}
        {--json : Print the figures as JSON}';

    protected $description = 'Summarise Smart Bot answers, fallbacks and their reasons (read-only)';

    public function handle(SmartBotRetrievalPolicy $policy): int
    {
        $days = max(1, (int) $this->option('days'));
        $workspaceId = $this->option('workspace') !== null ? (int) $this->option('workspace') : null;
        $since = now()->subDays($days);
        $threshold = $policy->privateAnswering()['answer_threshold'];

        $turns = fn (): Builder => AiKbRetrievalDiagnostic::query()
            ->where('created_at', '>=', $since)
            ->when($workspaceId !== null, fn (Builder $query) => $query->where('workspace_id', $workspaceId));
        $countBy = fn (string $column): array => $turns()
            ->select($column, DB::raw('COUNT(*) as total'))
            ->groupBy($column)
            ->orderByDesc('total')
            ->pluck('total', $column)
            ->mapWithKeys(fn ($total, $key) => [((string) $key === '' ? 'unrecorded' : (string) $key) => (int) $total])
            ->all();

        $total = $turns()->count();
        $reasons = $countBy('reason_code');
        $answered = array_sum(array_intersect_key($reasons, array_flip(self::ANSWERED)));
        $clarifications = $turns()->where('reason_code', 'answered')->where('response_mode', 'clarification')->count();

        // Fallbacks that never reached the model, by how close the best passage came.
        $noContextScores = $turns()->where('reason_code', 'no_context')->whereNotNull('best_score')->pluck('best_score');
        $scoreBands = [];
        foreach ($noContextScores as $score) {
            $band = number_format(floor(((float) $score) * 20) / 20, 2);
            $scoreBands[$band] = ($scoreBands[$band] ?? 0) + 1;
        }
        krsort($scoreBands);

        $latencies = $turns()->whereNotNull('latency_ms')->orderBy('latency_ms')->pluck('latency_ms')->values();
        $ledger = AiCreditLedger::query()
            ->where('feature', 'chatbot_reply')
            ->where('created_at', '>=', $since)
            ->when($workspaceId !== null, fn (Builder $query) => $query->where('workspace_id', $workspaceId));

        $report = [
            'period_days' => $days,
            'workspace_id' => $workspaceId,
            'settings' => [
                'retrieval_threshold' => $threshold,
                'business_aware_routing' => (bool) config('chatbot.business_aware_routing_enabled'),
                'hybrid_retrieval' => (bool) config('knowledge_base.hybrid_retrieval_enabled'),
                'guarded_publishing' => (bool) config('knowledge_base.guarded_publishing'),
                'live_product_facts' => (bool) config('knowledge_base.live_product_facts_enabled'),
            ],
            'turns' => [
                'total' => $total,
                'answered' => $answered,
                'fallback' => $total - $answered,
                'answered_rate' => $total > 0 ? round($answered / $total * 100, 1) : null,
                'fallback_rate' => $total > 0 ? round(($total - $answered) / $total * 100, 1) : null,
                'clarifications' => $clarifications,
            ],
            'reasons' => $reasons,
            'engines' => $countBy('engine'),
            'answer_origin' => $countBy('answer_origin'),
            'channels' => $countBy('channel'),
            'models' => $countBy('model'),
            'finish_reasons' => $countBy('finish_reason'),
            'no_context_best_scores' => [
                'turns' => $noContextScores->count(),
                // Would have passed a lower cut-off; the evidence for recalibrating it.
                'between_0.45_and_threshold' => $noContextScores->filter(fn ($score) => $score >= 0.45 && $score < $threshold)->count(),
                'between_0.30_and_0.45' => $noContextScores->filter(fn ($score) => $score >= 0.30 && $score < 0.45)->count(),
                'bands' => $scoreBands,
            ],
            'latency_ms' => [
                'p50' => $latencies->isEmpty() ? null : $latencies[(int) floor(($latencies->count() - 1) * 0.5)],
                'p95' => $latencies->isEmpty() ? null : $latencies[(int) floor(($latencies->count() - 1) * 0.95)],
            ],
            'team_feedback' => [
                'up' => $this->feedback($since, $workspaceId)->where('rating', 'up')->count(),
                'down' => $this->feedback($since, $workspaceId)->where('rating', 'down')->count(),
                'improved' => $this->feedback($since, $workspaceId)->where('improved', true)->count(),
            ],
            'credits' => [
                'by_status' => (clone $ledger)->select('status', DB::raw('COUNT(*) as total'))->groupBy('status')->pluck('total', 'status')->map(fn ($n) => (int) $n)->all(),
                'refund_reasons' => (clone $ledger)->where('status', 'refunded')->select('error_code', DB::raw('COUNT(*) as total'))->groupBy('error_code')->pluck('total', 'error_code')->map(fn ($n) => (int) $n)->all(),
                'credits_charged' => (int) (clone $ledger)->where('status', 'succeeded')->sum('credits'),
            ],
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->info("Smart Bot report — last {$days} days".($workspaceId !== null ? " — workspace {$workspaceId}" : ''));
        foreach ($report as $section => $values) {
            if (! is_array($values)) {
                continue;
            }
            $this->newLine();
            $this->line('<comment>'.str_replace('_', ' ', ucfirst($section)).'</comment>');
            $rows = [];
            foreach ($values as $key => $value) {
                $rows[] = [$key, is_array($value) ? $this->inline($value) : $this->scalar($value)];
            }
            $this->table(['Measure', 'Value'], $rows === [] ? [['—', '—']] : $rows);
        }

        return self::SUCCESS;
    }

    /** @return Builder<AiAnswerFeedback> */
    private function feedback(\DateTimeInterface $since, ?int $workspaceId): Builder
    {
        return AiAnswerFeedback::query()->where('updated_at', '>=', $since)
            ->when($workspaceId !== null, fn (Builder $query) => $query->where('workspace_id', $workspaceId));
    }

    /** @param array<array-key, mixed> $values */
    private function inline(array $values): string
    {
        if ($values === []) {
            return '—';
        }

        return collect($values)->map(fn ($value, $key) => $key.': '.$this->scalar($value))->implode(', ');
    }

    private function scalar(mixed $value): string
    {
        return match (true) {
            $value === null => '—',
            is_bool($value) => $value ? 'on' : 'off',
            default => (string) $value,
        };
    }
}
