<?php

namespace Tests\Feature;

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
        config(['csms.safe_reset_enabled' => true, 'csms.session_bridge_enabled' => true, 'csms.graphql_secret' => str_repeat('x', 64), 'csms.graphql_url' => 'http://source.test/graphql']);
        $owner = User::factory()->create(['role' => 'vendor']);
        $station = Station::create(['vendor_id' => $owner->id, 'name' => 'Reset fixture', 'country' => 'AE', 'currency' => 'AED', 'timezone' => 'Asia/Dubai', 'latitude' => 25, 'longitude' => 55, 'source' => 'simulator', 'connector_type' => 'CCS2', 'power_kw' => 50]);
        $company = VendorCompany::create(['owner_id' => $owner->id, 'legal_name' => 'Synthetic reset', 'country' => 'AE', 'trade_license_number' => 'SYNTHETIC', 'license_expires_on' => now()->addYear()->toDateString(), 'status' => 'approved']);
        $site = VendorSite::create(['company_id' => $company->id, 'station_id' => $station->id, 'name' => 'Fixture', 'country' => 'AE', 'timezone' => 'Asia/Dubai', 'address' => 'Synthetic', 'latitude' => 25, 'longitude' => 55]);
        $charger = VendorCharger::create(['company_id' => $company->id, 'site_id' => $site->id, 'asset_reference' => 'RESET', 'manufacturer' => 'Synthetic', 'model' => 'Fixture', 'source' => 'simulator', 'ocpp_protocol' => $protocol]);
        $binding = DB::table('csms_bindings')->insertGetId(['station_id' => $station->id, 'vendor_charger_id' => $charger->id, 'identifier' => 'ev-test-reset-fixture-'.$station->id, 'protocol' => $protocol, 'created_at' => now(), 'updated_at' => now()]);
        $path = '/api/vendor-ops/companies/'.$company->id.'/chargers/'.$charger->id.'/reset-operations';

        return compact('owner', 'station', 'company', 'charger', 'binding', 'path', 'protocol');
    }

    private function source(array $f, bool $active = false, bool $uncertain = false, ?string $statusTime = null): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(function ($r) use ($f, $active, $uncertain, $statusTime) {
            if ($r->url() === 'http://source.test/graphql') {
                if (str_contains($r['query'], 'ChargingStations')) {
                    return Http::response(['data' => ['ChargingStations' => [['id' => 9, 'isOnline' => true, 'protocol' => $f['protocol']]]]]);
                }

                return Http::response(['data' => ['Transactions' => $active ? [['id' => 1]] : [], 'Connectors' => [['id' => 7]], 'StatusNotifications' => [['connectorDatabaseId' => 7, 'connectorStatus' => 'Available', 'timestamp' => $statusTime ?? now()->toISOString()]]]]);
            }
            if (str_ends_with($r->url(), '/dev/token')) {
                return Http::response(['accessToken' => 'synthetic-source-token']);
            }

            return Http::response($uncertain ? ['message' => 'PRIVATE TRANSPORT FAILURE'] : [['success' => true]], $uncertain ? 503 : 200);
        });
    }

    public function test_both_safe_reset_types_are_one_shot_and_do_not_release_capacity(): void
    {
        foreach (['1.6', '2.0.1'] as $protocol) {
            $f = $this->fixture($protocol);
            $this->source($f);
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
                'connector_duplicate', 'status_object', 'status_string_connector', 'status_missing_time', 'active_transaction'] as $kind) {
                $out[$protocol.'_'.$kind] = [$protocol, $kind];
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
            $this->assertSame('http://source.test/graphql', $r->url());
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
            $status = ['connectorDatabaseId' => 7, 'connectorStatus' => 'Available',
                'timestamp' => now()->toISOString()];
            if ($kind === 'status_string_connector') {
                $status['connectorDatabaseId'] = '7';
            }
            if ($kind === 'status_missing_time') {
                unset($status['timestamp']);
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
                    $this->assertSame('http://source.test/graphql', $request->url());
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
                            ['connectorDatabaseId' => 8, 'connectorStatus' => $unregisteredStatus, 'timestamp' => now()->toISOString()],
                            ['connectorDatabaseId' => 7, 'connectorStatus' => 'Available', 'timestamp' => now()->subSecond()->toISOString()],
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
                if ($request->url() === 'http://source.test/graphql') {
                    if (str_contains($request['query'], 'ChargingStations')) {
                        return Http::response(['data' => ['ChargingStations' => [
                            ['id' => 9, 'isOnline' => true, 'protocol' => $f['protocol']],
                        ]]]);
                    }

                    return Http::response(['data' => [
                        'Transactions' => [],
                        'Connectors' => [['id' => 7], ['id' => 8]],
                        'StatusNotifications' => [
                            ['connectorDatabaseId' => 8, 'connectorStatus' => 'Available', 'timestamp' => now()->toISOString()],
                            ['connectorDatabaseId' => 7, 'connectorStatus' => 'Available', 'timestamp' => now()->subSecond()->toISOString()],
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
            foreach (['same_instant_conflict', 'reverse_source_clock'] as $kind) {
                $f = $this->fixture($protocol);
                Http::swap(new Factory);
                Http::preventStrayRequests();
                Http::fake(function ($r) use ($f, $kind) {
                    $this->assertSame('http://source.test/graphql', $r->url());
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
                            ['connectorDatabaseId' => 7, 'connectorStatus' => 'Available', 'timestamp' => $first],
                            ['connectorDatabaseId' => 7, 'connectorStatus' => 'Faulted', 'timestamp' => $second],
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
