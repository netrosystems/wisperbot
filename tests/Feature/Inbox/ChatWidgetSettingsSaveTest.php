<?php

namespace Tests\Feature\Inbox;

use App\Modules\Inbox\Models\ChatWidget;
use App\Modules\Shared\Models\ChannelAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChatWidgetSettingsSaveTest extends TestCase
{
    use RefreshDatabase;

    public function test_clearing_every_allowed_domain_and_prechat_field_is_saved(): void
    {
        ['workspace' => $workspace, 'user' => $user] = $this->createWorkspaceContext();
        $account = ChannelAccount::create([
            'workspace_id' => $workspace->id,
            'channel' => 'webchat',
            'display_name' => 'Website chat',
            'status' => 'active',
        ]);
        $widget = ChatWidget::create([
            'workspace_id' => $workspace->id,
            'channel_account_id' => $account->id,
            'position' => 'bottom_right',
            'allowed_domains' => ['shop.example'],
            'prechat_fields' => ['name', 'email'],
        ]);

        // Multipart form data omits empty arrays, so the page sends no key at all.
        $this->actingAs($user)->put(route('client.inbox.chat-widgets.update', $widget), [
            'name' => '',
            'title' => 'Chat with us',
            'position' => 'bottom_right',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $widget->refresh();
        $this->assertSame([], $widget->allowed_domains);
        $this->assertSame([], $widget->prechat_fields);
    }
}
