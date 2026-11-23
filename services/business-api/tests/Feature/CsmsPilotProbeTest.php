<?php

namespace Tests\Feature;

use App\Models\Station;
use App\Models\User;
use App\Models\VendorOps\VendorCharger;
use App\Models\VendorOps\VendorCompany;
use App\Models\VendorOps\VendorSite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CsmsPilotProbeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config([
            'csms.simulator_enabled' => true,
            'csms.session_bridge_enabled' => true,
            'csms.graphql_url' => 'http://127.0.0.1:8095/v1/graphql',
            'csms.graphql_secret' => str_repeat('a', 64),
            'csms.event_secret' => str_repeat('b', 64),
        ]);
    }

    private function fixture(string $protocol = '1.6'): array
    {
        $owner = User::factory()->create(['role' => 'vendor']);
        $station = Station::create([
            'vendor_id' => $owner->id, 'name' => 'Synthetic pilot source',
            'country' => 'AE', 'currency' => 'AED', 'timezone' => 'Asia/Dubai',
            'latitude' => 25, 'longitude' => 55, 'source' => 'simulator',
            'connector_type' => 'CCS2', 'power_kw' => 50,
        ]);
        $company = VendorCompany::create([
            'owner_id' => $owner->id, 'legal_name' => 'Pilot source fixture',
            'country' => 'AE', 'trade_license_number' => 'SYNTHETIC',
            'license_expires_on' => now()->addYear()->toDateString(), 'status' => 'approved',
        ]);
        $site = VendorSite::create([
            'company_id' => $company->id, 'station_id' => $station->id,
            'name' => 'Pilot site', 'country' => 'AE', 'timezone' => 'Asia/Dubai',
            'address' => 'Synthetic source', 'latitude' => 25, 'longitude' => 55,
        ]);
        $charger = VendorCharger::create([
            'company_id' => $company->id, 'site_id' => $site->id,
            'asset_reference' => 'PILOT', 'manufacturer' => 'Synthetic',
            'model' => 'Fixture', 'source' => 'simulator', 'ocpp_protocol' => $protocol,
        ]);
        $binding = DB::table('csms_bindings')->insertGetId([
            'station_id' => $station->id, 'vendor_charger_id' => $charger->id,
            'identifier' => 'ev-test-pilot-'.$station->id, 'protocol' => $protocol,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return compact('owner', 'station', 'company', 'charger', 'binding', 'protocol');
    }

    private function source(array $fixture, string $state = 'healthy'): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(function ($request) use ($fixture, $state) {
            $this->assertSame('http://127.0.0.1:8095/v1/graphql', $request->url());
            $this->assertSame(str_repeat('a', 64), $request->header('x-hasura-admin-secret')[0] ?? null);
            if (str_contains($request['query'], 'ChargingStations')) {
                $row = [
                    'id' => 91, 'isOnline' => true, 'protocol' => $fixture['protocol'],
                    'chargePointVendor' => $state === 'wrong_vendor' ? 'Other' : 'Synthetic',
                    'chargePointModel' => $state === 'wrong_model' ? 'Other' : 'Fixture',
                    'firmwareVersion' => $state === 'no_firmware' ? null : 'sim-fw-v1',
                    'latestOcppMessageTimestamp' => $state === 'relative_clock' ? 'now'
                        : ($state === 'stale' ? now()->subMinutes(10)->toISOString() : now()->toISOString()),
                ];

                return Http::response(['data' => ['ChargingStations' =>
                    $state === 'ambiguous' ? [$row, $row]
                        : ($state === 'object_station' ? ['station' => $row] : [$row])]]);
            }
            if ($state === 'suspended_owner') {
                DB::table('users')->where('id', $fixture['owner']->id)
                    ->update(['account_status' => 'suspended']);
            }
            if ($state === 'model_changed') {
                DB::table('vendor_chargers')->where('id', $fixture['charger']->id)
                    ->update(['model' => 'Changed']);
            }
            if ($state === 'reassigned') {
                DB::table('stations')->where('id', $fixture['station']->id)
                    ->update(['vendor_id' => User::factory()->create(['role' => 'vendor'])->id]);
            }
            $rows = [
                ['id' => 25, 'connectorDatabaseId' => 8, 'connectorStatus' => 'Available',
                    'timestamp' => now()->toISOString()],
                ['id' => 24, 'connectorDatabaseId' => 7, 'connectorStatus' => 'Available',
                    'timestamp' => now()->subSecond()->toISOString()],
            ];
            if ($state === 'busy') {
                $rows[0]['connectorStatus'] = 'Charging';
            }
            if ($state === 'fault') {
                $rows[0]['errorCode'] = 'PowerSwitchFailure';
            }
            if ($state === 'unknown_status') {
                $rows[0]['connectorStatus'] = 'NotAnOcppStatus';
            }
            if ($state === 'unknown_connector') {
                $rows[0]['connectorDatabaseId'] = 9;
            }
            if ($state === 'reversed_clock') {
                $rows[0]['timestamp'] = now()->subSeconds(30)->toISOString();
                $rows[1]['timestamp'] = now()->subSeconds(5)->toISOString();
            }
            if ($state === 'duplicate_event') {
                $rows[1]['id'] = $rows[0]['id'];
            }

            return Http::response(['data' => [
                'Transactions' => $state === 'active' ? [['id' => 99]] : [],
                'Connectors' => [['id' => 7], ['id' => 8]],
                'StatusNotifications' => $state === 'overflow' ? array_fill(0, 1001, $rows[0]) : $rows,
            ]]);
        });
    }

    public function test_both_ocpp_protocols_prove_simulated_transport_reads_without_device_commands(): void
    {
        foreach (['1.6', '2.0.1'] as $protocol) {
            $f = $this->fixture($protocol);
            $this->source($f);
            $this->assertSame(0, Artisan::call('csms:pilot-probe', ['binding' => $f['binding']]));
            $report = json_decode(trim(Artisan::output()), true, 32, JSON_THROW_ON_ERROR);
            $this->assertSame('simulated_source_observed', $report['status']);
            $this->assertSame($protocol, $report['protocol']);
            $this->assertSame('sim-fw-v1', $report['observed_source_firmware']);
            $this->assertSame(2, $report['connector_count']);
            $this->assertSame(2, $report['connectors_with_recent_status']);
            $this->assertTrue($report['software_source_observed']);
            $this->assertTrue($report['source_idle_candidate']);
            $this->assertSame(2, $report['idle_connectors_with_recent_safe_status']);
            foreach (['device_commands_sent', 'database_mutations', 'hardware_pilot_approved',
                'physical_hardware_accepted', 'production_accepted'] as $field) {
                $this->assertFalse($report[$field]);
            }
            $this->assertStringNotContainsString(str_repeat('a', 64), Artisan::output());
            $this->assertStringNotContainsString('ev-test-pilot', Artisan::output());
            Http::assertSentCount(2);
            Http::assertNotSent(fn ($request) => str_contains($request->url(), '/dev/token')
                || str_contains($request->url(), '/ocpp/')
                || str_contains($request->url(), '/configuration/'));
        }
        $this->assertDatabaseCount('charger_commands', 0);
        $this->assertDatabaseCount('finance_invoices', 0);
        $this->assertDatabaseCount('charging_sessions', 0);
    }

    public function test_stale_source_requires_review_without_creating_hardware_authority(): void
    {
        $f = $this->fixture();
        $this->source($f, 'stale');
        $this->assertSame(1, Artisan::call('csms:pilot-probe', ['binding' => $f['binding']]));
        $report = json_decode(trim(Artisan::output()), true, 32, JSON_THROW_ON_ERROR);
        $this->assertSame('requires_source_review', $report['status']);
        $this->assertFalse($report['software_source_observed']);
        $this->assertFalse($report['production_accepted']);
        Http::assertSentCount(2);
    }

    public function test_active_transactions_and_nonidle_or_faulted_connectors_prevent_source_pilot_success(): void
    {
        foreach (['active', 'busy', 'fault'] as $case) {
            $f = $this->fixture();
            $this->source($f, $case);
            $this->assertSame(1, Artisan::call('csms:pilot-probe', ['binding' => $f['binding']]));
            $report = json_decode(trim(Artisan::output()), true, 32, JSON_THROW_ON_ERROR);
            $this->assertSame('requires_source_review', $report['status']);
            $this->assertFalse($report['software_source_observed']);
            $this->assertFalse($report['source_idle_candidate']);
            $this->assertFalse($report['physical_hardware_accepted']);
            $this->assertFalse($report['device_commands_sent']);
        }
        $this->assertDatabaseCount('charger_commands', 0);
        $this->assertDatabaseCount('charging_sessions', 0);
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    public function test_invalid_source_identity_clocks_and_connector_events_fail_closed(): void
    {
        foreach (['ambiguous', 'object_station', 'wrong_vendor', 'wrong_model', 'no_firmware', 'relative_clock', 'unknown_connector', 'unknown_status', 'overflow', 'reversed_clock', 'duplicate_event', 'reassigned', 'suspended_owner', 'model_changed'] as $case) {
            $f = $this->fixture();
            $this->source($f, $case);
            $this->assertSame(1, Artisan::call('csms:pilot-probe', ['binding' => $f['binding']]));
            $this->assertStringContainsString('Source pilot probe blocked', Artisan::output());
            $this->assertStringNotContainsString(str_repeat('a', 64), Artisan::output());
            Http::assertNotSent(fn ($request) => str_contains($request->url(), '/dev/token')
                || str_contains($request->url(), '/ocpp/'));
        }
        $this->assertDatabaseCount('charger_commands', 0);
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    public function test_production_and_bad_input_refuse_source_access(): void
    {
        $f = $this->fixture();
        $this->source($f);
        $this->assertSame(2, Artisan::call('csms:pilot-probe', ['binding' => '0']));
        Http::assertNothingSent();
        $previous = app()->environment();
        app()->detectEnvironment(fn () => 'production');
        try {
            $this->assertSame(1, Artisan::call('csms:pilot-probe', ['binding' => $f['binding']]));
            Http::assertNothingSent();
        } finally {
            app()->detectEnvironment(fn () => $previous);
        }
    }
}
