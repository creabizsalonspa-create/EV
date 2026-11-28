<?php

namespace Tests\Feature;

use App\Models\Charging\ConnectorMapping;
use App\Models\Charging\Evse;
use App\Models\Station;
use App\Models\User;
use App\Models\VendorOps\VendorCharger;
use App\Models\VendorOps\VendorCompany;
use App\Models\VendorOps\VendorConnector;
use App\Models\VendorOps\VendorSite;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
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

    private function source(array $fixture, string $case = 'healthy', string $endpoint = 'https://source.test/graphql', ?Closure $duringRequest = null): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        $stationReads = 0;
        Http::fake(function ($request) use ($fixture, $case, $endpoint, $duringRequest, &$stationReads) {
            $this->assertSame($endpoint, $request->url());
            $this->assertSame(str_repeat('x', 64), $request->header('x-hasura-admin-secret')[0] ?? null);
            $duringRequest?->__invoke();
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

                return Http::response(['data' => ['ChargingStations' => [$row]]]);
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

    public static function sourceChanges(): array
    {
        return [
            '1.6 source origin changes during station read' => ['1.6', 1, 'csms.graphql_url', 'https://replacement.test/graphql'],
            '1.6 key changes during connector read' => ['1.6', 2, 'csms.graphql_secret', str_repeat('y', 64)],
            '1.6 bridge disabled during source recheck' => ['1.6', 3, 'csms.session_bridge_enabled', false],
            '2.0.1 key changes during station read' => ['2.0.1', 1, 'csms.graphql_secret', str_repeat('y', 64)],
            '2.0.1 bridge disabled during connector read' => ['2.0.1', 2, 'csms.session_bridge_enabled', false],
            '2.0.1 source origin changes during source recheck' => ['2.0.1', 3, 'csms.graphql_url', 'https://replacement.test/graphql'],
        ];
    }

    #[DataProvider('sourceChanges')]
    public function test_live_connector_projection_requires_one_stable_authenticated_source(string $protocol, int $afterRead, string $key, string|bool $value): void
    {
        $f = $this->fixture($protocol);
        $reads = 0;
        $this->source($f, duringRequest: function () use (&$reads, $afterRead, $key, $value) {
            if (++$reads === $afterRead) {
                config([$key => $value]);
            }
        });

        $this->actingAs($f['owner'])->getJson($f['url'])->assertServiceUnavailable()
            ->assertJsonMissingPath('connectors')->assertDontSee('replacement.test')->assertDontSee(str_repeat('y', 64));
        Http::assertSentCount($afterRead);
        $this->assertDatabaseCount('charger_commands', 0);
        $this->assertDatabaseCount('finance_invoices', 0);
        $this->assertDatabaseCount('charging_status_observations', 0);
    }

    public function test_bearer_revoked_during_the_source_read_cannot_receive_the_projection(): void
    {
        $f = $this->fixture('2.0.1');
        $token = $f['owner']->createToken('live connector status');
        $reads = 0;
        $this->source($f, duringRequest: function () use (&$reads, $token) {
            if (++$reads === 2) {
                $token->accessToken->delete();
            }
        });

        $path = str_replace('/api/', '/api/v1/', $f['url']);
        $this->withToken($token->plainTextToken)->getJson($path)->assertForbidden()
            ->assertJsonMissingPath('connectors');
        Http::assertSentCount(3);
        $this->assertDatabaseCount('charger_commands', 0);
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
