<?php

namespace Tests\Postgres;

use App\Services\Operations\OperationalHealth;
use App\Services\Operations\OperationalIncidents;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OperationalIncidentsConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        if (getenv('DB_CONNECTION') !== 'pgsql' || getenv('APP_ENV') !== 'testing') {
            $this->markTestSkipped('Disposable PostgreSQL only.');
        }
        parent::setUp();
        $this->assertSame('pgsql', DB::getDriverName());
        $this->assertStringEndsWith('_review', DB::connection()->getDatabaseName());
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        config(['operational_health.enabled' => true, 'queue.default' => 'database',
            'queue.connections.database.connection' => null, 'queue.connections.database.queue' => 'default']);
    }

    private function race(): array
    {
        $dir = sys_get_temp_dir().'/operational-incident-race-'.bin2hex(random_bytes(8));
        mkdir($dir, 0700);
        $workers = [];
        try {
            foreach ([0, 1] as $i) {
                $pipes = [];
                $ready = $dir.'/ready-'.$i;
                $process = proc_open([PHP_BINARY, base_path('tests/Support/operational-incident-worker.php'), $dir.'/gate', $ready],
                    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
                $this->assertIsResource($process);
                $workers[] = [$process, $pipes, $ready];
            }
            $deadline = microtime(true) + 15;
            while ((! is_file($workers[0][2]) || ! is_file($workers[1][2])) && microtime(true) < $deadline) {
                usleep(5000);
            }
            $this->assertFileExists($workers[0][2]);
            $this->assertFileExists($workers[1][2]);
            touch($dir.'/gate');
            $results = [];
            foreach ($workers as [$process, $pipes]) {
                $results[] = json_decode(stream_get_contents($pipes[1]), true, 16, JSON_THROW_ON_ERROR);
                $error = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $this->assertSame(0, proc_close($process), $error);
            }
            $workers = [];

            return $results;
        } finally {
            foreach ($workers as [$process, $pipes]) {
                proc_terminate($process);
                foreach ($pipes as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }
                proc_close($process);
            }
            foreach (glob($dir.'/*') as $file) {
                unlink($file);
            }
            rmdir($dir);
        }
    }

    public function test_parallel_scanners_record_one_stale_reserved_job_transition_and_one_recovery(): void
    {
        $health = app(OperationalHealth::class);
        $this->assertTrue($health->record('queue_worker'));
        $this->assertTrue($health->record('scheduler'));
        DB::table('jobs')->insert([
            'queue' => 'default', 'payload' => 'PRIVATE-COMPETING-JOB',
            'attempts' => 1, 'reserved_at' => now()->timestamp - 2000,
            'available_at' => now()->timestamp - 2000,
            'created_at' => now()->timestamp - 2100,
        ]);
        $opened = $this->race();
        $this->assertSame(1, array_sum(array_column($opened, 'opened')));
        $this->assertDatabaseCount('operational_incident_events', 1);
        $this->assertSame('queue_reserved_stale',
            app(OperationalIncidents::class)->summary()['open'][0]->condition_key);
        DB::table('jobs')->update(['reserved_at' => now()->timestamp]);
        $resolved = $this->race();
        $this->assertSame(1, array_sum(array_column($resolved, 'resolved')));
        $this->assertDatabaseCount('operational_incident_events', 2);
        $this->assertSame([], app(OperationalIncidents::class)->summary()['open']);
        $this->assertDatabaseCount('jobs', 1);
        $this->assertDatabaseCount('charger_commands', 0);
    }

    public function test_independent_scanners_do_not_duplicate_open_or_recovery_transition(): void
    {
        $health = app(OperationalHealth::class);
        $this->assertTrue($health->record('queue_worker'));
        $this->assertTrue($health->record('scheduler'));
        DB::table('operational_heartbeats')->update(['observed_at' => now()->subMinutes(4)]);
        $opened = $this->race();
        $this->assertSame(2, array_sum(array_column($opened, 'opened')));
        $this->assertDatabaseCount('operational_incident_events', 2);
        $this->assertCount(2, app(OperationalIncidents::class)->summary()['open']);
        $this->assertSame('recent', app(OperationalIncidents::class)->summary()['scanner']['state']);
        DB::table('operational_heartbeats')->update(['observed_at' => now()]);
        $resolved = $this->race();
        $this->assertSame(2, array_sum(array_column($resolved, 'resolved')));
        $this->assertDatabaseCount('operational_incident_events', 4);
        $this->assertSame([], app(OperationalIncidents::class)->summary()['open']);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('charger_commands', 0);
    }
}
