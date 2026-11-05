<?php

namespace Tests\Feature;

use App\Models\ChargerCommand;
use App\Models\Reservation;
use App\Models\Station;
use App\Models\User;
use App\Services\CsmsDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CommandWorkerTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): ChargerCommand
    {
        Http::preventStrayRequests();
        config(['csms.command_worker_enabled' => true, 'csms.session_bridge_enabled' => true, 'csms.simulator_enabled' => true]);
        $vendor = User::factory()->create(['role' => 'vendor']);
        $customer = User::factory()->create(['role' => 'customer']);
        $station = Station::create(['vendor_id' => $vendor->id, 'name' => 'Worker fixture', 'country' => 'AE', 'currency' => 'AED', 'timezone' => 'Asia/Dubai', 'latitude' => 25, 'longitude' => 55, 'source' => 'simulator', 'connector_type' => 'CCS2', 'power_kw' => 50]);
        DB::table('csms_bindings')->insert(['station_id' => $station->id, 'identifier' => 'ev-test-worker', 'protocol' => '1.6', 'created_at' => now(), 'updated_at' => now()]);
        $r = Reservation::create(['user_id' => $customer->id, 'station_id' => $station->id, 'starts_at' => now()->subMinutes(5), 'idempotency_key' => 'worker']);
        $this->actingAs($customer)->postJson('/api/reservations/'.$r->id.'/csms-start')->assertAccepted();

        return ChargerCommand::firstOrFail();
    }

    public function test_worker_only_claims_pending_canonical_commands_and_never_replays_unknown_or_unlinked_work(): void
    {
        $c = $this->fixture();
        foreach (['pending', 'outcome_unknown', 'submitted', 'dispatching'] as $status) {
            ChargerCommand::create(['actor_id' => $c->actor_id, 'station_id' => $c->station_id, 'action' => 'start', 'payload' => [], 'idempotency_key' => 'unlinked-'.$status, 'payload_hash' => str_repeat('a', 64), 'status' => $status]);
        }
        $this->mock(CsmsDispatcher::class)->shouldReceive('dispatch')->once()->with($c->id)->andReturnUsing(function () use ($c) {
            $c->update(['status' => 'outcome_unknown']);

            return $c;
        });
        $this->artisan('csms:dispatch-pending', ['--once' => true, '--station' => (string) $c->station_id])->assertSuccessful();
        $this->artisan('csms:dispatch-pending', ['--once' => true])->assertSuccessful();
        Http::assertNothingSent();
        $this->assertSame('outcome_unknown', $c->fresh()->status);
    }

    public function test_pre_claim_failure_becomes_operator_review_without_a_retry_loop(): void
    {
        $c = $this->fixture();
        $this->mock(CsmsDispatcher::class)->shouldReceive('dispatch')->once()->with($c->id)->andThrow(new \RuntimeException('PRIVATE SOURCE FAILURE'));
        $this->artisan('csms:dispatch-pending', ['--once' => true])->assertFailed();
        $this->assertSame('worker_requires_operator_review', $c->fresh()->failure_code);
        $this->artisan('csms:dispatch-pending', ['--once' => true])->assertSuccessful();
        Http::assertNothingSent();
        $this->assertDatabaseCount('charging_sessions', 1);
    }

    public function test_worker_requires_explicit_local_opt_in_and_valid_bounds(): void
    {
        config(['csms.command_worker_enabled' => false]);
        $this->artisan('csms:dispatch-pending', ['--once' => true])->assertFailed();
        config(['csms.command_worker_enabled' => true, 'csms.simulator_enabled' => true]);
        $this->artisan('csms:dispatch-pending', ['--once' => true, '--limit' => '101'])->assertExitCode(2);
        $this->app['env'] = 'production';
        $this->artisan('csms:dispatch-pending', ['--once' => true])->assertFailed();
    }
}
