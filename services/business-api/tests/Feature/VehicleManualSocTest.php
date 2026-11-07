<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class VehicleManualSocTest extends TestCase
{
    use RefreshDatabase;

    private function vehicle(User $customer): Vehicle
    {
        return Vehicle::create(['user_id' => $customer->id, 'name' => 'My EV',
            'connector_type' => 'CCS2']);
    }

    private function payload(int $percent = 65, int $expected = 0, ?string $key = null): array
    {
        return ['operation_key' => $key ?? (string) Str::uuid(),
            'expected_revision' => $expected, 'battery_percent' => $percent];
    }

    public function test_owner_can_record_a_manual_report_without_claiming_any_device_measurement(): void
    {
        $driver = User::factory()->create(['role' => 'customer']);
        $vehicle = $this->vehicle($driver);
        $path = '/api/vehicles/'.$vehicle->id.'/manual-soc';
        $this->getJson($path)->assertUnauthorized();
        $this->actingAs($driver)->getJson($path)->assertOk()->assertJsonPath('revision', 0)
            ->assertJsonPath('battery_percent', null)
            ->assertJsonPath('source', 'CUSTOMER_MANUAL_SELF_REPORT')
            ->assertJsonPath('measured_by_phone', false);

        $this->actingAs($driver)->postJson($path, $this->payload())
            ->assertCreated()->assertJsonPath('revision', 1)
            ->assertJsonPath('battery_percent', 65)
            ->assertJsonPath('measured_by_vehicle_api', false)
            ->assertJsonPath('charging_capability_verified', false);
        $this->actingAs($driver)->getJson($path)->assertOk()
            ->assertJsonPath('battery_percent', 65);
        $this->actingAs($driver)->getJson($path.'/history')->assertOk()
            ->assertJsonPath('data.0.battery_percent', 65);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'vehicle.manual_soc.reported')->count());
    }

    public function test_idempotent_retry_is_not_a_second_observation_and_conflicting_replay_fails(): void
    {
        $driver = User::factory()->create(['role' => 'customer']);
        $vehicle = $this->vehicle($driver);
        $route = '/api/vehicles/'.$vehicle->id.'/manual-soc';
        $request = $this->payload(55);
        $this->actingAs($driver)->postJson($route, $request)->assertCreated();
        $this->actingAs($driver)->postJson($route, $request)->assertOk()
            ->assertJsonPath('replayed', true)->assertJsonPath('revision', 1);
        $this->actingAs($driver)->postJson($route, array_replace($request, ['battery_percent' => 44]))
            ->assertConflict();
        $this->actingAs($driver)->postJson($route, $this->payload(50, 0))
            ->assertConflict();
        $this->assertSame(1, DB::table('vehicle_manual_soc_reports')->count());
        $this->assertSame(1, DB::table('audit_events')->where('action', 'vehicle.manual_soc.reported')->count());
    }

    public function test_multiple_user_observations_have_server_timestamps_and_private_history(): void
    {
        $driver = User::factory()->create(['role' => 'customer']);
        $other = User::factory()->create(['role' => 'customer']);
        $vendor = User::factory()->create(['role' => 'vendor']);
        $vehicle = $this->vehicle($driver);
        $route = '/api/vehicles/'.$vehicle->id.'/manual-soc';
        $this->actingAs($other)->getJson($route)->assertNotFound();
        $this->actingAs($other)->getJson($route.'/history')->assertNotFound();
        $this->actingAs($vendor)->postJson($route, $this->payload())->assertNotFound();

        $this->actingAs($driver)->postJson($route, $this->payload(100))->assertCreated();
        $this->actingAs($driver)->postJson($route, $this->payload(25, 1))->assertCreated()
            ->assertJsonPath('revision', 2)->assertJsonPath('battery_percent', 25);
        $history = $this->actingAs($driver)->getJson($route.'/history')->assertOk()
            ->assertJsonPath('data.0.battery_percent', 25)
            ->assertJsonPath('data.1.battery_percent', 100);
        $this->assertNotNull($history->json('data.0.reported_at'));
        $this->assertStringEndsWith('+00:00', $history->json('data.0.reported_at'));
        $this->assertStringEndsWith('+00:00', $history->json('data.1.reported_at'));
        $this->assertStringEndsWith('+00:00', $this->actingAs($driver)->getJson($route)->json('reported_at'));
        $this->assertSame(2, DB::table('vehicle_manual_soc_reports')->count());
        $this->assertSame('CCS2', $vehicle->fresh()->connector_type);
    }

    public function test_invalid_percent_revision_or_key_does_not_write(): void
    {
        $driver = User::factory()->create(['role' => 'customer']);
        $vehicle = $this->vehicle($driver);
        $route = '/api/vehicles/'.$vehicle->id.'/manual-soc';
        foreach ([
            ['battery_percent' => -1], ['battery_percent' => 101],
            ['expected_revision' => -1], ['operation_key' => 'invalid'],
            ['battery_percent' => 'abc'],
        ] as $change) {
            $this->actingAs($driver)->postJson($route,
                array_replace($this->payload(), $change))->assertUnprocessable();
        }
        $this->assertSame(0, DB::table('vehicle_manual_soc_reports')->count());
    }

    public function test_sanctum_uses_same_customer_only_contract(): void
    {
        $driver = User::factory()->create(['role' => 'customer']);
        $other = User::factory()->create(['role' => 'customer']);
        $vehicle = $this->vehicle($driver);
        $path = '/api/v1/vehicles/'.$vehicle->id.'/manual-soc';
        $this->actingAs($other, 'sanctum')->getJson($path)->assertNotFound();
        $this->actingAs($driver, 'sanctum')->postJson($path, $this->payload(0))
            ->assertCreated()->assertJsonPath('battery_percent', 0);
        $this->actingAs($driver, 'sanctum')->getJson($path.'/history')
            ->assertOk()->assertJsonCount(1, 'data');
    }
}
