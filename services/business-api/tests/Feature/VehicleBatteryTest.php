<?php

namespace Tests\Feature;

use App\Models\InboxNotification;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class VehicleBatteryTest extends TestCase
{
    use RefreshDatabase;

    public function test_simulated_alert_is_private_persisted_and_idempotent(): void
    {
        config(['vehicle_battery.simulator_enabled' => true]);
        $owner = User::factory()->create(['role' => 'customer']);
        $vehicle = Vehicle::create(['user_id' => $owner->id, 'name' => 'EV', 'connector_type' => 'CCS2']);
        $url = '/api/v1/vehicles/'.$vehicle->id.'/battery-simulation';
        $body = ['operation_key' => (string) Str::uuid(), 'battery_percent' => 15];
        $first = $this->actingAs($owner)->postJson($url, $body)->assertCreated()->assertJsonPath('source', 'simulator');
        $this->postJson($url, $body)->assertOk()->assertJsonPath('notification_id', $first->json('notification_id'));
        $this->postJson($url, array_replace($body, ['battery_percent' => 10]))->assertConflict();
        $this->assertDatabaseCount('inbox_notifications', 1);
        $this->getJson('/api/v1/notifications')->assertJsonPath('data.0.kind', 'vehicle.battery.simulated')->assertJsonPath('data.0.metadata.source', 'simulator');
        $this->actingAs(User::factory()->create(['role' => 'customer']))->postJson($url, $body)->assertNotFound();
        $this->getJson('/api/v1/notifications')->assertJsonCount(0, 'data');
        $this->postJson('/api/v1/notifications/'.$first->json('notification_id').'/read')->assertNotFound();
        $this->assertNull(InboxNotification::first()->read_at);
    }

    public function test_disabled_simulation_and_invalid_battery_cannot_create_alerts(): void
    {
        config(['vehicle_battery.simulator_enabled' => false]);
        $owner = User::factory()->create(['role' => 'customer']);
        $vehicle = Vehicle::create(['user_id' => $owner->id, 'name' => 'EV', 'connector_type' => 'CCS2']);
        $url = '/api/v1/vehicles/'.$vehicle->id.'/battery-simulation';
        $body = ['operation_key' => (string) Str::uuid(), 'battery_percent' => 15];
        $this->actingAs($owner)->postJson($url, $body)->assertForbidden();
        config(['vehicle_battery.simulator_enabled' => true]);
        $this->postJson($url, array_replace($body, ['battery_percent' => 101]))->assertUnprocessable();
        $this->assertDatabaseCount('vehicle_battery_reports', 0);
        $this->assertDatabaseCount('inbox_notifications', 0);
    }

    public function test_preferences_are_private_and_reject_stale_updates(): void
    {
        $owner = User::factory()->create(['role' => 'customer']);
        $vehicle = Vehicle::create(['user_id' => $owner->id, 'name' => 'EV', 'connector_type' => 'Type2']);
        $url = '/api/v1/vehicles/'.$vehicle->id.'/battery-preferences';
        $this->actingAs($owner)->getJson($url)->assertOk()->assertJsonPath('threshold_percent', 20)->assertJsonPath('connector_type', 'Type2')->assertJsonPath('telemetry_source', 'SIMULATOR_ONLY');
        $v = ['alerts_enabled' => false, 'threshold_percent' => 10, 'expected_version' => 1];
        $this->putJson($url, $v)->assertOk()->assertJsonPath('version', 2)->assertJsonPath('alerts_enabled', false);
        $this->putJson($url, $v)->assertConflict();
        $this->putJson($url, array_replace($v, ['expected_version' => 2, 'threshold_percent' => 90]))->assertUnprocessable();
        foreach (['customer', 'vendor', 'admin'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))->getJson($url)->assertNotFound();
            $this->putJson($url, $v)->assertNotFound();
        }
        $this->assertDatabaseHas('vehicles', ['id' => $vehicle->id, 'battery_alerts_enabled' => false, 'battery_alert_threshold' => 10, 'battery_preferences_version' => 2]);
    }

    public function test_threshold_disabled_alerts_and_replays_preserve_observed_preferences(): void
    {
        config(['vehicle_battery.simulator_enabled' => true]);
        $owner = User::factory()->create(['role' => 'customer']);
        $vehicle = Vehicle::create(['user_id' => $owner->id, 'name' => 'EV', 'connector_type' => 'CCS2']);
        $base = '/api/v1/vehicles/'.$vehicle->id;
        $this->actingAs($owner)->putJson($base.'/battery-preferences', ['alerts_enabled' => false, 'threshold_percent' => 10, 'expected_version' => 1])->assertOk();
        $body = ['operation_key' => (string) Str::uuid(), 'battery_percent' => 5];
        $this->postJson($base.'/battery-simulation', $body)->assertCreated()->assertJsonPath('alerted', false)->assertJsonPath('preferences_version', 2);
        $this->putJson($base.'/battery-preferences', ['alerts_enabled' => true, 'threshold_percent' => 10, 'expected_version' => 2])->assertOk();
        $this->postJson($base.'/battery-simulation', $body)->assertOk()->assertJsonPath('alerted', false)->assertJsonPath('alerts_enabled', false)->assertJsonPath('preferences_version', 2);
        $this->postJson($base.'/battery-simulation', ['operation_key' => (string) Str::uuid(), 'battery_percent' => 11])->assertCreated()->assertJsonPath('alerted', false);
        $low = ['operation_key' => (string) Str::uuid(), 'battery_percent' => 10];
        $id = $this->postJson($base.'/battery-simulation', $low)->assertCreated()->assertJsonPath('alerted', true)->json('notification_id');
        $this->postJson($base.'/battery-simulation', $low)->assertOk()->assertJsonPath('notification_id', $id);
        $this->postJson($base.'/battery-simulation', ['operation_key' => (string) Str::uuid(), 'battery_percent' => 100])->assertCreated()->assertJsonPath('alerted', false);
        $this->assertDatabaseCount('vehicle_battery_reports', 4);
        $this->assertDatabaseCount('inbox_notifications', 1);
    }
}
