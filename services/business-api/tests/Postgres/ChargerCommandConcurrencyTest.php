<?php

namespace Tests\Postgres;

use App\Models\Station;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

/** Independent PHP/connection processes; disposable PostgreSQL only. */
class ChargerCommandConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        if (getenv('DB_CONNECTION') !== 'pgsql' || getenv('APP_ENV') !== 'testing') {
            $this->markTestSkipped('Dedicated disposable PostgreSQL required.');
        }
        parent::setUp();
        $this->assertSame('pgsql', DB::connection()->getDriverName());
        $this->assertTrue(str_ends_with(DB::connection()->getDatabaseName(), '_review'));
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_command_idempotency_across_independent_workers(bool $competingStation): void
    {
        $vendor = User::factory()->create(['role' => 'vendor']);
        $stations = [];
        foreach ([0, 1] as $i) {
            $station = Station::create([
                'vendor_id' => $vendor->id, 'name' => 'Command race '.$i,
                'country' => 'AE', 'currency' => 'AED', 'timezone' => 'Asia/Dubai',
                'latitude' => 25, 'longitude' => 55, 'source' => 'simulator',
                'connector_type' => 'CCS2', 'power_kw' => 50,
            ]);
            DB::table('csms_bindings')->insert([
                'station_id' => $station->id, 'identifier' => 'ev-test-command-race-'.$i,
                'protocol' => '1.6', 'created_at' => now(), 'updated_at' => now(),
            ]);
            $stations[] = $station;
        }
        // Keep the first process inside INSERT while the other has completed
        // its optimistic read. The actor-row lock must serialize the internal
        // idempotency recheck without a database unique-constraint 500.
        DB::unprepared('CREATE FUNCTION command_race_delay() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN PERFORM pg_sleep(0.4); RETURN NEW; END; $$');
        DB::unprepared('CREATE TRIGGER command_race_delay BEFORE INSERT ON charger_commands FOR EACH ROW EXECUTE FUNCTION command_race_delay()');
        $dir = sys_get_temp_dir().'/charging-command-race-'.bin2hex(random_bytes(8));
        mkdir($dir, 0700);
        $workers = [];
        try {
            foreach ([0, 1] as $i) {
                $payload = [
                    'actor' => $vendor->id,
                    'station' => $competingStation ? $stations[$i]->id : $stations[0]->id,
                    'key' => 'same-actor-key',
                ];
                $file = $dir.'/worker-'.$i.'.json';
                file_put_contents($file, json_encode($payload, JSON_THROW_ON_ERROR));
                chmod($file, 0600);
                $pipes = [];
                $proc = proc_open(
                    [PHP_BINARY, base_path('tests/Support/charger-command-concurrency-worker.php'),
                        $file, $dir.'/start', $dir.'/ready-'.$i],
                    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path()
                );
                $this->assertIsResource($proc);
                $workers[] = [$proc, $pipes];
            }
            $deadline = microtime(true) + 15;
            while ((! is_file($dir.'/ready-0') || ! is_file($dir.'/ready-1')) && microtime(true) < $deadline) {
                usleep(5000);
            }
            $this->assertFileExists($dir.'/ready-0');
            $this->assertFileExists($dir.'/ready-1');
            touch($dir.'/start');
            $codes = [];
            foreach ($workers as [$proc, $pipes]) {
                $codes[] = trim(stream_get_contents($pipes[1]));
                $error = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $this->assertSame(0, proc_close($proc), $error);
            }
            $workers = [];
            sort($codes);
            $this->assertSame($competingStation ? ['202', '409'] : ['200', '202'], $codes);
            $this->assertDatabaseCount('charger_commands', 1);
            $this->assertDatabaseHas('charger_commands', [
                'actor_id' => $vendor->id, 'status' => 'pending',
                'idempotency_key' => 'same-actor-key',
            ]);
            $this->assertSame(1, DB::table('audit_events')
                ->where('action', 'csms.command_queued')->count());
            $this->assertDatabaseCount('csms_inbox_events', 0);
            $this->assertDatabaseCount('finance_invoices', 0);
        } finally {
            foreach ($workers as [$proc, $pipes]) {
                if (is_resource($proc)) {
                    proc_terminate($proc);
                }
                foreach ($pipes as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }
                if (is_resource($proc)) {
                    proc_close($proc);
                }
            }
            foreach (glob($dir.'/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($dir);
            DB::unprepared('DROP TRIGGER IF EXISTS command_race_delay ON charger_commands');
            DB::unprepared('DROP FUNCTION IF EXISTS command_race_delay()');
        }
    }
}
