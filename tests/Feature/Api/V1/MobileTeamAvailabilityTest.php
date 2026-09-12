<?php

namespace Tests\Feature\Api\V1;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MobileTeamAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_administrator_can_list_and_update_workspace_member_availability(): void
    {
        ['user' => $admin, 'workspace' => $workspace] = $this->createWorkspaceContext();
        Sanctum::actingAs($admin);
        $schedule = [
            'mon' => ['enabled' => true, 'all_day' => false, 'windows' => [['start' => '22:00', 'end' => '02:00']]],
            'tue' => ['enabled' => false, 'all_day' => false, 'windows' => []],
            'wed' => ['enabled' => false, 'all_day' => false, 'windows' => []],
            'thu' => ['enabled' => false, 'all_day' => false, 'windows' => []],
            'fri' => ['enabled' => false, 'all_day' => false, 'windows' => []],
            'sat' => ['enabled' => false, 'all_day' => false, 'windows' => []],
            'sun' => ['enabled' => false, 'all_day' => false, 'windows' => []],
        ];

        $this->putJson("/api/v1/mobile/team/{$admin->id}/availability", [
            'enabled' => true,
            'timezone' => 'Asia/Dhaka',
            'schedule' => $schedule,
        ])->assertOk()
            ->assertJsonPath('availability.timezone', 'Asia/Dhaka')
            ->assertJsonPath('availability.schedule.mon.windows.0.end', '02:00');

        $this->getJson('/api/v1/mobile/team/availability')
            ->assertOk()
            ->assertJsonFragment(['id' => $admin->id, 'name' => $admin->name]);

        $this->assertDatabaseHas('workspace_member_availabilities', [
            'workspace_id' => $workspace->id,
            'user_id' => $admin->id,
            'timezone' => 'Asia/Dhaka',
        ]);
    }

    public function test_omitted_all_day_values_are_normalized_to_false(): void
    {
        ['user' => $admin] = $this->createWorkspaceContext();
        Sanctum::actingAs($admin);
        $schedule = collect(['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'])
            ->mapWithKeys(fn (string $day) => [$day => [
                'enabled' => true,
                'windows' => [['start' => '10:00', 'end' => '23:00']],
            ]])
            ->all();

        $this->putJson("/api/v1/mobile/team/{$admin->id}/availability", [
            'enabled' => true,
            'timezone' => 'Asia/Dhaka',
            'schedule' => $schedule,
        ])->assertOk()
            ->assertJsonPath('availability.schedule.mon.all_day', false)
            ->assertJsonPath('availability.schedule.sun.all_day', false);
    }
}
