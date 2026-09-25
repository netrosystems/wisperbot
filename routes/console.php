<?php

Schedule::call(function () {
    if (! config('social_comments.enabled')) {
        return;
    }
    SocialAccount::whereIn('network', ['facebook', 'instagram'])->where('active', true)
        ->whereIn('id', SocialCommentSetting::where('connection_status', 'ready')
            ->where(fn ($q) => $q->whereNull('last_synced_at')->orWhere('last_synced_at', '<', now()->subMinutes(15)))->select('social_account_id'))
        ->chunkById(100, function ($accounts) {
            foreach ($accounts as $account) {
                SyncSocialComments::dispatch($account->id, $account->workspace_id);
            }
        });
})->everyFifteenMinutes()->name('social-comments-sync')->withoutOverlapping();

use App\Http\Controllers\Admin\CronSetupController;
use App\Modules\AI\Jobs\RefreshLiveProductDocumentJob;
use App\Modules\AI\Models\AiKbDocument;
use App\Modules\AI\Services\AiCreditService;
use App\Modules\Broadcasting\Jobs\LaunchScheduledCampaignsJob;
use App\Modules\Broadcasting\Models\UsageMeter;
use App\Modules\Inbox\Jobs\SyncEbayAccountJob;
use App\Modules\Inbox\Jobs\SyncEmailAccountJob;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Social\Jobs\DispatchScheduledPostsJob;
use App\Modules\Social\Jobs\RefreshSocialTokensJob;
use App\Modules\Social\Jobs\SyncSocialComments;
use App\Modules\Social\Models\SocialAccount;
use App\Modules\Social\Models\SocialCommentSetting;
use App\Modules\Whatsapp\Jobs\TemplateSyncJob;
use App\Modules\Whatsapp\Models\WhatsappBusinessAccount;
use App\Modules\Whatsapp\Models\WhatsappConnectionOperation;
use App\Modules\Whatsapp\Services\WhatsappConnectionHealthService;
use App\Services\WebhookIdempotencyService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Heartbeat: records the last time the scheduler ran so the admin "Cron Setup"
// guide can confirm the server's cron entry is actually firing.
Schedule::call(function () {
    Cache::put(CronSetupController::HEARTBEAT_KEY, now()->toIso8601String(), now()->addDay());
})->everyMinute()->name('scheduler-heartbeat');

Schedule::call(fn () => app(WhatsappConnectionHealthService::class)->tick())
    ->everyMinute()->name('whatsapp-connection-health')->withoutOverlapping();

Schedule::call(function () {
    WhatsappConnectionOperation::where('created_at', '<', now()->subDays(config('channel_health.retention_days', 90)))
        ->whereNotNull('finished_at')->delete();
})->daily()->name('prune-whatsapp-connection-health');

// ─── Marketing Suite Scheduled Tasks ────────────────────────────────────────

// Dispatch any campaigns scheduled for now
Schedule::job(new LaunchScheduledCampaignsJob, 'broadcast')
    ->everyMinute()
    ->name('launch-scheduled-campaigns')
    ->withoutOverlapping();

// Sync WhatsApp templates from Meta (once per day)
Schedule::call(function () {
    WhatsappBusinessAccount::all()->each(function ($waba) {
        TemplateSyncJob::dispatch($waba->id)->onQueue('whatsapp');
    });
})->daily()->name('sync-whatsapp-templates');

// Safety net for scheduled social posts. The primary path is the delayed
// PublishSocialPostJob queued when a post is created. Check frequently here so
// a temporarily unavailable queue worker does not introduce a full-minute lag
// once the scheduler is available again.
Schedule::job(new DispatchScheduledPostsJob, 'social')
    ->everyTenSeconds()
    ->name('dispatch-social-posts')
    ->withoutOverlapping();

// Refresh expiring social OAuth tokens daily
Schedule::job(new RefreshSocialTokensJob, 'social')
    ->dailyAt('02:00')
    ->name('refresh-social-tokens');

// Poll eBay seller conversations until production notification subscriptions
// are enabled. Each seller account is isolated in its own queued sync job.
Schedule::call(function () {
    ChannelAccount::where('channel', 'ebay')
        ->where('status', 'active')
        ->pluck('id')
        ->each(fn (int $id) => SyncEbayAccountJob::dispatch($id));
})->everyFiveMinutes()->name('sync-ebay-messages')->withoutOverlapping();

