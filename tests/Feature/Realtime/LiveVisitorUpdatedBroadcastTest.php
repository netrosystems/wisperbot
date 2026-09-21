<?php

namespace Tests\Feature\Realtime;

use App\Events\LiveVisitorUpdated;
use App\Modules\Inbox\Services\WebchatPresence;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class LiveVisitorUpdatedBroadcastTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_online_touch_broadcasts_a_workspace_scoped_safe_event(): void
    {
        Event::fake([LiveVisitorUpdated::class]);
        [$conversation] = $this->conversationContext();

        app(WebchatPresence::class)->touch(
            $conversation,
            null,
            'https://example.test/pricing',
            'Pricing',
        );

        Event::assertDispatched(LiveVisitorUpdated::class, function (LiveVisitorUpdated $event) use ($conversation): bool {
            $payload = $event->broadcastWith();
            $channels = array_map(fn ($channel) => $channel->name, $event->broadcastOn());

            return $event->workspaceId === (int) $conversation->workspace_id
                && $payload['conversation_id'] === $conversation->id
                && $payload['conversation_uuid'] === $conversation->uuid
                && $payload['online'] === true
                && ! array_key_exists('contact', $payload)
                && $channels === ["private-workspace.{$conversation->workspace_id}"];
        });
    }

    public function test_unchanged_online_heartbeat_does_not_repeat_the_event_but_page_change_does(): void
    {
        Event::fake([LiveVisitorUpdated::class]);
        [$conversation] = $this->conversationContext([
            'webchat_last_seen_at' => now(),
        ], [
            'webchat_page_url' => 'https://example.test/pricing',
            'webchat_page_title' => 'Pricing',
        ]);
        $presence = app(WebchatPresence::class);

        $presence->touch($conversation, null, 'https://example.test/pricing', 'Pricing');
        Event::assertNotDispatched(LiveVisitorUpdated::class);

        Cache::forget("webchat:presence-touch:{$conversation->id}");
        $presence->touch($conversation->fresh(), null, 'https://example.test/contact', 'Contact');
        Event::assertDispatchedTimes(LiveVisitorUpdated::class, 1);
    }

    /** @return array{Conversation, Contact} */
    private function conversationContext(array $conversationAttributes = [], array $customFields = []): array
    {
        $ctx = $this->createWorkspaceContext();
        $contact = Contact::factory()->create([
            'workspace_id' => $ctx['workspace']->id,
            'custom_fields' => $customFields,
        ]);
        $conversation = Conversation::create(array_merge([
            'workspace_id' => $ctx['workspace']->id,
            'contact_id' => $contact->id,
            'status' => 'open',
        ], $conversationAttributes));
        Cache::forget("webchat:presence-touch:{$conversation->id}");

        return [$conversation, $contact];
    }
}
