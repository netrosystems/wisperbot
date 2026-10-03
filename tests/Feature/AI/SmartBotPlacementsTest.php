<?php

namespace Tests\Feature\AI;

use App\Modules\AI\Models\AiChatbot;
use App\Modules\Inbox\Models\ChatWidget;
use App\Modules\Inbox\Models\WorkspaceAiAnsweringPolicy;
use App\Modules\Shared\Models\ChannelAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Where it answers" on the bot page (2026-10-04): the same settings Widget
 * Setup and Channel Setup save, switched from the bot.
 */
class SmartBotPlacementsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_bot_turns_the_website_chat_on_and_off_like_widget_setup(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();
        [$widget, $account] = $this->widget($workspace->id);
        $bot = AiChatbot::factory()->create(['workspace_id' => $workspace->id]);

        $this->actingAs($user)->put(route('client.ai.chatbots.placements.widget', [$bot, $widget]), ['on' => true])->assertRedirect();

        $this->assertTrue($widget->fresh()->ai_enabled);
        $this->assertSame($bot->id, $widget->fresh()->ai_chatbot_id);
        $this->assertSame(['ai_chatbot_id' => $bot->id], $account->fresh()->meta_json);

        $this->actingAs($user)->put(route('client.ai.chatbots.placements.widget', [$bot, $widget]), ['on' => false])->assertRedirect();
        $this->assertFalse($widget->fresh()->ai_enabled);
        $this->assertSame([], $account->fresh()->meta_json);
    }

    public function test_turning_off_leaves_another_bots_widget_alone_and_strangers_are_refused(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();
        [$widget, $account] = $this->widget($workspace->id);
        $mine = AiChatbot::factory()->create(['workspace_id' => $workspace->id]);
        $other = AiChatbot::factory()->create(['workspace_id' => $workspace->id]);
        $widget->update(['ai_enabled' => true, 'ai_chatbot_id' => $other->id]);
        $account->update(['meta_json' => ['ai_chatbot_id' => $other->id]]);

        $this->actingAs($user)->put(route('client.ai.chatbots.placements.widget', [$mine, $widget]), ['on' => false])->assertRedirect();
        $this->assertSame($other->id, $widget->fresh()->ai_chatbot_id);
        $this->assertTrue($widget->fresh()->ai_enabled);

        ['user' => $stranger, 'workspace' => $theirs] = $this->createWorkspaceContext();
        $foreignBot = AiChatbot::factory()->create(['workspace_id' => $theirs->id]);
        $this->actingAs($stranger)->put(route('client.ai.chatbots.placements.widget', [$foreignBot, $widget]), ['on' => true])->assertForbidden();
        $this->actingAs($user)->put(route('client.ai.chatbots.placements.widget', [$foreignBot, $widget]), ['on' => true])->assertForbidden();
    }

    public function test_the_bot_page_and_list_show_where_each_bot_answers(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();
        [$widget] = $this->widget($workspace->id);
        $bot = AiChatbot::factory()->create(['workspace_id' => $workspace->id, 'name' => 'Sales']);
        $other = AiChatbot::factory()->create(['workspace_id' => $workspace->id, 'name' => 'Support']);
        $widget->update(['ai_enabled' => true, 'ai_chatbot_id' => $bot->id]);
        WorkspaceAiAnsweringPolicy::create(['workspace_id' => $workspace->id, 'segment' => 'omni', 'mode' => 'always_on', 'chatbot_id' => $other->id, 'enabled_at' => now()]);
        ChannelAccount::create(['workspace_id' => $workspace->id, 'channel' => 'whatsapp', 'display_name' => 'WA', 'status' => 'active']);

        $this->actingAs($user)->get(route('client.ai.chatbots.show', $bot))
            ->assertInertia(fn ($page) => $page
                ->where('placements.widget.on', true)
                ->where('placements.segments.omni.on', false)
                ->where('placements.segments.omni.other_bot', 'Support')
                ->where('placements.segments.omni.connected', ['whatsapp'])
                ->where('placements.segments.email.on', false)
                ->where('canManagePlacements', true));

        $this->actingAs($user)->get(route('client.ai.chatbots.index'))
            ->assertInertia(fn ($page) => $page
                ->where('chatbots', fn ($bots) => collect($bots)->firstWhere('id', $bot->id)['places'] === ['widget']
                    && collect($bots)->firstWhere('id', $other->id)['places'] === ['omni']));
    }

    /** @return array{0: ChatWidget, 1: ChannelAccount} */
    private function widget(int $workspaceId): array
    {
        $account = ChannelAccount::create(['workspace_id' => $workspaceId, 'channel' => 'webchat', 'display_name' => 'Website chat', 'status' => 'active']);

        return [ChatWidget::create(['workspace_id' => $workspaceId, 'channel_account_id' => $account->id, 'position' => 'bottom_right']), $account];
    }
}
