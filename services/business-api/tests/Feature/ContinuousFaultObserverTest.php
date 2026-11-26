<?php

namespace Tests\Feature;

use App\Models\InboxNotification;
use App\Models\Station;
use App\Models\User;
use App\Models\VendorOps\VendorCharger;
use App\Models\VendorOps\VendorCompany;
use App\Models\VendorOps\VendorSite;
use App\Services\Charging\ContinuousFaultObserver;
use App\Services\Charging\FaultMonitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;
use Throwable;

class ContinuousFaultObserverTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(string $protocol = '1.6'): array
    {
        Http::preventStrayRequests();
        config(['csms.fault_worker_enabled' => true, 'csms.session_bridge_enabled' => true, 'csms.graphql_secret' => str_repeat('s', 32), 'csms.graphql_url' => 'https://source.test/graphql']);
        $owner = User::factory()->create(['role' => 'vendor']);
        $station = Station::create(['vendor_id' => $owner->id, 'name' => 'Observer fixture', 'country' => 'AE', 'currency' => 'AED', 'timezone' => 'Asia/Dubai', 'latitude' => 25, 'longitude' => 55, 'source' => 'simulator', 'connector_type' => 'CCS2', 'power_kw' => 50]);
        $company = VendorCompany::create(['owner_id' => $owner->id, 'legal_name' => 'Fixture', 'country' => 'AE', 'trade_license_number' => 'SYNTHETIC', 'license_expires_on' => now()->addYear()->toDateString(), 'status' => 'approved']);
        $site = VendorSite::create(['company_id' => $company->id, 'station_id' => $station->id, 'name' => 'Fixture', 'country' => 'AE', 'timezone' => 'Asia/Dubai', 'address' => 'Fixture', 'latitude' => 25, 'longitude' => 55]);
        $charger = VendorCharger::create(['company_id' => $company->id, 'site_id' => $site->id, 'asset_reference' => 'FAULT-OBSERVER', 'manufacturer' => 'Synthetic', 'model' => 'Fixture', 'source' => 'simulator', 'ocpp_protocol' => $protocol]);
        $binding = DB::table('csms_bindings')->insertGetId(['station_id' => $station->id, 'vendor_charger_id' => $charger->id, 'identifier' => 'ev-test-observer-'.$charger->id, 'protocol' => $protocol, 'created_at' => now(), 'updated_at' => now()]);

        return compact('owner', 'company', 'charger', 'binding', 'station', 'protocol');
    }

    private function events(int $from, int $to): array
    {
        $events = [];
        for ($id = $from; $id <= $to; $id++) {
            $events[] = ['id' => $id, 'connectorId' => 1, 'evseId' => 1, 'connectorStatus' => $id === 1 ? 'Faulted' : 'Available', 'errorCode' => $id === 1 ? 'GroundFailure' : 'NoError', 'timestamp' => now()->subSeconds(1000 - $id)->toISOString()];
        }

        return $events;
    }

    private function source(array $f, array $events, int $station = 42, int $newest = 101): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fakeSequence()->push(['data' => ['ChargingStations' => [['id' => $station, 'protocol' => $f['protocol']]]]])->push(['data' => ['StatusNotifications' => $events, 'newest' => [['id' => $newest]]]]);
    }

    public static function protocols(): array
    {
        return [['1.6'], ['2.0.1']];
    }

    #[DataProvider('protocols')]
    public function test_restart_catchup_preserves_every_event_and_existing_operator_alert_workflow(string $protocol): void
    {
        $f = $this->fixture($protocol);
        $this->source($f, $this->events(1, 101));
        $first = app(ContinuousFaultObserver::class)->observe($f['binding']);
        $this->assertSame(100, $first['events']);
        $this->assertSame('catching_up', $first['state']);
        $this->assertDatabaseHas('charging_fault_cursors', ['last_event_id' => 100, 'source_station_id' => 42]);
        $this->source($f, $this->events(101, 101));
        $second = (new ContinuousFaultObserver)->observe($f['binding']);
        $this->assertSame('caught_up', $second['state']);
        Http::assertSent(fn ($r) => ($r['variables']['after'] ?? null) === 100);
        $this->source($f, []);
        $this->assertSame(0, app(ContinuousFaultObserver::class)->observe($f['binding'])['events']);
        $this->assertDatabaseCount('charging_status_observations', 101);
        $this->assertDatabaseCount('charging_fault_alerts', 1);
        $this->assertDatabaseCount('inbox_notifications', 2);
        $notification = InboxNotification::where('kind', 'charging.fault.simulated')->firstOrFail();
        $recovered = InboxNotification::where('kind', 'charging.fault.recovered.simulated')->firstOrFail();
        $this->assertSame($f['owner']->id, $recovered->user_id);
        $this->assertSame($notification->metadata['alert_id'], $recovered->metadata['alert_id']);
        $this->assertSame(false, $recovered->metadata['physical_verification']);
        $this->assertSame($f['owner']->id, $notification->user_id);
        $this->assertSame('charging.fault.simulated', $notification->kind);
        $this->assertSame('simulator', $notification->metadata['source']);
        $this->assertSame('SIMULATED_SOURCE_OBSERVED', $notification->metadata['evidence']);
        $this->assertSame($f['station']->id, $notification->metadata['station_id']);
        $this->assertSame($f['company']->id, $notification->metadata['company_id']);
        $this->assertSame($f['charger']->id, $notification->metadata['charger_id']);
        $this->assertSame($f['company']->id, $recovered->metadata['company_id']);
        $this->assertSame($f['charger']->id, $recovered->metadata['charger_id']);
        $this->assertSame(false, $notification->metadata['physical_verification']);
        $this->actingAs($f['owner'])->getJson('/api/v1/notifications/summary')
            ->assertOk()->assertJsonPath('unread_count', 2);
        $this->getJson('/api/v1/notifications?unread_only=1')
            ->assertOk()->assertJsonPath('total', 2);
        $this->assertDatabaseCount('inbox_notifications', 2); // cursor replay and post-recovery calls do not duplicate notices
        $alert = DB::table('charging_fault_alerts')->first();
        $this->assertNotNull($alert->recovery_observation_id);
        $path = '/api/vendor-ops/companies/'.$f['company']->id.'/chargers/'.$f['charger']->id;
        $this->actingAs($f['owner'])->getJson($path.'/fault-alerts')->assertOk()->assertJsonCount(1, 'data');
        $this->patchJson($path.'/fault-alerts/'.$alert->id.'/acknowledge', ['note' => 'Reviewed automatically observed source fault'])->assertOk();
        $this->assertDatabaseCount('charger_commands', 0);
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    #[DataProvider('protocols')]
    public function test_three_page_250_status_observations_preserve_fault_recovery_and_notice_idempotency(string $protocol): void
    {
        $f = $this->fixture($protocol);
        $first = $this->events(1, 101);
        foreach ($first as &$row) {
            $row['connectorStatus'] = 'Available';
            $row['errorCode'] = 'NoError';
        }
        unset($row);
        $this->source($f, $first, newest: 250);
        $firstResult = app(ContinuousFaultObserver::class)->observe($f['binding']);
        $this->assertSame(100, $firstResult['events']);
        $this->assertSame('catching_up', $firstResult['state']);
        $this->assertDatabaseHas('charging_fault_cursors', [
            'last_event_id' => 100, 'source_station_id' => 42,
        ]);
        $this->assertDatabaseCount('charging_status_observations', 100);
        $this->assertDatabaseCount('charging_fault_alerts', 0);

        $second = $this->events(101, 201);
        foreach ($second as &$row) {
            $row['connectorStatus'] = $row['id'] === 150 ? 'Faulted' : 'Available';
            $row['errorCode'] = $row['id'] === 150 ? 'GroundFailure' : 'NoError';
        }
        unset($row);
        $this->source($f, $second, newest: 250);
        $secondResult = (new ContinuousFaultObserver)->observe($f['binding']);
        $this->assertSame(100, $secondResult['events']);
        $this->assertSame('catching_up', $secondResult['state']);
        $this->assertDatabaseHas('charging_fault_cursors', [
            'last_event_id' => 200, 'source_station_id' => 42,
        ]);
        $this->assertDatabaseCount('charging_status_observations', 200);
        $this->assertDatabaseCount('charging_fault_alerts', 1);
        $this->assertDatabaseCount('inbox_notifications', 2);

        $third = $this->events(201, 250);
        foreach ($third as &$row) {
            $row['connectorStatus'] = $row['id'] === 220 ? 'Faulted' : 'Available';
            $row['errorCode'] = $row['id'] === 220 ? 'GroundFailure' : 'NoError';
        }
        unset($row);
        $this->source($f, $third, newest: 250);
        $thirdResult = (new ContinuousFaultObserver)->observe($f['binding']);
        $this->assertSame(50, $thirdResult['events']);
        $this->assertSame('caught_up', $thirdResult['state']);
        $this->assertDatabaseHas('charging_fault_cursors', [
            'last_event_id' => 250, 'source_station_id' => 42, 'state' => 'caught_up',
        ]);
        $this->assertDatabaseCount('charging_status_observations', 250);
        $this->assertDatabaseCount('charging_fault_alerts', 2);
        $this->assertDatabaseCount('inbox_notifications', 4);
        $this->assertSame(2, DB::table('charging_fault_alerts')->whereNotNull('recovery_observation_id')->count());
        $this->assertSame(2, InboxNotification::where('kind', 'charging.fault.simulated')->count());
        $this->assertSame(2, InboxNotification::where('kind', 'charging.fault.recovered.simulated')->count());

        $this->source($f, [], newest: 250);
        $this->assertSame(0, app(ContinuousFaultObserver::class)->observe($f['binding'])['events']);
        $this->assertDatabaseCount('charging_status_observations', 250);
        $this->assertDatabaseCount('inbox_notifications', 4);
        $this->assertDatabaseCount('charger_commands', 0);
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    public function test_unparseable_source_timestamp_returns_review_required_without_event_loss(): void
    {
        $f = $this->fixture();
        $events = $this->events(1, 2);
        $events[0]['timestamp'] = 'not-a-time';
        $this->source($f, $events, newest: 2);

        try {
            app(ContinuousFaultObserver::class)->observe($f['binding']);
            $this->fail('Invalid timestamp was accepted as source evidence');
        } catch (HttpException $error) {
            $this->assertSame(503, $error->getStatusCode());
        }
        $this->assertDatabaseCount('charging_status_observations', 0);
        $this->assertDatabaseCount('charging_fault_alerts', 0);
        $this->assertDatabaseHas('charging_fault_cursors', [
            'state' => 'requires_review', 'last_event_id' => 0,
        ]);
        $notice = InboxNotification::where(
            'kind', 'charging.monitor.requires_review.simulated'
        )->firstOrFail();
        $this->assertSame('SIMULATED_SOURCE_UNVERIFIED', $notice->metadata['evidence']);
        $this->assertFalse($notice->metadata['physical_verification']);

        $this->source($f, $this->events(1, 2), newest: 2);
        $result = app(ContinuousFaultObserver::class)->observe($f['binding']);
        $this->assertSame('caught_up', $result['state']);
        $this->assertDatabaseHas('charging_fault_cursors', ['last_event_id' => 2]);
        $this->assertDatabaseCount('charging_status_observations', 2);
        $this->assertDatabaseCount('charger_commands', 0);
    }

    public static function malformedSourceStatusProjections(): array
    {
        $cases = [];
        foreach (['1.6', '2.0.1'] as $protocol) {
            foreach (['nonlist_station', 'missing_station_id', 'nonlist_status_events', 'nonlist_newest', 'missing_newest_id'] as $kind) {
                $cases[$protocol.'_'.$kind] = [$protocol, $kind];
            }
        }

        return $cases;
    }

    #[DataProvider('malformedSourceStatusProjections')]
    public function test_malformed_ocpp_status_projection_cannot_hide_faults_and_recovers_after_review(string $protocol, string $kind): void
    {
        $f = $this->fixture($protocol);
        $station = [['id' => 42, 'protocol' => $protocol]];
        $events = $this->events(1, 2);
        $newest = [['id' => 2]];
        if ($kind === 'nonlist_station') {
            $station = ['station' => $station[0]];
        }
        if ($kind === 'missing_station_id') {
            $station = [['protocol' => $protocol]];
        }
        if ($kind === 'nonlist_status_events') {
            $events = ['observation' => $events[0]];
        }
        if ($kind === 'nonlist_newest') {
            $events = [];
            $newest = ['observation' => ['id' => 2]];
        }
        if ($kind === 'missing_newest_id') {
            $events = [];
            $newest = [[]];
        }

        Http::fakeSequence()
            ->push(['data' => ['ChargingStations' => $station]])
            ->push(['data' => ['StatusNotifications' => $events, 'newest' => $newest]]);

        try {
            app(ContinuousFaultObserver::class)->observe($f['binding']);
            $this->fail('Malformed source projection was accepted as an observed status.');
        } catch (HttpException $error) {
            $this->assertSame(503, $error->getStatusCode());
        }
        $this->assertDatabaseHas('charging_fault_cursors', [
            'last_event_id' => 0, 'state' => 'requires_review',
        ]);
        $this->assertDatabaseCount('charging_status_observations', 0);
        $this->assertDatabaseCount('charging_fault_alerts', 0);
        $this->assertDatabaseCount('charger_commands', 0);
        $this->assertDatabaseCount('finance_invoices', 0);
        $this->assertSame(1, InboxNotification::where('kind', 'charging.monitor.requires_review.simulated')->count());

        // Independent confirmation of the original source identity and full
        // event page must be required before any cursor or alert progresses.
        $this->source($f, $this->events(1, 2), newest: 2);
        $result = app(ContinuousFaultObserver::class)->observe($f['binding']);
        $this->assertSame('caught_up', $result['state']);
        $this->assertSame(2, $result['events']);
        $this->assertDatabaseHas('charging_fault_cursors', [
            'last_event_id' => 2, 'source_station_id' => 42, 'state' => 'caught_up',
        ]);
        $this->assertDatabaseCount('charging_status_observations', 2);
        $this->assertDatabaseCount('charging_fault_alerts', 1);
        $this->assertDatabaseCount('charger_commands', 0);
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    public static function invalidOcppObservations(): array
    {
        return [
            'ocpp16_unknown_status' => ['1.6', 'connectorStatus', 'VendorFaultUnknown'],
            'ocpp201_unknown_status' => ['2.0.1', 'connectorStatus', 'VendorFaultUnknown'],
            'ocpp16_relative_clock' => ['1.6', 'timestamp', 'tomorrow'],
            'ocpp201_relative_clock' => ['2.0.1', 'timestamp', 'tomorrow'],
            'ocpp16_naive_clock' => ['1.6', 'timestamp', '2026-11-15 10:00:00'],
            'ocpp201_naive_clock' => ['2.0.1', 'timestamp', '2026-11-15 10:00:00'],
            'ocpp16_missing_error_code' => ['1.6', 'errorCode', null],
            'ocpp16_unknown_error_code' => ['1.6', 'errorCode', 'VendorFaultUnknown'],
            'ocpp16_faulted_with_noerror' => ['1.6', 'errorCode', 'NoError'],
            'ocpp16_reserved_ocpp201_only' => ['1.6', 'connectorStatus', 'Occupied'],
            'ocpp201_charging_ocpp16_only' => ['2.0.1', 'connectorStatus', 'Charging'],
        ];
    }

    #[DataProvider('invalidOcppObservations')]
    public function test_invalid_ocpp_status_or_fault_receipt_requires_reconciliation_without_skipping_event(string $protocol, string $field, mixed $value): void
    {
        $fixture = $this->fixture($protocol);
        $events = $this->events(1, 2);
        $events[0][$field] = $value;
        $this->source($fixture, $events, newest: 2);

        try {
            app(ContinuousFaultObserver::class)->observe($fixture['binding']);
            $this->fail('Unsupported OCPP observation advanced the fault source cursor.');
        } catch (HttpException $error) {
            $this->assertSame(503, $error->getStatusCode());
        }

        $this->assertDatabaseHas('charging_fault_cursors', [
            'last_event_id' => 0,
            'state' => 'requires_review',
        ]);
        $this->assertDatabaseCount('charging_status_observations', 0);
        $this->assertDatabaseCount('charging_fault_alerts', 0);
        $this->assertDatabaseCount('charger_commands', 0);
        $this->assertDatabaseCount('finance_invoices', 0);
        $this->assertSame(1, InboxNotification::where('kind', 'charging.monitor.requires_review.simulated')->count());

        // The same source ids may be admitted only after a corrected, fresh
        // source read. A prior failed batch must not skip the original fault.
        $this->source($fixture, $this->events(1, 2), newest: 2);
        $result = app(ContinuousFaultObserver::class)->observe($fixture['binding']);
        $this->assertSame('caught_up', $result['state']);
        $this->assertSame(2, $result['events']);
        $this->assertDatabaseHas('charging_fault_cursors', ['last_event_id' => 2]);
        $this->assertDatabaseCount('charging_status_observations', 2);
        $this->assertDatabaseCount('charging_fault_alerts', 1);
        $this->assertSame(1, InboxNotification::where('kind', 'charging.monitor.resumed.simulated')->count());
        $this->assertDatabaseCount('charger_commands', 0);
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    public function test_ocpp16_available_with_ground_failure_must_not_clear_fault_or_advance_cursor(): void
    {
        $f = $this->fixture('1.6');
        $events = $this->events(1, 2);
        $events[1]['errorCode'] = 'GroundFailure';
        $this->source($f, $events, newest: 2);
        try {
            app(ContinuousFaultObserver::class)->observe($f['binding']);
            $this->fail('Contradictory Available/GroundFailure source status was accepted.');
        } catch (HttpException $error) {
            $this->assertSame(503, $error->getStatusCode());
        }
        $this->assertDatabaseHas('charging_fault_cursors', [
            'last_event_id' => 0, 'state' => 'requires_review',
        ]);
        $this->assertDatabaseCount('charging_status_observations', 0);
        $this->assertDatabaseCount('charging_fault_alerts', 0);
        $this->assertDatabaseCount('charger_commands', 0);
        $this->assertDatabaseCount('finance_invoices', 0);
        $this->source($f, $this->events(1, 2), newest: 2);
        $this->assertSame('caught_up', app(ContinuousFaultObserver::class)->observe($f['binding'])['state']);
        $this->assertDatabaseCount('charging_status_observations', 2);
        $this->assertDatabaseCount('charging_fault_alerts', 1);
        $this->assertNotNull(DB::table('charging_fault_alerts')->value('recovery_observation_id'));
    }

    public function test_suspended_vendor_cannot_receive_new_private_source_fault_notifications(): void
    {
        $f = $this->fixture();
        DB::table('users')->where('id', $f['owner']->id)->update(['account_status' => 'suspended']);
        try {
            app(ContinuousFaultObserver::class)->observe($f['binding']);
            $this->fail('Suspended operator retained private source observation access.');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        Http::assertNothingSent();
        $this->assertDatabaseCount('charging_fault_cursors', 0);
        $this->assertDatabaseCount('inbox_notifications', 0);
        $this->assertDatabaseCount('charger_commands', 0);
    }

    public function test_suspension_during_source_http_read_prevents_old_owner_notification_commit(): void
    {
        $f = $this->fixture();
        Http::fake(function ($request) use ($f) {
            DB::table('users')->where('id', $f['owner']->id)->update(['account_status' => 'suspended']);

            return Http::response(['data' => [
                'ChargingStations' => [['id' => 42, 'protocol' => '1.6']],
            ]]);
        });
        try {
            app(ContinuousFaultObserver::class)->observe($f['binding']);
            $this->fail('Source read from a revoked owner was committed.');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        $this->assertDatabaseCount('charging_fault_cursors', 0);
        $this->assertDatabaseCount('charging_status_observations', 0);
        $this->assertDatabaseCount('inbox_notifications', 0);
        $this->assertDatabaseCount('charger_commands', 0);
    }

    public function test_empty_page_cannot_claim_catchup_when_source_has_newer_events(): void
    {
        $f = $this->fixture();
        $this->source($f, [], newest: 5);
        try {
            app(ContinuousFaultObserver::class)->observe($f['binding']);
            $this->fail('Empty page with a newer source event was accepted');
        } catch (HttpException $error) {
            $this->assertSame(503, $error->getStatusCode());
        }

        $this->assertDatabaseHas('charging_fault_cursors', [
            'last_event_id' => 0,
            'state' => 'requires_review',
        ]);
        $this->assertDatabaseCount('charging_status_observations', 0);
        $this->assertDatabaseCount('charging_fault_alerts', 0);
        $this->assertSame(1, InboxNotification::where(
            'kind', 'charging.monitor.requires_review.simulated'
        )->count());
        $review = InboxNotification::where(
            'kind', 'charging.monitor.requires_review.simulated'
        )->firstOrFail();
        $this->assertSame('SIMULATED_SOURCE_UNVERIFIED', $review->metadata['evidence']);
        $this->assertFalse($review->metadata['physical_verification']);

        // New source data is not silently skipped. Catch-up starts at zero
        // and independently records both the fault and its source recovery.
        $this->source($f, $this->events(1, 5), newest: 5);
        $result = app(ContinuousFaultObserver::class)->observe($f['binding']);
        $this->assertSame('caught_up', $result['state']);
        $this->assertSame(5, $result['events']);
        $this->assertDatabaseHas('charging_fault_cursors', [
            'last_event_id' => 5,
            'state' => 'caught_up',
        ]);
        $this->assertDatabaseCount('charging_status_observations', 5);
        $this->assertSame(1, InboxNotification::where(
            'kind', 'charging.monitor.resumed.simulated'
        )->count());
        $resumed = InboxNotification::where(
            'kind', 'charging.monitor.resumed.simulated'
        )->firstOrFail();
        $this->assertSame('SIMULATED_SOURCE_OBSERVED', $resumed->metadata['evidence']);
        $this->assertFalse($resumed->metadata['physical_verification']);
        $this->assertSame(1, InboxNotification::where(
            'kind', 'charging.fault.simulated'
        )->count());
        $this->assertSame(1, InboxNotification::where(
            'kind', 'charging.fault.recovered.simulated'
        )->count());
        $this->assertDatabaseCount('charger_commands', 0);
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    public function test_newest_source_event_must_not_be_older_than_initial_page(): void
    {
        $f = $this->fixture();
        $this->source($f, $this->events(1, 2), newest: 1);
        try {
            app(ContinuousFaultObserver::class)->observe($f['binding']);
            $this->fail('Contradictory source newest watermark was accepted');
        } catch (HttpException $error) {
            $this->assertSame(503, $error->getStatusCode());
        }

        $this->assertDatabaseCount('charging_status_observations', 0);
        $this->assertDatabaseCount('charging_fault_alerts', 0);
        $this->assertDatabaseHas('charging_fault_cursors', [
            'last_event_id' => 0,
            'state' => 'requires_review',
        ]);
        $this->assertSame(1, InboxNotification::where(
            'kind', 'charging.monitor.requires_review.simulated'
        )->count());
        $this->assertSame(0, InboxNotification::where(
            'kind', 'charging.fault.simulated'
        )->count());

        // A later consistent source page catches up from exactly the old
        // cursor, emits normal synthetic fault/recovery records and one
        // monitor-status recovery. No events were skipped.
        $this->source($f, $this->events(1, 2), newest: 2);
        $result = app(ContinuousFaultObserver::class)->observe($f['binding']);
        $this->assertSame('caught_up', $result['state']);
        $this->assertSame(2, $result['events']);
        $this->assertDatabaseCount('charging_status_observations', 2);
        $this->assertSame(1, InboxNotification::where(
            'kind', 'charging.monitor.resumed.simulated'
        )->count());
        $this->assertSame(1, InboxNotification::where(
            'kind', 'charging.fault.simulated'
        )->count());
        $this->assertSame(1, InboxNotification::where(
            'kind', 'charging.fault.recovered.simulated'
        )->count());
        $this->assertDatabaseCount('charger_commands', 0);
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    public function test_failed_batch_does_not_skip_events_and_source_replacement_needs_review(): void
    {
        $f = $this->fixture();
        $this->source($f, $this->events(1, 2), newest: 2);
        app(ContinuousFaultObserver::class)->observe($f['binding']);
        $invalid = $this->events(3, 4);
        // The first event would create a new fault+inbox notice, but a later
        // invalid source event must roll back the entire batch atomically.
        $invalid[0]['connectorStatus'] = 'Faulted';
        $invalid[0]['errorCode'] = 'GroundFailure';
        $invalid[1]['connectorId'] = -1;
        $this->source($f, $invalid, newest: 4);
        try {
            app(ContinuousFaultObserver::class)->observe($f['binding']);
            $this->fail('Invalid batch accepted');
        } catch (HttpException $e) {
            $this->assertSame(503, $e->getStatusCode());
        }
        $this->assertDatabaseCount('charging_status_observations', 2);
        $this->assertDatabaseCount('charging_fault_alerts', 1);
        // Failed source batch rolls back its proposed fault+inbox, but records
        // one monitor review notice in a separate verified transaction.
        $this->assertDatabaseCount('inbox_notifications', 3);
        $this->assertSame(1, InboxNotification::where('kind', 'charging.monitor.requires_review.simulated')->count());
        $this->assertDatabaseHas('charging_fault_cursors', ['last_event_id' => 2, 'state' => 'requires_review']);
        $this->source($f, $invalid, newest: 4);
        try {
            app(ContinuousFaultObserver::class)->observe($f['binding']);
            $this->fail('Repeated invalid batch accepted');
        } catch (HttpException $e) {
            $this->assertSame(503, $e->getStatusCode());
        }
        $this->assertSame(1, InboxNotification::where('kind', 'charging.monitor.requires_review.simulated')->count());
        $this->source($f, $this->events(3, 4), newest: 4);
        app(ContinuousFaultObserver::class)->observe($f['binding']);
        $this->assertDatabaseCount('charging_status_observations', 4);
        $this->assertSame(1, InboxNotification::where('kind', 'charging.monitor.resumed.simulated')->count());
        $this->source($f, [], station: 43, newest: 4);
        try {
            app(ContinuousFaultObserver::class)->observe($f['binding']);
            $this->fail('Replacement source accepted');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        $this->assertDatabaseHas('charging_fault_cursors', ['last_event_id' => 4, 'source_station_id' => 42, 'state' => 'requires_review']);
        $this->assertSame(2, InboxNotification::where('kind', 'charging.monitor.requires_review.simulated')->count());
    }

    public function test_ownership_or_mapping_change_during_network_discards_batch(): void
    {
        $f = $this->fixture();
        Http::fake(function ($r) use ($f) {
            $f['company']->update(['owner_id' => User::factory()->create(['role' => 'vendor'])->id]);

            return Http::response(['data' => ['ChargingStations' => []]]);
        });
        try {
            app(ContinuousFaultObserver::class)->observe($f['binding']);
            $this->fail('Changed owner accepted');
        } catch (Throwable $e) {
            $this->assertInstanceOf(HttpException::class, $e);
        }
        $this->assertDatabaseCount('charging_fault_cursors', 0);
        $this->assertDatabaseCount('charging_status_observations', 0);
        $this->assertDatabaseCount('inbox_notifications', 0);
    }

    public function test_station_transfer_during_source_read_cannot_emit_notice_to_previous_vendor(): void
    {
        $f = $this->fixture();
        $nextOwner = User::factory()->create(['role' => 'vendor']);
        Http::fake(function () use ($f, $nextOwner) {
            // Simulate authorization changing while the authenticated source
            // HTTP request is in flight. No old-owner notice may be persisted.
            Station::whereKey($f['station']->id)
                ->update(['vendor_id' => $nextOwner->id]);

            return Http::response([
                'data' => [
                    'ChargingStations' => [
                        ['id' => 42, 'protocol' => $f['protocol']],
                    ],
                ],
            ]);
        });

        try {
            app(ContinuousFaultObserver::class)->observe($f['binding']);
            $this->fail('Source event accepted after station transfer');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }

        $this->assertDatabaseCount('charging_fault_cursors', 0);
        $this->assertDatabaseCount('charging_status_observations', 0);
        $this->assertDatabaseCount('charging_fault_alerts', 0);
        $this->assertDatabaseCount('inbox_notifications', 0);
    }

    public function test_reassigned_charger_binding_during_source_read_cannot_emit_old_notice(): void
    {
        $f = $this->fixture();
        $second = VendorCharger::create([
            'company_id' => $f['company']->id,
            'site_id' => VendorSite::where('company_id', $f['company']->id)->firstOrFail()->id,
            'asset_reference' => 'REASSIGNED-SIMULATOR',
            'manufacturer' => 'Synthetic',
            'model' => 'Fixture',
            'source' => 'simulator',
            'ocpp_protocol' => $f['protocol'],
        ]);
        Http::fake(function () use ($f, $second) {
            DB::table('csms_bindings')->where('id', $f['binding'])
                ->update(['vendor_charger_id' => $second->id]);

            return Http::response([
                'data' => [
                    'ChargingStations' => [
                        ['id' => 42, 'protocol' => $f['protocol']],
                    ],
                ],
            ]);
        });

        try {
            app(ContinuousFaultObserver::class)->observe($f['binding']);
            $this->fail('Stale source mapping accepted');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }

        $this->assertDatabaseCount('charging_fault_cursors', 0);
        $this->assertDatabaseCount('charging_status_observations', 0);
        $this->assertDatabaseCount('charging_fault_alerts', 0);
        $this->assertDatabaseCount('inbox_notifications', 0);
    }

    #[DataProvider('protocols')]
    public function test_status_source_clock_regression_never_advances_fault_cursor_across_polls(string $protocol): void
    {
        $f = $this->fixture($protocol);
        // A short upstream page can be genuine but is not "caught up" if
        // its independently reported newest event is still ahead.
        $this->source($f, $this->events(1, 1), newest: 2);
        $initial = app(ContinuousFaultObserver::class)->observe($f['binding']);
        $this->assertSame('catching_up', $initial['state']);
        $this->assertSame(1, $initial['events']);
        $this->assertDatabaseHas('charging_fault_cursors', [
            'last_event_id' => 1, 'state' => 'catching_up',
        ]);
        $this->assertDatabaseCount('charging_fault_alerts', 1);
        $this->assertDatabaseCount('charging_status_observations', 1);

        $bad = $this->events(2, 2);
        // The later event ID falsely advertises an earlier clock and an
        // "Available" status. Do not let it recover the prior fault.
        $bad[0]['timestamp'] = now()->subSeconds(1001)->toISOString();
        $this->source($f, $bad, newest: 2);
        try {
            app(ContinuousFaultObserver::class)->observe($f['binding']);
            $this->fail('Cross-page status timestamp rollback was accepted.');
        } catch (HttpException $error) {
            $this->assertSame(503, $error->getStatusCode());
        }
        $this->assertDatabaseHas('charging_fault_cursors', [
            'last_event_id' => 1, 'state' => 'requires_review',
        ]);
        $this->assertDatabaseCount('charging_status_observations', 1);
        $this->assertDatabaseCount('charging_fault_alerts', 1);
        $this->assertSame(1, InboxNotification::where('kind', 'charging.fault.simulated')->count());
        $this->assertSame(0, InboxNotification::where('kind', 'charging.fault.recovered.simulated')->count());

        $this->source($f, $this->events(2, 2), newest: 2);
        $result = app(ContinuousFaultObserver::class)->observe($f['binding']);
        $this->assertSame('caught_up', $result['state']);
        $this->assertSame(1, $result['events']);
        $this->assertDatabaseHas('charging_fault_cursors', ['last_event_id' => 2]);
        $this->assertDatabaseCount('charging_status_observations', 2);
        $this->assertSame(1, InboxNotification::where('kind', 'charging.fault.recovered.simulated')->count());
        $this->assertSame(1, InboxNotification::where('kind', 'charging.monitor.resumed.simulated')->count());
        $this->assertDatabaseCount('charger_commands', 0);
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    #[DataProvider('protocols')]
    public function test_status_source_clock_regression_within_one_page_requires_review(string $protocol): void
    {
        $f = $this->fixture($protocol);
        $invalid = $this->events(1, 2);
        $invalid[1]['timestamp'] = now()->subSeconds(1001)->toISOString();
        $this->source($f, $invalid, newest: 2);
        try {
            app(ContinuousFaultObserver::class)->observe($f['binding']);
            $this->fail('Within-page source status clock rollback was accepted.');
        } catch (HttpException $error) {
            $this->assertSame(503, $error->getStatusCode());
        }
        $this->assertDatabaseHas('charging_fault_cursors', [
            'last_event_id' => 0, 'state' => 'requires_review',
        ]);
        $this->assertDatabaseCount('charging_status_observations', 0);
        $this->assertDatabaseCount('charging_fault_alerts', 0);
        $this->assertDatabaseCount('charger_commands', 0);
        $this->assertDatabaseCount('finance_invoices', 0);
        $this->source($f, $this->events(1, 2), newest: 2);
        $this->assertSame('caught_up', app(ContinuousFaultObserver::class)->observe($f['binding'])['state']);
        $this->assertDatabaseCount('charging_status_observations', 2);
        $this->assertDatabaseCount('charging_fault_alerts', 1);
        $this->assertSame(1, InboxNotification::where('kind', 'charging.fault.recovered.simulated')->count());
        $this->assertDatabaseCount('charger_commands', 0);
    }

    public static function sourceAuthorityChanges(): array
    {
        $cases = [];
        foreach (['1.6', '2.0.1'] as $protocol) {
            foreach (['endpoint', 'secret', 'disabled'] as $kind) {
                $cases[] = [$protocol, $kind];
            }
        }

        return $cases;
    }

    #[DataProvider('sourceAuthorityChanges')]
    public function test_fault_source_authority_change_retains_cursor_and_never_emits_false_recovery(string $protocol, string $kind): void
    {
        $this->freezeTime();
        $f = $this->fixture($protocol);
        $this->source($f, $this->events(1, 1), newest: 2);
        app(ContinuousFaultObserver::class)->observe($f['binding']);
        $page = $this->events(2, 2);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(function ($request) use ($protocol, $kind, $page) {
            $this->assertSame('https://source.test/graphql', $request->url());
            $this->assertSame(str_repeat('s', 32), $request->header('x-hasura-admin-secret')[0] ?? null);
            if (str_contains($request['query'], 'ChargingStations')) {
                if ($kind === 'endpoint') {
                    config(['csms.graphql_url' => 'https://alternate.example.test/graphql']);
                }

                return Http::response(['data' => ['ChargingStations' => [['id' => 42, 'protocol' => $protocol]]]]);
            }
            if ($kind === 'secret') {
                config(['csms.graphql_secret' => str_repeat('t', 32)]);
            } elseif ($kind === 'disabled') {
                config(['csms.fault_worker_enabled' => false]);
            }

            return Http::response(['data' => ['StatusNotifications' => $page, 'newest' => [['id' => 2]]]]);
        });
        try {
            app(ContinuousFaultObserver::class)->observe($f['binding']);
            $this->fail('Changed fault source authority was accepted.');
        } catch (HttpException $error) {
            $this->assertSame(503, $error->getStatusCode());
        }
        Http::assertSentCount($kind === 'endpoint' ? 1 : 2);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'alternate.example.test'));
        $this->assertDatabaseHas('charging_fault_cursors', ['last_event_id' => 1, 'state' => 'requires_review']);
        $this->assertDatabaseCount('charging_status_observations', 1);
        $this->assertSame(0, InboxNotification::where('kind', 'charging.fault.recovered.simulated')->count());
        config(['csms.graphql_url' => 'https://source.test/graphql', 'csms.graphql_secret' => str_repeat('s', 32), 'csms.fault_worker_enabled' => true]);
        $this->source($f, $page, newest: 2);
        $this->assertSame('caught_up', app(ContinuousFaultObserver::class)->observe($f['binding'])['state']);
        $this->assertSame(1, InboxNotification::where('kind', 'charging.fault.recovered.simulated')->count());
        $this->assertSame(1, InboxNotification::where('kind', 'charging.monitor.resumed.simulated')->count());
        $this->assertDatabaseCount('charger_commands', 0);
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    #[DataProvider('protocols')]
    public function test_same_time_conflicting_connector_receipts_do_not_advance_cursor(string $protocol): void
    {
        $this->freezeTime();
        $f = $this->fixture($protocol);
        $events = $this->events(1, 2);
        $events[1]['timestamp'] = $events[0]['timestamp'];
        $this->source($f, $events, newest: 2);
        try {
            app(ContinuousFaultObserver::class)->observe($f['binding']);
            $this->fail('Contradictory same-time connector states were accepted.');
        } catch (HttpException $error) {
            $this->assertSame(503, $error->getStatusCode());
        }
        $this->assertDatabaseCount('charging_status_observations', 0);
        $this->assertDatabaseCount('charging_fault_alerts', 0);
        $this->source($f, [$events[0]], newest: 2);
        app(ContinuousFaultObserver::class)->observe($f['binding']);
        $this->source($f, [$events[1]], newest: 2);
        try {
            app(ContinuousFaultObserver::class)->observe($f['binding']);
            $this->fail('Contradiction across the committed source cursor was accepted.');
        } catch (HttpException $error) {
            $this->assertSame(503, $error->getStatusCode());
        }
        $this->assertDatabaseHas('charging_fault_cursors', ['last_event_id' => 1, 'state' => 'requires_review']);
        $this->assertDatabaseCount('charging_status_observations', 1);
        $this->assertSame(0, InboxNotification::where('kind', 'charging.fault.recovered.simulated')->count());
        // Same instant on a different connector is independent evidence.
        $events[1]['connectorId'] = 2;
        $this->source($f, [$events[1]], newest: 2);
        $this->assertSame('caught_up', app(ContinuousFaultObserver::class)->observe($f['binding'])['state']);
        $this->assertDatabaseCount('charging_status_observations', 2);
        $this->assertNull(DB::table('charging_fault_alerts')->value('recovery_observation_id'));
        $this->source($f, $this->events(3, 3), newest: 3);
        $this->assertSame('caught_up', app(ContinuousFaultObserver::class)->observe($f['binding'])['state']);
        $this->assertSame(1, InboxNotification::where('kind', 'charging.fault.recovered.simulated')->count());
        $this->assertDatabaseCount('charger_commands', 0);
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    public function test_ocpp16_explicit_null_evse_uses_persisted_zero_identity_for_fault_and_recovery(): void
    {
        $f = $this->fixture('1.6');
        $events = $this->events(1, 2);
        $events[0]['evseId'] = null;
        $events[1]['evseId'] = null;
        $this->source($f, [$events[0]], newest: 2);
        $this->assertSame(1, app(ContinuousFaultObserver::class)->observe($f['binding'])['alerts_created']);
        $this->source($f, [$events[1]], newest: 2);
        $this->assertSame('caught_up', app(ContinuousFaultObserver::class)->observe($f['binding'])['state']);
        $this->assertDatabaseHas('charging_status_observations', ['source_event_id' => 1, 'evse_number' => 0, 'connector_number' => 1]);
        $this->assertDatabaseHas('charging_status_observations', ['source_event_id' => 2, 'evse_number' => 0, 'connector_number' => 1]);
        $this->assertNotNull(DB::table('charging_fault_alerts')->value('recovery_observation_id'));
        $this->assertSame(1, InboxNotification::where('kind', 'charging.fault.recovered.simulated')->count());
    }

    public function test_ocpp16_null_evse_same_time_conflict_with_zero_cannot_cross_cursor(): void
    {
        $f = $this->fixture('1.6');
        $events = $this->events(1, 2);
        $events[0]['evseId'] = null;
        $events[1]['evseId'] = 0;
        $events[1]['timestamp'] = $events[0]['timestamp'];
        $this->source($f, [$events[0]], newest: 2);
        app(ContinuousFaultObserver::class)->observe($f['binding']);
        $this->source($f, [$events[1]], newest: 2);
        try {
            app(ContinuousFaultObserver::class)->observe($f['binding']);
            $this->fail('Conflicting null/zero EVSE receipt accepted.');
        } catch (HttpException $error) {
            $this->assertSame(503, $error->getStatusCode());
        }
        $this->assertDatabaseHas('charging_fault_cursors', ['last_event_id' => 1, 'state' => 'requires_review']);
        $this->assertDatabaseCount('charging_status_observations', 1);
        $this->assertNull(DB::table('charging_fault_alerts')->value('recovery_observation_id'));
    }

    public static function invalidEvseProjection(): array
    {
        return [['1.6', true], ['2.0.1', false], ['2.0.1', true]];
    }

    #[DataProvider('invalidEvseProjection')]
    public function test_missing_evse_or_null_ocpp201_evse_remains_invalid(string $protocol, bool $missing): void
    {
        $f = $this->fixture($protocol);
        $events = $this->events(1, 1);
        $events[0]['evseId'] = null;
        if ($missing) {
            unset($events[0]['evseId']);
        }
        $this->source($f, $events, newest: 1);
        try {
            app(ContinuousFaultObserver::class)->observe($f['binding']);
            $this->fail('Incomplete source component was accepted.');
        } catch (HttpException $error) {
            $this->assertSame(503, $error->getStatusCode());
        }
        $this->assertDatabaseHas('charging_fault_cursors', ['last_event_id' => 0, 'state' => 'requires_review']);
        $this->assertDatabaseCount('charging_status_observations', 0);
        $this->assertDatabaseCount('charging_fault_alerts', 0);
    }

    public function test_disabled_or_production_worker_cannot_read_source(): void
    {
        $f = $this->fixture();
        config(['csms.fault_worker_enabled' => false]);
        $this->artisan('csms:observe-faults --once')->assertFailed();
        Http::assertNothingSent();
        config(['csms.fault_worker_enabled' => true]);
        $this->app->instance('env', 'production');
        $this->artisan('csms:observe-faults --once')->assertFailed();
        Http::assertNothingSent();
    }

    #[DataProvider('protocols')]
    public function test_retained_recovery_requires_exact_binding_and_protocol_clearance(string $protocol): void
    {
        $f = $this->fixture($protocol);
        $foreign = $this->fixture($protocol);
        $this->source($f, $this->events(1, 1), newest: 1);
        $this->assertSame(1, app(ContinuousFaultObserver::class)->observe($f['binding'])['alerts_created']);
        $binding = DB::table('csms_bindings')->find($f['binding']);
        $generation = FaultMonitor::generation($binding);
        $alert = DB::table('charging_fault_alerts')->firstOrFail();
        $recovered = [];
        $scan = function () use ($binding, $generation, &$recovered) {
            DB::transaction(function () use ($binding, $generation, &$recovered) {
                $locked = DB::table('csms_bindings')->where('id', $binding->id)->lockForUpdate()->firstOrFail();
                app(FaultMonitor::class)->ingest($locked,
                    ['source_generation' => $generation, 'events' => []],
                    null,
                    function (int $id) use (&$recovered) {
                        $recovered[] = $id;
                    });
            });
        };
        $candidate = [
            'binding_id' => $foreign['binding'],
            'source_generation' => $generation,
            'source_event_id' => 2,
            'fingerprint' => str_repeat('b', 64),
            'evse_number' => 1, 'connector_number' => 1,
            'status' => 'Available', 'error_code' => 'NoError',
            'occurred_at' => now()->subMinutes(5), 'created_at' => now(),
        ];
        DB::table('charging_status_observations')->insert($candidate);
        $scan();
        $this->assertSame([], $recovered);
        $this->assertNull(DB::table('charging_fault_alerts')->where('id', $alert->id)->value('recovery_observation_id'));

        // This retained historical row lacks explicit OCPP 1.6 clearance.
        // Null is only valid for the 2.0.1 status projection.
        $local = array_replace($candidate, [
            'binding_id' => $binding->id, 'source_event_id' => 3,
            'fingerprint' => str_repeat('c', 64), 'error_code' => null,
        ]);
        $localId = DB::table('charging_status_observations')->insertGetId($local);
        $scan();
        if ($protocol === '1.6') {
            $this->assertSame([], $recovered);
            $this->assertNull(DB::table('charging_fault_alerts')->where('id', $alert->id)->value('recovery_observation_id'));
            $localId = DB::table('charging_status_observations')->insertGetId(array_replace($local, [
                'source_event_id' => 4, 'fingerprint' => str_repeat('d', 64),
                'error_code' => 'NoError',
            ]));
            $scan();
        }
        $this->assertSame([(int) $alert->id], $recovered);
        $this->assertDatabaseHas('charging_fault_alerts', [
            'id' => $alert->id, 'recovery_observation_id' => $localId,
        ]);
        $scan();
        $this->assertSame([(int) $alert->id], $recovered);
        $this->assertDatabaseCount('charger_commands', 0);
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    #[DataProvider('protocols')]
    public function test_manual_fault_inspection_cannot_bypass_worker_protocol_receipt_validation(string $protocol): void
    {
        $f = $this->fixture($protocol);
        $this->travelTo(now()->startOfSecond());
        $url = '/api/vendor-ops/companies/'.$f['company']->id.'/chargers/'.$f['charger']->id.'/fault-alerts/inspect';
        $inspect = function (array $events) use ($f, $url) {
            Http::swap(new Factory);
            Http::preventStrayRequests();
            Http::fakeSequence()
                ->push(['data' => ['ChargingStations' => [['id' => 42, 'protocol' => $f['protocol'], 'isOnline' => true]]]])
                ->push(['data' => ['StatusNotifications' => $events]]);

            return $this->actingAs($f['owner'])->postJson($url);
        };
        $events = $this->events(1, 3);
        $inspect([$events[0]])->assertOk()->assertJsonPath('alerts_created', 1);
        $alert = DB::table('charging_fault_alerts')->firstOrFail();
        $this->assertNull($alert->recovery_observation_id);

        $invalid = $protocol === '1.6'
            ? [
                ['connectorStatus' => 'Occupied'],
                ['errorCode' => null],
                ['errorCode' => 'VendorDefinedFault'],
                ['errorCode' => 'GroundFailure'],
                ['connectorStatus' => 'Faulted', 'errorCode' => 'NoError'],
            ]
            : [
                ['connectorStatus' => 'Preparing'],
                ['connectorStatus' => 'Charging'],
                ['connectorStatus' => 'VendorDefinedState'],
            ];
        foreach ($invalid as $patch) {
            // The newest row is valid recovery, but the older invalid
            // row must invalidate the complete manual inspection batch.
            $inspect([$events[2], array_replace($events[1], $patch)])
                ->assertServiceUnavailable();
            $this->assertDatabaseCount('charging_status_observations', 1);
            $this->assertDatabaseCount('charging_fault_alerts', 1);
            $this->assertNull(DB::table('charging_fault_alerts')->where('id', $alert->id)->value('recovery_observation_id'));
        }
        $inspect([$events[2], $events[1]])->assertOk()->assertJsonPath('alerts_created', 0);
        $this->assertDatabaseCount('charging_status_observations', 3);
        $this->assertNotNull(DB::table('charging_fault_alerts')->where('id', $alert->id)->value('recovery_observation_id'));
        $inspect([$events[2], $events[1]])->assertOk()->assertJsonPath('alerts_created', 0);
        $this->assertDatabaseCount('charging_status_observations', 3);
        $this->assertDatabaseCount('charging_fault_alerts', 1);
        $this->assertDatabaseCount('charger_commands', 0);
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    #[DataProvider('protocols')]
    public function test_manual_and_automatic_fault_notices_share_first_transition_and_never_duplicate(string $protocol): void
    {
        $f = $this->fixture($protocol);
        $this->travelTo(now()->startOfSecond());
        $events = $this->events(1, 2);
        if ($protocol === '1.6') {
            $events[0]['evseId'] = null;
            $events[1]['evseId'] = null;
        }
        $url = '/api/vendor-ops/companies/'.$f['company']->id.'/chargers/'.$f['charger']->id.'/fault-alerts/inspect';
        $inspect = function (array $page) use ($f, $url) {
            Http::swap(new Factory);
            Http::preventStrayRequests();
            Http::fakeSequence()
                ->push(['data' => ['ChargingStations' => [['id' => 42, 'protocol' => $f['protocol'], 'isOnline' => true]]]])
                ->push(['data' => ['StatusNotifications' => $page]]);

            return $this->actingAs($f['owner'])->postJson($url);
        };

        // Manual or legacy CLI inspection can discover the fault before the
        // continuous cursor. The worker must not emit a duplicate on replay.
        $inspect([$events[0]])->assertOk()->assertJsonPath('alerts_created', 1);
        $notice = InboxNotification::where('kind', 'charging.fault.simulated')->sole();
        $this->assertSame($f['owner']->id, $notice->user_id);
        $this->assertFalse($notice->metadata['physical_verification']);
        $this->source($f, [$events[0]], newest: 1);
        app(ContinuousFaultObserver::class)->observe($f['binding']);
        $this->assertDatabaseCount('inbox_notifications', 1);

        // A manual inspection may also observe the terminal recovery before
        // the worker. Both transitions must remain owner-scoped and durable.
        $inspect([$events[1]])->assertOk()->assertJsonPath('alerts_created', 0);
        $recovery = InboxNotification::where('kind', 'charging.fault.recovered.simulated')->sole();
        $this->assertSame($notice->user_id, $recovery->user_id);
        $this->assertSame($notice->metadata['alert_id'], $recovery->metadata['alert_id']);
        $this->assertSame($f['company']->id, $recovery->metadata['company_id']);
        $this->assertSame($f['charger']->id, $recovery->metadata['charger_id']);
        $this->assertFalse($recovery->metadata['physical_verification']);
        $inspect([$events[1], $events[0]])->assertOk()->assertJsonPath('alerts_created', 0);
        $this->source($f, [$events[1]], newest: 2);
        app(ContinuousFaultObserver::class)->observe($f['binding']);
        $this->assertDatabaseCount('inbox_notifications', 2);
        $this->assertDatabaseCount('charging_fault_alerts', 1);
        $this->assertDatabaseCount('charging_status_observations', 2);
        $this->assertDatabaseCount('charger_commands', 0);
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    public function test_cli_fault_review_classification_redacts_exception_text_and_preserves_failure(): void
    {
        $f = $this->fixture('1.6');
        $events = $this->events(1, 2);
        $events[0]['errorCode'] = null;
        $this->source($f, $events, newest: 2);
        $this->artisan('csms:observe-faults', ['--once' => true, '--binding' => $f['binding']])
            ->expectsOutput('Binding '.$f['binding'].' requires source/access review. No command sent. [review:protocol_receipt]')
            ->assertFailed();
        $this->assertDatabaseHas('charging_fault_cursors', ['binding_id' => $f['binding'], 'last_event_id' => 0, 'state' => 'requires_review']);
        $this->assertDatabaseCount('charging_status_observations', 0);
        $this->assertDatabaseCount('charging_fault_alerts', 0);
        $this->assertDatabaseCount('charger_commands', 0);
        $this->assertDatabaseCount('finance_invoices', 0);
        $command = \App\Console\Commands\ObserveChargerFaults::class;
        foreach ([
            [503, 'Malformed source component identity.', 'component_identity'],
            [503, 'Conflicting same-time source status receipts.', 'same_time_conflict'],
            [503, 'Source status chronology regressed.', 'source_chronology'],
            [503, 'PRIVATE-TRANSPORT-SECRET', 'source_unavailable'],
            [409, 'PRIVATE-MAPPING-DETAIL', 'scope_or_lineage'],
        ] as [$status, $message, $expected]) {
            $this->assertSame($expected, $command::reviewCode(new HttpException($status, $message)));
        }
        $this->assertSame('worker_internal_failure', $command::reviewCode(new \RuntimeException('PRIVATE-DATABASE-CREDENTIAL')));
    }
}
