<?php

namespace App\Modules\Inbox\Services;

use App\Events\WidgetCommand;
use App\Modules\Shared\Models\Conversation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Lightweight website-visitor presence and agent-to-widget commands.
 *
 * Contact presence is persisted at most once every 15 seconds so an open site
 * can poll frequently without turning every heartbeat into a database write.
 */
class WebchatPresence
{
    public const ONLINE_SECONDS = 90;

    public function touch(Conversation $conversation, ?string $ipAddress = null): void
    {
        $contact = $conversation->contact;
        if (! $contact) {
            return;
        }

        $throttleKey = "webchat:presence-touch:{$contact->id}";
        if (! Cache::add($throttleKey, true, 15)) {
            return;
        }

        $customFields = $contact->custom_fields ?? [];
        if ($ipAddress) {
            $customFields['webchat_last_ip'] = $ipAddress;
        }

        $contact->update([
            'last_seen_at' => now(),
            'custom_fields' => $customFields,
        ]);
    }

    /** @return array{id:string,type:string,created_at:string} */
    public function requestWidgetOpen(Conversation $conversation): array
    {
        $command = [
            'id' => (string) Str::uuid(),
            'type' => 'open_widget',
            'created_at' => now()->toIso8601String(),
        ];

        Cache::put($this->commandKey($conversation), $command, 60);
        $conversation->loadMissing('channelAccount');

        if ($conversation->channelAccount?->channel === 'webchat') {
            broadcast(new WidgetCommand((int) $conversation->id, $command));
        }

        return $command;
    }

    /** @return array{id:string,type:string,created_at:string}|null */
    public function command(Conversation $conversation): ?array
    {
        $command = Cache::get($this->commandKey($conversation));

        return is_array($command) ? $command : null;
    }

    public function onlineSince(): \Illuminate\Support\Carbon
    {
        return now()->subSeconds(self::ONLINE_SECONDS);
    }

    private function commandKey(Conversation $conversation): string
    {
        return "webchat:command:{$conversation->id}";
    }
}
