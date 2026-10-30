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
use App\Services\Charging\UnsentStartExpiry;
use App\Services\CsmsDispatcher;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
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

    public function test_expiry_is_exactly_at_window_end_idempotent_audited_and_releases_binding_without_money_or_http(): void
    {
        [$customer, $station, $company, $booking, $queued] = $this->pending();
        $expiry = app(UnsentStartExpiry::class);
        $end = CarbonImmutable::parse($booking->starts_at, 'UTC')->addMinutes(30);
        $this->travelTo($end->subSecond());
        $this->assertSame(0, $expiry->run()['expired']);
        $this->travelTo($end);
        $this->assertSame(1, $expiry->run()['expired']);
        $this->assertSame(0, $expiry->run()['expired']);
        $this->assertSame('expired_unstarted', $booking->fresh()->status);
        $session = ChargingSession::findOrFail($queued['session']['id']);
        $this->assertSame('expired_unstarted', $session->status);
        $this->assertNull($session->stopped_at);
        $this->assertNull($session->total_minor);
        $link = SessionLink::findOrFail($queued['link']['id']);
        $this->assertNotNull($link->closed_without_dispatch_at);
        $this->assertNull($link->ended_at);
        $this->assertNull($link->transaction_id);
        $this->assertSame('reservation_expired_before_dispatch', $link->closure_reason);
        $this->assertDatabaseHas('charging_request_expirations', ['reservation_id' => $booking->id, 'initiator' => 'system_simulator_expiry']);
        $this->assertDatabaseCount('charging_request_expirations', 1);
        $this->assertDatabaseCount('finance_invoices', 0);
        $this->assertSame(0, DB::table('audit_events')->where('action', 'reservation.cancelled')->count());
        $this->assertSame('cancelled', app(CsmsDispatcher::class)->dispatch($queued['start_command']['id'])->status);
        $this->assertSame(0, app(SourceConsumer::class)->consume($link));
        Http::assertNothingSent();
        $this->getJson('/api/simulations')->assertOk()->assertJsonPath('data.0.status', 'expired_unstarted')->assertJsonPath('data.0.can_cancel_before_dispatch', false);
        $next = Reservation::create(['station_id' => $station->id, 'user_id' => $customer->id, 'starts_at' => $end->format('Y-m-d H:i:s'), 'idempotency_key' => 'after-expiry']);
        $this->postJson('/api/reservations/'.$next->id.'/csms-start')->assertAccepted();
    }

    #[DataProvider('admittedStates')]
    public function test_expiry_never_closes_admitted_or_unknown_dispatch(string $status): void
    {
        [$customer, $station, $company, $booking, $queued] = $this->pending();
        ChargerCommand::whereKey($queued['start_command']['id'])->update(['status' => $status]);
        $this->travelTo(CarbonImmutable::parse($booking->starts_at, 'UTC')->addHour());
        $this->assertSame(0, app(UnsentStartExpiry::class)->run()['expired']);
        $this->assertSame('confirmed', $booking->fresh()->status);
        $this->assertNull(SessionLink::findOrFail($queued['link']['id'])->closed_without_dispatch_at);
        $this->assertDatabaseCount('charging_request_expirations', 0);
        Http::assertNothingSent();
    }

    public static function contradictorySource(): array
    {
        return [['energy'], ['source'], ['binding'], ['stopped'], ['inbox']];
    }

    #[DataProvider('contradictorySource')]
    public function test_expiry_preserves_contradictory_observed_state_for_reconciliation(string $state): void
    {
        [$customer, $station, $company, $booking, $queued] = $this->pending();
        $link = SessionLink::findOrFail($queued['link']['id']);
        match ($state) {
            'energy' => ChargingSession::whereKey($link->charging_session_id)->update(['energy_wh' => 1]),
            'stopped' => ChargingSession::whereKey($link->charging_session_id)->update(['stopped_at' => now()]),
            'source' => $link->update(['transaction_id' => '123', 'last_sequence' => 0]),
            'binding' => DB::table('csms_bindings')->where('id', $link->binding_id)->update(['identifier' => 'not-a-test-binding']),
            'inbox' => DB::table('csms_inbox_events')->insert(['link_id' => $link->id, 'event_id' => 'synthetic-inconsistent-event', 'fingerprint' => str_repeat('a', 64), 'type' => 'started', 'sequence' => 0, 'meter_wh' => 0, 'occurred_at' => now(), 'outcome' => 'accepted', 'created_at' => now()]),
        };
        $this->travelTo(CarbonImmutable::parse($booking->starts_at, 'UTC')->addHour());
        $result = app(UnsentStartExpiry::class)->run();
        $this->assertSame(0, $result['expired']);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame('confirmed', $booking->fresh()->status);
        $this->assertSame('pending', ChargerCommand::findOrFail($queued['start_command']['id'])->status);
        $this->assertDatabaseCount('charging_request_expirations', 0);
        Http::assertNothingSent();
    }

    public function test_expiry_command_validation_feature_gate_and_scheduler_registration(): void
    {
        [$customer, $station, $company, $booking] = $this->pending();
        $this->travelTo(CarbonImmutable::parse($booking->starts_at, 'UTC')->addHour());
        $this->artisan('csms:expire-unsent', ['--limit' => '0'])->assertExitCode(2);
        $this->artisan('csms:expire-unsent', ['--reservation' => '-1'])->assertExitCode(2);
        $this->artisan('csms:expire-unsent', ['--reservation' => (string) $booking->id])->expectsOutputToContain('"expired":1')->assertSuccessful();
        $this->artisan('csms:expire-unsent')->expectsOutputToContain('"expired":0')->assertSuccessful();
        $events = collect(app(Schedule::class)->events());
        $event = $events->first(fn ($e) => str_contains($e->command ?? '', 'csms:expire-unsent'));
        $this->assertNotNull($event);
        $this->assertSame('* * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertTrue($event->filtersPass($this->app));
        $this->app['env'] = 'production';
        $this->assertFalse($event->filtersPass($this->app));
        try {
            app(UnsentStartExpiry::class)->run();
            $this->fail('Production must reject simulator expiry.');
        } catch (HttpException $e) {
            $this->assertSame(503, $e->getStatusCode());
        } finally {
            $this->app['env'] = 'testing';
        }
        config(['csms.session_bridge_enabled' => false]);
        $this->assertFalse($event->filtersPass($this->app));
        try {
            app(UnsentStartExpiry::class)->run();
            $this->fail('Disabled simulator must fail closed.');
        } catch (HttpException $e) {
            $this->assertSame(503, $e->getStatusCode());
        }
    }

    public function test_bounded_scheduled_scan_advances_past_reconciliation_records_and_restarts_safely(): void
    {
        [$customer, $station, $company, $booking, $queued] = $this->pending();
        ChargingSession::whereKey($queued['session']['id'])->update(['energy_wh' => 1]);
        $this->travelTo(CarbonImmutable::parse($booking->starts_at, 'UTC')->addHour());
        $this->artisan('csms:expire-unsent', ['--limit' => '1', '--scheduled' => true])->expectsOutputToContain('"skipped":1')->assertSuccessful();
        $this->assertSame($booking->id, Cache::get('csms:unsent-expiry-cursor'));
        $this->artisan('csms:expire-unsent', ['--limit' => '1', '--scheduled' => true])->expectsOutputToContain('"examined":0')->assertSuccessful();
        $this->assertSame(0, Cache::get('csms:unsent-expiry-cursor'));
        $this->artisan('csms:expire-unsent', ['--limit' => '1', '--scheduled' => true])->expectsOutputToContain('"skipped":1')->assertSuccessful();
        $this->assertSame('confirmed', $booking->fresh()->status);
        $this->assertDatabaseCount('charging_request_expirations', 0);
    }
}
