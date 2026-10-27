<?php

namespace Tests\Feature;

use App\Models\ChargerCommand;
use App\Models\Charging\SessionLink;
use App\Models\ChargingSession;
use App\Models\Reservation;
use App\Models\Station;
use App\Models\User;
use App\Models\VendorOps\VendorCharger;
use App\Models\VendorOps\VendorCompany;
use App\Models\VendorOps\VendorConnector;
use App\Models\VendorOps\VendorSite;
use App\Services\Charging\SessionBridge;
use App\Services\CsmsDispatcher;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ConnectorTopologyTest extends TestCase
{
    use RefreshDatabase;

    private function topology(?string $protocol = null): array
    {
        config(['csms.session_bridge_enabled' => true, 'csms.simulator_enabled' => true]);
        Http::preventStrayRequests();
        $owner = User::factory()->create(['role' => 'vendor']);
        $customer = User::factory()->create();
        $station = Station::create(['vendor_id' => $owner->id, 'name' => 'Canonical fixture', 'country' => 'AE', 'currency' => 'AED', 'timezone' => 'Asia/Dubai', 'latitude' => 25, 'longitude' => 55, 'source' => 'simulator', 'connector_type' => 'CCS2', 'power_kw' => 50]);
        $company = VendorCompany::create(['owner_id' => $owner->id, 'legal_name' => 'Synthetic topology', 'country' => 'AE', 'trade_license_number' => 'PRIVATE', 'license_expires_on' => now()->addYear()->toDateString(), 'status' => 'approved']);
        $site = VendorSite::create(['company_id' => $company->id, 'station_id' => $station->id, 'name' => 'Canonical site', 'country' => 'AE', 'timezone' => 'Asia/Dubai', 'address' => 'Synthetic', 'latitude' => 25, 'longitude' => 55]);
        $charger = VendorCharger::create(['company_id' => $company->id, 'site_id' => $site->id, 'asset_reference' => 'CANONICAL', 'manufacturer' => 'Synthetic', 'model' => 'Fixture', 'source' => 'simulator', 'ocpp_protocol' => $protocol]);
        $base = '/api/vendor-ops/companies/'.$company->id.'/chargers/'.$charger->id;
        $binding = $protocol ? DB::table('csms_bindings')->insertGetId(['station_id' => $station->id, 'vendor_charger_id' => $charger->id, 'identifier' => 'ev-test-canonical', 'protocol' => $protocol, 'created_at' => now(), 'updated_at' => now()]) : null;
        $evses = [];
        $maps = [];
        $connectors = [];
        for ($i = 1; $i <= 2; $i++) {
            $evses[] = $this->actingAs($owner)->postJson($base.'/evses', ['number' => $i, 'mode' => $protocol ? 'csms' : 'application', 'binding_id' => $binding, 'source_evse_number' => $protocol === '2.0.1' ? $i + 4 : 0])->assertCreated()->json();
        }
        for ($i = 1; $i <= 3; $i++) {
            $connector = VendorConnector::create(['charger_id' => $charger->id, 'connector_number' => $i, 'connector_type' => 'CCS2', 'max_power_kw' => 50]);
            $connectors[] = $connector;
            $evse = $evses[$i === 3 ? 1 : 0];
            $maps[] = $this->postJson($base.'/evses/'.$evse['id'].'/connectors/'.$connector->id.'/mapping', ['source_connector_number' => $protocol ? $i + 6 : null])->assertCreated()->json();
        }
        $this->actingAs($customer);

        return compact('owner', 'customer', 'station', 'company', 'charger', 'connectors', 'evses', 'maps', 'base', 'binding');
    }

    private function book(array $f, int $index, string $key): array
    {
        return ['station_id' => $f['station']->id, 'connector_mapping_id' => $f['maps'][$index]['id'], 'starts_at' => now()->startOfHour()->addHour()->format('Y-m-d\TH:i:s\Z'), 'idempotency_key' => $key];
    }

    public function test_parallel_evse_bookings_shared_connector_capacity_and_payload_bound_replay(): void
    {
        $f = $this->topology();
        $body = $this->book($f, 0, 'first');
        $r = $this->postJson('/api/reservations', $body)->assertCreated()->json();
        $this->postJson('/api/reservations', $this->book($f, 2, 'parallel'))->assertCreated();
        $this->postJson('/api/reservations', $this->book($f, 1, 'sibling'))->assertConflict();
        $this->postJson('/api/reservations', array_replace($body, ['connector_mapping_id' => $f['maps'][1]['id']]))->assertConflict();
        $f['connectors'][0]->update(['maintenance_blocked' => true]);
        $this->postJson('/api/reservations', $body)->assertOk()->assertJsonPath('id', $r['id']);
        $without = $this->book($f, 2, 'missing');
        unset($without['connector_mapping_id']);
        $this->postJson('/api/reservations', $without)->assertConflict();
        $this->deleteJson('/api/reservations/'.$r['id'])->assertOk();
        $this->postJson('/api/reservations', $this->book($f, 1, 'reuse'))->assertCreated();
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    public function test_public_choices_do_not_leak_sources_or_maintenance_reasons_and_scope_remains_enforced(): void
    {
        $f = $this->topology('2.0.1');
        $f['connectors'][0]->update(['maintenance_blocked' => true, 'maintenance_reason' => 'PRIVATE-MAINTENANCE']);
        $r = $this->getJson('/api/stations/'.$f['station']->id.'/connectors')->assertOk()->assertJsonPath('data.0.reservable', false)->assertJsonPath('data.2.reservable', true)->assertJsonPath('data.2.evse_number', 2);
        foreach (['PRIVATE', 'ev-test-canonical', 'binding_id', 'source_target_key'] as $secret) {
            $this->assertStringNotContainsString($secret, $r->getContent());
        }
        $this->getJson('/api/stations?q=Canonical')->assertJsonPath('data.0.reservation_policy', 'canonical')->assertJsonPath('data.0.reservable', false);
        $this->postJson($f['base'].'/evses', ['number' => 9, 'mode' => 'application', 'source_evse_number' => 0])->assertNotFound();
        $this->postJson('/api/reservations', $this->book($f, 0, 'blocked'))->assertConflict();
        $f['company']->update(['status' => 'suspended']);
        $this->postJson('/api/reservations', $this->book($f, 2, 'suspended'))->assertConflict();
    }

    public function test_application_sessions_are_parallel_across_evses_but_unresolved_prior_slot_blocks_same_evse(): void
    {
        $f = $this->topology();
        $start = now()->startOfHour()->addHour();
        $one = $this->postJson('/api/reservations', $this->book($f, 0, 'a'))->assertCreated()->json();
        $two = $this->postJson('/api/reservations', $this->book($f, 2, 'b'))->assertCreated()->json();
        $later = $this->book($f, 1, 'later');
        $later['starts_at'] = $start->copy()->addMinutes(30)->format('Y-m-d\TH:i:s\Z');
        $three = $this->postJson('/api/reservations', $later)->assertCreated()->json();
        $this->travelTo($start->copy()->addMinutes(1));
        $this->postJson('/api/reservations/'.$one['id'].'/simulate-start')->assertCreated();
        $this->postJson('/api/reservations/'.$two['id'].'/simulate-start')->assertCreated();
        $this->travelTo($start->copy()->addMinutes(31));
        $this->postJson('/api/reservations/'.$three['id'].'/simulate-start')->assertConflict();
        $this->assertSame(2, ChargingSession::where('status', 'charging')->count());
    }

    public static function protocols(): array
    {
        return [['1.6'], ['2.0.1']];
    }

    #[DataProvider('protocols')]
    public function test_csms_dispatch_targets_selected_source_and_parallel_evses(string $protocol): void
    {
        $f = $this->topology($protocol);
        $start = now()->startOfHour()->addHour();
        $bookings = [];
        foreach ([0, 2] as $i) {
            $bookings[] = $this->postJson('/api/reservations', $this->book($f, $i, 'mapped-'.$i))->assertCreated()->json();
        }
        $this->travelTo($start->addMinute());
        Http::fake(['*/dev/token' => Http::response(['accessToken' => 'synthetic']), '*/ocpp/*' => Http::response([['success' => true]])]);
        foreach ($bookings as $index => $booking) {
            $this->postJson('/api/reservations/'.$booking['id'].'/simulate-start')->assertConflict();
            $queued = $this->postJson('/api/reservations/'.$booking['id'].'/csms-start')->assertAccepted()->json();
            $this->assertSame('submitted', app(CsmsDispatcher::class)->dispatch($queued['start_command']['id'])->status);
            $expected = $protocol === '1.6' ? ($index === 0 ? 7 : 9) : ($index === 0 ? 5 : 6);
            Http::assertSent(fn ($r) => str_contains($r->url(), '/ocpp/') && $r[$protocol === '1.6' ? 'connectorId' : 'evseId'] === $expected);
            $this->postJson('/api/reservations/'.$booking['id'].'/simulate-start')->assertOk()->assertJsonPath('id', $queued['session']['id']);
        }
        $this->assertDatabaseCount('csms_session_links', 2);
        Http::assertSentCount(4);
    }

    public function test_source_inbox_requires_the_exact_canonical_target_before_accepting_energy(): void
    {
        $f = $this->topology('1.6');
        $start = now()->startOfHour()->addHour();
        $booking = $this->postJson('/api/reservations', $this->book($f, 0, 'source'))->assertCreated()->json();
        $this->travelTo($start->addMinute());
        $queued = $this->postJson('/api/reservations/'.$booking['id'].'/csms-start')->assertAccepted()->json();
        $event = ['event_id' => 'target-proof', 'session_id' => $queued['session']['id'], 'identifier' => 'ev-test-canonical', 'protocol' => '1.6', 'transaction_id' => '333', 'type' => 'started', 'sequence' => 0, 'meter_wh' => 1000, 'occurred_at' => now()->toISOString(), 'source_evse_number' => 0, 'source_connector_number' => 9];
        try {
            app(SessionBridge::class)->ingest($event);
            $this->fail('Wrong source must not be accepted.');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        $this->assertDatabaseCount('csms_inbox_events', 0);
        $event['source_connector_number'] = 7;
        app(SessionBridge::class)->ingest($event);
        $this->assertSame('charging', ChargingSession::findOrFail($queued['session']['id'])->status);
    }

    public function test_activation_preserves_legacy_bookings_and_rejects_uncorrelated_outbox_history(): void
    {
        $f = $this->topology();
        DB::table('charging_connector_mappings')->delete();
        $legacy = Reservation::create(['user_id' => $f['customer']->id, 'station_id' => $f['station']->id, 'starts_at' => now()->addHour()->format('Y-m-d H:i:s'), 'idempotency_key' => 'legacy']);
        $url = $f['base'].'/evses/'.$f['evses'][0]['id'].'/connectors/'.$f['connectors'][0]->id.'/mapping';
        $this->actingAs($f['owner'])->postJson($url, [])->assertConflict();
        $this->assertNull($legacy->fresh()->connector_mapping_id);
        $this->actingAs($f['customer'])->deleteJson('/api/reservations/'.$legacy->id)->assertOk();
        $command = ChargerCommand::create(['actor_id' => $f['owner']->id, 'station_id' => $f['station']->id, 'action' => 'start', 'idempotency_key' => 'legacy-direct', 'payload_hash' => str_repeat('a', 64), 'payload' => []]);
        $this->actingAs($f['owner'])->postJson($url, [])->assertConflict();
        $command->update(['status' => 'cancelled']);
        $this->postJson($url, [])->assertCreated();
        $this->postJson($url, [])->assertConflict();
    }

    public function test_multiple_charge_points_use_their_own_source_binding_not_first_station_match(): void
    {
        $f = $this->topology('2.0.1');
        $charger = VendorCharger::create(['company_id' => $f['company']->id, 'site_id' => $f['charger']->site_id, 'asset_reference' => 'SECOND', 'manufacturer' => 'Synthetic', 'model' => 'Fixture', 'source' => 'simulator', 'ocpp_protocol' => '2.0.1']);
        $admin = User::factory()->create(['role' => 'admin']);
        $binding = $this->actingAs($admin)->postJson('/api/stations/'.$f['station']->id.'/csms-binding', ['identifier' => 'ev-test-second', 'protocol' => '2.0.1', 'vendor_charger_id' => $charger->id])->assertCreated()->json();
        $base = '/api/vendor-ops/companies/'.$f['company']->id.'/chargers/'.$charger->id;
        $evse = $this->actingAs($f['owner'])->postJson($base.'/evses', ['number' => 1, 'mode' => 'csms', 'binding_id' => $binding['id'], 'source_evse_number' => 8])->assertCreated()->json();
        $this->postJson($base.'/evses', ['number' => 2, 'mode' => 'csms', 'binding_id' => $binding['id'], 'source_evse_number' => 8])->assertConflict();
        $connector = VendorConnector::create(['charger_id' => $charger->id, 'connector_number' => 1, 'connector_type' => 'CCS2', 'max_power_kw' => 50]);
        $map = $this->postJson($base.'/evses/'.$evse['id'].'/connectors/'.$connector->id.'/mapping', ['source_connector_number' => 2])->assertCreated()->json();
        $body = $this->book($f, 0, 'second-source');
        $body['connector_mapping_id'] = $map['id'];
        $booking = $this->actingAs($f['customer'])->postJson('/api/reservations', $body)->assertCreated()->json();
        $this->travelTo(CarbonImmutable::parse($body['starts_at'])->addMinute());
        $queued = $this->postJson('/api/reservations/'.$booking['id'].'/csms-start')->assertAccepted()->json();
        Http::fake(['*/dev/token' => Http::response(['accessToken' => 'synthetic']), '*/ocpp/*' => Http::response([['success' => true]])]);
        $this->assertSame('submitted', app(CsmsDispatcher::class)->dispatch($queued['start_command']['id'])->status);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'identifier=ev-test-second') && $r['evseId'] === 8);
        $this->assertSame($binding['id'], SessionLink::findOrFail($queued['link']['id'])->binding_id);
    }

    public function test_catalog_filters_use_actual_mapped_connector_capabilities(): void
    {
        $f = $this->topology();
        foreach ($f['connectors'] as $connector) {
            $connector->update(['connector_type' => 'Type2', 'max_power_kw' => 22]);
        }
        $this->getJson('/api/stations?q=Canonical&connector_type=Type2&min_power_kw=22')->assertOk()->assertJsonPath('total', 1);
        $this->getJson('/api/stations?q=Canonical&connector_type=CCS2')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/stations?q=Canonical&min_power_kw=50')->assertOk()->assertJsonPath('total', 0);
        $f['connectors'][2]->update(['connector_type' => 'CCS2', 'max_power_kw' => 100]);
        $this->getJson('/api/stations?q=Canonical&connector_type=Type2&min_power_kw=50')->assertOk()->assertJsonPath('total', 0);
    }
}
