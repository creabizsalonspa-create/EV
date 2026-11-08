<?php

namespace Tests\Feature;

use App\Models\ChargerCommand;
use App\Models\ChargingSession;
use App\Models\InboxNotification;
use App\Models\Reservation;
use App\Models\Station;
use App\Models\User;
use App\Services\Charging\CommandReconciler;
use App\Services\Charging\SessionBridge;
use App\Services\Charging\SourceConsumer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ChargingOperationsTest extends TestCase
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

    public function test_uncertain_case_cannot_release_capacity_or_repeat_commands_without_source(): void
    {
        [$vendor,$customer,$session,$command] = $this->fixture();
        $command->update(['status' => 'outcome_unknown']);
        $url = '/api/charging-operations/'.$command->id;
        $this->postJson($url.'/case', ['note' => 'Investigate source absence'])->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'vendor']))->postJson($url.'/case', ['note' => 'Investigate source absence'])->assertNotFound();
        $this->actingAs($vendor)->postJson($url.'/case', ['note' => 'Investigate source absence'])->assertCreated()->assertJsonPath('state', 'open');
        $this->postJson($url.'/case', ['note' => 'Repeated request unchanged'])->assertOk();
        $this->patchJson($url.'/case', ['state' => 'resolved', 'note' => 'No hardware evidence exists'])->assertConflict();
        $this->patchJson($url.'/case', ['state' => 'investigating', 'note' => 'Checking authorized source logs'])->assertOk()->assertJsonPath('capacity_release_forced', false);
        $mock = \Mockery::mock(SourceConsumer::class);
        $mock->shouldReceive('consume')->once()->andReturn(0);
        $this->app->instance(SourceConsumer::class, $mock);
        $this->postJson($url.'/inspect-source')->assertOk()->assertJsonPath('events_received', 0)->assertJsonPath('source_observed', false)->assertJsonPath('command_resent', false);
        $this->getJson('/api/charging-operations')->assertOk()->assertJsonPath('data.0.case.state', 'awaiting_source');
        $this->assertSame('outcome_unknown', $command->fresh()->status);
        $this->assertSame('pending_start', $session->fresh()->status);
        $this->assertDatabaseCount('charger_commands', 1);
        $this->assertDatabaseCount('finance_invoices', 0);
        $this->assertStringNotContainsString('source absence', DB::table('charging_operational_cases')->value('operator_note'));
        Http::assertNothingSent();
    }

    public function test_source_inspection_rechecks_vendor_ownership_after_inflight_source_read(): void
    {
        [$vendor, $customer, $session, $command] = $this->fixture();
        $command->update(['status' => 'outcome_unknown']);
        $url = '/api/charging-operations/'.$command->id;
        $this->actingAs($vendor)->postJson($url.'/case', [
            'note' => 'Review isolated source without commands',
        ])->assertCreated();
        $oldCount = DB::table('charging_operational_case_events')->count();

        $newOwner = User::factory()->create(['role' => 'vendor']);
        $source = \Mockery::mock(SourceConsumer::class);
        $source->shouldReceive('consume')->once()->andReturnUsing(
            function () use ($command, $newOwner): int {
                Station::whereKey($command->station_id)
                    ->update(['vendor_id' => $newOwner->id]);

                return 0;
            }
        );
        $reconciler = \Mockery::mock(CommandReconciler::class);
        $reconciler->shouldNotReceive('forSession');
        $this->app->instance(SourceConsumer::class, $source);
        $this->app->instance(CommandReconciler::class, $reconciler);

        $this->postJson($url.'/inspect-source')->assertNotFound();
        $this->assertSame(
            $oldCount,
            DB::table('charging_operational_case_events')->count()
        );
        $this->assertDatabaseHas('charging_operational_cases', [
            'command_id' => $command->id,
            'state' => 'open',
        ]);
        $this->assertDatabaseCount('finance_invoices', 0);
        Http::assertNothingSent();
    }

    public function test_recovery_case_and_event_pagination_are_bounded_and_owner_scoped(): void
    {
        [$vendor, $customer, $session, $command] = $this->fixture();
        $command->update(['status' => 'outcome_unknown']);
        $this->actingAs($vendor)->postJson('/api/charging-operations/'.$command->id.'/case', [
            'note' => 'Inspecting the limited recovery page',
        ])->assertCreated();

        foreach (['0', '-1', 'garbage', '1000001', '1.5'] as $invalid) {
            $this->getJson('/api/charging-operations?page='.$invalid)
                ->assertUnprocessable();
            $this->getJson('/api/charging-operations/'.$command->id.'/case?page='.$invalid)
                ->assertUnprocessable();
        }
        $this->getJson('/api/charging-operations?page=1')->assertOk()
            ->assertJsonPath('total', 1);
        $this->getJson('/api/charging-operations/'.$command->id.'/case?page=1')
            ->assertOk()->assertJsonPath('events.total', 1);

        $outsider = User::factory()->create(['role' => 'vendor']);
        $this->actingAs($outsider)->getJson('/api/charging-operations/'.$command->id.'/case?page=bad')
            ->assertNotFound();
        $this->getJson('/api/charging-operations?page=1')
            ->assertOk()->assertJsonPath('total', 0);
        $this->assertDatabaseCount('finance_invoices', 0);
        Http::assertNothingSent();
    }

    public function test_operational_case_open_notice_is_owner_scoped_and_replay_idempotent(): void
    {
        [$vendor, $customer, $session, $command] = $this->fixture();
        $command->update(['status' => 'outcome_unknown']);
        $url = '/api/charging-operations/'.$command->id;

        $this->actingAs($vendor)->postJson($url.'/case', [
            'note' => 'Investigating source evidence absence',
        ])->assertCreated();
        $this->postJson($url.'/case', [
            'note' => 'Duplicate case-open attempt',
        ])->assertOk();

        $note = InboxNotification::where('kind', 'charging.operational_case.opened')
            ->firstOrFail();
        $this->assertSame($vendor->id, $note->user_id);
        $this->assertSame($command->id, $note->metadata['command_id']);
        $this->assertSame(false, $note->metadata['physical_verification']);
        $this->assertSame(1, InboxNotification::where('kind', 'charging.operational_case.opened')->count());

        $this->getJson('/api/v1/notifications?category=charging')
            ->assertOk()->assertJsonPath('total', 1);
        $this->actingAs($customer)->getJson('/api/v1/notifications?category=charging')
            ->assertOk()->assertJsonPath('total', 0);
        $this->assertDatabaseCount('charger_commands', 1);
        $this->assertDatabaseCount('finance_invoices', 0);
        Http::assertNothingSent();
    }

    public function test_first_source_observation_notifies_vendor_once_without_hardware_actions(): void
    {
        [$vendor, $customer, $session, $command] = $this->fixture();
        $command->update(['status' => 'outcome_unknown']);
        $url = '/api/charging-operations/'.$command->id;
        $this->actingAs($vendor)->postJson($url.'/case', [
            'note' => 'Checking source for uncertain command',
        ])->assertCreated();

        app(SessionBridge::class)->ingest($this->event($session));
        $source = \Mockery::mock(SourceConsumer::class);
        $source->shouldReceive('consume')->twice()->andReturn(0);
        $reconciler = \Mockery::mock(CommandReconciler::class);
        $reconciler->shouldReceive('forSession')->twice()->andReturn(0);
        $this->app->instance(SourceConsumer::class, $source);
        $this->app->instance(CommandReconciler::class, $reconciler);

        $this->postJson($url.'/inspect-source')->assertOk()
            ->assertJsonPath('source_observed', true)
            ->assertJsonPath('command_resent', false)
            ->assertJsonPath('capacity_release_forced', false);
        $this->postJson($url.'/inspect-source')->assertOk()
            ->assertJsonPath('source_observed', true);

        $this->assertSame(1, InboxNotification::where('kind', 'charging.operational_case.opened')->count());
        $this->assertSame(1, InboxNotification::where('kind', 'charging.operational_case.source_observed')->count());
        $this->assertSame(1, DB::table('charging_operational_case_events')
            ->where('action', 'source_observed_notice')->count());
        $this->actingAs($customer)->getJson('/api/notifications?category=charging')
            ->assertOk()->assertJsonPath('total', 0);
        $this->assertDatabaseCount('charger_commands', 1);
        $this->assertDatabaseCount('finance_invoices', 0);
        Http::assertNothingSent();
    }

    public function test_operational_case_inbox_requires_current_station_command_and_case_relationship(): void
    {
        [$vendor, $customer, $session, $command] = $this->fixture();
        $command->update(['status' => 'outcome_unknown']);
        $this->actingAs($vendor)->postJson('/api/charging-operations/'.$command->id.'/case', [
            'note' => 'Verify the original case belongs to this command',
        ])->assertCreated();
        $notice = InboxNotification::where('kind', 'charging.operational_case.opened')->firstOrFail();
        $original = $notice->metadata;
        $prebuilt = InboxNotification::visibleTo($vendor)->whereKey($notice->id);
        $this->assertTrue($prebuilt->exists());
        $this->getJson('/api/notifications?category=charging')
            ->assertOk()->assertJsonPath('total', 1);

        // A valid station alone must not authorize arbitrary command/case IDs.
        foreach ([
            ['station_id' => $command->station_id + 1000],
            ['command_id' => $command->id + 1000],
            ['case_id' => $original['case_id'] + 1000],
        ] as $invalid) {
            $notice->update(['metadata' => array_replace($original, $invalid)]);
            $this->assertFalse($prebuilt->exists());
            foreach (['/api/notifications', '/api/v1/notifications'] as $prefix) {
                $this->getJson($prefix.'?category=charging')
                    ->assertOk()->assertJsonPath('total', 0);
                $this->getJson($prefix.'/summary')
                    ->assertOk()->assertJsonPath('unread_count', 0);
                $this->postJson($prefix.'/'.$notice->id.'/read')->assertNotFound();
                $this->postJson($prefix.'/'.$notice->id.'/unread')->assertNotFound();
                $this->postJson($prefix.'/read-through', ['through_id' => $notice->id])
                    ->assertOk()->assertJsonPath('marked_read', 0);
            }
        }

        $notice->update(['metadata' => $original]);
        $this->assertTrue($prebuilt->exists());
        $next = User::factory()->create(['role' => 'vendor']);
        Station::whereKey($command->station_id)->update(['vendor_id' => $next->id]);
        $this->assertFalse($prebuilt->exists());
        $this->getJson('/api/notifications?category=charging')
            ->assertOk()->assertJsonPath('total', 0);
        $this->actingAs($next)->getJson('/api/notifications?category=charging')
            ->assertOk()->assertJsonPath('total', 0);
        Station::whereKey($command->station_id)->update(['vendor_id' => $vendor->id]);
        $this->actingAs($vendor)->getJson('/api/notifications?category=charging')
            ->assertOk()->assertJsonPath('total', 1);
        $this->assertNull($notice->fresh()->read_at);
        $this->assertDatabaseCount('charger_commands', 1);
        $this->assertDatabaseCount('finance_invoices', 0);
        Http::assertNothingSent();
    }

    public function test_operator_role_demotion_revokes_existing_session_for_reads_and_writes(): void
    {
        [$vendor, $customer, $session, $command] = $this->fixture();
        $command->update(['status' => 'outcome_unknown']);
        $uri = '/api/charging-operations/'.$command->id;
        $this->actingAs($vendor)->postJson($uri.'/case', [
            'note' => 'Case opened before authorized role change',
        ])->assertCreated();
        $this->getJson('/api/charging-operations')->assertOk()->assertJsonPath('total', 1);

        // Change the database record while the authenticated User object
        // remains hydrated with its former vendor role.
        DB::table('users')->where('id', $vendor->id)->update(['role' => 'customer']);
        $this->getJson('/api/charging-operations')->assertForbidden();
        $this->getJson($uri.'/case')->assertForbidden();
        $this->postJson($uri.'/case', ['note' => 'Replay with revoked privileges'])
            ->assertForbidden();
        $this->patchJson($uri.'/case', [
            'state' => 'investigating', 'note' => 'Stale vendor review blocked',
        ])->assertForbidden();
        $this->postJson($uri.'/inspect-source')->assertForbidden();

        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->getJson($uri.'/case')->assertOk();
        DB::table('users')->where('id', $admin->id)->update(['role' => 'vendor']);
        $this->getJson($uri.'/case')->assertNotFound();
        $this->getJson('/api/charging-operations')
            ->assertOk()->assertJsonPath('total', 0);
        $this->patchJson($uri.'/case', [
            'state' => 'investigating', 'note' => 'Stale admin review blocked',
        ])->assertNotFound();
        $this->assertDatabaseCount('charging_operational_cases', 1);
        $this->assertDatabaseCount('charger_commands', 1);
        $this->assertDatabaseCount('finance_invoices', 0);
        Http::assertNothingSent();
    }

    public function test_source_inspection_rechecks_role_after_remote_source_read(): void
    {
        [$vendor, $customer, $session, $command] = $this->fixture();
        $command->update(['status' => 'outcome_unknown']);
        $uri = '/api/charging-operations/'.$command->id;
        $this->actingAs($vendor)->postJson($uri.'/case', [
            'note' => 'Case opened for delayed role revocation test',
        ])->assertCreated();
        $eventsBefore = DB::table('charging_operational_case_events')->count();
        $consumer = \Mockery::mock(SourceConsumer::class);
        $consumer->shouldReceive('consume')->once()->andReturnUsing(
            function () use ($vendor): int {
                DB::table('users')->where('id', $vendor->id)->update(['role' => 'customer']);

                return 0;
            }
        );
        $reconciler = \Mockery::mock(CommandReconciler::class);
        $reconciler->shouldNotReceive('forSession');
        $this->app->instance(SourceConsumer::class, $consumer);
        $this->app->instance(CommandReconciler::class, $reconciler);

        $this->postJson($uri.'/inspect-source')->assertForbidden();
        $this->assertSame($eventsBefore, DB::table('charging_operational_case_events')->count());
        $this->assertDatabaseHas('charging_operational_cases', [
            'command_id' => $command->id, 'state' => 'open',
        ]);
        $this->assertDatabaseCount('finance_invoices', 0);
        Http::assertNothingSent();
    }

    public function test_proof_is_required_for_case_resolution_and_tenant_scope_precedes_source_reads(): void
    {
        [$vendor,$customer,$session,$command] = $this->fixture();
        $command->update(['status' => 'outcome_unknown']);
        $url = '/api/charging-operations/'.$command->id;
        $this->actingAs($vendor)->postJson($url.'/case', ['note' => 'Investigating uncertain dispatch'])->assertCreated();
        $mock = \Mockery::mock(SourceConsumer::class);
        $mock->shouldNotReceive('consume');
        $this->app->instance(SourceConsumer::class, $mock);
        $this->actingAs(User::factory()->create(['role' => 'vendor']))->postJson($url.'/inspect-source')->assertNotFound();
        app(SessionBridge::class)->ingest($this->event($session));
        $this->actingAs($vendor)->patchJson($url.'/case', ['state' => 'resolved', 'note' => 'Authenticated source start observed'])->assertOk()->assertJsonPath('capacity_release_forced', false);
        $this->patchJson($url.'/case', ['state' => 'resolved', 'note' => 'Repeated source-evidence review closure'])->assertOk();
        $this->assertSame(1, InboxNotification::where('kind', 'charging.operational_case.review_resolved')->count());
        $notice = InboxNotification::where('kind', 'charging.operational_case.review_resolved')->firstOrFail();
        $this->assertSame($vendor->id, $notice->user_id);
        $this->assertSame(false, $notice->metadata['physical_verification']);
        $this->assertSame(true, $notice->metadata['source_observed']);
        $this->actingAs($customer)->getJson('/api/v1/notifications?category=charging')
            ->assertOk()->assertJsonPath('total', 0);
        $this->assertSame('charging', $session->fresh()->status);
        $this->assertSame('source_observed', $command->fresh()->status);
        $this->assertDatabaseCount('command_source_observations', 1);
        $this->assertDatabaseCount('charger_commands', 1);
        Http::assertNothingSent();
    }
}
