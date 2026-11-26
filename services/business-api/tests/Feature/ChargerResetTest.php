<?php

namespace Tests\Feature;

use App\Models\ChargerCommand;
use App\Models\Station;
use App\Models\User;
use App\Models\VendorOps\VendorCharger;
use App\Models\VendorOps\VendorCompany;
use App\Models\VendorOps\VendorSite;
use App\Services\Charging\StationGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ChargerResetTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(string $protocol = '1.6'): array
    {
        config(['csms.safe_reset_enabled' => true, 'csms.session_bridge_enabled' => true, 'csms.graphql_secret' => str_repeat('x', 64), 'csms.graphql_url' => 'http://127.0.0.1:8095/v1/graphql']);
        $owner = User::factory()->create(['role' => 'vendor']);
        $station = Station::create(['vendor_id' => $owner->id, 'name' => 'Reset fixture', 'country' => 'AE', 'currency' => 'AED', 'timezone' => 'Asia/Dubai', 'latitude' => 25, 'longitude' => 55, 'source' => 'simulator', 'connector_type' => 'CCS2', 'power_kw' => 50]);
        $company = VendorCompany::create(['owner_id' => $owner->id, 'legal_name' => 'Synthetic reset', 'country' => 'AE', 'trade_license_number' => 'SYNTHETIC', 'license_expires_on' => now()->addYear()->toDateString(), 'status' => 'approved']);
        $site = VendorSite::create(['company_id' => $company->id, 'station_id' => $station->id, 'name' => 'Fixture', 'country' => 'AE', 'timezone' => 'Asia/Dubai', 'address' => 'Synthetic', 'latitude' => 25, 'longitude' => 55]);
        $charger = VendorCharger::create(['company_id' => $company->id, 'site_id' => $site->id, 'asset_reference' => 'RESET', 'manufacturer' => 'Synthetic', 'model' => 'Fixture', 'source' => 'simulator', 'ocpp_protocol' => $protocol]);
        $binding = DB::table('csms_bindings')->insertGetId(['station_id' => $station->id, 'vendor_charger_id' => $charger->id, 'identifier' => 'ev-test-reset-fixture-'.$station->id, 'protocol' => $protocol, 'created_at' => now(), 'updated_at' => now()]);
        $path = '/api/vendor-ops/companies/'.$company->id.'/chargers/'.$charger->id.'/reset-operations';

        return compact('owner', 'station', 'company', 'charger', 'binding', 'path', 'protocol');
    }

    private function source(array $f, bool $active = false, bool $uncertain = false, ?string $statusTime = null, ?string $faultCode = 'NoError', ?callable $duringToken = null): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(function ($r) use ($f, $active, $uncertain, $statusTime, $faultCode, $duringToken) {
            if ($r->url() === 'http://127.0.0.1:8095/v1/graphql') {
                if (str_contains($r['query'], 'ChargingStations')) {
                    return Http::response(['data' => ['ChargingStations' => [['id' => 9, 'isOnline' => true, 'protocol' => $f['protocol']]]]]);
                }

                return Http::response(['data' => ['Transactions' => $active ? [['id' => 1]] : [], 'Connectors' => [['id' => 7]], 'StatusNotifications' => [['connectorDatabaseId' => 7, 'connectorStatus' => 'Available', 'errorCode' => $faultCode, 'timestamp' => $statusTime ?? now()->toISOString()]]]]);
            }
            if (str_ends_with($r->url(), '/dev/token')) {
                if ($duringToken) {
                    $duringToken();
                }

                return Http::response(['accessToken' => 'synthetic-source-token']);
            }

            return Http::response($uncertain ? ['message' => 'PRIVATE TRANSPORT FAILURE'] : [['success' => true]], $uncertain ? 503 : 200);
        });
    }

    public function test_both_safe_reset_types_are_one_shot_and_do_not_release_capacity(): void
    {
        foreach (['1.6', '2.0.1'] as $protocol) {
            $f = $this->fixture($protocol);
            $this->source($f, faultCode: $protocol === '1.6' ? 'NoError' : null);
            $body = ['reason' => 'PRIVATE operational safety review', 'idempotency_key' => 'reset-'.$protocol];
            $id = $this->actingAs($f['owner'])->postJson($f['path'], $body)->assertCreated()->assertJsonPath('status', 'transport_submitted')->assertJsonPath('capacity_released', false)->json('id');
            $this->postJson($f['path'], $body)->assertOk()->assertJsonPath('id', $id);
            $this->postJson($f['path'], array_replace($body, ['reason' => 'A different request reason']))->assertConflict();
            Http::assertSentCount(6); // Two preflights, OAuth and exactly one write.
            Http::assertSent(fn ($r) => str_contains($r->url(), '/configuration/reset?') && $r['type'] === ($protocol === '1.6' ? 'Soft' : 'OnIdle'));
            $this->assertStringNotContainsString('PRIVATE', DB::table('charger_reset_operations')->where('id', $id)->value('reason'));
            $this->postJson($f['path'].'/'.$id.'/review', ['note' => 'Reviewing source state safely'])->assertConflict();
            $this->travel(21)->seconds();
            $this->postJson($f['path'].'/'.$id.'/review', ['note' => 'Reviewing fresh source state'])->assertOk()->assertJsonPath('charging_sessions_closed', false);
            $this->postJson($f['path'].'/'.$id.'/review', ['note' => 'Reviewing fresh source state'])->assertOk();
            $this->assertSame(1, DB::table('audit_events')->where('subject', (string) $id)->where('action', 'charger.reset_source_reviewed')->count());
        }
    }

    public function test_relative_source_time_neither_dispatches_reset_nor_releases_an_existing_hold(): void
    {
        foreach (['1.6', '2.0.1'] as $protocol) {
            $f = $this->fixture($protocol);
            $this->source($f, statusTime: 'now');
            $body = ['reason' => 'Source timestamp safety review', 'idempotency_key' => 'strict-time-'.$protocol];
            $this->actingAs($f['owner'])->postJson($f['path'], $body)->assertServiceUnavailable();
            Http::assertNotSent(fn ($r) => str_contains($r->url(), '/configuration/reset?'));
            $this->assertSame(0, DB::table('charger_reset_operations')->where('binding_id', $f['binding'])->count());
            $this->source($f);
            $id = $this->postJson($f['path'], $body)->assertCreated()->json('id');
            $this->travel(21)->seconds();
            $this->source($f, statusTime: 'now');
            $this->postJson($f['path'].'/'.$id.'/review', ['note' => 'Malformed source evidence must retain the hold'])->assertServiceUnavailable();
            $this->assertDatabaseHas('charger_reset_operations', ['id' => $id, 'reviewed_at' => null, 'status' => 'transport_submitted']);
            Http::assertNotSent(fn ($r) => str_contains($r->url(), '/configuration/reset?'));
        }
    }

    public static function malformedIdleProjections(): array
    {
        $out = [];
        foreach (['1.6', '2.0.1'] as $protocol) {
            foreach (['station_object', 'station_string_id', 'station_string_online', 'connector_object', 'connector_string_id',
                'connector_duplicate', 'status_object', 'status_string_connector', 'status_missing_time', 'active_transaction', 'fault_code', 'malformed_fault_code'] as $kind) {
                $out[$protocol.'_'.$kind] = [$protocol, $kind];
            }
            if ($protocol === '1.6') {
                $out[$protocol.'_missing_error_code'] = [$protocol, 'missing_error_code'];
            }
        }

        return $out;
    }

    #[DataProvider('malformedIdleProjections')]
    public function test_untrusted_idle_source_projections_never_dispatch_reset(string $protocol, string $kind): void
    {
        $f = $this->fixture($protocol);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(function ($r) use ($f, $kind) {
            $this->assertSame('http://127.0.0.1:8095/v1/graphql', $r->url());
            if (str_contains($r['query'], 'ChargingStations')) {
                $station = ['id' => 9, 'isOnline' => true, 'protocol' => $f['protocol']];
                if ($kind === 'station_string_id') {
                    $station['id'] = '9';
                }
                if ($kind === 'station_string_online') {
                    $station['isOnline'] = 'true';
                }
                $rows = $kind === 'station_object' ? ['station' => $station] : [$station];

                return Http::response(['data' => ['ChargingStations' => $rows]]);
            }
            $connectors = [['id' => 7]];
            if ($kind === 'connector_object') {
                $connectors = ['connector' => $connectors[0]];
            }
            if ($kind === 'connector_string_id') {
                $connectors[0]['id'] = '7';
            }
            if ($kind === 'connector_duplicate') {
                $connectors[] = ['id' => 7];
            }
            $status = ['connectorDatabaseId' => 7, 'connectorStatus' => 'Available', 'errorCode' => 'NoError',
                'timestamp' => now()->toISOString()];
            if ($kind === 'status_string_connector') {
                $status['connectorDatabaseId'] = '7';
            }
            if ($kind === 'status_missing_time') {
                unset($status['timestamp']);
            }
            if ($kind === 'fault_code') {
                $status['errorCode'] = 'GroundFailure';
            } elseif ($kind === 'malformed_fault_code') {
                $status['errorCode'] = ['NoError'];
            } elseif ($kind === 'missing_error_code') {
                unset($status['errorCode']);
            }
            $notifications = $kind === 'status_object' ? ['status' => $status] : [$status];

            return Http::response(['data' => [
                'Transactions' => $kind === 'active_transaction' ? [['id' => 11]] : [],
                'Connectors' => $connectors,
                'StatusNotifications' => $notifications,
            ]]);
        });
        $this->actingAs($f['owner'])->postJson($f['path'], [
            'reason' => 'Reject malformed reset source evidence safely',
            'idempotency_key' => 'source-'.$kind,
        ])->assertStatus(409);
        $this->assertSame(0, DB::table('charger_reset_operations')
            ->where('binding_id', $f['binding'])->count());
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/dev/token')
            || str_contains($request->url(), '/configuration/reset'));
        $this->assertDatabaseCount('charger_commands', 0);
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    public function test_unknown_source_connector_status_never_authorizes_a_reset(): void
    {
        foreach (['1.6', '2.0.1'] as $protocol) {
            foreach (['Available', 'Faulted'] as $unregisteredStatus) {
                $f = $this->fixture($protocol);
                Http::swap(new Factory);
                Http::preventStrayRequests();
                Http::fake(function ($request) use ($f, $unregisteredStatus) {
                    $this->assertSame('http://127.0.0.1:8095/v1/graphql', $request->url());
                    if (str_contains($request['query'], 'ChargingStations')) {
                        return Http::response(['data' => ['ChargingStations' => [
                            ['id' => 9, 'isOnline' => true, 'protocol' => $f['protocol']],
                        ]]]);
                    }

                    return Http::response(['data' => [
                        'Transactions' => [],
                        'Connectors' => [['id' => 7]],
                        // This newer source row was omitted from the current
                        // connector inventory; even Available is not idle proof.
                        'StatusNotifications' => [
                            ['connectorDatabaseId' => 8, 'connectorStatus' => $unregisteredStatus, 'errorCode' => $unregisteredStatus === 'Available' ? 'NoError' : 'GroundFailure', 'timestamp' => now()->toISOString()],
                            ['connectorDatabaseId' => 7, 'connectorStatus' => 'Available', 'errorCode' => 'NoError', 'timestamp' => now()->subSecond()->toISOString()],
                        ],
                    ]]);
                });
                $this->actingAs($f['owner'])->postJson($f['path'], [
                    'reason' => 'Unrecognized source connector requires safety review',
                    'idempotency_key' => 'unknown-'.$unregisteredStatus.'-'.$protocol,
                ])->assertConflict();
                $this->assertSame(0, DB::table('charger_reset_operations')
                    ->where('binding_id', $f['binding'])->count());
                Http::assertNotSent(fn ($request) => str_contains($request->url(), '/dev/token')
                    || str_contains($request->url(), '/configuration/reset'));
                $this->assertDatabaseCount('charger_commands', 0);
                $this->assertDatabaseCount('finance_invoices', 0);
            }
        }
    }

    public function test_complete_two_connector_source_inventory_can_submit_one_safe_reset(): void
    {
        foreach (['1.6', '2.0.1'] as $protocol) {
            $f = $this->fixture($protocol);
            Http::swap(new Factory);
            Http::preventStrayRequests();
            Http::fake(function ($request) use ($f) {
                if ($request->url() === 'http://127.0.0.1:8095/v1/graphql') {
                    if (str_contains($request['query'], 'ChargingStations')) {
                        return Http::response(['data' => ['ChargingStations' => [
                            ['id' => 9, 'isOnline' => true, 'protocol' => $f['protocol']],
                        ]]]);
                    }

                    return Http::response(['data' => [
                        'Transactions' => [],
                        'Connectors' => [['id' => 7], ['id' => 8]],
                        'StatusNotifications' => [
                            ['connectorDatabaseId' => 8, 'connectorStatus' => 'Available', 'errorCode' => 'NoError', 'timestamp' => now()->toISOString()],
                            ['connectorDatabaseId' => 7, 'connectorStatus' => 'Available', 'errorCode' => 'NoError', 'timestamp' => now()->subSecond()->toISOString()],
                        ],
                    ]]);
                }
                if (str_ends_with($request->url(), '/dev/token')) {
                    return Http::response(['accessToken' => 'synthetic-source-token']);
                }

                return Http::response([['success' => true]]);
            });
            $this->actingAs($f['owner'])->postJson($f['path'], [
                'reason' => 'Both source connectors are confirmed safely idle',
                'idempotency_key' => 'both-connectors-'.$protocol,
            ])->assertCreated()->assertJsonPath('status', 'transport_submitted')
                ->assertJsonPath('capacity_released', false);
            Http::assertSentCount(6); // Two typed source preflights, token and exactly one reset.
            Http::assertSent(fn ($request) => str_contains($request->url(), '/configuration/reset?')
                && $request['type'] === ($protocol === '1.6' ? 'Soft' : 'OnIdle'));
        }
    }

    public function test_conflicting_source_status_clocks_cannot_authorize_a_reset(): void
    {
        foreach (['1.6', '2.0.1'] as $protocol) {
            foreach (['same_instant_conflict', 'same_instant_fault_code', 'reverse_source_clock'] as $kind) {
                $f = $this->fixture($protocol);
                Http::swap(new Factory);
                Http::preventStrayRequests();
                Http::fake(function ($r) use ($f, $kind) {
                    $this->assertSame('http://127.0.0.1:8095/v1/graphql', $r->url());
                    if (str_contains($r['query'], 'ChargingStations')) {
                        return Http::response(['data' => [
                            'ChargingStations' => [['id' => 9, 'isOnline' => true, 'protocol' => $f['protocol']]],
                        ]]);
                    }
                    $first = now()->subSeconds($kind === 'reverse_source_clock' ? 30 : 0)->toISOString();
                    $second = now()->toISOString();

                    return Http::response(['data' => [
                        'Transactions' => [],
                        'Connectors' => [['id' => 7]],
                        'StatusNotifications' => [
                            ['connectorDatabaseId' => 7, 'connectorStatus' => 'Available', 'errorCode' => 'NoError', 'timestamp' => $first],
                            ['connectorDatabaseId' => 7, 'connectorStatus' => $kind === 'same_instant_fault_code' ? 'Available' : 'Faulted', 'errorCode' => 'GroundFailure', 'timestamp' => $second],
                        ],
                    ]]);
                });
                $this->actingAs($f['owner'])->postJson($f['path'], [
                    'reason' => 'Quarantine contradictory source connector observations',
                    'idempotency_key' => 'status-'.$kind.'-'.$protocol,
                ])->assertConflict();
                $this->assertSame(0, DB::table('charger_reset_operations')
                    ->where('binding_id', $f['binding'])->count());
                Http::assertNotSent(fn ($request) => str_contains($request->url(), '/dev/token')
                    || str_contains($request->url(), '/configuration/reset'));
                $this->assertDatabaseCount('charger_commands', 0);
                $this->assertDatabaseCount('finance_invoices', 0);
            }
        }
    }

    public function test_fault_receipt_prevents_review_of_existing_reset_hold(): void
    {
        foreach (['1.6', '2.0.1'] as $protocol) {
            $f = $this->fixture($protocol);
            $this->source($f);
            $id = $this->actingAs($f['owner'])->postJson($f['path'], [
                'reason' => 'Review simulator reset source safety',
                'idempotency_key' => 'fault-review-'.$protocol,
            ])->assertCreated()->json('id');
            $this->travel(21)->seconds();
            $this->source($f, faultCode: 'GroundFailure');
            $this->postJson($f['path'].'/'.$id.'/review', ['note' => 'Fault must retain the operation hold'])->assertConflict();
            $this->assertDatabaseHas('charger_reset_operations', ['id' => $id, 'reviewed_at' => null]);
            Http::assertNotSent(fn ($request) => str_contains($request->url(), '/configuration/reset'));
        }
    }

    public function test_operations_origin_change_after_token_blocks_reset_for_both_protocols(): void
    {
        foreach (['1.6', '2.0.1'] as $protocol) {
            $f = $this->fixture($protocol);
            $original = config('csms.operations_base_url');
            $this->source($f, duringToken: fn () => config(['csms.operations_base_url' => 'https://other.example.test']));
            $body = ['reason' => 'Inspect reset token source drift', 'idempotency_key' => 'token-drift-'.$protocol];
            $id = $this->actingAs($f['owner'])->postJson($f['path'], $body)->assertCreated()->assertJsonPath('status', 'outcome_unknown')->json('id');
            config(['csms.operations_base_url' => $original]);
            $this->postJson($f['path'], $body)->assertOk()->assertJsonPath('id', $id);
            Http::assertSentCount(5);
            Http::assertNotSent(fn ($r) => str_contains($r->url(), '/ocpp/') || str_contains($r->url(), 'other.example.test'));
            $this->assertDatabaseHas('charger_reset_operations', ['id' => $id, 'reviewed_at' => null]);
        }
    }

    public function test_unresolved_station_commands_block_reset_even_without_session_links(): void
    {
        foreach (['1.6', '2.0.1'] as $protocol) {
            $f = $this->fixture($protocol);
            $this->source($f);
            $command = ChargerCommand::create([
                'actor_id' => $f['owner']->id, 'station_id' => $f['station']->id,
                'action' => 'start', 'idempotency_key' => 'legacy-hold-'.$protocol,
                'payload_hash' => str_repeat('a', 64), 'payload' => [],
            ]);
            $body = ['reason' => 'Review unresolved station command safely',
                'idempotency_key' => 'blocked-reset-'.$protocol];
            foreach (['pending', 'dispatching', 'submitted', 'outcome_unknown'] as $status) {
                $command->update(['status' => $status]);
                $this->actingAs($f['owner'])->postJson($f['path'], $body)->assertConflict();
                $this->assertDatabaseCount('csms_session_links', 0);
                $this->assertSame(0, DB::table('charger_reset_operations')
                    ->where('binding_id', $f['binding'])->count());
                $this->assertSame($status, $command->fresh()->status);
                Http::assertNothingSent();
            }
            // A terminal rejected command does not create an unresolved hold.
            $command->update(['status' => 'rejected']);
            $this->postJson($f['path'], $body)->assertCreated()
                ->assertJsonPath('status', 'transport_submitted')
                ->assertJsonPath('capacity_released', false);
            $this->assertSame('rejected', $command->fresh()->status);
            $this->assertDatabaseCount('finance_invoices', 0);
            $this->assertDatabaseCount('charging_sessions', 0);
        }
    }

    public function test_new_command_hold_during_source_read_prevents_reset_and_review(): void
    {
        foreach (['1.6', '2.0.1'] as $protocol) {
            $f = $this->fixture($protocol);
            $command = ChargerCommand::create([
                'actor_id' => $f['owner']->id, 'station_id' => $f['station']->id,
                'action' => 'start', 'idempotency_key' => 'source-read-hold-'.$protocol,
                'payload_hash' => str_repeat('b', 64), 'payload' => [], 'status' => 'rejected',
            ]);
            $body = ['reason' => 'Check current command hold after source read',
                'idempotency_key' => 'source-read-reset-'.$protocol];
            $race = function () use ($f, $command): void {
                Http::swap(new Factory);
                Http::preventStrayRequests();
                Http::fake(function ($request) use ($f, $command) {
                    if (str_contains($request['query'], 'ChargingStations')) {
                        return Http::response(['data' => ['ChargingStations' => [
                            ['id' => 9, 'isOnline' => true, 'protocol' => $f['protocol']],
                        ]]]);
                    }
                    // Simulate a retained command becoming uncertain during
                    // the read. The enclosing rejected transaction rolls
                    // back this synthetic change, never an actual wire send.
                    DB::table('charger_commands')->where('id', $command->id)
                        ->update(['status' => 'outcome_unknown']);

                    return Http::response(['data' => [
                        'Transactions' => [], 'Connectors' => [['id' => 7]],
                        'StatusNotifications' => [[
                            'connectorDatabaseId' => 7, 'connectorStatus' => 'Available',
                            'errorCode' => 'NoError', 'timestamp' => now()->toISOString(),
                        ]],
                    ]]);
                });
            };
            $race();
            $this->actingAs($f['owner'])->postJson($f['path'], $body)->assertConflict();
            Http::assertSentCount(2);
            $this->assertSame(0, DB::table('charger_reset_operations')
                ->where('binding_id', $f['binding'])->count());
            $this->assertSame('rejected', $command->fresh()->status);

            $this->source($f);
            $id = $this->postJson($f['path'], $body)->assertCreated()->json('id');
            $this->travel(21)->seconds();
            $race();
            $this->postJson($f['path'].'/'.$id.'/review', ['note' => 'Do not release unresolved source command hold'])
                ->assertConflict();
            Http::assertSentCount(2);
            $this->assertDatabaseHas('charger_reset_operations', [
                'id' => $id, 'reviewed_at' => null, 'status' => 'transport_submitted',
            ]);
            $this->assertSame('rejected', $command->fresh()->status);
            $this->source($f);
            $this->postJson($f['path'].'/'.$id.'/review', ['note' => 'Current idle source and no unresolved command'])
                ->assertOk()->assertJsonPath('charging_sessions_closed', false);
            $this->assertDatabaseCount('finance_invoices', 0);
        }
    }

    public function test_uncertain_reset_is_not_retried_and_blocks_start_admission(): void
    {
        $f = $this->fixture();
        $this->source($f, uncertain: true);
        $body = ['reason' => 'Inspect uncertain simulator reset', 'idempotency_key' => 'uncertain'];
        $this->actingAs($f['owner'])->postJson($f['path'], $body)->assertCreated()->assertJsonPath('status', 'outcome_unknown');
        Http::assertSentCount(6);
        $this->postJson($f['path'], $body)->assertOk()->assertJsonPath('status', 'outcome_unknown');
        Http::assertSentCount(6);
        $this->postJson($f['path'], array_replace($body, ['idempotency_key' => 'new-reset']))->assertConflict();
        $this->expectException(HttpException::class);
        app(StationGate::class)->requireReady($f['station']);
    }

    public function test_foreign_operators_active_source_and_disabled_adapter_cannot_dispatch(): void
    {
        $f = $this->fixture();
        $this->source($f, active: true);
        $body = ['reason' => 'No reset during charging', 'idempotency_key' => 'blocked'];
        $this->actingAs(User::factory()->create(['role' => 'vendor']))->postJson($f['path'], $body)->assertNotFound();
        Http::assertNothingSent();
        $this->actingAs($f['owner'])->postJson($f['path'], $body)->assertConflict();
        $this->assertDatabaseCount('charger_reset_operations', 0);
        config(['csms.safe_reset_enabled' => false]);
        $this->postJson($f['path'], $body)->assertServiceUnavailable();
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/configuration/reset'));
    }
}
