<?php

namespace Tests\Feature;

use App\Models\ChargingSession;
use App\Models\Reservation;
use App\Models\Station;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SimulationTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $vendor = User::factory()->create(['role' => 'vendor']);
        $customer = User::factory()->create();
        $station = Station::create(['vendor_id' => $vendor->id, 'name' => 'Simulator', 'country' => 'AE', 'currency' => 'AED', 'timezone' => 'Asia/Dubai', 'latitude' => 25, 'longitude' => 55, 'source' => 'simulator', 'connector_type' => 'CCS2', 'power_kw' => 50]);
        $b = Reservation::create(['user_id' => $customer->id, 'station_id' => $station->id, 'starts_at' => now()->startOfHour()->format('Y-m-d H:i:s'), 'idempotency_key' => 'test']);
        $this->travelTo(now()->startOfHour()->addMinutes(5));

        return [$vendor, $customer, $b];
    }

    public function test_start_meter_stop_are_authorized_idempotent_and_reconcile_integer_minor_units(): void
    {
        [$v,$c,$b] = $this->fixture();
        $this->actingAs($c)->postJson('/api/reservations/'.$b->id.'/simulate-start')->assertCreated();
        $this->postJson('/api/reservations/'.$b->id.'/simulate-start')->assertOk();
        $s = ChargingSession::first();
        $this->assertDatabaseCount('charging_sessions', 1);
        $this->putJson('/api/simulations/'.$s->id.'/meter', ['energy_wh' => 1200])->assertForbidden();
        $this->actingAs($v)->putJson('/api/simulations/'.$s->id.'/meter', ['energy_wh' => 12345])->assertOk();
        $this->putJson('/api/simulations/'.$s->id.'/meter', ['energy_wh' => 12345])->assertOk();
        $this->putJson('/api/simulations/'.$s->id.'/meter', ['energy_wh' => 10])->assertConflict();
        $this->actingAs($c)->postJson('/api/simulations/'.$s->id.'/stop')->assertOk()->assertJsonPath('total_minor', 1235);
        $this->postJson('/api/simulations/'.$s->id.'/stop')->assertOk()->assertJsonPath('total_minor', 1235);
        $this->deleteJson('/api/reservations/'.$b->id)->assertConflict();
        $this->getJson('/api/simulations')->assertJsonPath('data.0.evidence', 'SIMULATED')->assertJsonPath('data.0.payment_status', 'not_processed');
        $this->actingAs($v)->putJson('/api/simulations/'.$s->id.'/meter', ['energy_wh' => 15000])->assertConflict();
    }

    public function test_another_tenant_cannot_control_or_view_a_session(): void
    {
        [$v,$c,$b] = $this->fixture();
        $this->actingAs($c)->postJson('/api/reservations/'.$b->id.'/simulate-start')->assertCreated();
        $s = ChargingSession::first();
        $this->actingAs(User::factory()->create(['role' => 'vendor']))->postJson('/api/simulations/'.$s->id.'/stop')->assertNotFound();
        $this->getJson('/api/simulations')->assertJsonCount(0, 'data');
    }

    public function test_cancelled_or_out_of_window_reservations_cannot_start(): void
    {
        [$v,$c,$b] = $this->fixture();
        $this->travel(40)->minutes();
        $this->actingAs($c)->postJson('/api/reservations/'.$b->id.'/simulate-start')->assertConflict();
        $b->update(['status' => 'cancelled']);
        $this->travelBack();
        $this->postJson('/api/reservations/'.$b->id.'/simulate-start')->assertConflict();
    }
}
