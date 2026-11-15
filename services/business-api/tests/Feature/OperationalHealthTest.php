<?php

namespace Tests\Feature;

use App\Http\Controllers\AdminOperationalHealthController;
use App\Models\User;
use App\Services\Operations\OperationalHealth;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\OperationalHealthProbe;
use Tests\TestCase;

class OperationalHealthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['operational_health.enabled' => true, 'queue.default' => 'database', 'queue.connections.database.connection' => null, 'queue.connections.database.queue' => 'default']);
        $this->travelTo(CarbonImmutable::parse('2026-11-14T12:00:00.900000Z'));
    }

    public function test_admin_only_snapshots_are_private_and_do_not_manufacture_heartbeats(): void
    {
        $url = '/api/admin/operational-health';
        $this->getJson($url)->assertUnauthorized();
        foreach (['customer', 'vendor'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))->getJson($url)->assertForbidden();
        }
        $this->actingAs(User::factory()->create(['role' => 'admin']))->getJson($url)->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('components.0.state', 'unknown')->assertJsonPath('components.1.state', 'unknown')
            ->assertJsonPath('queue.ready', 0)->assertJsonPath('job_success_verified', false)->assertJsonPath('production_readiness_verified', false);
        $this->assertDatabaseCount('operational_heartbeats', 0);
        config(['admin_security.enforce_in_local_tests' => true]);
        $this->getJson($url)->assertForbidden()->assertJsonPath('admin_second_factor_required', true);
    }

    public function test_real_database_worker_and_scheduler_command_emit_scoped_observations(): void
    {
        Bus::dispatch(new OperationalHealthProbe(User::factory()->create()->id));
        $this->assertDatabaseCount('jobs', 1);
        $this->assertSame(0, Artisan::call('queue:work', ['connection' => 'database', '--stop-when-empty' => true, '--sleep' => 0, '--tries' => 1, '--timeout' => 5, '--memory' => 512]));
        $this->assertDatabaseCount('jobs', 0);
        $this->assertSame(0, DB::table('failed_jobs')->count(), substr(strtok(DB::table('failed_jobs')->value('exception') ?? '', "\n"), 0, 200));
        $this->assertDatabaseHas('audit_events', ['action' => 'health.synthetic_job_processed']);
        $this->assertSame(0, Artisan::call('platform:health-heartbeat'));
        $report = app(OperationalHealth::class)->report();
        $this->assertSame(['recent', 'recent'], array_column($report['components'], 'state'), json_encode($report['components']).$report['as_of']);
        $this->assertSame($report['as_of'], $report['components'][0]['observed_at']);
        $this->assertSame($report['as_of'], $report['components'][1]['observed_at']);
        $this->assertFalse($report['all_workers_verified']);
        $this->assertFalse($report['job_success_verified']);
        $this->assertFalse($report['charger_connectivity_verified']);
        $this->assertDatabaseCount('operational_heartbeats', 2);
        $this->assertStringNotContainsString('PRIVATE-HEALTH-JOB-PAYLOAD', json_encode($report));
    }

    public function test_scope_changes_future_and_expired_heartbeats_fail_closed_and_writes_are_coalesced(): void
    {
        $health = app(OperationalHealth::class);
        $this->assertTrue($health->record('queue_worker'));
        $this->assertFalse($health->record('queue_worker'));
        $this->travel(150)->seconds();
        $this->assertSame('stale', $health->report()['components'][0]['state']);
        DB::table('operational_heartbeats')->update(['observed_at' => now()->addMinute()->toISOString()]);
        $this->assertSame('unknown', $health->report()['components'][0]['state']);
        config(['queue.connections.database.queue' => 'different']);
        $this->assertSame('unknown', $health->report()['components'][0]['state']);
        $health->workerLoop(new Looping('database', 'default'));
        $this->assertSame('unknown', $health->report()['components'][0]['state']);
        $health->workerLoop(new Looping('database', 'high,different'));
        $this->assertSame('recent', $health->report()['components'][0]['state']);
    }

    public function test_aggregate_queue_counts_never_include_other_queues_or_private_payloads(): void
    {
        foreach ([['default', null, -10], ['default', now()->timestamp, -10], ['default', null, 20], ['foreign-private', null, -10]] as [$queue, $reserved, $delay]) {
            DB::table('jobs')->insert(['queue' => $queue, 'payload' => 'PRIVATE-QUEUE-PAYLOAD', 'attempts' => 0, 'reserved_at' => $reserved, 'available_at' => now()->timestamp + $delay, 'created_at' => now()->timestamp - 30]);
        }
        DB::table('failed_jobs')->insert(['uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'default', 'payload' => 'PRIVATE-FAILED-PAYLOAD', 'exception' => 'PRIVATE-PROVIDER-SECRET', 'failed_at' => now()]);
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $response = $this->getJson('/api/admin/operational-health')->assertOk()->assertJsonPath('queue.ready', 1)->assertJsonPath('queue.reserved', 1)->assertJsonPath('queue.delayed', 1)->assertJsonPath('queue.failed', 1)->assertJsonPath('queue.oldest_ready_age_seconds', 30)
            ->assertJsonPath('queue.oldest_reserved_age_seconds', 0);
        $this->assertStringNotContainsString('PRIVATE', $response->getContent());
        $this->assertStringNotContainsString('foreign-private', $response->getContent());
        $this->assertDatabaseCount('jobs', 4);
        $this->assertDatabaseCount('failed_jobs', 1);
    }

    public function test_disabled_unsupported_and_unavailable_telemetry_are_unknown_not_zero_or_ready(): void
    {
        $health = app(OperationalHealth::class);
        config(['operational_health.enabled' => false]);
        $this->assertFalse($health->record('scheduler'));
        $this->assertSame('disabled', $health->report()['queue']['state']);
        $this->assertNull($health->report()['queue']['ready']);
        config(['operational_health.enabled' => true, 'queue.default' => 'sync']);
        $this->assertSame('unsupported', $health->report()['queue']['state']);
        config(['queue.default' => 'database', 'queue.connections.database.table' => 'missing_health_table']);
        $this->assertSame('unavailable', $health->report()['queue']['state']);
        Schema::drop('operational_heartbeats');
        $this->assertFalse($health->record('scheduler'));
        $this->assertFalse($health->report()['heartbeat_store_readable']);
        $this->assertSame('unknown', $health->report()['components'][1]['state']);
        $this->assertSame(0, DB::table('jobs')->count(), 'Telemetry failure must not abort its caller transaction.');
    }

    public function test_revoked_admin_snapshot_cannot_read_private_queue_health(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        DB::table('users')->where('id', $admin->id)->update(['auth_epoch' => $admin->auth_epoch + 1]);
        $request = Request::create('/api/admin/operational-health');
        $request->setUserResolver(fn () => $admin);
        $this->expectException(HttpException::class);
        app(AdminOperationalHealthController::class)->show($request, app(OperationalHealth::class));
    }
}
