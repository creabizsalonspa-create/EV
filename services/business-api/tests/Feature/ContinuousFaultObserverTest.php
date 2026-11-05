<?php

namespace Tests\Feature;

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
        $alert = DB::table('charging_fault_alerts')->first();
        $this->assertNotNull($alert->recovery_observation_id);
        $path = '/api/vendor-ops/companies/'.$f['company']->id.'/chargers/'.$f['charger']->id;
        $this->actingAs($f['owner'])->getJson($path.'/fault-alerts')->assertOk()->assertJsonCount(1, 'data');
        $this->patchJson($path.'/fault-alerts/'.$alert->id.'/acknowledge', ['note' => 'Reviewed automatically observed source fault'])->assertOk();
        $this->assertDatabaseCount('charger_commands', 0);
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    public function test_failed_batch_does_not_skip_events_and_source_replacement_needs_review(): void
    {
        $f = $this->fixture();
        $this->source($f, $this->events(1, 2), newest: 2);
        app(ContinuousFaultObserver::class)->observe($f['binding']);
        $invalid = $this->events(3, 4);
        $invalid[1]['connectorId'] = -1;
        $this->source($f, $invalid, newest: 4);
        try {
            app(ContinuousFaultObserver::class)->observe($f['binding']);
            $this->fail('Invalid batch accepted');
        } catch (HttpException $e) {
            $this->assertSame(503, $e->getStatusCode());
        }
        $this->assertDatabaseCount('charging_status_observations', 2);
        $this->assertDatabaseHas('charging_fault_cursors', ['last_event_id' => 2, 'state' => 'requires_review']);
        $this->source($f, $this->events(3, 4), newest: 4);
        app(ContinuousFaultObserver::class)->observe($f['binding']);
        $this->assertDatabaseCount('charging_status_observations', 4);
        $this->source($f, [], station: 43, newest: 4);
        try {
            app(ContinuousFaultObserver::class)->observe($f['binding']);
            $this->fail('Replacement source accepted');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        $this->assertDatabaseHas('charging_fault_cursors', ['last_event_id' => 4, 'source_station_id' => 42, 'state' => 'requires_review']);
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
