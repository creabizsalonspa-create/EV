<?php

namespace Tests\Feature;

use App\Models\ChargerCommand;
use App\Models\Charging\ConnectorMapping;
use App\Models\Charging\Evse;
use App\Models\Reservation;
use App\Models\Station;
use App\Models\User;
use App\Models\VendorOps\VendorCharger;
use App\Models\VendorOps\VendorCompany;
use App\Models\VendorOps\VendorConnector;
use App\Models\VendorOps\VendorSite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
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

        $evse = Evse::create([
            'charger_id' => $charger->id, 'number' => 1, 'mode' => 'csms',
            'binding_id' => $binding,
            'source_evse_number' => $protocol === '1.6' ? 0 : 1,
        ]);
        foreach ([1, 2] as $number) {
            $connector = VendorConnector::create([
                'charger_id' => $charger->id, 'connector_number' => $number,
                'connector_type' => 'CCS2', 'max_power_kw' => 50,
            ]);
            ConnectorMapping::create([
                'station_id' => $station->id, 'evse_id' => $evse->id,
                'connector_id' => $connector->id,
                'source_connector_number' => $number,
            ]);
        }

        return compact('owner', 'station', 'company', 'charger', 'binding', 'protocol');
    }

    private function source(array $fixture, string $state = 'healthy'): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        $stationReads = 0;
        Http::fake(function ($request) use ($fixture, $state, &$stationReads) {
            $this->assertSame('http://127.0.0.1:8095/v1/graphql', $request->url());
            $this->assertSame(str_repeat('a', 64), $request->header('x-hasura-admin-secret')[0] ?? null);
            if ($state === 'http_503') {
                return Http::response(['message' => 'PRIVATE UPSTREAM ERROR'], 503);
            }
            if ($state === 'graphql_errors') {
                return Http::response(['errors' => [['message' => 'PRIVATE SOURCE SCHEMA SECRET']]]);
            }
            if (str_contains($request['query'], 'ChargingStations')) {
                $stationReads++;
                if ($stationReads === 1 && $state === 'origin_changed') {
                    config(['csms.graphql_url' => 'https://alternate.example.test/v1/graphql']);
                }
                if ($stationReads === 2 && $state === 'inbox_key_changed') {
                    config(['csms.event_secret' => str_repeat('c', 64)]);
                }
                $row = [
                    'id' => 91, 'isOnline' => true, 'protocol' => $fixture['protocol'],
                    'chargePointVendor' => $state === 'wrong_vendor' ? 'Other' : 'Synthetic',
                    'chargePointModel' => $state === 'wrong_model' ? 'Other' : 'Fixture',
                    'firmwareVersion' => $state === 'no_firmware' ? null : 'sim-fw-v1',
                    'latestOcppMessageTimestamp' => $state === 'relative_clock' ? 'now'
                        : ($state === 'future_station_clock' ? now()->addMinute()->toISOString()
                        : ($state === 'stale' ? now()->subMinutes(10)->toISOString() : now()->toISOString())),
                ];
                if ($stationReads === 2) {
                    if ($state === 'station_offline_after_inventory') {
                        $row['isOnline'] = false;
                    }
                    if ($state === 'station_firmware_changed') {
                        $row['firmwareVersion'] = 'sim-fw-v2';
                    }
                    if ($state === 'station_clock_regressed') {
                        $row['latestOcppMessageTimestamp'] = now()->subSeconds(20)->toISOString();
                    }
                    if ($state === 'station_identity_replaced') {
                        $row['id'] = 92;
                    }
                }

                $projection = $state === 'ambiguous' ? [$row, $row]
                    : ($state === 'object_station' ? ['station' => $row] : [$row]);

                return Http::response(['data' => ['ChargingStations' => $projection]]);
            }
            if ($state === 'adapter_disabled') {
                config(['csms.session_bridge_enabled' => false]);
            }
            if ($state === 'source_key_changed') {
                config(['csms.graphql_secret' => str_repeat('c', 64)]);
            }
            if ($state === 'removed_mapping') {
                $mappingId = DB::table('charging_connector_mappings')
                    ->where('station_id', $fixture['station']->id)->value('id');
                DB::table('charging_connector_mappings')->where('id', $mappingId)->delete();
            }
            if ($state === 'maintenance') {
                DB::table('vendor_connectors')->where('charger_id', $fixture['charger']->id)
                    ->update(['maintenance_blocked' => true]);
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
                ['id' => 25, 'connectorDatabaseId' => 8,
                    'evseId' => $fixture['protocol'] === '1.6' ? 0 : 1,
                    'connectorId' => 2, 'connectorStatus' => 'Available',
                    'errorCode' => $fixture['protocol'] === '1.6' ? 'NoError' : null,
                    'timestamp' => now()->toISOString()],
                ['id' => 24, 'connectorDatabaseId' => 7,
                    'evseId' => $fixture['protocol'] === '1.6' ? 0 : 1,
                    'connectorId' => 1, 'connectorStatus' => 'Available',
                    'errorCode' => $fixture['protocol'] === '1.6' ? 'NoError' : null,
                    'timestamp' => now()->subSecond()->toISOString()],
            ];
            if ($state === 'ocpp16_missing_error') {
                unset($rows[0]['errorCode']);
            }
            if ($state === 'cross_protocol_status') {
                $rows[0]['connectorStatus'] = $fixture['protocol'] === '1.6' ? 'Occupied' : 'Charging';
            }
            if ($state === 'busy') {
                $rows[0]['connectorStatus'] = 'Charging';
            }
            if ($state === 'fault') {
                $rows[0]['errorCode'] = 'PowerSwitchFailure';
            }
            if ($state === 'unknown_status') {
                $rows[0]['connectorStatus'] = 'NotAnOcppStatus';
            }
            if ($state === 'source_target_wrong') {
                $rows[0]['connectorId'] = 3;
            }
            if ($state === 'source_target_duplicate') {
                $rows[0]['connectorId'] = 1;
            }
            if ($state === 'source_target_changed') {
                $rows[] = array_replace($rows[1], [
                    'id' => 23, 'connectorId' => 2,
                    'timestamp' => now()->subSeconds(3)->toISOString(),
                ]);
            }
            if ($state === 'source_target_string') {
                $rows[0]['connectorId'] = '2';
            }
            if ($state === 'source_evse_wrong') {
                $rows[0]['evseId'] = $fixture['protocol'] === '1.6' ? 1 : 0;
            }
            if ($state === 'unknown_connector') {
                $rows[0]['connectorDatabaseId'] = 9;
            }
            if ($state === 'stale_connector') {
                $rows[0]['timestamp'] = now()->subMinutes(10)->toISOString();
                $rows[1]['timestamp'] = now()->subMinutes(11)->toISOString();
            }
            if ($state === 'future_status_clock') {
                $rows[0]['timestamp'] = now()->addMinute()->toISOString();
            }
            if ($state === 'reversed_clock') {
                $rows[0]['timestamp'] = now()->subSeconds(30)->toISOString();
                $rows[1]['timestamp'] = now()->subSeconds(5)->toISOString();
            }
            if ($state === 'duplicate_event') {
                $rows[1]['id'] = $rows[0]['id'];
            }

            return Http::response(['data' => [
                'Transactions' => $state === 'active' ? [['id' => 99]] : ($state === 'multiple_active' ? [['id' => 99], ['id' => 100]] : []),
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
            $this->assertTrue($report['source_identity_rechecked_after_inventory']);
            $this->assertTrue($report['source_idle_candidate']);
            $this->assertTrue($report['source_connector_count_matches_local_topology']);
            $this->assertTrue($report['source_connector_targets_match_local_topology']);
            $this->assertFalse($report['pending_local_charging_or_maintenance_review']);
            $this->assertFalse($report['physical_topology_verified']);
            $this->assertSame(2, $report['idle_connectors_with_recent_safe_status']);
            foreach (['device_commands_sent', 'database_mutations', 'hardware_pilot_approved',
                'physical_hardware_accepted', 'production_accepted'] as $field) {
                $this->assertFalse($report[$field]);
            }
            $this->assertStringNotContainsString(str_repeat('a', 64), Artisan::output());
            $this->assertStringNotContainsString('ev-test-pilot', Artisan::output());
            Http::assertSentCount(3);
            Http::assertNotSent(fn ($request) => str_contains($request->url(), '/dev/token')
                || str_contains($request->url(), '/ocpp/')
                || str_contains($request->url(), '/configuration/'));
        }
        $this->assertDatabaseCount('charger_commands', 0);
        $this->assertDatabaseCount('finance_invoices', 0);
        $this->assertDatabaseCount('charging_sessions', 0);
        $this->assertDatabaseCount('csms_inbox_events', 0);
        $this->assertDatabaseCount('charging_status_observations', 0);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_station_offline_during_inventory_never_promotes_source_read_to_success(): void
    {
        foreach (['1.6', '2.0.1'] as $protocol) {
            $f = $this->fixture($protocol);
            $this->source($f, 'station_offline_after_inventory');
            $this->assertSame(1, Artisan::call('csms:pilot-probe', ['binding' => $f['binding']]));
            $report = json_decode(trim(Artisan::output()), true, 32, JSON_THROW_ON_ERROR);
            $this->assertFalse($report['source_online']);
            $this->assertFalse($report['software_source_observed']);
            $this->assertFalse($report['physical_hardware_accepted']);
            Http::assertSentCount(3);
        }
    }

    public function test_ocpp16_available_without_explicit_noerror_remains_blocked_not_healthy(): void
    {
        $fixture = $this->fixture('1.6');
        $this->source($fixture, 'ocpp16_missing_error');
        $this->assertSame(1, Artisan::call('csms:pilot-probe', ['binding' => $fixture['binding']]));
        $report = json_decode(trim(Artisan::output()), true, 32, JSON_THROW_ON_ERROR);
        $this->assertSame('requires_source_review', $report['status']);
        $this->assertFalse($report['software_source_observed']);
        $this->assertFalse($report['source_idle_candidate']);
        $this->assertSame(1, $report['idle_connectors_with_recent_safe_status']);
        $this->assertFalse($report['hardware_pilot_approved']);
        $this->assertFalse($report['physical_hardware_accepted']);
        Http::assertSentCount(3);
        $this->assertDatabaseCount('charger_commands', 0);
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    public function test_pilot_probe_rejects_cross_protocol_status_without_a_hardware_claim(): void
    {
        foreach (['1.6', '2.0.1'] as $protocol) {
            $fixture = $this->fixture($protocol);
            $this->source($fixture, 'cross_protocol_status');
            $this->assertSame(1, Artisan::call('csms:pilot-probe', ['binding' => $fixture['binding']]));
            $this->assertStringContainsString('Source pilot probe blocked', Artisan::output());
            $this->assertStringNotContainsString(str_repeat('a', 64), Artisan::output());
            Http::assertNotSent(fn ($request) => str_contains($request->url(), '/ocpp/')
                || str_contains($request->url(), '/dev/token'));
        }
        $this->assertDatabaseCount('charger_commands', 0);
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    public function test_mid_probe_source_authority_change_never_certifies_software_readiness(): void
    {
        foreach (['1.6', '2.0.1'] as $protocol) {
            foreach (['origin_changed' => 1, 'adapter_disabled' => 2, 'source_key_changed' => 2, 'inbox_key_changed' => 3] as $state => $calls) {
                config([
                    'csms.graphql_url' => 'http://127.0.0.1:8095/v1/graphql',
                    'csms.graphql_secret' => str_repeat('a', 64),
                    'csms.event_secret' => str_repeat('b', 64),
                    'csms.session_bridge_enabled' => true,
                ]);
                $f = $this->fixture($protocol);
                $this->source($f, $state);
                $this->assertSame(1, Artisan::call('csms:pilot-probe', ['binding' => $f['binding']]));
                $output = Artisan::output();
                $this->assertStringContainsString('Source pilot probe blocked', $output);
                $this->assertStringNotContainsString(str_repeat('a', 64), $output);
                $this->assertStringNotContainsString(str_repeat('c', 64), $output);
                Http::assertSentCount($calls);
                Http::assertNotSent(fn ($request) => str_contains($request->url(), 'alternate.example.test')
                    || str_contains($request->url(), '/ocpp/') || str_contains($request->url(), '/dev/token'));
            }
        }
        $this->assertDatabaseCount('charger_commands', 0);
        $this->assertDatabaseCount('finance_invoices', 0);
        $this->assertDatabaseCount('csms_inbox_events', 0);
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
        Http::assertSentCount(3);
    }

    public function test_stale_connector_events_require_review_for_both_protocols(): void
    {
        foreach (['1.6', '2.0.1'] as $protocol) {
            $f = $this->fixture($protocol);
            $this->source($f, 'stale_connector');
            $this->assertSame(1, Artisan::call('csms:pilot-probe', ['binding' => $f['binding']]));
            $report = json_decode(trim(Artisan::output()), true, 32, JSON_THROW_ON_ERROR);
            $this->assertSame('requires_source_review', $report['status']);
            $this->assertFalse($report['software_source_observed']);
            $this->assertSame(0, $report['connectors_with_recent_status']);
            $this->assertFalse($report['physical_hardware_accepted']);
            Http::assertSentCount(3);
        }
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

    public function test_unresolved_local_maintenance_operation_quarantines_an_otherwise_idle_source(): void
    {
        $f = $this->fixture();
        $this->source($f);
        DB::table('charger_reset_operations')->insert([
            'binding_id' => $f['binding'], 'actor_id' => $f['owner']->id,
            'idempotency_key' => 'synthetic-held-reset',
            'payload_hash' => str_repeat('a', 64),
            'reason' => Crypt::encryptString('synthetic operator hold'),
            'reset_type' => 'Soft', 'status' => 'outcome_unknown',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertSame(1, Artisan::call('csms:pilot-probe', ['binding' => $f['binding']]));
        $report = json_decode(trim(Artisan::output()), true, 32, JSON_THROW_ON_ERROR);
        $this->assertSame('requires_source_review', $report['status']);
        $this->assertTrue($report['pending_local_charging_or_maintenance_review']);
        $this->assertFalse($report['software_source_observed']);
        $this->assertFalse($report['device_commands_sent']);
        $this->assertDatabaseCount('charger_reset_operations', 1);
        $this->assertDatabaseCount('charger_commands', 0);
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    public function test_imminent_reservation_holds_software_source_even_without_an_active_transaction(): void
    {
        $f = $this->fixture();
        $this->source($f);
        Reservation::create([
            'user_id' => User::factory()->create(['role' => 'customer'])->id,
            'station_id' => $f['station']->id,
            'starts_at' => now()->addMinutes(4)->format('Y-m-d H:i:s'),
            'idempotency_key' => 'prepilot-booking',
        ]);
        $this->assertSame(1, Artisan::call('csms:pilot-probe', ['binding' => $f['binding']]));
        $report = json_decode(trim(Artisan::output()), true, 32, JSON_THROW_ON_ERROR);
        $this->assertTrue($report['pending_local_charging_or_maintenance_review']);
        $this->assertFalse($report['software_source_observed']);
        $this->assertFalse($report['device_commands_sent']);
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    public function test_uncertain_start_command_blocks_read_only_source_success(): void
    {
        $f = $this->fixture();
        $this->source($f);
        ChargerCommand::create([
            'actor_id' => User::factory()->create(['role' => 'customer'])->id,
            'station_id' => $f['station']->id, 'action' => 'start',
            'payload' => [], 'payload_hash' => str_repeat('b', 64),
            'consent_auth_epoch' => 0, 'idempotency_key' => 'pilot-outcome-unknown',
            'status' => 'outcome_unknown',
        ]);
        $this->assertSame(1, Artisan::call('csms:pilot-probe', ['binding' => $f['binding']]));
        $report = json_decode(trim(Artisan::output()), true, 32, JSON_THROW_ON_ERROR);
        $this->assertSame('requires_source_review', $report['status']);
        $this->assertTrue($report['pending_local_charging_or_maintenance_review']);
        $this->assertFalse($report['software_source_observed']);
        $this->assertFalse($report['device_commands_sent']);
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    public function test_wrong_source_connector_target_blocks_matching_count_evidence_for_both_protocols(): void
    {
        foreach (['1.6', '2.0.1'] as $protocol) {
            $f = $this->fixture($protocol);
            $this->source($f, 'source_target_wrong');
            $this->assertSame(1, Artisan::call('csms:pilot-probe', [
                'binding' => $f['binding'],
            ]));
            $report = json_decode(trim(Artisan::output()), true, 32, JSON_THROW_ON_ERROR);
            $this->assertTrue($report['source_connector_count_matches_local_topology']);
            $this->assertFalse($report['source_connector_targets_match_local_topology']);
            $this->assertFalse($report['software_source_observed']);
            $this->assertFalse($report['physical_topology_verified']);
            $this->assertDatabaseCount('charger_commands', 0);
        }
    }

    public function test_removed_canonical_mapping_never_counts_as_source_ready(): void
    {
        $f = $this->fixture();
        $this->source($f, 'removed_mapping');
        $this->assertSame(1, Artisan::call('csms:pilot-probe', ['binding' => $f['binding']]));
        $report = json_decode(trim(Artisan::output()), true, 32, JSON_THROW_ON_ERROR);
        $this->assertFalse($report['source_connector_count_matches_local_topology']);
        $this->assertFalse($report['software_source_observed']);
        $this->assertDatabaseCount('charger_commands', 0);
    }

    public function test_invalid_source_identity_clocks_and_connector_events_fail_closed(): void
    {
        foreach (['ambiguous', 'object_station', 'wrong_vendor', 'wrong_model', 'no_firmware', 'relative_clock', 'unknown_connector', 'unknown_status', 'source_target_duplicate', 'source_target_changed', 'source_target_string', 'source_evse_wrong', 'overflow', 'reversed_clock', 'duplicate_event', 'reassigned', 'suspended_owner', 'model_changed', 'maintenance', 'station_firmware_changed', 'station_clock_regressed', 'station_identity_replaced', 'http_503', 'graphql_errors', 'future_station_clock', 'future_status_clock', 'multiple_active'] as $case) {
            $f = $this->fixture();
            $this->source($f, $case);
            $this->assertSame(1, Artisan::call('csms:pilot-probe', ['binding' => $f['binding']]));
            $this->assertStringContainsString('Source pilot probe blocked', Artisan::output());
            $this->assertStringNotContainsString(str_repeat('a', 64), Artisan::output());
            $this->assertStringNotContainsString('PRIVATE', Artisan::output());
            Http::assertNotSent(fn ($request) => str_contains($request->url(), '/dev/token')
                || str_contains($request->url(), '/ocpp/'));
        }
        $this->assertDatabaseCount('charger_commands', 0);
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    public function test_expired_vendor_license_never_causes_source_reads_or_claims_source_ready(): void
    {
        $f = $this->fixture();
        $f['company']->update([
            'license_expires_on' => now()->subDay()->toDateString(),
        ]);
        $this->source($f);
        $this->assertSame(1, Artisan::call('csms:pilot-probe', [
            'binding' => $f['binding'],
        ]));
        Http::assertNothingSent();
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
