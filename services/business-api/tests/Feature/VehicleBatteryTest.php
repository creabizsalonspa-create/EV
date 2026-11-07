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
        $this->postJson($url, array_replace($body, ['battery_percent' => 80]))->assertUnprocessable();
        $this->assertDatabaseCount('vehicle_battery_reports', 0);
        $this->assertDatabaseCount('inbox_notifications', 0);
    }
}
