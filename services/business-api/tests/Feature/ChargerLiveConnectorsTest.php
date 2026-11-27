<?php

namespace Tests\Feature;

use App\Http\Controllers\ChargerLiveConnectorsController;
use App\Http\Controllers\ChargerResetController;
use App\Models\Charging\AdmissionProfile;
use App\Models\Charging\ConnectorMapping;
use App\Models\Charging\Evse;
use App\Models\Station;
use App\Models\User;
use App\Models\VendorOps\VendorCharger;
use App\Models\VendorOps\VendorCompany;
use App\Models\VendorOps\VendorConnector;
use App\Models\VendorOps\VendorSite;
use App\Services\Charging\RemoteSessionAuthorization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ChargerLiveConnectorsTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(string $protocol = '1.6'): array
    {
        Http::preventStrayRequests();
        config([
            'csms.session_bridge_enabled' => true,
            'csms.graphql_secret' => str_repeat('x', 64),
            'csms.graphql_url' => 'https://source.test/graphql',
        ]);
        $owner = User::factory()->create(['role' => 'vendor']);
        $station = Station::create([
            'vendor_id' => $owner->id, 'name' => 'Live source simulator',
            'country' => 'AE', 'currency' => 'AED', 'timezone' => 'Asia/Dubai',
            'latitude' => 25, 'longitude' => 55, 'source' => 'simulator',
            'connector_type' => 'CCS2', 'power_kw' => 50,
        ]);
        $company = VendorCompany::create([
            'owner_id' => $owner->id, 'legal_name' => 'Synthetic owner',
            'country' => 'AE', 'trade_license_number' => 'SYNTHETIC',
            'license_expires_on' => now()->addYear()->toDateString(),
            'status' => 'approved',
        ]);
        $site = VendorSite::create([
            'company_id' => $company->id, 'station_id' => $station->id,
            'name' => 'Local', 'country' => 'AE', 'timezone' => 'Asia/Dubai',
            'address' => 'Synthetic', 'latitude' => 25, 'longitude' => 55,
        ]);
        $charger = VendorCharger::create([
            'company_id' => $company->id, 'site_id' => $site->id,
            'asset_reference' => 'LIVE-'.$protocol, 'manufacturer' => 'Synthetic',
            'model' => 'Fixture', 'source' => 'simulator', 'ocpp_protocol' => $protocol,
        ]);
        $binding = DB::table('csms_bindings')->insertGetId([
            'station_id' => $station->id, 'vendor_charger_id' => $charger->id,
            'identifier' => 'ev-test-live-'.$charger->id, 'protocol' => $protocol,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $evse = Evse::create([
            'charger_id' => $charger->id, 'number' => 1, 'mode' => 'csms',
            'binding_id' => $binding,
            'source_evse_number' => $protocol === '1.6' ? 0 : 1,
        ]);
        for ($number = 1; $number <= 2; $number++) {
            $connector = VendorConnector::create([
                'charger_id' => $charger->id, 'connector_number' => $number,
                'connector_type' => 'CCS2', 'max_power_kw' => 50,
            ]);
            ConnectorMapping::create([
                'station_id' => $station->id, 'evse_id' => $evse->id,
                'connector_id' => $connector->id,
                'source_connector_number' => $number,
                'source_target_key' => hash('sha256', $binding.':'.($protocol === '1.6' ? 0 : 1).':'.$number),
            ]);
        }

        return [
            'owner' => $owner, 'company' => $company, 'charger' => $charger,
            'station' => $station,
            'url' => '/api/vendor-ops/companies/'.$company->id.'/chargers/'.$charger->id.'/live-connectors',
            'protocol' => $protocol,
        ];
    }

    private function registeredFixture(string $protocol): array
    {
        $f = $this->fixture($protocol);
        $f['station']->update(['source' => 'direct_unverified']);
        $f['charger']->update(['source' => 'direct_unverified']);
        config(['csms.registered_live_monitoring_enabled' => true, 'csms.session_bridge_enabled' => false]);
        DB::table('charging_connector_mappings')->where('station_id', $f['station']->id)->delete();
        Evse::where('charger_id', $f['charger']->id)->delete();
        $binding = DB::table('csms_bindings')->where('vendor_charger_id', $f['charger']->id)->first();
        $identity = 'DIRECT-'.$f['charger']->id;
        DB::table('csms_bindings')->where('id', $binding->id)->update(['identifier' => $identity]);
        $profile = AdmissionProfile::create(['charger_id' => $f['charger']->id, 'identity' => $identity, 'protocol' => $protocol]);
        $generation = hash('sha256', 'citrineos-v2.1.0-tenant-1|'.config('csms.graphql_url'));
        $payload = hash('sha256', json_encode([$f['company']->id, $f['owner']->id,
            $f['charger']->id, $f['station']->id, $identity, $protocol,
            $f['charger']->manufacturer, $f['charger']->model, $generation], JSON_THROW_ON_ERROR));
        $admin = User::factory()->create(['role' => 'admin']);
        $f['receipt'] = DB::table('charger_source_registrations')->insertGetId([
            'charger_id' => $f['charger']->id, 'binding_id' => $binding->id, 'profile_id' => $profile->id,
            'actor_id' => $admin->id, 'idempotency_key' => 'direct-live-test', 'payload_hash' => $payload,
            'source_generation' => $generation, 'state' => 'registered', 'source_station_id' => 42,
            'source_receipt_hash' => hash('sha256', 'synthetic-registered-source'), 'verified_at' => now(),
            'attempted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        return $f;
    }

    public static function directSourceCases(): array
    {
        $cases = [];
        foreach (['1.6', '2.0.1'] as $protocol) {
            foreach (['healthy', 'disabled', 'plaintext', 'unregistered', 'wrong_station',
                'changed_generation', 'receipt_changed_during_read', 'missing_event', 'foreign_owner'] as $case) {
                $cases[] = [$protocol, $case];
            }
        }
        return $cases;
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('directSourceCases')]
    public function test_registered_direct_source_inspection_is_scoped_tls_read_only_and_never_a_mapping(string $protocol, string $case): void
    {
        $f = $this->registeredFixture($protocol);
        if ($case === 'disabled') {
            config(['csms.registered_live_monitoring_enabled' => false]);
        } elseif ($case === 'plaintext') {
            config(['csms.graphql_url' => 'http://127.0.0.1:8095/v1/graphql']);
        } elseif ($case === 'unregistered') {
            DB::table('charger_source_registrations')->where('id', $f['receipt'])->update(['state' => 'prepared']);
        } elseif ($case === 'wrong_station') {
            DB::table('charger_source_registrations')->where('id', $f['receipt'])->update(['source_station_id' => 43]);
        } elseif ($case === 'changed_generation') {
            config(['csms.graphql_url' => 'https://replacement.test/graphql']);
        }
        $this->source($f, $case);
        $actor = $case === 'foreign_owner' ? User::factory()->create(['role' => 'vendor']) : $f['owner'];
        $response = $this->actingAs($actor)->getJson($f['url']);
        if ($case === 'healthy' || $case === 'missing_event') {
            $response->assertOk()->assertJsonPath('evidence', 'REGISTERED_DIRECT_SOURCE')
                ->assertJsonPath('source_topology_complete', $case === 'healthy')
                ->assertJsonPath('source_topology_matches_local', false)->assertJsonPath('local_mapping_verified', false)
                ->assertJsonPath('connectors.0.mapping_id', null)->assertJsonPath('connectors.0.operator_maintenance_blocked', null)
                ->assertJsonPath('connectors.0.state', $case === 'healthy' ? 'recent' : 'unknown')
                ->assertJsonPath('connectors.0.reported_status', $case === 'healthy' ? 'Available' : null)
                ->assertJsonPath('physical_availability_verified', false)
                ->assertJsonPath('remote_control_authorized', false)->assertJsonPath('commands_sent', false);
            Http::assertSentCount(3);
        } else {
            $response->assertStatus(match ($case) {
                'disabled', 'plaintext' => 503, 'foreign_owner' => 404, default => 409,
            });
            if (! in_array($case, ['wrong_station', 'receipt_changed_during_read'], true)) {
                Http::assertNothingSent();
            }
        }
        Http::assertNotSent(fn ($request) => str_contains($request['query'], 'mutation'));
        $this->assertDatabaseCount('charging_connector_mappings', 0);
        $this->assertDatabaseCount('charger_commands', 0);
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    private function source(array $fixture, string $case = 'healthy', string $endpoint = 'https://source.test/graphql'): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        $stationReads = 0;
        Http::fake(function ($request) use ($fixture, $case, $endpoint, &$stationReads) {
            $this->assertSame($endpoint, $request->url());
            $this->assertSame(str_repeat('x', 64), $request->header('x-hasura-admin-secret')[0] ?? null);
            if (str_contains($request['query'], 'ChargingStations')) {
                $stationReads++;
                $row = [
                    'id' => 42, 'protocol' => $fixture['protocol'],
                    'isOnline' => $case !== 'offline',
                    'chargePointVendor' => 'Synthetic', 'chargePointModel' => 'Fixture',
                    'firmwareVersion' => 'sim-fw',
                    'latestOcppMessageTimestamp' => $case === 'stale'
                        ? now()->subMinutes(10)->toISOString() : now()->toISOString(),
                ];
                if ($stationReads === 2 && $case === 'firmware_changed') {
                    $row['firmwareVersion'] = 'unreviewed-fw';
                }
                if ($stationReads === 2 && $case === 'disconnected') {
                    $row['isOnline'] = false;
                }
                if ($case === 'ambiguous_source') {
                    return Http::response(['data' => ['ChargingStations' => [$row, $row]]]);
                }
                if ($stationReads === 2 && $case === 'source_identity_disappeared') {
                    return Http::response(['data' => ['ChargingStations' => []]]);
                }
                if ($case === 'malformed_source_id') {
                    $row['id'] = '42';
                }
                if ($case === 'source_endpoint_changed') {
                    config(['csms.graphql_url' => 'https://replacement.test/graphql']);
                }

                return Http::response(['data' => ['ChargingStations' => [$row]]]);
            }
            if ($case === 'receipt_changed_during_read') {
                DB::table('charger_source_registrations')->where('id', $fixture['receipt'])
                    ->update(['source_receipt_hash' => hash('sha256', 'changed-receipt')]);
            }
            if ($case === 'source_secret_changed') {
                config(['csms.graphql_secret' => str_repeat('r', 64)]);
            }
            if ($case === 'bearer_revoked_during_read') {
                $fixture['owner']->tokens()->delete();
            }
            if ($case === 'revoke_during_read') {
                DB::table('users')->where('id', $fixture['owner']->id)->increment('auth_epoch');
            }
            if ($case === 'model_changed_during_read') {
                DB::table('vendor_chargers')->where('id', $fixture['charger']->id)
                    ->update(['model' => 'Unexpected changed model']);
            }
            if ($case === 'mapping_changed_during_read') {
                $first = DB::table('charging_connector_mappings')
                    ->where('station_id', $fixture['station']->id)->min('id');
                DB::table('charging_connector_mappings')->where('id', $first)
                    ->update(['source_target_key' => str_repeat('e', 64)]);
            }
            $events = [
                [
                    'id' => 12, 'connectorDatabaseId' => 8,
                    'evseId' => $fixture['protocol'] === '1.6' ? 0 : 1,
                    'connectorId' => 2, 'connectorStatus' => $fixture['protocol'] === '1.6' ? 'Charging' : 'Occupied',
                    'errorCode' => null, 'timestamp' => now()->subSeconds(30)->toISOString(),
                ],
                [
                    'id' => 11, 'connectorDatabaseId' => 7,
                    'evseId' => $fixture['protocol'] === '1.6' ? 0 : 1,
                    'connectorId' => 1, 'connectorStatus' => 'Available',
                    'errorCode' => 'NoError', 'timestamp' => now()->subMinute()->toISOString(),
                ],
            ];
            if ($case === 'evse_null') {
                $events[1]['evseId'] = null;
            }
            if ($case === 'evse_missing') {
                unset($events[1]['evseId']);
            }
            if ($case === 'evse_string') {
                $events[1]['evseId'] = (string) $events[1]['evseId'];
            }
            if ($case === 'evse_wrong_protocol') {
                $events[1]['evseId'] = $fixture['protocol'] === '1.6' ? 1 : 0;
            }
            if ($case === 'evse_negative') {
                $events[1]['evseId'] = -1;
            }
            if ($case === 'unknown_connector') {
                $events[0]['connectorDatabaseId'] = 999;
            }
            if ($case === 'wrong_target') {
                $events[0]['connectorId'] = 3;
            }
            if ($case === 'duplicate_target') {
                $events[0]['connectorId'] = 1;
            }
            if ($case === 'clock_regressed') {
                $events[] = [
                    'id' => 10, 'connectorDatabaseId' => 7,
                    'evseId' => $fixture['protocol'] === '1.6' ? 0 : 1,
                    'connectorId' => 1, 'connectorStatus' => 'Available',
                    'errorCode' => 'NoError',
                    'timestamp' => now()->subSeconds(10)->toISOString(),
                ];
            }
            if ($case === 'contradictory_same_timestamp') {
                $events[] = [
                    'id' => 10, 'connectorDatabaseId' => 7,
                    'evseId' => $fixture['protocol'] === '1.6' ? 0 : 1,
                    'connectorId' => 1, 'connectorStatus' => 'Faulted',
                    'errorCode' => 'GroundFailure',
                    'timestamp' => $events[1]['timestamp'],
                ];
            }
            if (in_array($case, ['deep_clock_regressed', 'deep_clock_contradiction', 'deep_history_valid'], true)) {
                // An older pair must be validated even though both times
                // precede the latest status of connector 1. Interleave
                // connector 2 to ensure the chronology is target-specific.
                $older = now()->subMinutes(3);
                $events[] = array_replace($events[1], [
                    'id' => 10, 'timestamp' => $older->toISOString(),
                ]);
                $events[] = array_replace($events[0], [
                    'id' => 9, 'timestamp' => now()->subMinutes(2)->toISOString(),
                ]);
                $events[] = array_replace($events[1], [
                    'id' => 8,
                    'timestamp' => match ($case) {
                        'deep_clock_regressed' => $older->copy()->addSecond()->toISOString(),
                        'deep_clock_contradiction' => $older->toISOString(),
                        default => $older->copy()->subSecond()->toISOString(),
                    },
                    'connectorStatus' => $case === 'deep_clock_contradiction' ? 'Faulted' : 'Available',
                    'errorCode' => $case === 'deep_clock_contradiction' ? 'GroundFailure' : 'NoError',
                ]);
            }
            if ($case === 'wrong_protocol_status') {
                $events[0]['connectorStatus'] = $fixture['protocol'] === '1.6' ? 'Occupied' : 'Charging';
            }
            if ($case === 'invalid_status') {
                $events[1]['connectorStatus'] = 'DISPATCH_AUTHORIZED';
            }
            if ($case === 'missing_ocpp16_error') {
                $events[1]['errorCode'] = null;
            }
            if ($case === 'fault_code') {
                $events[1]['errorCode'] = 'GroundFailure';
            }
            if ($case === 'source_stale_event') {
                $events[0]['timestamp'] = now()->subMinutes(12)->toISOString();
                $events[1]['timestamp'] = now()->subMinutes(13)->toISOString();
            }
            if ($case === 'future_status') {
                $events[1]['timestamp'] = now()->addMinutes(10)->toISOString();
            }
            if ($case === 'relative_status') {
                $events[1]['timestamp'] = 'now';
            }
            if ($case === 'source_error') {
                return Http::response(['errors' => [['message' => 'PRIVATE_SOURCE_ERROR']]]);
            }
            if ($case === 'events_unavailable') {
                return Http::response(['data' => ['Connectors' => [], 'StatusNotifications' => null]]);
            }

            if ($case === 'missing_event') {
                array_shift($events);
            }
            return Http::response(['data' => [
                'Connectors' => [['id' => 7], ['id' => 8]],
                'StatusNotifications' => $events,
            ]]);
        });
    }

    public function test_read_only_live_connector_statuses_are_scoped_and_not_physical_for_both_protocols(): void
    {
        foreach (['1.6', '2.0.1'] as $protocol) {
            $f = $this->fixture($protocol);
            $this->source($f);
            $this->actingAs($f['owner'])->getJson($f['url'])->assertOk()
                ->assertJsonPath('evidence', 'SIMULATED_CSMS_SOURCE')
                ->assertJsonPath('protocol', $protocol)
                ->assertJsonPath('source_topology_matches_local', true)
                ->assertJsonPath('source_identity_rechecked', true)
                ->assertJsonPath('physical_availability_verified', false)
                ->assertJsonPath('remote_control_authorized', false)
                ->assertJsonPath('commands_sent', false)
                ->assertJsonCount(2, 'connectors')
                ->assertJsonPath('connectors.0.source_connector_number', 1)
                ->assertJsonPath('connectors.0.reported_status', 'Available')
                ->assertJsonPath('connectors.0.state', 'recent')
                ->assertJsonPath('connectors.0.physical_available', false)
                ->assertJsonPath('connectors.1.reported_status', $protocol === '1.6' ? 'Charging' : 'Occupied');
            Http::assertSentCount(3);
            $this->assertDatabaseCount('charger_commands', 0);
            $this->assertDatabaseCount('finance_invoices', 0);
            $this->assertDatabaseCount('charging_status_observations', 0);
        }
    }

    public function test_live_connector_projection_requires_protocol_exact_evse_identity(): void
    {
        foreach (['1.6', '2.0.1'] as $protocol) {
            $f = $this->fixture($protocol);
            foreach (['evse_missing', 'evse_string', 'evse_wrong_protocol', 'evse_negative', 'evse_null'] as $case) {
                $this->source($f, $case);
                $response = $this->actingAs($f['owner'])->getJson($f['url']);
                if ($protocol === '1.6' && $case === 'evse_null') {
                    $response->assertOk()
                        ->assertJsonPath('source_topology_matches_local', true)
                        ->assertJsonPath('connectors.0.source_evse_number', 0)
                        ->assertJsonPath('connectors.0.reported_status', 'Available')
                        ->assertJsonPath('connectors.0.physical_available', false)
                        ->assertJsonPath('commands_sent', false);
                    Http::assertSentCount(3);
                } else {
                    $response->assertConflict()->assertDontSee('remote_control_authorized');
                    Http::assertSentCount(2);
                }
            }
        }
        $this->assertDatabaseCount('charger_commands', 0);
        $this->assertDatabaseCount('finance_invoices', 0);
        $this->assertDatabaseCount('charging_status_observations', 0);
    }

    public function test_known_local_charging_graphql_compose_host_allows_authenticated_read_only_source(): void
    {
        $f = $this->fixture('2.0.1');
        $endpoint = 'http://charging-graphql:8080/v1/graphql';
        config(['csms.graphql_url' => $endpoint]);
        $this->source($f, endpoint: $endpoint);
        $this->actingAs($f['owner'])->getJson($f['url'])->assertOk()
            ->assertJsonPath('source_topology_matches_local', true)
            ->assertJsonPath('connectors.0.state', 'recent')
            ->assertJsonPath('connectors.0.reported_status', 'Available')
            ->assertJsonPath('connectors.0.physical_available', false)
            ->assertJsonPath('remote_control_authorized', false)
            ->assertJsonPath('commands_sent', false);
        Http::assertSentCount(3);
        Http::assertSent(fn ($request) => $request->url() === $endpoint
            && ($request->header('x-hasura-admin-secret')[0] ?? null) === str_repeat('x', 64));
        $this->assertDatabaseCount('charger_commands', 0);
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    public function test_ocpp201_charging_status_from_old_protocol_is_not_a_valid_source_receipt(): void
    {
        $f = $this->fixture('2.0.1');
        $this->source($f, 'wrong_protocol_status');
        $this->actingAs($f['owner'])->getJson($f['url'])->assertConflict()
            ->assertDontSee('remote_control_authorized');
        $this->assertDatabaseCount('charger_commands', 0);
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    public function test_ocpp16_available_without_noerror_is_unknown_not_recent_or_physical(): void
    {
        $f = $this->fixture('1.6');
        $this->source($f, 'missing_ocpp16_error');
        $this->actingAs($f['owner'])->getJson($f['url'])->assertOk()
            ->assertJsonPath('source_topology_matches_local', true)
            ->assertJsonPath('connectors.0.state', 'unknown')
            ->assertJsonPath('connectors.0.reported_status', null)
            ->assertJsonPath('connectors.0.physical_available', false)
            ->assertJsonPath('remote_control_authorized', false)
            ->assertJsonPath('commands_sent', false);
        Http::assertSentCount(3);
        $this->assertDatabaseCount('charger_commands', 0);
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    public function test_operator_maintenance_block_never_reinterprets_a_recent_source_as_physical_availability(): void
    {
        $f = $this->fixture('2.0.1');
        DB::table('vendor_connectors')->where('charger_id', $f['charger']->id)
            ->where('connector_number', 1)->update(['maintenance_blocked' => true]);
        $this->source($f);
        $this->actingAs($f['owner'])->getJson($f['url'])->assertOk()
            ->assertJsonPath('source_topology_matches_local', true)
            ->assertJsonPath('connectors.0.state', 'recent')
            ->assertJsonPath('connectors.0.reported_status', 'Available')
            ->assertJsonPath('connectors.0.operator_maintenance_blocked', true)
            ->assertJsonPath('connectors.0.physical_available', false)
            ->assertJsonPath('connectors.0.remote_control_authorized', false)
            ->assertJsonPath('commands_sent', false);
        $this->assertDatabaseCount('charger_commands', 0);
    }

    public function test_fault_code_overrides_healthy_report_for_both_protocols(): void
    {
        foreach (['1.6', '2.0.1'] as $protocol) {
            $f = $this->fixture($protocol);
            $this->source($f, 'fault_code');
            $this->actingAs($f['owner'])->getJson($f['url'])->assertOk()
                ->assertJsonPath('connectors.0.state', 'recent')
                ->assertJsonPath('connectors.0.reported_status', 'Faulted')
                ->assertJsonPath('connectors.0.error_code', 'GroundFailure')
                ->assertJsonPath('connectors.0.physical_available', false)
                ->assertJsonPath('commands_sent', false);
            $this->assertDatabaseCount('charger_commands', 0);
        }
    }

    public function test_offline_stale_or_unmatched_source_never_promotes_connector_availability(): void
    {
        foreach (['offline', 'stale', 'disconnected', 'wrong_target', 'source_stale_event'] as $state) {
            $f = $this->fixture();
            $this->source($f, $state);
            $result = $this->actingAs($f['owner'])->getJson($f['url'])->assertOk()
                ->assertJsonPath('physical_availability_verified', false)
                ->assertJsonPath('commands_sent', false);
            if (in_array($state, ['offline', 'stale', 'disconnected'], true)) {
                $this->assertFalse($result->json('source_online'));
            }
            foreach ($result->json('connectors') as $connector) {
                $this->assertNotSame('recent', $connector['state']);
                $this->assertNull($connector['reported_status']);
                $this->assertFalse($connector['remote_control_authorized']);
            }
            $this->assertDatabaseCount('charger_commands', 0);
        }
    }

    public function test_tampered_id_event_order_status_or_station_identity_fail_closed_without_leaking_source(): void
    {
        foreach (['unknown_connector', 'duplicate_target', 'clock_regressed',
            'invalid_status', 'wrong_protocol_status', 'firmware_changed', 'revoke_during_read',
            'model_changed_during_read', 'contradictory_same_timestamp',
            'mapping_changed_during_read', 'ambiguous_source',
            'source_identity_disappeared', 'malformed_source_id',
            'future_status', 'relative_status', 'source_error',
            'events_unavailable'] as $state) {
            $f = $this->fixture();
            $this->source($f, $state);
            $response = $this->actingAs($f['owner'])->getJson($f['url']);
            $this->assertContains($response->status(), [403, 409, 503]);
            $response->assertDontSee(str_repeat('x', 64));
            $response->assertDontSee('PRIVATE_SOURCE_ERROR');
            $this->assertDatabaseCount('charger_commands', 0);
            $this->assertDatabaseCount('finance_invoices', 0);
        }
    }

    public function test_all_retained_connector_status_pairs_require_consistent_chronology_for_both_protocols(): void
    {
        foreach (['1.6', '2.0.1'] as $protocol) {
            $f = $this->fixture($protocol);
            foreach (['deep_clock_regressed', 'deep_clock_contradiction'] as $case) {
                $this->source($f, $case);
                $this->actingAs($f['owner'])->getJson($f['url'])->assertConflict()
                    ->assertDontSee('remote_control_authorized');
            }
            $this->source($f, 'deep_history_valid');
            $this->actingAs($f['owner'])->getJson($f['url'])->assertOk()
                ->assertJsonPath('source_topology_matches_local', true)
                ->assertJsonPath('connectors.0.state', 'recent')
                ->assertJsonPath('connectors.0.reported_status', 'Available')
                ->assertJsonPath('connectors.1.reported_status', $protocol === '1.6' ? 'Charging' : 'Occupied')
                ->assertJsonPath('physical_availability_verified', false)
                ->assertJsonPath('commands_sent', false);
            $this->assertDatabaseCount('charging_status_observations', 0);
            $this->assertDatabaseCount('charger_commands', 0);
            $this->assertDatabaseCount('finance_invoices', 0);
        }
    }

    public function test_live_connector_inspection_cannot_mix_source_authorities(): void
    {
        foreach (['1.6', '2.0.1'] as $protocol) {
            $fixture = $this->fixture($protocol);
            foreach (['source_endpoint_changed' => 1, 'source_secret_changed' => 2] as $case => $requests) {
                config(['csms.graphql_url' => 'https://source.test/graphql', 'csms.graphql_secret' => str_repeat('x', 64)]);
                $this->source($fixture, $case);
                $this->actingAs($fixture['owner'])->getJson($fixture['url'])->assertServiceUnavailable()
                    ->assertJsonMissingPath('connectors');
                Http::assertSentCount($requests);
            }
        }
        $this->assertDatabaseCount('charger_commands', 0);
        $this->assertDatabaseCount('charging_sessions', 0);
    }

    public function test_captured_bearer_revocation_fences_live_connector_disclosure_for_both_protocols(): void
    {
        foreach (['1.6', '2.0.1'] as $protocol) {
            $f = $this->fixture($protocol);
            $token = $f['owner']->createToken('live-fleet', ['*'], now()->addHour())->accessToken;
            $snapshot = $f['owner']->fresh()->withAccessToken($token);
            $request = Request::create($f['url'], 'GET');
            $request->setUserResolver(fn () => $snapshot);
            $read = fn () => app(ChargerLiveConnectorsController::class)(
                $request, $f['company'], $f['charger'], app(ChargerResetController::class), app(RemoteSessionAuthorization::class)
            );
            $this->source($f);
            $this->assertSame(200, $read()->getStatusCode());
            Http::assertSentCount(3);

            $this->source($f, 'bearer_revoked_during_read');
            try {
                $read();
                $this->fail('A revoked captured token received live source evidence.');
            } catch (HttpException $error) {
                $this->assertSame(403, $error->getStatusCode());
            }
            Http::assertSentCount(3);

            $this->source($f);
            try {
                $read();
                $this->fail('A revoked captured token initiated source reads.');
            } catch (HttpException $error) {
                $this->assertSame(403, $error->getStatusCode());
            }
            Http::assertNothingSent();
            $this->assertDatabaseCount('charger_commands', 0);
            $this->assertDatabaseCount('finance_invoices', 0);
            $this->assertDatabaseCount('charging_status_observations', 0);
        }
    }

    public function test_missing_canonical_target_digest_prevents_any_source_request(): void
    {
        $f = $this->fixture();
        $this->source($f);
        DB::table('charging_connector_mappings')->where('station_id', $f['station']->id)
            ->update(['source_target_key' => null]);
        $this->actingAs($f['owner'])->getJson($f['url'])->assertConflict();
        Http::assertNothingSent();
        $this->assertDatabaseCount('charger_commands', 0);
    }

    public function test_rejects_privileged_source_secrets_over_external_plaintext_urls(): void
    {
        $f = $this->fixture();
        $this->source($f);
        foreach ([
            'http://source.test/graphql',
            'http://charging-graphql:8080/other-path',
            'http://charging-graphql.evil.test:8080/v1/graphql',
            'http://user:secret@source.test/graphql',
            'https://user:secret@source.test/graphql',
            'https://source.test/graphql?secret=unsafe',
            'file:///private/secret',
        ] as $url) {
            config(['csms.graphql_url' => $url]);
            $this->actingAs($f['owner'])->getJson($f['url'])->assertServiceUnavailable()
                ->assertDontSee('secret');
            Http::assertNothingSent();
        }
        $this->assertDatabaseCount('charger_commands', 0);
    }

    public function test_unrelated_vendor_and_production_environment_have_no_live_source_access(): void
    {
        $f = $this->fixture();
        $this->source($f);
        $this->actingAs(User::factory()->create(['role' => 'vendor']))
            ->getJson($f['url'])->assertNotFound();
        Http::assertNothingSent();
        $old = app()->environment();
        try {
            app()->detectEnvironment(fn () => 'production');
            $this->actingAs($f['owner'])->getJson($f['url'])->assertServiceUnavailable();
            Http::assertNothingSent();
        } finally {
            app()->detectEnvironment(fn () => $old);
        }
    }
}
