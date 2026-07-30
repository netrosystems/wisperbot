<?php

namespace Tests\Feature\ProductionHardening;

use App\Modules\Broadcasting\Models\SmsProviderConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SmsProviderConfigTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_supported_sms_gateways_are_available_to_clients(): void
    {
        ['user' => $user] = $this->createWorkspaceContext();

        $this->actingAs($user)
            ->get(route('client.sms-gateways.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Broadcasting/SmsProviders/Index')
                ->has('providers', 7)
                ->where('providers.0.provider', 'twilio')
                ->where('providers.1.provider', 'messagebird')
                ->where('providers.2.provider', 'smsbd')
                ->where('providers.3.provider', 'reve')
                ->where('providers.4.provider', 'alaris')
                ->where('providers.4.label', 'ProSMS')
                ->where('providers.5.provider', 'bulksmsbd')
                ->where('providers.6.provider', 'amazon_sns'));
    }

    public function test_a_hidden_sms_gateway_cannot_be_configured(): void
    {
        ['user' => $user] = $this->createWorkspaceContext();

        $this->actingAs($user)
            ->put(route('client.sms-gateways.update', 'nexmo'), [
                'credentials' => [
                    'api_key' => 'key',
                    'api_secret' => 'secret',
                ],
            ])
            ->assertNotFound();
    }

    public function test_partial_sms_credentials_are_rejected_before_persistence(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();

        $this->actingAs($user)
            ->put(route('client.sms-gateways.update', 'reve'), [
                'default' => true,
                'credentials' => ['api_key' => 'reve-key'],
            ])
            ->assertSessionHasErrors('credentials.api_secret');

        $this->assertDatabaseMissing('sms_provider_configs', [
            'workspace_id' => $workspace->id,
            'provider' => 'reve',
        ]);
    }

    public function test_masked_values_can_update_an_existing_complete_provider(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();

        SmsProviderConfig::create([
            'workspace_id' => $workspace->id,
            'provider' => 'reve',
            'credentials' => ['api_key' => 'reve-key', 'api_secret' => 'secret'],
            'default' => true,
        ]);

        $this->actingAs($user)
            ->put(route('client.sms-gateways.update', 'reve'), [
                'default' => true,
                'credentials' => [
                    'api_key' => '••••••••••••',
                    'api_secret' => '••••••••••••',
                ],
            ])
            ->assertSessionHasNoErrors();

        $config = SmsProviderConfig::where('workspace_id', $workspace->id)
            ->where('provider', 'reve')
            ->firstOrFail();

        $this->assertSame('reve-key', $config->credentials['api_key']);
        $this->assertSame('secret', $config->credentials['api_secret']);
    }

    public function test_an_enabled_sms_gateway_can_be_configured(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();

        $this->actingAs($user)
            ->put(route('client.sms-gateways.update', 'smsbd'), [
                'default' => true,
                'credentials' => [
                    'api_key' => 'smsbd-key',
                    'sender' => 'WISPERBOT',
                ],
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('sms_provider_configs', [
            'workspace_id' => $workspace->id,
            'provider' => 'smsbd',
            'default' => true,
        ]);
    }
}
