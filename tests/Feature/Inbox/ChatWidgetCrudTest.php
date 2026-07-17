<?php

namespace Tests\Feature\Inbox;

use App\Modules\Inbox\Models\ChatWidget;
use App\Modules\Shared\Models\ChannelAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChatWidgetCrudTest extends TestCase
{
    use RefreshDatabase;

    private array $ctx;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ctx = $this->createWorkspaceContext();
    }

    public function test_can_delete_owned_chat_widget(): void
    {
        $workspace = $this->ctx['workspace'];
        $channelAccount = ChannelAccount::create([
            'workspace_id' => $workspace->id,
            'channel' => 'webchat',
            'display_name' => 'Website chat',
            'status' => 'active',
        ]);
        $widget = ChatWidget::create([
            'workspace_id' => $workspace->id,
            'channel_account_id' => $channelAccount->id,
            'name' => 'Website chat',
            'position' => 'bottom_right',
        ]);

        $response = $this->actingAs($this->ctx['user'])
            ->delete(route('client.inbox.chat-widgets.destroy', $widget->id));

        $response->assertRedirect(route('client.inbox.chat-widgets.index'));
        $this->assertDatabaseMissing('chat_widgets', ['id' => $widget->id]);
        $this->assertDatabaseHas('channel_accounts', [
            'id' => $channelAccount->id,
            'status' => 'inactive',
        ]);
    }

    public function test_cannot_delete_another_workspaces_chat_widget(): void
    {
        $other = $this->createWorkspaceContext();
        $channelAccount = ChannelAccount::create([
            'workspace_id' => $other['workspace']->id,
            'channel' => 'webchat',
            'display_name' => 'Other website chat',
            'status' => 'active',
        ]);
        $widget = ChatWidget::create([
            'workspace_id' => $other['workspace']->id,
            'channel_account_id' => $channelAccount->id,
            'name' => 'Other website chat',
            'position' => 'bottom_right',
        ]);

        $response = $this->actingAs($this->ctx['user'])
            ->delete(route('client.inbox.chat-widgets.destroy', $widget->id));

        $response->assertStatus(403);
        $this->assertDatabaseHas('chat_widgets', ['id' => $widget->id]);
        $this->assertDatabaseHas('channel_accounts', [
            'id' => $channelAccount->id,
            'status' => 'active',
        ]);
    }
}
