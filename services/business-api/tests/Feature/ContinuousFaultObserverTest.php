<?php

namespace Tests\Feature;

use App\Models\InboxNotification;
use App\Models\Station;
use App\Models\User;
use App\Models\VendorOps\VendorCharger;
use App\Models\VendorOps\VendorCompany;
use App\Models\VendorOps\VendorSite;
use App\Services\Charging\ContinuousFaultObserver;
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
}
