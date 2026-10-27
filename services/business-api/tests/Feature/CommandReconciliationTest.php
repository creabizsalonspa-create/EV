<?php

namespace Tests\Feature;

use App\Models\ChargerCommand;
use App\Models\Charging\SessionLink;
use App\Models\ChargingSession;
use App\Models\Reservation;
use App\Models\Station;
use App\Models\User;
use App\Services\Charging\CommandReconciler;
use App\Services\Charging\SessionBridge;
use App\Services\CsmsDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CommandReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        Http::preventStrayRequests();
        config(['csms.session_bridge_enabled' => true, 'csms.simulator_enabled' => true, 'csms.base_url' => 'http://csms.test']);
        $this->travelTo(now()->startOfSecond());
        $vendor = User::factory()->create(['role' => 'vendor']);
        $customer = User::factory()->create();
        $station = Station::create(['vendor_id' => $vendor->id, 'name' => 'Recovery simulator', 'country' => 'AE', 'currency' => 'AED', 'timezone' => 'Asia/Dubai', 'latitude' => 25, 'longitude' => 55, 'source' => 'simulator', 'connector_type' => 'CCS2', 'power_kw' => 50]);
        DB::table('csms_bindings')->insert(['station_id' => $station->id, 'identifier' => 'ev-test-recovery', 'protocol' => '1.6', 'created_at' => now(), 'updated_at' => now()]);
        $r = Reservation::create(['user_id' => $customer->id, 'station_id' => $station->id, 'starts_at' => now()->subMinutes(5), 'idempotency_key' => 'recovery']);
        $this->actingAs($customer)->postJson('/api/reservations/'.$r->id.'/csms-start')->assertAccepted();

        return [$vendor, $customer, ChargingSession::firstOrFail(), ChargerCommand::firstOrFail()];
    }

    private function event(ChargingSession $s, string $type = 'started'): array
    {
        $end = $type === 'ended';

        return ['event_id' => 'recovery-'.$s->id.'-'.$type, 'session_id' => $s->id, 'identifier' => 'ev-test-recovery', 'protocol' => '1.6', 'transaction_id' => '123', 'type' => $type, 'sequence' => $end ? 1 : 0, 'meter_wh' => $end ? 3500 : 1000, 'occurred_at' => now()->addSeconds($end ? 1 : 0)->toISOString()];
    }

    public function test_authenticated_applied_source_recovers_unknown_start_and_stop_without_network_or_acceptance_claim(): void
    {
        [$vendor, $customer, $session, $start] = $this->fixture();
        $start->update(['status' => 'outcome_unknown', 'failure_code' => 'dispatch_requires_reconciliation']);
        $bridge = app(SessionBridge::class);
        $bridge->ingest($this->event($session));
        $this->assertSame('source_observed', $start->fresh()->status);
        $this->assertDatabaseHas('command_source_observations', ['command_id' => $start->id, 'prior_status' => 'outcome_unknown', 'prior_failure_code' => 'dispatch_requires_reconciliation', 'observed_state' => 'started', 'command_acceptance_verified' => false]);
        $bridge->ingest($this->event($session));
        $this->assertSame('source_observed', app(CsmsDispatcher::class)->dispatch($start->id)->status);
        $bridge->stop($session->fresh(), $customer->id, 'customer');
        $stop = ChargerCommand::where('action', 'stop')->firstOrFail();
        $stop->update(['status' => 'dispatching']);
        $bridge->ingest($this->event($session, 'ended'));
        $this->assertSame('source_observed', $stop->fresh()->status);
        $this->assertDatabaseCount('command_source_observations', 2);
        $this->assertDatabaseCount('finance_invoices', 1);
        $this->postJson('/api/charging-sessions/'.$session->id.'/reconcile')->assertOk()->assertJsonPath('reconciled', 0)->assertJsonPath('network_requests', 0)->assertJsonPath('command_acceptance_verified', false);
        Http::assertNothingSent();
    }

    public function test_missing_or_unapplied_evidence_never_recovers_and_scope_is_checked_before_work(): void
    {
        [$vendor, $customer, $session, $start] = $this->fixture();
        $start->update(['status' => 'outcome_unknown']);
        $url = '/api/charging-sessions/'.$session->id.'/reconcile';
        $this->actingAs(User::factory()->create())->postJson($url)->assertNotFound();
        $this->actingAs(User::factory()->create(['role' => 'vendor']))->postJson($url)->assertNotFound();
        $this->actingAs($vendor)->postJson($url)->assertOk()->assertJsonPath('reconciled', 0);
        $this->assertSame('outcome_unknown', $start->fresh()->status);
        $this->assertDatabaseCount('command_source_observations', 0);
        Http::assertNothingSent();
        $this->actingAs($customer)->deleteJson('/api/reservations/'.$session->reservation_id)->assertConflict();
        $link = SessionLink::where('charging_session_id', $session->id)->firstOrFail();
        $link->update(['transaction_id' => '123', 'started_at' => now()]);
        $session->update(['status' => 'charging']);
        DB::table('csms_inbox_events')->insert(['link_id' => $link->id, 'event_id' => 'unapplied-import', 'fingerprint' => str_repeat('a', 64), 'type' => 'started', 'sequence' => 0, 'meter_wh' => 1000, 'occurred_at' => now(), 'outcome' => 'stale', 'created_at' => now()]);
        $this->postJson($url)->assertOk()->assertJsonPath('reconciled', 0);
        $this->assertSame('outcome_unknown', $start->fresh()->status);
        $this->assertDatabaseCount('command_source_observations', 0);
        Http::assertNothingSent();
    }

    public static function lateReplies(): array
    {
        return [[true], [false]];
    }

    #[DataProvider('lateReplies')]
    public function test_late_transport_reply_does_not_overwrite_observed_source_state(bool $lost): void
    {
        [$vendor, $customer, $session, $start] = $this->fixture();
        $attempts = 0;
        Http::fake(function ($request) use ($session, $lost, &$attempts) {
            $attempts++;
            if (str_ends_with($request->url(), '/dev/token')) {
                return Http::response(['accessToken' => 'synthetic-only']);
            }
            app(SessionBridge::class)->ingest($this->event($session));
            if ($lost) {
                throw new ConnectionException('Simulated response lost after source delivery');
            }

            return Http::response([['success' => true]]);
        });
        $this->assertSame('source_observed', app(CsmsDispatcher::class)->dispatch($start->id)->status);
        app(CsmsDispatcher::class)->dispatch($start->id);
        $this->assertSame(2, $attempts);
        Http::assertSentCount($lost ? 1 : 2);
        $this->assertSame($lost ? null : [['success' => true]], $start->fresh()->confirmation);
        $this->assertDatabaseCount('command_source_observations', 1);
        $this->assertDatabaseHas('command_source_observations', ['prior_status' => 'dispatching']);
    }

    public function test_versioned_bearer_charging_routes_preserve_scope_and_source_admission(): void
    {
        [$vendor, $customer, $session, $start] = $this->fixture();
        $this->app['auth']->forgetGuards();
        $path = '/api/v1/reservations/'.$session->reservation_id.'/csms-start';
        $this->postJson($path)->assertUnauthorized();
        $other = User::factory()->create();
        $this->withToken($other->createToken('other')->plainTextToken)->postJson($path)->assertNotFound();
        $this->app['auth']->forgetGuards();
        $this->withToken($customer->createToken('owned')->plainTextToken)->postJson($path)->assertAccepted()->assertJsonPath('session.id', $session->id);
        $this->postJson('/api/v1/charging-sessions/'.$session->id.'/reconcile')->assertOk()->assertJsonPath('reconciled', 0)->assertJsonPath('network_requests', 0);
        $this->postJson('/api/v1/charging-sessions/'.$session->id.'/csms-stop')->assertConflict();
        app(SessionBridge::class)->ingest($this->event($session));
        $this->postJson('/api/v1/charging-sessions/'.$session->id.'/csms-stop')->assertAccepted()->assertJsonPath('session.status', 'stopping');
        Http::assertNothingSent();
    }

    public function test_committed_event_with_interrupted_followup_is_recovered_before_any_dispatch(): void
    {
        [$vendor, $customer, $session, $start] = $this->fixture();
        // Model a process stopping after source commit but before reconciliation.
        $real = app(CommandReconciler::class);
        $fake = \Mockery::mock(CommandReconciler::class);
        $fake->shouldReceive('forSession')->once()->andReturn(0);
        $this->app->instance(CommandReconciler::class, $fake);
        app(SessionBridge::class)->ingest($this->event($session));
        $this->app->instance(CommandReconciler::class, $real);
        $this->assertSame('pending', $start->fresh()->status);
        $this->assertSame('source_observed', app(CsmsDispatcher::class)->dispatch($start->id)->status);
        Http::assertNothingSent();
        $this->artisan('csms:reconcile-commands', ['--limit' => '0'])->assertExitCode(2);
        $this->artisan('csms:reconcile-commands', ['--limit' => '1'])->assertSuccessful();
        $this->assertDatabaseCount('command_source_observations', 1);
    }
}
