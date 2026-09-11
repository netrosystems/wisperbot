<?php

namespace Tests\Feature\Inbox;

use App\Events\MessageReceived;
use App\Listeners\AutoReplyListener;
use App\Modules\AI\Models\AiChatbot;
use App\Modules\AI\Services\ChatbotRunner;
use App\Modules\Inbox\Models\ChatWidget;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery\MockInterface;
use Tests\TestCase;

class ChatWidgetAiScheduleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_outside_hours_schedule_obeys_its_timezone_and_closed_days(): void
    {
        [$widget] = $this->scheduledWidget('outside_hours', 'America/New_York');

        // 14:00 UTC is 10:00 in New York on this Monday: inside office hours.
        $this->assertFalse($widget->shouldAiAnswerNow(CarbonImmutable::parse('2026-09-07 14:00:00 UTC')));
        // 23:00 UTC is 19:00 in New York: outside office hours.
        $this->assertTrue($widget->shouldAiAnswerNow(CarbonImmutable::parse('2026-09-07 23:00:00 UTC')));
        // Sunday is marked closed, so outside-hours AI runs all day.
        $this->assertTrue($widget->shouldAiAnswerNow(CarbonImmutable::parse('2026-09-06 16:00:00 UTC')));
    }

    public function test_inside_hours_mode_runs_only_during_an_enabled_office_day(): void
    {
        [$widget] = $this->scheduledWidget('inside_hours', 'Asia/Dhaka');

        $this->assertTrue($widget->shouldAiAnswerNow(CarbonImmutable::parse('2026-09-07 05:00:00 UTC'))); // 11:00 Monday
        $this->assertFalse($widget->shouldAiAnswerNow(CarbonImmutable::parse('2026-09-07 14:00:00 UTC'))); // 20:00 Monday
        $this->assertFalse($widget->shouldAiAnswerNow(CarbonImmutable::parse('2026-09-06 05:00:00 UTC'))); // closed Sunday
    }

    public function test_disabled_schedule_keeps_enabled_ai_available_all_day(): void
    {
        [$widget] = $this->scheduledWidget('outside_hours', 'UTC');
        $widget->update(['ai_schedule_json' => ['enabled' => false]]);

        $this->assertTrue($widget->fresh()->shouldAiAnswerNow(CarbonImmutable::parse('2026-09-07 12:00:00 UTC')));
    }

    public function test_invalid_enabled_schedule_fails_closed(): void
    {
        [$widget] = $this->scheduledWidget('outside_hours', 'UTC');
        $widget->update(['ai_schedule_json' => [
            'enabled' => true,
            'mode' => 'outside_hours',
            'timezone' => 'Not/A_Timezone',
            'schedule' => [],
        ]]);

        $this->assertFalse($widget->fresh()->shouldAiAnswerNow());
    }

    public function test_widget_update_persists_a_valid_workspace_schedule(): void
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
        ]);

        $this->actingAs($user)->put(route('client.inbox.chat-widgets.update', $widget), [
            'name' => 'Website chat',
            'position' => 'bottom_right',
            'ai_schedule_json' => $this->schedule('outside_hours', 'Asia/Dhaka'),
        ])->assertRedirect();

        $stored = $widget->fresh()->ai_schedule_json;
        $this->assertTrue($stored['enabled']);
        $this->assertSame('scheduled', $stored['mode']);
        $this->assertSame('Asia/Dhaka', $stored['timezone']);
        $this->assertTrue($stored['schedule']['mon']['enabled']);
        $this->assertCount(2, $stored['schedule']['mon']['windows']);
        $this->assertTrue($stored['schedule']['sun']['all_day']);
    }

    public function test_invalid_office_window_is_rejected(): void
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
        ]);
        $schedule = $this->schedule('outside_hours', 'UTC');
        $schedule['schedule']['mon'] = ['enabled' => true, 'start' => '18:00', 'end' => '09:00'];

        $this->actingAs($user)->put(route('client.inbox.chat-widgets.update', $widget), [
            'name' => 'Website chat',
            'position' => 'bottom_right',
            'ai_schedule_json' => $schedule,
        ])->assertSessionHasErrors('ai_schedule_json.schedule');
    }

    public function test_listener_does_not_generate_a_webchat_reply_while_ai_is_resting(): void
    {
        [$widget, $account, $chatbot] = $this->scheduledWidget('inside_hours', 'UTC');
        CarbonImmutable::setTestNow('2026-09-07 20:00:00 UTC');

        $contact = Contact::factory()->create(['workspace_id' => $widget->workspace_id]);
        $conversation = Conversation::create([
            'workspace_id' => $widget->workspace_id,
            'channel_account_id' => $account->id,
            'contact_id' => $contact->id,
            'status' => 'open',
            'assigned_to' => 'bot',
        ]);
        $message = Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'in',
            'channel' => 'webchat',
            'type' => 'text',
            'body' => 'Can you help?',
            'status' => 'delivered',
            'sent_by' => 'human',
            'sent_at' => now(),
        ]);
        $message->setRelation('conversation', $conversation->load('channelAccount'));

        $this->mock(ChatbotRunner::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('run');
        });

        app(AutoReplyListener::class)->handle(new MessageReceived($message));

        $this->assertSame(0, Message::where('conversation_id', $conversation->id)->where('direction', 'out')->count());
        $this->assertSame($chatbot->id, $account->fresh()->meta_json['ai_chatbot_id']);
    }

    /** @return array{ChatWidget,ChannelAccount,AiChatbot} */
    private function scheduledWidget(string $mode, string $timezone): array
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $chatbot = AiChatbot::create([
            'workspace_id' => $workspace->id,
            'name' => 'Scheduled Smart Bot',
            'enabled' => true,
        ]);
        $account = ChannelAccount::create([
            'workspace_id' => $workspace->id,
            'channel' => 'webchat',
            'display_name' => 'Website chat',
            'status' => 'active',
            'meta_json' => ['ai_chatbot_id' => $chatbot->id],
        ]);
        $widget = ChatWidget::create([
            'workspace_id' => $workspace->id,
            'channel_account_id' => $account->id,
            'position' => 'bottom_right',
            'ai_enabled' => true,
            'ai_chatbot_id' => $chatbot->id,
            'ai_schedule_json' => $this->schedule($mode, $timezone),
        ]);

        return [$widget, $account, $chatbot];
    }

    /** @return array<string,mixed> */
    private function schedule(string $mode, string $timezone): array
    {
        $days = [];
        foreach (['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'] as $index => $day) {
            $days[$day] = ['enabled' => $index < 5, 'start' => '09:00', 'end' => '17:00'];
        }

        return [
            'enabled' => true,
            'mode' => $mode,
            'timezone' => $timezone,
            'schedule' => $days,
        ];
    }
}
