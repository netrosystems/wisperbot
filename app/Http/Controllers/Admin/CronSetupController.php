<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Whatsapp\Models\WhatsappBusinessAccount;
use App\Modules\Whatsapp\Models\WhatsappConnectionHealth;
use App\Modules\Whatsapp\Services\WhatsappConnectionHealthService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

class CronSetupController extends Controller
{
    /** Every named queue used by application jobs in production. */
    public const QUEUE_NAMES = ['default', 'whatsapp', 'broadcast', 'ai', 'social', 'leads', 'automation', 'channel-health'];

    /**
     * Cache key written every minute by the scheduler heartbeat (see routes/console.php).
     * Reading it back lets the admin verify their cron entry is actually firing.
     */
    public const HEARTBEAT_KEY = 'admin:scheduler_last_run';

    public function index(): Response
    {
        return Inertia::render('Admin/CronSetup/Index', [
            'basePath' => base_path(),
            'phpBinary' => PHP_BINARY,
            'queueConnection' => (string) config('queue.default'),
            'queueNames' => self::QUEUE_NAMES,
            'tasks' => $this->scheduledTasks(),
            'schedulerLastRun' => $this->heartbeat()?->toIso8601String(),
            'schedulerStatus' => $this->status($this->heartbeat()),
            'whatsappHealth' => $this->whatsappHealth(),
        ]);
    }

    private function whatsappHealth(): array
    {
        $service = app(WhatsappConnectionHealthService::class);
        if (! $service->enabled()) {
            return ['enabled' => false];
        }

        return [
            'enabled' => true,
            'platform' => array_merge(Cache::get('wa-health:last-platform', []), $service->runtime()),
            'accounts' => WhatsappBusinessAccount::where('status', 'active')
                ->whereIn('id', WhatsappConnectionHealth::query()->where(function ($q) {
                    $q->where('state', '!=', 'ready')->orWhere('checked_at', '<', now()->subMinutes(30))->orWhere('pending_live_messages', '>', 0);
                })->select('waba_id'))
                ->orderBy('id')->limit(50)->get()->map(fn ($waba) => [
                    'id' => $waba->id, 'workspace_id' => $waba->workspace_id, 'waba_id' => $waba->waba_id,
                    'health' => $service->summary($waba),
                ])->all(),
        ];
    }

    /**
     * The tasks currently registered in routes/console.php, so the guide always
     * reflects what the app actually runs rather than a hard-coded list.
     *
     * @return array<int, array{description: string, expression: string}>
     */
    private function scheduledTasks(): array
    {
        $tasks = [];

        try {
            foreach (app(Schedule::class)->events() as $event) {
                $tasks[] = [
                    'description' => $event->description ?: $event->getSummaryForDisplay(),
                    'expression' => $event->expression,
                ];
            }
        } catch (\Throwable) {
            // Schedule could not be resolved — fall back to an empty list.
        }

        return $tasks;
    }

    private function heartbeat(): ?Carbon
    {
        $raw = Cache::get(self::HEARTBEAT_KEY);

        if (! $raw) {
            return null;
        }

        try {
            return Carbon::parse($raw);
        } catch (\Throwable) {
            return null;
        }
    }

    private function status(?Carbon $lastRun): string
    {
        if (! $lastRun) {
            return 'inactive';
        }

        $secondsAgo = $lastRun->diffInSeconds(now());

        return match (true) {
            $secondsAgo <= 120 => 'active',
            $secondsAgo <= 3600 => 'stale',
            default => 'inactive',
        };
    }
}
