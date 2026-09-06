<?php

namespace App\Modules\Social\Jobs;

use App\Modules\Social\Models\SocialAccount;
use App\Modules\Social\Services\SocialCommentService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class IngestSocialComments implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public function __construct(public array $entry, public string $object)
    {
        $this->onQueue('social');
    }

    public function backoff(): array
    {
        return [30, 120, 600];
    }

    public function handle(SocialCommentService $service): void
    {
        if (! config('social_comments.enabled')) {
            return;
        }
        $accounts = SocialAccount::where('network', $this->object === 'page' ? 'facebook' : 'instagram')
            ->where('account_id', (string) ($this->entry['id'] ?? ''))->where('active', true)->get();
        // Ambiguous legacy asset ownership fails closed instead of cross-workspace fan-out.
        if ($accounts->count() !== 1) {
            return;
        }
        $account = $accounts->first();
        foreach ($this->entry['changes'] ?? [] as $change) {
            $value = $change['value'] ?? [];
            if (($change['field'] ?? '') === 'feed' && ($value['item'] ?? '') === 'comment') {
                $parent = isset($value['parent_id']) && $value['parent_id'] !== ($value['post_id'] ?? null) ? $value['parent_id'] : null;
                $service->ingest($account, (string) ($value['post_id'] ?? ''), $value + ['updated_time' => $this->entry['time'] ?? null], false, $parent);
            } elseif (($change['field'] ?? '') === 'comments') {
                $service->ingest($account, (string) ($value['media']['id'] ?? ''), $value + ['updated_time' => $this->entry['time'] ?? null]);
            }
        }
    }
}
