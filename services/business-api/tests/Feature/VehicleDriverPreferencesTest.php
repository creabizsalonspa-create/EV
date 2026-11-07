<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class VehicleDriverPreferencesTest extends TestCase
{
    use RefreshDatabase;

    private function vehicle(User $user): Vehicle
    {
        return Vehicle::create(['user_id' => $user->id, 'name' => 'Family EV', 'connector_type' => 'CCS2']);
    }

    private function body(int $revision = 0): array
    {
        return [
            'revision' => $revision,
            'preferred_power_kw' => 150,
            'charging_goal' => 'balanced',
            'accessible_site_required' => true,
            'avoid_paid_parking' => false,
        ];
    }

    public function test_customer_owns_independent_vehicle_preferences_and_can_update_them(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);
        $vehicle = $this->vehicle($customer);
        $path = '/api/vehicles/'.$vehicle->id.'/driver-preferences';

        $this->getJson($path)->assertUnauthorized();
        $this->actingAs($customer)->getJson($path)
            ->assertOk()->assertJsonPath('revision', 0)
            ->assertJsonPath('charging_goal', 'balanced')
            ->assertJsonPath('preferred_power_kw', null)
            ->assertJsonPath('applied_to_charging', false);

        $this->actingAs($customer)->putJson($path, $this->body())
            ->assertOk()->assertJsonPath('revision', 1)->assertJsonPath('preferred_power_kw', 150)
            ->assertJsonPath('accessible_site_required', true);

        $revised = $this->body(1);
        $revised['charging_goal'] = 'lowest_cost';
        $revised['preferred_power_kw'] = null;
        $this->actingAs($customer)->putJson($path, $revised)
            ->assertOk()->assertJsonPath('revision', 2)
            ->assertJsonPath('charging_goal', 'lowest_cost')
            ->assertJsonPath('preferred_power_kw', null);
        $this->assertSame(2, DB::table('audit_events')->where('action', 'vehicle.driver_preferences.updated')->count());
        $this->assertSame('CCS2', $vehicle->fresh()->connector_type);
    }

    public function test_ownership_and_customer_role_cannot_be_bypassed(): void
    {
        $owner = User::factory()->create(['role' => 'customer']);
        $other = User::factory()->create(['role' => 'customer']);
        $vendor = User::factory()->create(['role' => 'vendor']);
        $vehicle = $this->vehicle($owner);
        $path = '/api/vehicles/'.$vehicle->id.'/driver-preferences';
        $this->actingAs($other)->getJson($path)->assertNotFound();
        $this->actingAs($other)->putJson($path, $this->body())->assertNotFound();
        $this->actingAs($vendor)->putJson($path, $this->body())->assertNotFound();
        $this->actingAs($vendor)->getJson($path)->assertNotFound();
        $this->assertSame(0, DB::table('vehicle_driver_preferences')->count());
    }

    public function test_concurrent_versions_and_invalid_fields_are_rejected(): void
    {
        $owner = User::factory()->create(['role' => 'customer']);
        $vehicle = $this->vehicle($owner);
        $path = '/api/vehicles/'.$vehicle->id.'/driver-preferences';
        $this->actingAs($owner)->putJson($path, $this->body())->assertOk();
        $this->actingAs($owner)->putJson($path, $this->body())->assertStatus(409);
        foreach ([
            ['preferred_power_kw' => 0],
            ['preferred_power_kw' => 1001],
            ['charging_goal' => 'unknown'],
            ['accessible_site_required' => 'yes'],
            ['revision' => -1],
        ] as $invalid) {
            $this->actingAs($owner)->putJson($path, array_replace($this->body(1), $invalid))
                ->assertUnprocessable();
        }
        $this->assertSame(1, DB::table('vehicle_driver_preferences')->where('vehicle_id', $vehicle->id)->value('revision'));
    }

    public function test_native_api_uses_identical_vehicle_ownership_contract(): void
    {
        $owner = User::factory()->create(['role' => 'customer']);
        $other = User::factory()->create(['role' => 'customer']);
        $vehicle = $this->vehicle($owner);
        $path = '/api/v1/vehicles/'.$vehicle->id.'/driver-preferences';
        $this->actingAs($other, 'sanctum')->getJson($path)->assertNotFound();
        $this->actingAs($owner, 'sanctum')->putJson($path, $this->body())->assertOk()->assertJsonPath('revision', 1);
        $this->actingAs($owner, 'sanctum')->getJson($path)->assertOk()->assertJsonPath('scope', 'DRIVER_PREFERENCE_ONLY');
    }
}
