<?php

namespace Tests\Feature\AI;

use App\Modules\AI\Models\AiChatbot;
use App\Modules\AI\Models\AiKnowledgeBase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Smart Bot setup (2026-10-04): a bot is created with its own knowledge and
 * set up on one page; the old Knowledge Base pages lead there.
 */
class SmartBotSetupTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_new_bot_is_created_with_its_own_knowledge(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();

        $response = $this->actingAs($user)->post(route('client.ai.chatbots.store'), ['name' => 'Support', 'tone' => 'professional', 'system_prompt' => 'Always offer the booking link.']);

        $bot = AiChatbot::sole();
        $response->assertRedirect(route('client.ai.chatbots.show', $bot));
        $kb = AiKnowledgeBase::findOrFail($bot->ai_kb_id);
        $this->assertSame($workspace->id, $kb->workspace_id);
        $this->assertSame('Support', $kb->name);
        $this->assertNotNull($kb->draft_revision_id);
        $this->assertSame(['professional', 'Always offer the booking link.', 'business_only'], [$bot->tone, $bot->system_prompt, $bot->answer_scope]);
        $this->assertNull($bot->answers_configured_at);
    }

    public function test_unused_knowledge_gets_a_bot_and_other_workspaces_knowledge_is_refused(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();
        $kb = AiKnowledgeBase::create(['workspace_id' => $workspace->id, 'name' => 'Old help centre']);
        $foreign = AiKnowledgeBase::create(['workspace_id' => $this->createWorkspaceContext()['workspace']->id, 'name' => 'Theirs']);

        $this->actingAs($user)->post(route('client.ai.chatbots.store'), ['name' => 'Old help centre', 'from_kb' => $foreign->uuid])->assertStatus(422);
        $this->actingAs($user)->post(route('client.ai.chatbots.store'), ['name' => 'Old help centre', 'from_kb' => $kb->uuid])->assertRedirect();

        $this->assertSame($kb->id, AiChatbot::sole()->ai_kb_id);
        $this->assertSame(1, AiKnowledgeBase::where('workspace_id', $workspace->id)->count());
    }

    public function test_the_bot_page_shows_its_knowledge_and_hides_flagged_settings(): void
    {
        ['user' => $user] = $this->createWorkspaceContext();
        config()->set('chatbot.business_aware_routing_enabled', false);
        $this->actingAs($user)->post(route('client.ai.chatbots.store'), ['name' => 'Support']);
        $bot = AiChatbot::sole();

        $this->actingAs($user)->get(route('client.ai.chatbots.show', $bot))
            ->assertInertia(fn ($page) => $page->component('AI/Chatbots/Show')
                ->where('chatbot.uuid', $bot->uuid)
                ->where('kb.id', $bot->ai_kb_id)
                ->where('researchAvailable', false)
                ->has('kb.documents', 0));

        $stranger = $this->createWorkspaceContext()['user'];
        $this->actingAs($stranger)->get(route('client.ai.chatbots.show', $bot))->assertForbidden();
    }

    public function test_saving_how_it_answers_ticks_the_step_and_dropped_fields_are_ignored(): void
    {
        ['user' => $user] = $this->createWorkspaceContext();
        $this->actingAs($user)->post(route('client.ai.chatbots.store'), ['name' => 'Support']);
        $bot = AiChatbot::sole();

        $this->actingAs($user)->put(route('client.ai.chatbots.update', $bot), [
            'name' => 'Support', 'answer_scope' => 'verified_only', 'reply_length' => 'short',
            'answers_configured' => true, 'enabled' => false, 'channels' => ['webchat'],
        ])->assertRedirect();

        $bot->refresh();
        $this->assertNotNull($bot->answers_configured_at);
        $this->assertSame(['verified_only', 'short'], [$bot->answer_scope, $bot->reply_length]);
        $this->assertTrue((bool) $bot->getRawOriginal('enabled'));
    }

    public function test_business_details_save_without_a_name_and_old_pages_redirect(): void
    {
        ['user' => $user] = $this->createWorkspaceContext();
        $this->actingAs($user)->post(route('client.ai.chatbots.store'), ['name' => 'Support']);
        $bot = AiChatbot::sole();
        $kb = AiKnowledgeBase::findOrFail($bot->ai_kb_id);

        $this->actingAs($user)->put(route('client.ai.knowledge-bases.update', $kb), ['brand' => 'Telzen', 'purpose' => 'Travel eSIM data plans'])->assertRedirect();
        $this->assertSame(['Support', 'Telzen'], [$kb->fresh()->name, $kb->fresh()->brand]);

        $this->actingAs($user)->get(route('client.ai.knowledge-bases.index'))->assertRedirect(route('client.ai.chatbots.index'));
        $this->actingAs($user)->get(route('client.ai.knowledge-bases.show', $kb))->assertRedirect(route('client.ai.chatbots.show', $bot));
    }

    public function test_deleting_a_bot_keeps_its_knowledge_and_lists_it_as_unused(): void
    {
        ['user' => $user] = $this->createWorkspaceContext();
        $this->actingAs($user)->post(route('client.ai.chatbots.store'), ['name' => 'Support']);
        $bot = AiChatbot::sole();

        $this->actingAs($user)->delete(route('client.ai.chatbots.destroy', $bot))->assertRedirect(route('client.ai.chatbots.index'));

        $this->assertDatabaseHas('ai_knowledge_bases', ['id' => $bot->ai_kb_id]);
        $this->actingAs($user)->get(route('client.ai.chatbots.index'))
            ->assertInertia(fn ($page) => $page->has('chatbots', 0)->where('unusedKnowledge.0.id', $bot->ai_kb_id));
    }

    public function test_an_older_bot_without_knowledge_can_be_given_its_own(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();
        $bot = AiChatbot::factory()->create(['workspace_id' => $workspace->id, 'ai_kb_id' => null]);

        $this->actingAs($user)->post(route('client.ai.chatbots.knowledge', $bot))->assertRedirect();

        $this->assertSame($workspace->id, AiKnowledgeBase::findOrFail($bot->fresh()->ai_kb_id)->workspace_id);
    }
}
