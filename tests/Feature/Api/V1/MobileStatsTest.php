<?php

namespace Tests\Feature\Api\V1;

use App\Models\User;
use App\Models\Workspace;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MobileStatsTest extends TestCase
{
    use RefreshDatabase;

    public function test_stats_are_workspace_scoped_unpaginated_and_limited_to_real_omni_threads(): void
    {
        $workspace = Workspace::factory()->create();
        $foreignWorkspace = Workspace::factory()->create();
        $user = User::factory()->create(['workspace_id' => $workspace->id]);
        $agent = User::factory()->create(['workspace_id' => $workspace->id]);

        $webchat = $this->createChannelContext($workspace->id, 'webchat');
        $telegram = $this->createChannelContext($workspace->id, 'telegram');
        $email = $this->createChannelContext($workspace->id, 'email');
        $foreign = $this->createChannelContext($foreignWorkspace->id, 'webchat');

        foreach (range(1, 31) as $index) {
            $this->createConversation($webchat, unread: $index === 31 ? 2 : 0);
        }

        $this->createConversation($webchat, unread: 3, assignedUserId: $agent->id);
        $this->createConversation($telegram, status: 'resolved');
        $this->createConversation($email, unread: 10);
        $this->createConversation($foreign, unread: 10);

        Conversation::create([
            'workspace_id' => $workspace->id,
            'channel_account_id' => $webchat['account']->id,
            'contact_id' => $webchat['contact']->id,
            'status' => 'open',
            'unread_count' => 10,
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/mobile/stats')
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'total_conversations' => 33,
                    'open' => 32,
                    'unread' => 5,
                    'unread_conversations' => 2,
                    'assigned' => 1,
                    'resolved' => 1,
                    'channels' => [
                        'telegram' => 1,
                        'webchat' => 32,
                    ],
                ],
            ]);
    }

    /** @param array{account: ChannelAccount, contact: Contact} $context */
    private function createConversation(
        array $context,
        string $status = 'open',
        int $unread = 0,
        ?int $assignedUserId = null,
    ): void {
        $conversation = Conversation::create([
            'workspace_id' => $context['account']->workspace_id,
            'channel_account_id' => $context['account']->id,
            'contact_id' => $context['contact']->id,
            'assigned_user_id' => $assignedUserId,
            'status' => $status,
            'unread_count' => $unread,
        ]);

        Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'in',
            'channel' => $context['account']->channel,
            'type' => 'text',
            'body' => 'Stats fixture',
            'status' => 'delivered',
            'sent_at' => now(),
        ]);
    }

    /** @return array{account: ChannelAccount, contact: Contact} */
    private function createChannelContext(int $workspaceId, string $channel): array
    {
        return [
            'account' => ChannelAccount::create([
                'workspace_id' => $workspaceId,
                'channel' => $channel,
                'provider' => $channel,
                'display_name' => ucfirst($channel),
                'status' => 'active',
            ]),
            'contact' => Contact::create([
                'workspace_id' => $workspaceId,
                'source' => $channel,
                'first_name' => ucfirst($channel),
            ]),
        ];
    }
}
