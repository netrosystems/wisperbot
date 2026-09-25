<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Models\Workspace;
use App\Modules\AI\Services\EmbeddingStore;
use App\Modules\Inbox\Models\ChatWidget;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Social\Jobs\DispatchScheduledPostsJob;
use App\Modules\Social\Jobs\PublishSocialPostJob;
use App\Modules\Social\Models\SocialPost;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class WorkspaceDeletionTest extends TestCase
{
    use RefreshDatabase;

    // ── Delete ─────────────────────────────────────────────────────────────

    public function test_the_owner_deletes_a_workspace_by_typing_its_name(): void
    {
        [$owner, $home, $branch] = $this->ownerWithTwoWorkspaces();

        $this->actingAs($owner)
            ->delete(route('client.workspaces.destroy', $branch), ['confirm_name' => 'Branch'])
            ->assertSessionHasErrors('confirm_name');
        $this->assertNotSoftDeleted($branch);

        $this->actingAs($owner)
            ->delete(route('client.workspaces.destroy', $branch), ['confirm_name' => 'Downtown Branch'])
            ->assertRedirect(route('client.workspaces.index'))
            ->assertSessionHas('success');

        $this->assertSoftDeleted($branch);
        $this->assertSame($owner->id, (int) $branch->fresh()->deleted_by_user_id);
        $this->assertTrue(AuditLog::where('action', 'workspace.deleted')->where('auditable_id', $branch->id)->exists());

        $this->actingAs($owner)->get(route('client.workspaces.index'))
            ->assertInertia(fn ($page) => $page
                ->where('workspaces', fn ($list) => collect($list)->pluck('id')->all() === [$home->id])
                ->where('deletedWorkspaces.0.id', $branch->id));
    }

    public function test_only_the_owner_can_delete_or_restore(): void
    {
        [$owner, , $branch] = $this->ownerWithTwoWorkspaces();
        $member = User::factory()->create(['role' => User::ROLE_CLIENT, 'client_id' => $owner->client_id, 'email_verified_at' => now()]);
        $branch->members()->syncWithoutDetaching([$member->id => ['role' => 'member']]);

        $this->actingAs($member)
            ->delete(route('client.workspaces.destroy', $branch), ['confirm_name' => 'Downtown Branch'])
            ->assertForbidden();
        $this->assertNotSoftDeleted($branch);

        $branch->delete();
        $this->actingAs($member)->post(route('client.workspaces.restore', $branch->id))->assertForbidden();
        $this->assertSoftDeleted($branch);
    }

    public function test_members_whose_main_workspace_is_deleted_move_to_another_one(): void
    {
        [$owner, $home, $branch] = $this->ownerWithTwoWorkspaces();
        $member = User::factory()->create([
            'role' => User::ROLE_CLIENT, 'client_id' => $owner->client_id,
            'workspace_id' => $branch->id, 'email_verified_at' => now(),
        ]);
        $branch->members()->syncWithoutDetaching([$member->id => ['role' => 'member']]);
        $home->members()->syncWithoutDetaching([$member->id => ['role' => 'member']]);
        $owner->forceFill(['workspace_id' => $branch->id])->save();

        $this->actingAs($owner)
            ->delete(route('client.workspaces.destroy', $branch), ['confirm_name' => 'Downtown Branch']);

        $this->assertSame($home->id, (int) $member->fresh()->workspace_id);
        $this->assertSame($home->id, (int) $owner->fresh()->workspace_id);
    }

    public function test_someone_left_without_a_workspace_is_sent_to_restore_or_create_one(): void
    {
        ['user' => $owner, 'workspace' => $only] = $this->createWorkspaceContext();
        $only->update(['owner_id' => $owner->id, 'name' => 'Solo']);

        $this->actingAs($owner)
            ->delete(route('client.workspaces.destroy', $only), ['confirm_name' => 'Solo'])
            ->assertRedirect(route('client.workspaces.index'));
        $this->assertNull($owner->fresh()->workspace_id);

        $this->actingAs($owner->fresh())->get(route('client.dashboard'))
            ->assertRedirect(route('client.workspaces.index'));
        $this->actingAs($owner->fresh())->get(route('client.workspaces.index'))->assertOk();

        $this->actingAs($owner->fresh())->post(route('client.workspaces.restore', $only->id))
            ->assertRedirect(route('client.workspaces.index'));
        $this->assertNotSoftDeleted($only);
        $this->assertSame($only->id, (int) $owner->fresh()->workspace_id);
        $this->actingAs($owner->fresh())->get(route('client.dashboard'))->assertOk();
    }

    // ── Everything stops, and resumes on restore ───────────────────────────

    public function test_a_deleted_workspace_stops_until_it_is_restored(): void
    {
        [$owner, $home, $branch] = $this->ownerWithTwoWorkspaces();
        $channel = ChannelAccount::create(['workspace_id' => $branch->id, 'channel' => 'telegram', 'display_name' => 'Branch bot', 'status' => 'active']);
        $widget = ChatWidget::create(['workspace_id' => $branch->id, 'widget_key' => 'wk_'.Str::random(20), 'enabled' => true]);
        $keptChannel = ChannelAccount::create(['workspace_id' => $home->id, 'channel' => 'telegram', 'display_name' => 'Home bot', 'status' => 'active']);
        $post = SocialPost::create([
            'workspace_id' => $branch->id, 'body' => 'Weekend offer', 'media_urls' => [], 'target_accounts' => [],
            'status' => 'scheduled', 'scheduled_at' => now()->subMinute(),
        ]);

        $this->actingAs($owner)
            ->delete(route('client.workspaces.destroy', $branch), ['confirm_name' => 'Downtown Branch']);

        // Webhooks, widgets and schedulers look these up and find nothing.
        $this->assertNull(ChannelAccount::find($channel->id));
        $this->assertNull(ChatWidget::where('widget_key', $widget->widget_key)->first());
        $this->assertNotNull(ChannelAccount::find($keptChannel->id));
        Bus::fake([PublishSocialPostJob::class]);
        (new DispatchScheduledPostsJob)->handle();
        Bus::assertNotDispatched(PublishSocialPostJob::class);
        $this->assertSame('scheduled', DB::table('social_media_posts')->where('id', $post->id)->value('status'));

        $this->actingAs($owner)->post(route('client.workspaces.restore', $branch->id))
            ->assertSessionHas('success', 'Workspace restored.');

        $this->assertNotNull(ChannelAccount::find($channel->id));
        $this->assertNotNull(ChatWidget::where('widget_key', $widget->widget_key)->first());
        (new DispatchScheduledPostsJob)->handle();
        Bus::assertDispatched(PublishSocialPostJob::class);
        $this->assertTrue(AuditLog::where('action', 'workspace.restored')->exists());
    }

    public function test_a_workspace_cannot_be_restored_after_30_days(): void
    {
        [$owner, , $branch] = $this->ownerWithTwoWorkspaces();
        $branch->delete();
        $branch->forceFill(['deleted_at' => now()->subDays(31)])->save();

        $this->actingAs($owner)->post(route('client.workspaces.restore', $branch->id))->assertStatus(410);
        $this->assertSoftDeleted($branch);
        $this->actingAs($owner)->get(route('client.workspaces.index'))
            ->assertInertia(fn ($page) => $page->where('deletedWorkspaces', []));
    }

    // ── Permanent erase ────────────────────────────────────────────────────

    public function test_after_30_days_the_workspace_is_erased_and_other_workspaces_are_untouched(): void
    {
        [$owner, $home, $branch] = $this->ownerWithTwoWorkspaces();
        $gone = $this->fillWorkspace($branch);
        $kept = $this->fillWorkspace($home);
        DB::table('usage_meters')->insert(['workspace_id' => $branch->id, 'metric' => 'social_posts', 'period' => now()->format('Y-m'), 'created_at' => now(), 'updated_at' => now()]);

        $embeddings = $this->mock(EmbeddingStore::class);
        $embeddings->shouldReceive('deleteDocumentEmbeddings')->once()->with($gone['document']);

        $this->actingAs($owner)->delete(route('client.workspaces.destroy', $branch), ['confirm_name' => 'Downtown Branch']);

        // Not yet due.
        $this->artisan('workspaces:purge-deleted')->assertSuccessful();
        $this->assertSoftDeleted($branch);

        $branch->forceFill(['deleted_at' => now()->subDays(31)])->save();
        $this->artisan('workspaces:purge-deleted')->assertSuccessful();

        $this->assertDatabaseMissing('workspaces', ['id' => $branch->id]);
        foreach (['contacts' => 'contact', 'conversations' => 'conversation', 'messages' => 'message', 'channel_accounts' => 'channel', 'ai_knowledge_bases' => 'kb', 'ai_kb_documents' => 'document', 'ai_kb_chunks' => 'chunk'] as $table => $key) {
            $this->assertDatabaseMissing($table, ['id' => $gone[$key]]);
            $this->assertDatabaseHas($table, ['id' => $kept[$key]]);
        }
        $this->assertDatabaseHas('users', ['id' => $owner->id]);
        $this->assertDatabaseHas('usage_meters', ['workspace_id' => $branch->id]);
        $this->assertTrue(AuditLog::where('action', 'workspace.deleted')->exists());
    }

    public function test_a_vector_store_failure_leaves_the_workspace_for_the_next_run(): void
    {
        [$owner, , $branch] = $this->ownerWithTwoWorkspaces();
        $gone = $this->fillWorkspace($branch);
        $this->mock(EmbeddingStore::class)->shouldReceive('deleteDocumentEmbeddings')->andThrow(new \RuntimeException('Qdrant unavailable'));

        $branch->delete();
        $branch->forceFill(['deleted_at' => now()->subDays(31)])->save();

        $this->artisan('workspaces:purge-deleted')->assertFailed();

        $this->assertSoftDeleted($branch);
        $this->assertDatabaseHas('contacts', ['id' => $gone['contact']]);
        $this->assertDatabaseHas('ai_kb_documents', ['id' => $gone['document']]);
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    /** @return array{User, Workspace, Workspace} */
    private function ownerWithTwoWorkspaces(): array
    {
        ['user' => $owner, 'workspace' => $home] = $this->createWorkspaceContext();
        $home->update(['owner_id' => $owner->id, 'name' => 'Head Office']);
        $branch = Workspace::create(['name' => 'Downtown Branch', 'owner_id' => $owner->id, 'client_id' => $owner->client_id]);
        $branch->members()->attach($owner->id, ['role' => 'owner']);

        return [$owner->fresh(), $home->fresh(), $branch->fresh()];
    }

    /** @return array<string, int> */
    private function fillWorkspace(Workspace $workspace): array
    {
        $contact = Contact::factory()->create(['workspace_id' => $workspace->id]);
        $channel = DB::table('channel_accounts')->insertGetId(['workspace_id' => $workspace->id, 'channel' => 'telegram', 'display_name' => 'Bot', 'created_at' => now(), 'updated_at' => now()]);
        $conversation = DB::table('conversations')->insertGetId(['uuid' => (string) Str::uuid(), 'workspace_id' => $workspace->id, 'contact_id' => $contact->id, 'created_at' => now(), 'updated_at' => now()]);
        $message = DB::table('messages')->insertGetId(['conversation_id' => $conversation, 'direction' => 'in', 'channel' => 'telegram', 'created_at' => now(), 'updated_at' => now()]);
        $kb = DB::table('ai_knowledge_bases')->insertGetId(['uuid' => (string) Str::uuid(), 'workspace_id' => $workspace->id, 'name' => 'FAQ', 'created_at' => now(), 'updated_at' => now()]);
        $document = DB::table('ai_kb_documents')->insertGetId(['uuid' => (string) Str::uuid(), 'kb_id' => $kb, 'source_type' => 'text', 'created_at' => now(), 'updated_at' => now()]);
        $chunk = DB::table('ai_kb_chunks')->insertGetId(['document_id' => $document, 'kb_id' => $kb, 'content' => 'Opening hours', 'created_at' => now(), 'updated_at' => now()]);

        return ['contact' => $contact->id] + compact('channel', 'conversation', 'message', 'kb', 'document', 'chunk');
    }
}
