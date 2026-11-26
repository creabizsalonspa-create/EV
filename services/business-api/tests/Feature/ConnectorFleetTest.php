<?php

namespace Tests\Feature;

use App\Http\Controllers\ConnectorFleetController;
use App\Models\Charging\ConnectorMapping;
use App\Models\Charging\Evse;
use App\Models\Station;
use App\Models\User;
use App\Models\VendorOps\VendorCharger;
use App\Models\VendorOps\VendorCompany;
use App\Models\VendorOps\VendorConnector;
use App\Models\VendorOps\VendorSite;
use App\Services\Charging\FaultMonitor;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ConnectorFleetTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(string $protocol = '2.0.1'): array
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-15T12:00:00Z'));
        Http::preventStrayRequests();
        $owner = User::factory()->create(['role' => 'vendor']);
        $station = Station::create(['vendor_id' => $owner->id, 'name' => '<b>Private fleet</b>', 'country' => 'AE', 'currency' => 'AED', 'timezone' => 'Asia/Dubai', 'latitude' => 25, 'longitude' => 55, 'source' => 'simulator', 'connector_type' => 'CCS2', 'power_kw' => 50]);
        $company = VendorCompany::create(['owner_id' => $owner->id, 'legal_name' => 'Fleet fixture', 'country' => 'AE', 'trade_license_number' => 'PRIVATE', 'license_expires_on' => now()->addYear()->toDateString(), 'status' => 'approved']);
        $site = VendorSite::create(['company_id' => $company->id, 'station_id' => $station->id, 'name' => 'Fixture', 'country' => 'AE', 'timezone' => 'Asia/Dubai', 'address' => 'Fixture', 'latitude' => 25, 'longitude' => 55]);
        $charger = VendorCharger::create(['company_id' => $company->id, 'site_id' => $site->id, 'asset_reference' => 'FLEET', 'manufacturer' => 'Synthetic', 'model' => 'Fixture', 'source' => 'simulator', 'ocpp_protocol' => $protocol]);
        $id = DB::table('csms_bindings')->insertGetId(['station_id' => $station->id, 'vendor_charger_id' => $charger->id, 'identifier' => 'ev-test-fleet', 'protocol' => $protocol, 'created_at' => now(), 'updated_at' => now()]);
        $binding = DB::table('csms_bindings')->find($id);
        $evse = Evse::create(['charger_id' => $charger->id, 'number' => 1, 'mode' => 'csms', 'binding_id' => $id, 'source_evse_number' => $protocol === '1.6' ? 0 : 1]);
        $connector = VendorConnector::create(['charger_id' => $charger->id, 'connector_number' => 1, 'connector_type' => 'CCS2', 'max_power_kw' => 50]);
        $map = ConnectorMapping::create(['station_id' => $station->id, 'evse_id' => $evse->id, 'connector_id' => $connector->id, 'source_connector_number' => 1, 'source_target_key' => hash('sha256', $id.':'.$evse->source_evse_number.':1')]);

        return compact('owner', 'station', 'company', 'binding', 'evse', 'connector', 'map', 'charger');
    }

    private function observe(array $f, int $age = 60, string $status = 'Charging', ?string $error = null): void
    {
        $id = DB::table('charging_status_observations')->count() + 1;
        DB::table('charging_status_observations')->insert(['binding_id' => $f['binding']->id, 'source_generation' => FaultMonitor::generation($f['binding']), 'source_event_id' => $id, 'fingerprint' => hash('sha256', (string) $id), 'evse_number' => $f['evse']->source_evse_number, 'connector_number' => 1, 'status' => $status, 'error_code' => $error, 'occurred_at' => now()->subSeconds($age), 'created_at' => now()]);
    }

    public static function protocols(): array
    {
        return [['1.6'], ['2.0.1']];
    }

    #[DataProvider('protocols')]
    public function test_owner_and_admin_read_qualified_protocols_without_commands_or_private_source(string $protocol): void
    {
        $f = $this->fixture($protocol);
        $this->observe($f);
        $path = '/api/charging-operations/connectors';
        $this->actingAs($f['owner'])->getJson($path)->assertOk()->assertJsonPath('connectors.total', 1)->assertJsonPath('connectors.data.0.protocol', $protocol)->assertJsonPath('connectors.data.0.operations_target.company_id', $f['company']->id)->assertJsonPath('connectors.data.0.operations_target.charger_id', $f['charger']->id)->assertJsonPath('connectors.data.0.status_freshness.reported_status', 'Charging')->assertJsonPath('physical_availability_verified', false)->assertJsonPath('remote_control', false)->assertJsonMissingPath('connectors.data.0.identifier')->assertJsonMissingPath('connectors.data.0.customer');
        $this->actingAs(User::factory()->create(['role' => 'admin']))->getJson($path)->assertOk()->assertJsonPath('connectors.total', 1);
        $this->actingAs(User::factory()->create(['role' => 'vendor']))->getJson($path)->assertOk()->assertJsonPath('connectors.total', 0);
        $this->getJson($path.'?station_id='.$f['station']->id)->assertNotFound();
        $this->actingAs(User::factory()->create())->getJson($path)->assertForbidden();
        Http::assertNothingSent();
        $this->assertDatabaseCount('charger_commands', 0);
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    public function test_unknown_stale_future_conflicting_error_and_maintenance_states_share_canonical_rules(): void
    {
        $f = $this->fixture();
        $this->actingAs($f['owner']);
        $path = '/api/charging-operations/connectors';
        $this->getJson($path)->assertOk()->assertJsonPath('connectors.data.0.status_freshness.state', 'unknown');
        $this->observe($f, 300);
        $this->getJson($path)->assertOk()->assertJsonPath('connectors.data.0.status_freshness.state', 'stale');
        $this->observe($f, 60, 'Available', 'GroundFailure');
        $this->getJson($path)->assertOk()->assertJsonPath('connectors.data.0.status_freshness.reported_status', 'Faulted');
        $this->observe($f, 60, 'Charging');
        $this->getJson($path)->assertOk()->assertJsonPath('connectors.data.0.status_freshness.state', 'unknown');
        $this->observe($f, -60);
        $this->getJson($path)->assertOk()->assertJsonPath('connectors.data.0.status_freshness.state', 'unknown');
        $f['connector']->update(['maintenance_blocked' => true]);
        $this->getJson($path)->assertOk()->assertJsonPath('connectors.data.0.inventory_eligible_at_read', false)->assertJsonPath('connectors.data.0.status_freshness.reported_status', null)->assertJsonPath('connectors.data.0.operations_target.company_id', $f['company']->id);
    }

    public function test_reassignment_changed_mapping_and_revoked_request_never_inherit_source_authority(): void
    {
        $f = $this->fixture();
        $this->observe($f);
        $path = '/api/charging-operations/connectors';
        DB::table('csms_bindings')->where('id', $f['binding']->id)->update(['identifier' => 'ev-test-replaced']);
        $this->actingAs($f['owner'])->getJson($path)->assertOk()->assertJsonPath('connectors.data.0.status_freshness.state', 'unknown');
        $newOwner = User::factory()->create(['role' => 'vendor']);
        $f['station']->update(['vendor_id' => $newOwner->id]);
        $this->getJson($path)->assertOk()->assertJsonPath('connectors.total', 0);
        $this->actingAs($newOwner)->getJson($path)->assertOk()->assertJsonPath('connectors.data.0.inventory_eligible_at_read', false)->assertJsonPath('connectors.data.0.status_freshness.reported_status', null)->assertJsonPath('connectors.data.0.operations_target', null);
        $request = Request::create($path);
        $request->setUserResolver(fn () => $newOwner);
        DB::table('users')->where('id', $newOwner->id)->increment('auth_epoch');
        try {
            app(ConnectorFleetController::class)($request);
            $this->fail('Old login must remain revoked.');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        Http::assertNothingSent();
    }
}
