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
use App\Services\Charging\SourceConsumer;
use App\Services\CsmsDispatcher;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ManagedChargingGateTest extends TestCase
{
    use RefreshDatabase;

    private function inventory(): array
    {
        $owner = User::factory()->create(['role' => 'vendor']);
        $customer = User::factory()->create();
        $station = Station::create(['vendor_id' => $owner->id, 'name' => 'Managed gate fixture', 'country' => 'AE', 'currency' => 'AED', 'timezone' => 'Asia/Dubai', 'latitude' => 25, 'longitude' => 55, 'source' => 'simulator', 'connector_type' => 'CCS2', 'power_kw' => 50]);
        $company = VendorCompany::create(['owner_id' => $owner->id, 'legal_name' => 'Synthetic company', 'country' => 'AE', 'trade_license_number' => 'PRIVATE-SYNTHETIC', 'license_expires_on' => now()->addYear()->toDateString(), 'status' => 'approved']);
        $site = VendorSite::create(['company_id' => $company->id, 'station_id' => $station->id, 'name' => 'Synthetic site', 'country' => 'AE', 'timezone' => 'Asia/Dubai', 'address' => 'Synthetic address', 'latitude' => 25, 'longitude' => 55]);
        $charger = VendorCharger::create(['company_id' => $company->id, 'site_id' => $site->id, 'asset_reference' => 'SYNTHETIC-1', 'manufacturer' => 'Synthetic', 'model' => 'Fixture', 'source' => 'simulator', 'ocpp_protocol' => '1.6']);
        $connector = VendorConnector::create(['charger_id' => $charger->id, 'connector_number' => 1, 'connector_type' => 'CCS2', 'max_power_kw' => 50]);

        return [$owner, $customer, $station, $company, $charger, $connector];
    }

    public static function unavailable(): array
    {
        return array_map(fn ($reason) => [$reason], ['suspended', 'expired', 'ambiguous', 'unverified_source', 'wrong_type', 'insufficient_power', 'missing_connector']);
    }

    #[DataProvider('unavailable')]
    public function test_unavailable_or_ambiguous_inventory_is_not_bookable_and_leaks_no_private_reasons(string $reason): void
    {
        [$owner, $customer, $station, $company, $charger, $connector] = $this->inventory();
        match ($reason) {
            'suspended' => $company->update(['status' => 'suspended']),
            'expired' => $company->update(['license_expires_on' => now()->subDay()->toDateString()]),
            'ambiguous' => VendorConnector::create(['charger_id' => $charger->id, 'connector_number' => 2, 'connector_type' => 'CCS2', 'max_power_kw' => 50]),
            'unverified_source' => $charger->update(['source' => 'partner_unverified']),
            'wrong_type' => $connector->update(['connector_type' => 'Type2']),
            'insufficient_power' => $connector->update(['max_power_kw' => 22]),
            'missing_connector' => $connector->delete(),
        };
        $this->getJson('/api/stations?q=Managed')->assertOk()->assertJsonPath('data.0.reservable', false)->assertJsonPath('data.0.availability', 'unknown')->assertJsonPath('data.0.reservation_policy', 'managed_blocked')->assertJsonMissing(['trade_license_number' => 'PRIVATE-SYNTHETIC'])->assertJsonMissingPath('data.0.company');
        $this->actingAs($customer)->postJson('/api/reservations', ['station_id' => $station->id, 'starts_at' => now()->startOfHour()->addHour()->format('Y-m-d\TH:i:s\Z'), 'idempotency_key' => 'gate-blocked'])->assertConflict();
        $this->assertDatabaseCount('reservations', 0);
    }

    public function test_maintenance_blocks_new_admission_but_preserves_replay_and_safe_stop(): void
    {
        [$owner, $customer, $station, $company, $charger, $connector] = $this->inventory();
        $start = CarbonImmutable::now()->startOfHour()->addHour();
        $body = ['station_id' => $station->id, 'starts_at' => $start->format('Y-m-d\TH:i:s\Z'), 'idempotency_key' => 'gate-booking'];
        $booking = $this->actingAs($customer)->postJson('/api/reservations', $body)->assertCreated()->json();
        $url = '/api/vendor-ops/companies/'.$company->id.'/chargers/'.$charger->id.'/connectors/'.$connector->id.'/maintenance';
        $this->actingAs($owner)->patchJson($url, ['maintenance_blocked' => true, 'maintenance_reason' => 'PRIVATE-MAINTENANCE'])->assertOk();
        $this->getJson('/api/stations?q=Managed')->assertJsonPath('data.0.reservable', false)->assertJsonMissing(['maintenance_reason' => 'PRIVATE-MAINTENANCE']);
        $this->actingAs($customer)->postJson('/api/reservations', $body)->assertOk()->assertJsonPath('id', $booking['id']);
        $this->travelTo($start->addMinutes(5));
        $this->postJson('/api/reservations/'.$booking['id'].'/simulate-start')->assertConflict();
        $this->assertDatabaseCount('charging_sessions', 0);
        $this->actingAs($owner)->patchJson($url, ['maintenance_blocked' => false, 'maintenance_reason' => 'Synthetic recovery'])->assertOk();
        $session = $this->actingAs($customer)->postJson('/api/reservations/'.$booking['id'].'/simulate-start')->assertCreated()->json();
        $company->update(['status' => 'suspended']);
        $this->actingAs($owner)->patchJson($url, ['maintenance_blocked' => true, 'maintenance_reason' => 'Suspended for maintenance'])->assertOk();
        $this->actingAs($customer)->postJson('/api/reservations/'.$booking['id'].'/simulate-start')->assertOk()->assertJsonPath('id', $session['id']);
        $this->postJson('/api/simulations/'.$session['id'].'/stop')->assertOk()->assertJsonPath('status', 'stopped');
        $this->assertDatabaseCount('charging_sessions', 1);
    }

    private function pending(): array
    {
        config(['csms.session_bridge_enabled' => true, 'csms.simulator_enabled' => true]);
        Http::preventStrayRequests();
        [$owner, $customer, $station, $company] = $this->inventory();
        $start = CarbonImmutable::now()->startOfHour();
        $this->travelTo($start->addMinutes(5));
        $booking = Reservation::create(['station_id' => $station->id, 'user_id' => $customer->id, 'starts_at' => $start->format('Y-m-d H:i:s'), 'idempotency_key' => 'pending-cancellation']);
        DB::table('csms_bindings')->insert(['station_id' => $station->id, 'identifier' => 'ev-test-pending-cancel', 'protocol' => '1.6', 'created_at' => now(), 'updated_at' => now()]);
        $queued = $this->actingAs($customer)->postJson('/api/reservations/'.$booking->id.'/csms-start')->assertAccepted()->json();

        return [$customer, $station, $company, $booking, $queued];
    }

    public function test_provably_undispatched_start_can_cancel_without_faking_source_end_and_releases_binding(): void
    {
        [$customer, $station, $company, $booking, $queued] = $this->pending();
        $company->update(['status' => 'suspended']);
        $this->deleteJson('/api/reservations/'.$booking->id)->assertOk()->assertJsonPath('status', 'cancelled');
        $this->deleteJson('/api/reservations/'.$booking->id)->assertOk();
        $link = SessionLink::findOrFail($queued['link']['id']);
        $this->assertNotNull($link->closed_without_dispatch_at);
        $this->assertNull($link->ended_at);
        $this->assertNull($link->transaction_id);
        $this->assertSame('cancelled', ChargingSession::findOrFail($link->charging_session_id)->status);
        $this->assertSame('cancelled', app(CsmsDispatcher::class)->dispatch($queued['start_command']['id'])->status);
        $this->assertSame(0, app(SourceConsumer::class)->consume($link));
        Http::assertNothingSent();
        $this->assertDatabaseCount('finance_invoices', 0);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'reservation.cancelled')->count());
        $company->update(['status' => 'approved']);
        $next = CarbonImmutable::parse($booking->starts_at, 'UTC')->addMinutes(30);
        $new = $this->postJson('/api/reservations', ['station_id' => $station->id, 'starts_at' => $next->format('Y-m-d\TH:i:s\Z'), 'idempotency_key' => 'after-cancel'])->assertCreated()->json();
        $this->travelTo($next->addMinutes(5));
        $this->postJson('/api/reservations/'.$new['id'].'/csms-start')->assertAccepted();
        $this->assertSame(2, ChargerCommand::count());
    }

    public static function admittedStates(): array
    {
        return [['dispatching'], ['submitted'], ['outcome_unknown'], ['rejected']];
    }

    #[DataProvider('admittedStates')]
    public function test_admitted_or_uncertain_start_cannot_be_cancelled_as_undispatched(string $status): void
    {
        [$customer, $station, $company, $booking, $queued] = $this->pending();
        ChargerCommand::whereKey($queued['start_command']['id'])->update(['status' => $status]);
        $this->deleteJson('/api/reservations/'.$booking->id)->assertConflict();
        $this->assertSame('confirmed', $booking->fresh()->status);
        $this->assertNull(SessionLink::findOrFail($queued['link']['id'])->closed_without_dispatch_at);
    }

    public function test_bound_admission_and_dispatch_recheck_company_policy_window_and_target(): void
    {
        config(['csms.session_bridge_enabled' => true, 'csms.simulator_enabled' => true]);
        Http::preventStrayRequests();
        [$owner, $customer, $station, $company, $charger, $connector] = $this->inventory();
        $start = CarbonImmutable::now()->startOfHour();
        $this->travelTo($start->addMinutes(5));
        $booking = Reservation::create(['station_id' => $station->id, 'user_id' => $customer->id, 'starts_at' => $start->format('Y-m-d H:i:s'), 'idempotency_key' => 'bound-gate']);
        DB::table('csms_bindings')->insert(['station_id' => $station->id, 'identifier' => 'ev-test-company-gate', 'protocol' => '2.0.1', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($customer)->postJson('/api/reservations/'.$booking->id.'/csms-start')->assertConflict();
        $this->assertDatabaseCount('charger_commands', 0);
        $charger->update(['ocpp_protocol' => '2.0.1']);
        $connector->update(['connector_number' => 2]);
        $this->postJson('/api/reservations/'.$booking->id.'/csms-start')->assertConflict();
        $connector->update(['connector_number' => 1]);
        $queued = $this->postJson('/api/reservations/'.$booking->id.'/csms-start')->assertAccepted()->json();
        $commandId = $queued['start_command']['id'];
        $company->update(['status' => 'suspended']);
        $blocked = app(CsmsDispatcher::class)->dispatch($commandId);
        $this->assertSame('pending', $blocked->status);
        $this->assertSame('pre_dispatch_company_gate', $blocked->failure_code);
        $this->getJson('/api/simulations')->assertOk()->assertJsonPath('data.0.start_admission_blocked', true)->assertJsonPath('data.0.can_cancel_before_dispatch', true);
        Http::assertNothingSent();
        $company->update(['status' => 'approved']);
        $this->travelTo($start->addMinutes(35));
        $this->assertSame('pre_dispatch_window_unavailable', app(CsmsDispatcher::class)->dispatch($commandId)->failure_code);
        Http::assertNothingSent();
        $this->travelTo($start->addMinutes(10));
        Http::fake(['*/dev/token' => Http::response(['accessToken' => 'synthetic-test-only']), '*/ocpp/*' => Http::response([['success' => true]])]);
        $this->assertSame('submitted', app(CsmsDispatcher::class)->dispatch($commandId)->status);
        app(CsmsDispatcher::class)->dispatch($commandId);
        Http::assertSentCount(2); // Token + one actual command, never a replay.
        $this->assertSame(1, ChargerCommand::count());
        $this->assertSame('pending_start', ChargingSession::first()->status);
    }
}
