<?php

namespace Tests\Feature\Polish;

use App\Models\Invitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TeamInviteUiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_see_team_page_with_invitations(): void
    {
        $ctx = $this->createWorkspaceContext([], ['client_role' => 'administrator', 'email_verified_at' => now()]);
        $user = $ctx['user'];

        Invitation::create([
            'client_id' => $user->client_id,
            'email' => 'invited@example.com',
            'client_role' => 'staff',
            'token' => 'abc123',
            'invited_by' => $user->id,
            'expires_at' => now()->addDays(7),
        ]);

        $response = $this->actingAs($user)->get(route('client.team.index'));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('client/Team/Index')
            ->has('invitations', 1)
        );
    }

    public function test_admin_can_send_invitation(): void
    {
        $ctx = $this->createWorkspaceContext([], ['client_role' => 'administrator', 'email_verified_at' => now()]);
        $user = $ctx['user'];

        $response = $this->actingAs($user)->post(route('client.invitations.store'), [
            'email' => 'newuser@example.com',
            'client_role' => 'staff',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('invitations', ['email' => 'newuser@example.com']);
    }

    public function test_admin_can_revoke_invitation(): void
    {
        $ctx = $this->createWorkspaceContext([], ['client_role' => 'administrator', 'email_verified_at' => now()]);
        $user = $ctx['user'];

        $invitation = Invitation::create([
            'client_id' => $user->client_id,
            'email' => 'rev@example.com',
            'client_role' => 'staff',
            'token' => 'tokxyz',
            'invited_by' => $user->id,
            'expires_at' => now()->addDays(7),
        ]);

        $this->actingAs($user)
            ->delete(route('client.invitations.destroy', $invitation))
            ->assertRedirect();

        $this->assertDatabaseMissing('invitations', ['id' => $invitation->id]);
    }

    public function test_staff_cannot_send_invitation(): void
    {
        $ctx = $this->createWorkspaceContext([], ['client_role' => 'staff', 'email_verified_at' => now()]);
        $user = $ctx['user'];

        $this->actingAs($user)
            ->post(route('client.invitations.store'), ['email' => 'x@x.com', 'client_role' => 'staff'])
            ->assertStatus(403);
    }

    public function test_admin_can_create_a_team_member_with_a_profile_photo(): void
    {
        Storage::fake('public');
        $ctx = $this->createWorkspaceContext([], ['client_role' => 'administrator', 'email_verified_at' => now()]);

        $this->actingAs($ctx['user'])->post(route('client.team.store'), [
            'name' => 'Photo Agent',
            'email' => 'photo-agent@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'client_role' => 'staff',
            'status' => 'active',
            'avatar' => UploadedFile::fake()->image('agent.jpg', 160, 160),
        ])->assertRedirect(route('client.team.index'));

        $member = User::where('email', 'photo-agent@example.com')->sole();
        $this->assertNotNull($member->avatar);
        Storage::disk('public')->assertExists($member->avatar);

        $this->actingAs($ctx['user'])->get(route('client.team.index'))
            ->assertInertia(fn ($page) => $page
                ->where('users', fn ($users) => collect($users)->contains(fn ($user) => $user['id'] === $member->id && filled($user['avatar_url'])))
            );
    }

    public function test_admin_can_replace_and_remove_a_team_member_photo(): void
    {
        Storage::fake('public');
        $ctx = $this->createWorkspaceContext([], ['client_role' => 'administrator', 'email_verified_at' => now()]);
        $member = User::factory()->create([
            'client_id' => $ctx['client']->id,
            'workspace_id' => $ctx['workspace']->id,
            'role' => User::ROLE_CLIENT,
            'client_role' => User::CLIENT_ROLE_STAFF,
            'status' => User::STATUS_ACTIVE,
            'avatar' => 'avatars/clients/'.$ctx['client']->id.'/old.jpg',
        ]);
        Storage::disk('public')->put($member->avatar, 'old image');

        $payload = [
            '_method' => 'put',
            'name' => $member->name,
            'email' => $member->email,
            'client_role' => 'staff',
            'status' => 'active',
            'avatar' => UploadedFile::fake()->image('replacement.png', 160, 160),
        ];
        $this->actingAs($ctx['user'])->post(route('client.team.update', $member), $payload)->assertRedirect();

        $member->refresh();
        $replacement = $member->avatar;
        $this->assertNotSame('avatars/clients/'.$ctx['client']->id.'/old.jpg', $replacement);
        Storage::disk('public')->assertMissing('avatars/clients/'.$ctx['client']->id.'/old.jpg');
        Storage::disk('public')->assertExists($replacement);

        unset($payload['avatar']);
        $payload['remove_avatar'] = true;
        $this->actingAs($ctx['user'])->post(route('client.team.update', $member), $payload)->assertRedirect();

        $this->assertNull($member->fresh()->avatar);
        Storage::disk('public')->assertMissing($replacement);
    }
}