Schedule::call(function () {
    ChannelAccount::where('channel', 'email')
        ->where('status', 'active')
        ->pluck('id')
        ->each(fn (int $id) => SyncEmailAccountJob::dispatch($id)->onQueue('default'));
})->everyMinute()->name('sync-email-inboxes')->withoutOverlapping();

// Reset monthly usage meters on the 1st of each month
Schedule::call(function () {
    // Meters older than 2 months are pruned; current month is always kept
    UsageMeter::where('period', '<', (int) now()->subMonths(2)->format('Ym'))->delete();
})->monthlyOn(1, '00:05')->name('reset-usage-meters');

// Provider/network failures can strand a reservation if a worker is killed.
// Reconcile after the ten-minute safety window; finalized calls are never refunded.
Schedule::call(fn () => app(AiCreditService::class)->reconcileStaleReservations())
    ->everyFiveMinutes()
    ->name('reconcile-ai-credit-reservations')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::call(function (): void {
    if (! config('knowledge_base.live_product_facts_enabled')) {
        return;
    }
    $cutoff = now()->subMinutes(max(5, (int) config('knowledge_base.live_product_freshness_minutes', 15)));
    AiKbDocument::query()
        ->where('source_type', 'url')
        ->where('enabled', true)
        ->where('publication_status', 'published')
        ->where(fn ($query) => $query->whereNull('products_verified_at')->orWhere('products_verified_at', '<=', $cutoff))
        ->whereHas('knowledgeBase.chatbots', fn ($query) => $query->where('live_product_facts_enabled', true))
        ->whereExists(function ($query): void {
            $query->select(DB::raw(1))
                ->from('ai_knowledge_bases as live_product_kb')
                ->join('ai_kb_revision_documents as live_product_revision_documents', 'live_product_revision_documents.revision_id', '=', 'live_product_kb.published_revision_id')
                ->whereColumn('live_product_kb.id', 'ai_kb_documents.kb_id')
                ->whereColumn('live_product_revision_documents.document_id', 'ai_kb_documents.id');
        })
        ->orderBy('products_verified_at')
        ->limit(max(1, min(200, (int) config('knowledge_base.live_product_refresh_batch', 50))))
        ->pluck('id')
        ->each(fn (int $id) => RefreshLiveProductDocumentJob::dispatch($id)->onQueue('ai'));
})->everyFiveMinutes()->name('refresh-live-kb-products')->withoutOverlapping()->onOneServer();

// Prune inbound webhook idempotency records older than 30 days
Schedule::call(function () {
    app(WebhookIdempotencyService::class)->prune(30);
})->weekly()->name('prune-inbound-webhook-events');

// Permanently erase workspaces deleted more than 30 days ago (restore window over)
Schedule::command('workspaces:purge-deleted')
    ->dailyAt('03:30')
    ->name('workspaces-purge-deleted')
    ->withoutOverlapping()
    ->onOneServer();

// Sync subscription statuses with payment gateways (hourly)
Schedule::command('billing:sync')
    ->hourly()
    ->name('billing-sync')
    ->withoutOverlapping()
    ->onOneServer();

// Expire trials that have passed their trial_ends_at and not yet converted
Schedule::command('billing:expire-trials')
    ->hourly()
    ->name('billing-expire-trials')
    ->withoutOverlapping()
    ->onOneServer();

// Legacy gateway recurring-charge schedules are intentionally disabled.

// Notify users whose trial ends in 3 days (daily at 09:00)
Schedule::command('notifications:trial-ending --days=3')
    ->dailyAt('09:00')
    ->name('notify-trial-ending-3d')
    ->withoutOverlapping()
    ->onOneServer();

// Send weekly performance digest to all workspace owners (Monday 09:00)
Schedule::command('reports:weekly-digest')
    ->mondays()
    ->at('09:00')
    ->name('weekly-digest-emails')
    ->withoutOverlapping()
    ->onOneServer();

// Email the assigned teammate and workspace owner when the latest customer
// message has not received a reply within one hour.
Schedule::command('inbox:send-unanswered-reminders')
    ->everyTenMinutes()
    ->name('inbox-unanswered-conversation-reminders')
    ->withoutOverlapping()
    ->onOneServer();
