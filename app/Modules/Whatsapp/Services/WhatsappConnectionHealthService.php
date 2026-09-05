<?php

namespace App\Modules\Whatsapp\Services;

use App\Http\Controllers\Admin\CronSetupController;
use App\Models\AdminUser;
use App\Models\Role;
use App\Models\User;
use App\Models\Workspace;
use App\Modules\Whatsapp\Jobs\CheckWhatsappConnectionJob;
use App\Modules\Whatsapp\Jobs\CheckWhatsappPlatformJob;
use App\Modules\Whatsapp\Jobs\WhatsappWorkerHeartbeatJob;
use App\Modules\Whatsapp\Models\WhatsappBusinessAccount;
use App\Modules\Whatsapp\Models\WhatsappConnectionHealth;
use App\Modules\Whatsapp\Models\WhatsappConnectionOperation;
use App\Notifications\WhatsappConnectionHealthNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class WhatsappConnectionHealthService
{
    public function __construct(private readonly WhatsappHealthProbe $probe) {}

    public function enabled(?int $workspaceId = null): bool
    {
        $ids = config('channel_health.workspace_ids', []);

        return (bool) config('channel_health.enabled') && ($workspaceId === null || $ids === [] || in_array((string) $workspaceId, $ids, true));
    }

    public function snapshot(WhatsappBusinessAccount $waba): WhatsappConnectionHealth
    {
        return WhatsappConnectionHealth::firstOrCreate(['waba_id' => $waba->id, 'workspace_id' => $waba->workspace_id]);
    }

    public function enqueue(WhatsappBusinessAccount $waba, string $kind = 'check'): WhatsappConnectionOperation
    {
        return DB::transaction(function () use ($waba, $kind) {
            $locked = WhatsappBusinessAccount::where('workspace_id', $waba->workspace_id)->lockForUpdate()->findOrFail($waba->id);
            abort_unless($locked->status === 'active', 422, 'Reconnect this WhatsApp account before checking it.');
            $health = $this->snapshot($locked);
            $active = $health->operation_id ? WhatsappConnectionOperation::where('workspace_id', $waba->workspace_id)->find($health->operation_id) : null;
            if ($active && ! $active->finished_at && $active->created_at->gt(now()->subMinutes(30)) && hash_equals($active->credential_revision, $this->probe->revision($locked))) {
                return $active;
            }
            if ($active && ! $active->finished_at) {
                $active->update(['state' => 'expired', 'finished_at' => now(), 'results' => ['code' => 'worker_delayed']]);
            }
            $operation = WhatsappConnectionOperation::create([
                'id' => (string) Str::uuid(), 'workspace_id' => $waba->workspace_id, 'waba_id' => $waba->id,
                'kind' => $kind, 'credential_revision' => $this->probe->revision($locked),
            ]);
            $health->update(['operation_id' => $operation->id, 'next_check_at' => now()->addMinutes(30)]);
            CheckWhatsappConnectionJob::dispatch($operation->id, (int) $waba->workspace_id)->afterCommit();

            return $operation;
        });
    }

    public function execute(string $operationId, int $workspaceId): void
    {
        Cache::put('wa-health:worker', now()->toIso8601String(), 86400);
        $operation = WhatsappConnectionOperation::where('workspace_id', $workspaceId)->find($operationId);
        if (! $operation || $operation->finished_at) {
            return;
        }
        $lock = Cache::lock('wa-health:account:'.$operation->waba_id, 180);
        if (! $lock->get()) {
            return;
        }
        try {
            $waba = WhatsappBusinessAccount::where('workspace_id', $workspaceId)->find($operation->waba_id);
            if (! $waba || ! $this->enabled($workspaceId) || $waba->status !== 'active' || ! hash_equals($operation->credential_revision, $this->probe->revision($waba))) {
                $operation->update(['state' => 'discarded', 'finished_at' => now()]);
                WhatsappConnectionHealth::where('workspace_id', $workspaceId)->where('operation_id', $operation->id)
                    ->update(['operation_id' => null, 'next_check_at' => now()]);

                return;
            }
            $operation->update(['state' => 'running']);
            $result = $this->probe->inspect($waba, $operation->kind === 'repair');
            $platform = $this->probe->platform();
            if (($waba->meta_json['connected_via'] ?? '') !== 'coexistence') {
                unset($platform['coexistence']);
            }
            $components = array_merge($result['components'], array_combine(array_map(fn ($key) => 'platform:'.$key, array_keys($platform)), array_values($platform)), $this->runtime());
            DB::transaction(function () use ($waba, $operation, $components, $result) {
                $current = WhatsappBusinessAccount::where('workspace_id', $waba->workspace_id)->lockForUpdate()->find($waba->id);
                if (! $current) {
                    return;
                }
                $health = $this->snapshot($current);
                if ($current->status !== 'active' || $health->operation_id !== $operation->id || ! hash_equals($operation->credential_revision, $this->probe->revision($current))) {
                    $operation->update(['state' => 'discarded', 'finished_at' => now()]);
                    if ($health->operation_id === $operation->id) {
                        $health->update(['operation_id' => null, 'next_check_at' => now()]);
                    }

                    return;
                }
                $state = $this->state($components);
                $transient = collect($components)->contains(fn ($c) => $c['state'] === 'delayed');
                $failures = $transient ? $health->transient_failures + 1 : 0;
                $delay = $transient ? max($result['retry_after'], min(3600, 60 * (2 ** min($failures, 6)))) : (int) config('channel_health.interval_seconds', 900);
                $updates = [
                    'state' => $state, 'components' => $components, 'checked_at' => now(),
                    'credential_revision' => $operation->credential_revision, 'operation_id' => null,
                    'transient_failures' => $failures, 'next_check_at' => now()->addSeconds($delay),
                ];
                if ($state === 'ready') {
                    $updates['last_success_at'] = now();
                }
                if ($operation->kind === 'repair' && $state === 'ready') {
                    $updates['repaired_at'] = now();
                }
                foreach ($result['metadata'] ?? [] as $id => $metadata) {
                    $current->phoneNumbers()->whereKey($id)->update($metadata);
                }
                $health->update($updates);
                $operation->update(['state' => 'completed', 'finished_at' => now(), 'results' => ['health_state' => $state, 'components' => $components, 'subscription_restored' => $result['repaired']]]);
                $this->incident($health, $components, $failures);
            });
        } finally {
            $lock->release();
        }
    }

    /** @return array<string, array<string, string>> */
    public function runtime(): array
    {
        $components = [];
        foreach (['scheduler' => CronSetupController::HEARTBEAT_KEY, 'whatsapp_worker' => 'wa-health:whatsapp-worker', 'health_worker' => 'wa-health:worker'] as $name => $key) {
            $raw = Cache::get($key);
            $fresh = $raw && Carbon::parse($raw)->gt(now()->subMinutes(5));
            $components['platform:'.$name] = $fresh
                ? $this->probe->component('passed', $name.'_verified', str_replace('_', ' ', ucfirst($name)).' is running.')
                : $this->probe->component('delayed', $name.'_delayed', 'WhatsApp service monitoring or processing is delayed. An administrator should check workers and scheduling.', 'contact_admin');
        }

        return $components;
    }

    /** @return array<string, mixed> */
    public function summary(WhatsappBusinessAccount $waba): array
    {
        if (! $this->enabled((int) $waba->workspace_id)) {
            return ['enabled' => false];
        }
        $health = WhatsappConnectionHealth::where('workspace_id', $waba->workspace_id)->where('waba_id', $waba->id)->first();
        $components = array_merge($health->components ?? [], $this->runtime());
        if ($health?->last_live_received_at && $health->pending_live_messages > 0 && $health->last_live_received_at->lt(now()->subMinutes(5))) {
            $components['platform:processing'] = $this->probe->component('failed', 'processing_delayed', 'A received message has not completed processing. An administrator should review the WhatsApp queue.', 'contact_admin');
        }
        if ($health?->last_processing_error_at && (! $health->last_message_at || $health->last_processing_error_at->gt($health->last_message_at))) {
            $components['platform:processing'] = $this->probe->component('failed', 'processing_failed', 'An incoming message could not be processed. An administrator should review it.', 'contact_admin');
        }
        $state = $this->state($components);
        if ($state === 'ready' && $health?->state === 'check_delayed') {
            $state = 'check_delayed';
        }
        $operation = $health?->operation_id ? WhatsappConnectionOperation::where('workspace_id', $waba->workspace_id)->find($health->operation_id) : null;
        if ($operation && ! $operation->finished_at && $operation->created_at->gt(now()->subMinutes(5))) {
            $state = 'checking';
        } elseif (! $health?->checked_at || $health->checked_at->lt(now()->subSeconds(config('channel_health.stale_seconds', 1800)))) {
            $state = in_array($state, ['needs_attention', 'reconnect_required'], true) ? $state : 'check_delayed';
        }
        $action = collect($components)->contains('action', 'reconnect') ? 'reconnect'
            : (collect($components)->contains('action', 'repair') ? 'repair' : (collect($components)->contains('action', 'contact_admin') ? 'contact_admin' : 'check'));

        return [
            'enabled' => true, 'state' => $state, 'action' => $action, 'components' => $components,
            'operation_id' => $operation && ! $operation->finished_at ? $operation->id : null,
            'checked_at' => $health?->checked_at?->toIso8601String(),
            'last_success_at' => $health?->last_success_at?->toIso8601String(),
            'last_webhook_at' => $health?->last_webhook_at?->toIso8601String(),
            'last_message_at' => $health?->last_message_at?->toIso8601String(),
            'delivery_verified' => (bool) ($health?->last_message_at && (! $health->repaired_at || $health->last_message_at->gt($health->repaired_at))),
        ];
    }

    /** @param array<string, array<string, string>> $components */
    private function state(array $components): string
    {
        $values = collect($components);
        if ($values->contains('action', 'reconnect')) {
            return 'reconnect_required';
        }
        if ($values->contains('state', 'failed')) {
            return 'needs_attention';
        }
        if ($values->contains(fn ($c) => in_array($c['state'], ['delayed', 'unknown'], true))) {
            return 'check_delayed';
        }

        return 'ready';
    }

    /** @param array<string, array<string, string>> $components */
    private function incident(WhatsappConnectionHealth $health, array $components, int $failures): void
    {
        $accountFailed = collect($components)->filter(fn ($c, $k) => ! str_starts_with($k, 'platform:') && ($c['state'] === 'failed' || ($c['state'] === 'delayed' && $failures >= 3)));
        $key = $accountFailed->isEmpty() ? null : 'account_incident';
        // Unknown/delayed checks cannot establish recovery.
        if ($key === null && collect($components)->contains(fn ($c) => in_array($c['state'], ['delayed', 'unknown'], true))) {
            return;
        }
        if ($key === $health->incident_key) {
            return;
        }
        $health->update(['incident_key' => $key]);
        $workspace = Workspace::find($health->workspace_id);
        if (! $workspace) {
            return;
        }
        $recipients = $workspace->members()->wherePivotIn('role', ['owner', 'admin'])->get();
        if ($workspace->owner) {
            $recipients->push($workspace->owner);
        }
        if ($workspace->client_id) {
            $recipients = $recipients->merge(User::where('client_id', $workspace->client_id)
                ->where('client_role', User::CLIENT_ROLE_ADMINISTRATOR)->get());
        }
        $recipients->unique('id')->each(fn ($user) => $user->notify(new WhatsappConnectionHealthNotification($key === null)));
    }

    /** Runs in the scheduler too, so a stopped health worker can still alert admins. */
    public function platformIncident(): void
    {
        $runtime = $this->runtime();
        $failed = collect($runtime)->contains(fn ($c) => $c['state'] !== 'passed');
        $probe = Cache::get('wa-health:last-platform', []);
        $probe = is_array($probe) ? $probe : [];
        $failed = $failed || collect($probe)->contains('state', 'failed');
        $unknown = collect($probe)->contains(fn ($c) => in_array($c['state'], ['delayed', 'unknown'], true));
        $processingDelayed = WhatsappConnectionHealth::where(function ($q) {
            $q->where('pending_live_messages', '>', 0)->where('last_live_received_at', '<', now()->subMinutes(5));
        })->exists();
        $failed = $failed || $processingDelayed;
        $count = $failed ? (int) Cache::get('wa-health:platform-failures', 0) + 1 : 0;
        Cache::forever('wa-health:platform-failures', $count);
        $incident = (bool) Cache::get('wa-health:platform-incident', false);
        if (($failed && $count < 3) || $incident === $failed || (! $failed && ($probe === [] || $unknown))) {
            return;
        }
        Cache::forever('wa-health:platform-incident', $failed);
        AdminUser::where('status', AdminUser::STATUS_ACTIVE)->whereHas('roles', fn ($query) => $query->where('key', Role::KEY_SUPER_ADMIN))->get()
            ->each(fn ($admin) => $admin->notify(new WhatsappConnectionHealthNotification(! $failed, true)));
    }

    public function tick(): void
    {
        if (! $this->enabled()) {
            return;
        }
        WhatsappWorkerHeartbeatJob::dispatch();
        CheckWhatsappPlatformJob::dispatch();
        WhatsappBusinessAccount::where('status', 'active')->chunkById(100, function ($wabas) {
            foreach ($wabas as $waba) {
                if (! $this->enabled((int) $waba->workspace_id)) {
                    continue;
                }
                $health = $this->snapshot($waba);
                if (! $health->next_check_at) {
                    $health->update(['next_check_at' => now()->addSeconds(crc32((string) $waba->id) % 900)]);
                } elseif ($health->next_check_at->lte(now())) {
                    $this->enqueue($waba);
                }
            }
        });
        $this->platformIncident();
    }

    /** @param array<int, array<string, mixed>> $entries */
    public function received(array $entries, ?int $onlyWabaId = null): void
    {
        try {
            $this->recordReceipt($entries, $onlyWabaId);
        } catch (\Throwable) {
            // Monitoring must never prevent a verified webhook from being queued.
            Log::warning('whatsapp.health.receipt_recording_failed');
        }
    }

    /** @param array<int, array<string, mixed>> $entries */
    private function recordReceipt(array $entries, ?int $onlyWabaId = null): void
    {
        foreach ($entries as $entry) {
            $waba = WhatsappBusinessAccount::where('status', 'active')->where('waba_id', (string) ($entry['id'] ?? ''))
                ->when($onlyWabaId, fn ($q) => $q->whereKey($onlyWabaId))->first();
            if (! $waba || ! $this->enabled((int) $waba->workspace_id)) {
                continue;
            }
            $count = 0;
            $phones = $waba->phoneNumbers()->pluck('phone_number_id')->map(fn ($id) => (string) $id)->all();
            $shouldCheck = false;
            foreach ($entry['changes'] ?? [] as $change) {
                $field = $change['field'] ?? '';
                if ($field === 'messages' && in_array((string) data_get($change, 'value.metadata.phone_number_id'), $phones, true)) {
                    $count += count($change['value']['messages'] ?? []);
                }
                $shouldCheck = $shouldCheck || in_array($field, ['account_update', 'phone_number_quality_update', 'phone_number_name_update', 'smb_app_state_sync'], true);
            }
            $health = $this->snapshot($waba);
            $updates = ['last_webhook_at' => now()];
            if ($count > 0) {
                $updates['last_live_received_at'] = now();
                $updates['pending_live_messages'] = DB::raw('pending_live_messages + '.(int) $count);
            }
            if ($shouldCheck) {
                $updates['next_check_at'] = now();
            }
            $health->update($updates);
        }
    }

    public function processed(string $wabaId, string $phoneId, bool $success): void
    {
        try {
            $this->recordProcessing($wabaId, $phoneId, $success);
        } catch (\Throwable) {
            Log::warning('whatsapp.health.processing_recording_failed');
        }
    }

    private function recordProcessing(string $wabaId, string $phoneId, bool $success): void
    {
        $waba = WhatsappBusinessAccount::where('status', 'active')->where('waba_id', $wabaId)->whereHas('phoneNumbers', fn ($q) => $q->where('phone_number_id', $phoneId))->first();
        if (! $waba || ! $this->enabled((int) $waba->workspace_id)) {
            return;
        }
        $health = $this->snapshot($waba);
        if (! $health->last_live_received_at || $health->pending_live_messages < 1) {
            return;
        }
        $health->update($success
            ? ['last_message_at' => now(), 'pending_live_messages' => DB::raw('CASE WHEN pending_live_messages > 0 THEN pending_live_messages - 1 ELSE 0 END')]
            : ['last_processing_error_at' => now(), 'next_check_at' => now()]);
    }

    public function authenticationFailed(string $phoneId): void
    {
        try {
            $waba = WhatsappBusinessAccount::whereHas('phoneNumbers', fn ($q) => $q->where('phone_number_id', $phoneId))->first();
            if ($waba && $this->enabled((int) $waba->workspace_id)) {
                $this->snapshot($waba)->update(['next_check_at' => now()]);
            }
        } catch (\Throwable) {
            Log::warning('whatsapp.health.auth_failure_recording_failed');
        }
    }
}
