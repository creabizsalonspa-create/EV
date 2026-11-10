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
