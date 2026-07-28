<?php

namespace Tests\Feature\Realtime;

use App\Providers\BroadcastChannelsServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Tests\TestCase;

class MobileBroadcastAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_mobile_broadcast_auth_requires_a_sanctum_token(): void
    {
        $this->postJson('/api/v1/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => 'private-workspace.1',
        ])->assertUnauthorized();
    }

    public function test_mobile_token_can_authorize_its_workspace_private_channel(): void
    {
        $ctx = $this->createWorkspaceContext();
        $this->useTestPusherBroadcaster();

        $token = $ctx['user']->createToken('mobile-test')->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/v1/broadcasting/auth', [
                'socket_id' => '1234.5678',
                'channel_name' => "private-workspace.{$ctx['workspace']->id}",
            ])
            ->assertOk()
            ->assertJsonStructure(['auth']);
    }

    public function test_mobile_token_cannot_authorize_an_unrelated_workspace(): void
    {
        $ctx = $this->createWorkspaceContext();
        $other = $this->createWorkspaceContext();
        $this->useTestPusherBroadcaster();

        $token = $ctx['user']->createToken('mobile-test')->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/v1/broadcasting/auth', [
                'socket_id' => '1234.5678',
                'channel_name' => "private-workspace.{$other['workspace']->id}",
            ])
            ->assertForbidden();
    }

    private function useTestPusherBroadcaster(): void
    {
        config([
            'broadcasting.default' => 'pusher',
            'broadcasting.connections.pusher.key' => 'test-key',
            'broadcasting.connections.pusher.secret' => 'test-secret',
            'broadcasting.connections.pusher.app_id' => 'test-app',
            'broadcasting.connections.pusher.options.cluster' => 'mt1',
            'broadcasting.connections.pusher.options.host' => 'api-mt1.pusher.com',
        ]);

        Broadcast::purge('pusher');
        Broadcast::setDefaultDriver('pusher');
        Broadcast::channel(
            'workspace.{workspaceId}',
            fn ($user, int $workspaceId) => BroadcastChannelsServiceProvider::userCanAccessWorkspace(
                $user,
                $workspaceId
            )
        );
    }
}
