<?php

namespace Tests\Feature;

use App\Models\Charging\ConnectorMapping;
use App\Models\Charging\Evse;
use App\Models\ChargingSession;
use App\Models\Reservation;
use App\Models\Station;
use App\Models\User;
use App\Models\VendorOps\VendorCharger;
use App\Models\VendorOps\VendorCompany;
use App\Models\VendorOps\VendorConnector;
use App\Models\VendorOps\VendorSite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MaintenanceWindowTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $this->travelTo(now()->startOfHour());
        $owner = User::factory()->create(['role' => 'vendor']);
        $customer = User::factory()->create();
        $station = Station::create(['vendor_id' => $owner->id, 'name' => 'Maintenance fixture', 'country' => 'AE', 'currency' => 'AED', 'timezone' => 'Asia/Dubai', 'latitude' => 25, 'longitude' => 55, 'source' => 'simulator', 'connector_type' => 'CCS2', 'power_kw' => 50]);
        $company = VendorCompany::create(['owner_id' => $owner->id, 'legal_name' => 'Synthetic maintenance', 'country' => 'AE', 'trade_license_number' => 'SYNTHETIC', 'license_expires_on' => now()->addYear()->toDateString(), 'status' => 'approved']);
        $site = VendorSite::create(['company_id' => $company->id, 'station_id' => $station->id, 'name' => 'Fixture', 'country' => 'AE', 'timezone' => 'Asia/Dubai', 'address' => 'Synthetic', 'latitude' => 25, 'longitude' => 55]);
        $charger = VendorCharger::create(['company_id' => $company->id, 'site_id' => $site->id, 'asset_reference' => 'MAINTENANCE', 'manufacturer' => 'Synthetic', 'model' => 'Fixture', 'source' => 'simulator']);
        $maps = [];
        $connectors = [];
        for ($i = 1; $i <= 2; $i++) {
            $connectors[] = VendorConnector::create(['charger_id' => $charger->id, 'connector_number' => $i, 'connector_type' => 'CCS2', 'max_power_kw' => 50]);
            $evse = Evse::create(['charger_id' => $charger->id, 'number' => $i, 'mode' => 'application']);
            $maps[] = ConnectorMapping::create(['station_id' => $station->id, 'evse_id' => $evse->id, 'connector_id' => $connectors[$i - 1]->id]);
        }
        $base = '/api/vendor-ops/companies/'.$company->id.'/chargers/'.$charger->id.'/connectors/'.$connectors[0]->id.'/maintenance-windows';
        $payload = ['starts_at' => now()->addHours(2)->format('Y-m-d\TH:i:s\Z'), 'ends_at' => now()->addHours(3)->format('Y-m-d\TH:i:s\Z'), 'reason' => 'PRIVATE scheduled safety review', 'idempotency_key' => 'maintenance-request'];

        return compact('owner', 'customer', 'station', 'company', 'charger', 'maps', 'connectors', 'base', 'payload');
    }

    private function book(array $f, int $map, int $minutes, string $key): array
    {
        return ['station_id' => $f['station']->id, 'connector_mapping_id' => $f['maps'][$map]->id, 'starts_at' => now()->addMinutes($minutes)->format('Y-m-d\TH:i:s\Z'), 'idempotency_key' => $key];
    }

    public function test_half_open_windows_block_only_the_affected_connector_and_replay_is_bound(): void
    {
        $f = $this->fixture();
        $id = $this->actingAs($f['owner'])->postJson($f['base'], $f['payload'])->assertCreated()->assertJsonPath('commands_sent', false)->json('id');
        $this->postJson($f['base'], $f['payload'])->assertOk()->assertJsonPath('id', $id);
        $this->postJson($f['base'], array_replace($f['payload'], ['reason' => 'Changed payload must conflict']))->assertConflict();
        $this->actingAs($f['customer'])->postJson('/api/reservations', $this->book($f, 0, 120, 'blocked'))->assertConflict();
        $this->postJson('/api/reservations', $this->book($f, 1, 120, 'other-connector'))->assertCreated();
        $this->postJson('/api/reservations', $this->book($f, 0, 90, 'ends-at-maintenance'))->assertCreated();
        $this->postJson('/api/reservations', $this->book($f, 0, 180, 'starts-at-end'))->assertCreated();
        $this->assertDatabaseCount('connector_maintenance_windows', 1);
        $this->assertStringNotContainsString('PRIVATE', DB::table('connector_maintenance_windows')->value('reason'));
        $this->assertStringNotContainsString('PRIVATE scheduled', $this->getJson('/api/stations')->getContent());
    }

    public function test_existing_bookings_and_overlapping_windows_are_preserved(): void
    {
        $f = $this->fixture();
        $this->actingAs($f['customer'])->postJson('/api/reservations', $this->book($f, 0, 120, 'existing'))->assertCreated();
        $this->actingAs($f['owner'])->postJson($f['base'], $f['payload'])->assertConflict();
        $this->assertDatabaseCount('connector_maintenance_windows', 0);
        $payload = array_replace($f['payload'], ['starts_at' => now()->addHours(4)->format('Y-m-d\TH:i:s\Z'), 'ends_at' => now()->addHours(5)->format('Y-m-d\TH:i:s\Z')]);
        $id = $this->postJson($f['base'], $payload)->assertCreated()->json('id');
        $this->postJson($f['base'], array_replace($payload, ['idempotency_key' => 'overlap']))->assertConflict();
        $this->deleteJson($f['base'].'/'.$id)->assertOk()->assertJsonPath('active_sessions_stopped', false);
        $this->deleteJson($f['base'].'/'.$id)->assertOk();
        $this->assertSame(1, DB::table('audit_events')->where('action', 'vendor_ops.maintenance.cancelled')->count());
        $this->assertDatabaseCount('reservations', 1);
    }

    public function test_start_rechecks_current_maintenance_but_active_session_stop_remains_available(): void
    {
        $f = $this->fixture();
        $this->actingAs($f['owner'])->postJson($f['base'], $f['payload'])->assertCreated();
        $this->travelTo(now()->addHours(2));
        $r = Reservation::create(['station_id' => $f['station']->id, 'user_id' => $f['customer']->id, 'connector_mapping_id' => $f['maps'][0]->id, 'charging_evse_id' => $f['maps'][0]->evse_id, 'starts_at' => now(), 'idempotency_key' => 'imported']);
        $this->actingAs($f['customer'])->postJson('/api/reservations/'.$r->id.'/simulate-start')->assertConflict();
        $this->assertDatabaseCount('charging_sessions', 0);
        $session = ChargingSession::create(['reservation_id' => $r->id, 'rate_minor_per_kwh' => 100, 'currency' => 'AED']);
        $this->postJson('/api/simulations/'.$session->id.'/stop')->assertOk()->assertJsonPath('status', 'stopped');
        $this->assertDatabaseCount('charger_commands', 0);
    }

    public function test_tenant_scope_validation_and_native_admin_read(): void
    {
        $f = $this->fixture();
        $this->getJson($f['base'])->assertUnauthorized();
        $this->actingAs(User::factory()->create(['role' => 'vendor']))->postJson($f['base'], $f['payload'])->assertNotFound();
        $this->actingAs($f['owner'])->postJson($f['base'], array_replace($f['payload'], ['ends_at' => $f['payload']['starts_at']]))->assertUnprocessable();
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->getJson(str_replace('/api/','/api/v1/',$f['base']))->assertOk()->assertJsonPath('total',0);
    }
}
