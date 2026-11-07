<?php

namespace Tests\Postgres;

use App\Models\Reservation;
use App\Models\Station;
use App\Models\User;
use App\Services\Charging\CommandReconciler;
use App\Services\Charging\SessionBridge;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CommandReconciliationConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        if (getenv('DB_CONNECTION') !== 'pgsql' || getenv('APP_ENV') !== 'testing') {
            $this->markTestSkipped('Explicit disposable PostgreSQL required.');
        }
        parent::setUp();
        $this->assertSame('pgsql', DB::connection()->getDriverName());
        $this->assertTrue(str_ends_with(DB::connection()->getDatabaseName(), '_review'));
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    }

    public function test_concurrent_source_recovery_records_one_observation_without_device_or_financial_mutation(): void
    {
        $owner = User::factory()->create(['role' => 'vendor']);
        $customer = User::factory()->create();
        $station = Station::create(['vendor_id' => $owner->id, 'name' => 'Lifecycle PG', 'country' => 'AE', 'currency' => 'AED', 'timezone' => 'Asia/Dubai', 'latitude' => 25, 'longitude' => 55, 'source' => 'simulator', 'connector_type' => 'CCS2', 'power_kw' => 50]);
        config(['csms.session_bridge_enabled' => true]);
        DB::table('csms_bindings')->insert(['station_id' => $station->id, 'identifier' => 'ev-test-pg-recovery', 'protocol' => '1.6', 'created_at' => now(), 'updated_at' => now()]);
        $r = Reservation::create(['user_id' => $customer->id, 'station_id' => $station->id, 'starts_at' => now()->subMinute(), 'idempotency_key' => 'recovery-race']);
        $bridge = app(SessionBridge::class);
        $queued = $bridge->start($r, $customer->id);
        $commandId = $queued['start_command']['id'];
        $real = app(CommandReconciler::class);
        $fake = \Mockery::mock(CommandReconciler::class);
        $fake->shouldReceive('forSession')->once()->andReturn(0);
        $this->app->instance(CommandReconciler::class, $fake);
        $bridge->ingest(['event_id' => 'pg-recovery', 'session_id' => $queued['session']['id'], 'identifier' => 'ev-test-pg-recovery', 'protocol' => '1.6', 'transaction_id' => '123', 'type' => 'started', 'sequence' => 0, 'meter_wh' => 1000, 'occurred_at' => now()->toISOString()]);
        $this->app->instance(CommandReconciler::class, $real);
        DB::unprepared('CREATE FUNCTION recovery_delay() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN PERFORM pg_sleep(1); RETURN NEW; END; $$');
        DB::unprepared('CREATE TRIGGER recovery_delay BEFORE INSERT ON command_source_observations FOR EACH ROW EXECUTE FUNCTION recovery_delay()');
        $dir = sys_get_temp_dir().'/command-recovery-'.bin2hex(random_bytes(8));
        mkdir($dir, 0700);
        $gate = $dir.'/start';
        $workers = [];
        try {
            foreach (['reconcile', 'reconcile'] as $i => $operation) {
                $pipes = [];
                $ready = $dir.'/ready-'.$i;
                $body = base64_encode(json_encode(['command_id' => $commandId]));
                $p = proc_open([PHP_BINARY, base_path('tests/Support/connector-reservation-worker.php'), $body, (string) $customer->id, $gate, $ready, $operation], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
                $this->assertIsResource($p);
                $workers[] = [$p, $pipes, $ready];
            }
            $deadline = microtime(true) + 15;
            while ((! is_file($workers[0][2]) || ! is_file($workers[1][2])) && microtime(true) < $deadline) {
                usleep(5000);
            }
            $this->assertFileExists($workers[0][2]);
            $this->assertFileExists($workers[1][2]);
            touch($gate);
            $results = [];
            foreach ($workers as [$p,$pipes]) {
                $results[] = trim(stream_get_contents($pipes[1]));
                $error = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $this->assertSame(0, proc_close($p), $error);
            }$workers = [];
            sort($results);
            $this->assertSame(['0', '1'], $results);
            $this->assertDatabaseCount('command_source_observations', 1);
            $this->assertDatabaseHas('charger_commands', ['id' => $commandId, 'status' => 'source_observed']);
            $this->assertDatabaseHas('command_source_observations', ['command_id' => $commandId, 'prior_status' => 'pending', 'command_acceptance_verified' => false]);
            $this->assertDatabaseCount('charging_sessions', 1);
            $this->assertDatabaseCount('finance_invoices', 0);
        } finally {
            foreach ($workers as [$p,$pipes]) {
                if (is_resource($p)) {
                    proc_terminate($p);
                }foreach ($pipes as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }if (is_resource($p)) {
                    proc_close($p);
                }
            }
            foreach (glob($dir.'/*') as $file) {
                unlink($file);
            }rmdir($dir);
            DB::unprepared('DROP TRIGGER IF EXISTS recovery_delay ON command_source_observations');
            DB::unprepared('DROP FUNCTION IF EXISTS recovery_delay()');
        }
    }
}
