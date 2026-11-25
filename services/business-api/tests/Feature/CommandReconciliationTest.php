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

    private function fixture(string $protocol = '1.6'): array
    {
        Http::preventStrayRequests();
        config(['csms.session_bridge_enabled' => true, 'csms.simulator_enabled' => true, 'csms.base_url' => 'http://csms.test']);
        $this->travelTo(now()->startOfSecond());
        $vendor = User::factory()->create(['role' => 'vendor']);
        $customer = User::factory()->create();
        $station = Station::create(['vendor_id' => $vendor->id, 'name' => 'Recovery simulator', 'country' => 'AE', 'currency' => 'AED', 'timezone' => 'Asia/Dubai', 'latitude' => 25, 'longitude' => 55, 'source' => 'simulator', 'connector_type' => 'CCS2', 'power_kw' => 50]);
        DB::table('csms_bindings')->insert(['station_id' => $station->id, 'identifier' => 'ev-test-recovery', 'protocol' => $protocol, 'created_at' => now(), 'updated_at' => now()]);
        $r = Reservation::create(['user_id' => $customer->id, 'station_id' => $station->id, 'starts_at' => now()->subMinutes(5), 'idempotency_key' => 'recovery']);
        $this->actingAs($customer)->postJson('/api/reservations/'.$r->id.'/csms-start')->assertAccepted();

        return [$vendor, $customer, ChargingSession::firstOrFail(), ChargerCommand::firstOrFail()];
    }

    private function event(ChargingSession $s, string $type = 'started', string $protocol = '1.6'): array
    {
        $end = $type === 'ended';

        return ['event_id' => 'recovery-'.$s->id.'-'.$type, 'session_id' => $s->id, 'identifier' => 'ev-test-recovery', 'protocol' => $protocol, 'transaction_id' => '123', 'type' => $type, 'sequence' => $end ? 1 : 0, 'meter_wh' => $end ? 3500 : 1000, 'occurred_at' => now()->addSeconds($end ? 1 : 0)->toISOString()];
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

    public static function stopIdentityChanges(): array
    {
        return [
            ['1.6', 'before_claim'], ['2.0.1', 'before_claim'],
            ['1.6', 'during_token'], ['2.0.1', 'during_token'],
        ];
    }

    #[DataProvider('stopIdentityChanges')]
    public function test_pending_session_stop_cannot_target_a_replaced_transaction(string $protocol, string $when): void
    {
        [, $customer, $session] = $this->fixture($protocol);
        $bridge = app(SessionBridge::class);
        $bridge->ingest($this->event($session, protocol: $protocol));
        $bridge->stop($session->fresh(), $customer->id, 'customer');
        $stop = ChargerCommand::where('action', 'stop')->firstOrFail();
        $link = SessionLink::where('charging_session_id', $session->id)->firstOrFail();
        $this->assertSame('123', $stop->payload['transaction_id']);
        if ($when === 'before_claim') {
            $link->update(['transaction_id' => '456']);
        }
        Http::fake(function ($request) use ($link, $when) {
            if (str_ends_with($request->url(), '/dev/token')) {
                if ($when === 'during_token') {
                    $link->update(['transaction_id' => '456']);
                }

                return Http::response(['accessToken' => 'synthetic-only']);
            }

            return Http::response([['success' => true]]);
        });
        $blocked = app(CsmsDispatcher::class)->dispatch($stop->id);
        $this->assertSame('pending', $blocked->status);
        $this->assertSame('pre_dispatch_source_lineage_changed', $blocked->failure_code);
        $this->assertSame('stopping', $session->fresh()->status);
        $this->assertSame('123', $blocked->payload['transaction_id']);

        // Restoring a row cannot silently clear the durable quarantine.
        $link->update(['transaction_id' => '123']);
        $again = app(CsmsDispatcher::class)->dispatch($stop->id);
        $this->assertSame('pending', $again->status);
        $this->assertSame('pre_dispatch_source_lineage_changed', $again->failure_code);
        Http::assertSentCount($when === 'during_token' ? 1 : 0);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/ocpp/'));
        $this->assertDatabaseCount('finance_invoices', 0);
        $this->assertDatabaseMissing('command_source_observations', ['command_id' => $stop->id]);
    }

    public static function stopProtocols(): array
    {
        return [['1.6'], ['2.0.1']];
    }

    #[DataProvider('stopProtocols')]
    public function test_terminal_source_evidence_cannot_reconcile_a_stop_for_another_transaction(string $protocol): void
    {
        [, $customer, $session] = $this->fixture($protocol);
        $bridge = app(SessionBridge::class);
        $bridge->ingest($this->event($session, protocol: $protocol));
        $bridge->stop($session->fresh(), $customer->id, 'customer');
        $stop = ChargerCommand::where('action', 'stop')->firstOrFail();
        $original = $stop->payload;
        $stop->update(['status' => 'outcome_unknown',
            'payload' => array_replace($original, ['transaction_id' => '456'])]);
        $bridge->ingest($this->event($session, 'ended', $protocol));
        $this->assertSame('stopped', $session->fresh()->status);
        $this->assertSame('outcome_unknown', $stop->fresh()->status);
        $this->assertFalse(app(CommandReconciler::class)->forCommand($stop->id));
        $this->assertDatabaseMissing('command_source_observations', ['command_id' => $stop->id]);
        $this->assertDatabaseCount('finance_invoices', 1);
        $stop->update(['payload' => $original]);
        $this->assertTrue(app(CommandReconciler::class)->forCommand($stop->id));
        $this->assertFalse(app(CommandReconciler::class)->forCommand($stop->id));
        $this->assertSame('source_observed', $stop->fresh()->status);
        $this->assertDatabaseHas('command_source_observations', [
            'command_id' => $stop->id, 'observed_state' => 'ended',
            'command_acceptance_verified' => false,
        ]);
        $this->assertDatabaseCount('finance_invoices', 1);
        Http::assertNothingSent();
    }

    #[DataProvider('stopProtocols')]
    public function test_applied_receipt_must_match_durable_session_meter_sequence_and_time(string $protocol): void
    {
        [, $customer, $session, $start] = $this->fixture($protocol);
        $real = app(CommandReconciler::class);
        $fake = \Mockery::mock(CommandReconciler::class);
        $fake->shouldReceive('forSession')->twice()->andReturn(0);
        $this->app->instance(CommandReconciler::class, $fake);
        $bridge = app(SessionBridge::class);
        $start->update(['status' => 'outcome_unknown']);
        $bridge->ingest($this->event($session, protocol: $protocol));
        $link = SessionLink::where('charging_session_id', $session->id)->firstOrFail();
        $events = DB::table('csms_inbox_events')->where('link_id', $link->id);
        $started = (clone $events)->where('type', 'started')->first();
        foreach (['meter_wh' => 999, 'occurred_at' => now()->subMinute()] as $field => $value) {
            DB::table('csms_inbox_events')->where('id', $started->id)->update([$field => $value]);
            $this->assertFalse($real->forCommand($start->id));
            $this->assertSame('outcome_unknown', $start->fresh()->status);
            $this->assertDatabaseCount('command_source_observations', 0);
            DB::table('csms_inbox_events')->where('id', $started->id)->update([$field => $started->{$field}]);
        }
        $this->assertTrue($real->forCommand($start->id));
        $this->assertFalse($real->forCommand($start->id));

        $bridge->stop($session->fresh(), $customer->id, 'customer');
        $stop = ChargerCommand::where('action', 'stop')->firstOrFail();
        $stop->update(['status' => 'outcome_unknown']);
        $bridge->ingest($this->event($session, 'ended', $protocol));
        $ended = (clone $events)->where('type', 'ended')->first();
        foreach (['meter_wh' => 3501, 'sequence' => 2, 'occurred_at' => now()->subMinute()] as $field => $value) {
            DB::table('csms_inbox_events')->where('id', $ended->id)->update([$field => $value]);
            $this->assertFalse($real->forCommand($stop->id));
            $this->assertSame('outcome_unknown', $stop->fresh()->status);
            $this->assertDatabaseCount('command_source_observations', 1);
            $this->assertDatabaseCount('finance_invoices', 1);
            DB::table('csms_inbox_events')->where('id', $ended->id)->update([$field => $ended->{$field}]);
        }
        $this->assertTrue($real->forCommand($stop->id));
        $this->assertFalse($real->forCommand($stop->id));
        $this->assertSame('source_observed', $stop->fresh()->status);
        $this->assertDatabaseCount('command_source_observations', 2);
        $this->assertDatabaseCount('finance_invoices', 1);
        Http::assertNothingSent();
        $this->app->instance(CommandReconciler::class, $real);
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
