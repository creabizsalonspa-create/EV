<?php

namespace Tests\Feature;

use App\Models\Reservation;
use App\Models\Station;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformTest extends TestCase
{
    use RefreshDatabase;

    private function station(string $source = 'simulator', ?User $vendor = null): Station
    {
        return Station::create(['vendor_id' => ($vendor ?? User::factory()->create(['role' => 'vendor']))->id, 'name' => 'Test connector', 'country' => 'AE', 'currency' => 'AED', 'timezone' => 'Asia/Dubai', 'latitude' => 25.2, 'longitude' => 55.3, 'source' => $source, 'connector_type' => 'CCS2', 'power_kw' => 50]);
    }

    private function payload(Station $s, string $key = 'request-1'): array
    {
        return ['station_id' => $s->id, 'starts_at' => now()->addDay()->startOfHour()->format('Y-m-d\TH:i:s\Z'), 'idempotency_key' => $key];
    }

    public function test_registration_cannot_grant_vendor_or_admin(): void
    {
        $this->postJson('/api/register', ['name' => 'Driver', 'email' => 'driver@example.test', 'password' => 'long-safe-test-password', 'role' => 'admin'])->assertCreated()->assertJsonPath('role', 'customer');
        $this->getJson('/api/me')->assertOk()->assertJsonPath('email', 'driver@example.test');
        $this->postJson('/api/stations', [])->assertForbidden();
        $this->postJson('/api/logout')->assertNoContent();
        $this->getJson('/api/me')->assertUnauthorized();
    }

    public function test_invalid_login_and_guest_operations_are_rejected(): void
    {
        $this->postJson('/api/login', ['email' => 'missing@example.test', 'password' => 'wrong'])->assertUnprocessable();
        $this->postJson('/api/reservations', [])->assertUnauthorized();
    }

    public function test_discovery_has_no_live_or_control_claims(): void
    {
        $s = $this->station('discovery');
        $this->getJson('/api/stations?connector_type=CCS2')->assertOk()->assertJsonPath('data.0.availability', 'unknown')->assertJsonPath('data.0.remote_control', false)->assertJsonPath('data.0.reservable', false);
        $this->actingAs(User::factory()->create())->postJson('/api/reservations', $this->payload($s))->assertConflict();
    }

    public function test_booking_is_idempotent_and_prevents_conflicting_slot(): void
    {
        $s = $this->station();
        $u = User::factory()->create();
        $v = $this->payload($s);
        $this->actingAs($u)->postJson('/api/reservations', $v)->assertCreated();
        $this->postJson('/api/reservations', $v)->assertOk();
        $this->assertDatabaseCount('reservations', 1);
        $this->actingAs(User::factory()->create())->postJson('/api/reservations', $this->payload($s, 'other'))->assertConflict();
    }

    public function test_idempotency_key_cannot_change_payload(): void
    {
        $u = User::factory()->create();
        $a = $this->station();
        $b = $this->station();
        $this->actingAs($u)->postJson('/api/reservations', $this->payload($a))->assertCreated();
        $this->postJson('/api/reservations', $this->payload($b))->assertConflict();
    }

    public function test_customer_and_vendor_tenant_isolation(): void
    {
        $vendor = User::factory()->create(['role' => 'vendor']);
        $other = User::factory()->create(['role' => 'vendor']);
        $s = $this->station('simulator', $vendor);
        $u = User::factory()->create();
        $this->actingAs($u)->postJson('/api/reservations', $this->payload($s))->assertCreated();
        $b = Reservation::first();
        $this->actingAs($other)->getJson('/api/reservations')->assertJsonCount(0, 'data');
        $this->actingAs($vendor)->getJson('/api/reservations')->assertJsonCount(1, 'data');
        $this->actingAs(User::factory()->create())->deleteJson('/api/reservations/'.$b->id)->assertNotFound();
        $this->getJson('/api/reservations')->assertJsonCount(0, 'data');
        $this->actingAs($u)->deleteJson('/api/reservations/'.$b->id)->assertOk();
        $this->postJson('/api/reservations', $this->payload($s, 'new-after-cancel'))->assertCreated();
    }

    public function test_half_hour_window_and_country_validation(): void
    {
        $s = $this->station();
        $u = User::factory()->create();
        $v = $this->payload($s);
        $v['starts_at'] = now()->addDay()->startOfHour()->addMinutes(10)->format('Y-m-d\TH:i:s\Z');
        $this->actingAs($u)->postJson('/api/reservations', $v)->assertUnprocessable();
        $v['starts_at'] = now()->subDay()->startOfHour()->format('Y-m-d\TH:i:s\Z');
        $this->postJson('/api/reservations', $v)->assertUnprocessable();
    }

    public function test_vehicle_records_are_private(): void
    {
        $this->actingAs(User::factory()->create())->postJson('/api/vehicles', ['name' => 'Personal EV', 'connector_type' => 'CCS2'])->assertCreated();
        $this->getJson('/api/vehicles')->assertJsonCount(1, 'data');
        $this->actingAs(User::factory()->create())->getJson('/api/vehicles')->assertJsonCount(0, 'data');
    }

    public function test_vendor_creation_is_audited_and_cannot_claim_hardware_integration(): void
    {
        $v = ['name' => 'Station A', 'country' => 'AE', 'currency' => 'AED', 'timezone' => 'Asia/Dubai', 'latitude' => 25, 'longitude' => 55, 'source' => 'simulator', 'connector_type' => 'CCS2', 'power_kw' => 50];
        $u = User::factory()->create(['role' => 'vendor']);
        $this->actingAs($u)->postJson('/api/stations', $v)->assertCreated()->assertJsonPath('vendor_id', $u->id);
        $this->assertDatabaseHas('audit_events', ['actor_id' => $u->id, 'action' => 'station.created']);
        $v['source'] = 'live';
        $this->postJson('/api/stations', $v)->assertUnprocessable();
    }
}
