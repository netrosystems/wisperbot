<?php

namespace Tests\Feature\AI;

use App\Models\User;
use App\Models\Workspace;
use App\Modules\AI\Models\AiChatbot;
use App\Modules\AI\Services\SmartBotRetrievalPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SmartBotManagedPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_bots_store_a_compatibility_snapshot_of_the_managed_policy(): void
    {
        [$user, $workspace] = $this->clientWorkspace();
        $defaults = app(SmartBotRetrievalPolicy::class)->compatibilityDefaults();

        $this->actingAs($user)
            ->post(route('client.ai.chatbots.store'), ['name' => 'Managed Assistant'])
            ->assertRedirect();

        $bot = AiChatbot::where('workspace_id', $workspace->id)->sole();

        $this->assertSame($defaults['max_context_chunks'], $bot->max_context_chunks);
        $this->assertSame($defaults['retrieval_match_threshold'], $bot->retrieval_match_threshold);
        $this->assertSame($defaults['max_context_tokens'], $bot->max_context_tokens);
        $this->assertSame($defaults['video_match_threshold'], $bot->video_match_threshold);
    }

    public function test_client_updates_ignore_raw_retrieval_tuning_fields(): void
    {
        [$user, $workspace] = $this->clientWorkspace();
        $bot = AiChatbot::create([
            'workspace_id' => $workspace->id,
            'name' => 'Original Assistant',
            'max_context_chunks' => 3,
            'retrieval_match_threshold' => 0.60,
            'max_context_tokens' => 1200,
            'video_match_threshold' => 0.72,
        ]);

        $this->actingAs($user)
            ->put(route('client.ai.chatbots.update', $bot), [
                'name' => 'Updated Assistant',
                'max_context_chunks' => 20,
                'retrieval_match_threshold' => 0.01,
                'max_context_tokens' => 4000,
                'video_match_threshold' => 0.01,
            ])
            ->assertRedirect();

        $bot->refresh();

        $this->assertSame('Updated Assistant', $bot->name);
        $this->assertSame(3, $bot->max_context_chunks);
        $this->assertSame(0.60, $bot->retrieval_match_threshold);
        $this->assertSame(1200, $bot->max_context_tokens);
        $this->assertSame(0.72, $bot->video_match_threshold);
    }

    private function clientWorkspace(): array
    {
        $user = User::factory()->create(['role' => 'client', 'email_verified_at' => now()]);
        $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
        $user->update(['workspace_id' => $workspace->id]);

        return [$user, $workspace];
    }
}
