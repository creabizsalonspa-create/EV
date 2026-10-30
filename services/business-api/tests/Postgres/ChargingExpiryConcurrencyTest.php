<?php

namespace Tests\Postgres;

use App\Models\Charging\SessionLink;
use App\Models\Reservation;
use App\Models\Station;
use App\Models\User;
use App\Services\Charging\SessionBridge;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ChargingExpiryConcurrencyTest extends TestCase
{
    use DatabaseMigrations {
        runDatabaseMigrations as private migrateDisposableDatabase;
    }

    protected function setUp(): void
    {
        if (getenv('DB_CONNECTION') !== 'pgsql' || getenv('APP_ENV') !== 'testing') {
            $this->markTestSkipped('Requires an explicitly disposable PostgreSQL testing database.');
        }
        parent::setUp();
    }

    public function runDatabaseMigrations(): void
    {
        $this->assertSame('pgsql', DB::connection()->getDriverName());
        $this->assertTrue(str_ends_with(DB::connection()->getDatabaseName(), '_review'));
        $this->migrateDisposableDatabase();
    }

    public function test_concurrent_expiry_workers_close_once_without_faking_source_end(): void
    {
        config(['csms.session_bridge_enabled' => true, 'csms.simulator_enabled' => true]);
        $vendor = User::factory()->create(['role' => 'vendor']);
        $customer = User::factory()->create();
        $station = Station::create(['vendor_id' => $vendor->id, 'name' => 'Expiry race simulator', 'country' => 'AE', 'currency' => 'AED', 'timezone' => 'Asia/Dubai', 'latitude' => 25, 'longitude' => 55, 'source' => 'simulator', 'connector_type' => 'CCS2', 'power_kw' => 50]);
        DB::table('csms_bindings')->insert(['station_id' => $station->id, 'identifier' => 'ev-test-expiry-race', 'protocol' => '1.6', 'created_at' => now(), 'updated_at' => now()]);
        $reservation = Reservation::create(['user_id' => $customer->id, 'station_id' => $station->id, 'starts_at' => now()->subMinute()->format('Y-m-d H:i:s'), 'idempotency_key' => 'expiry-race']);
        app(SessionBridge::class)->start($reservation, $customer->id);
        $reservation->update(['starts_at' => now()->subHour()->format('Y-m-d H:i:s')]);
        DB::unprepared('CREATE FUNCTION charging_expiry_delay() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN PERFORM pg_sleep(1); RETURN NEW; END; $$');
        DB::unprepared('CREATE TRIGGER charging_expiry_delay BEFORE INSERT ON charging_request_expirations FOR EACH ROW EXECUTE FUNCTION charging_expiry_delay()');
        $directory = sys_get_temp_dir().'/charging-expiry-race-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $gate = $directory.'/start';
        $workers = [];
        try {
            for ($i = 0; $i < 2; $i++) {
                $ready = $directory.'/ready-'.$i;
                $pipes = [];
                $process = proc_open([PHP_BINARY, base_path('tests/Support/charging-expiry-worker.php'), (string) $reservation->id, $gate, $ready], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
                $this->assertIsResource($process);
                $workers[] = [$process, $pipes, $ready];
            }
            $deadline = microtime(true) + 15;
            while ((! is_file($workers[0][2]) || ! is_file($workers[1][2])) && microtime(true) < $deadline) {
                usleep(5000);
            }
            $this->assertFileExists($workers[0][2]);
            $this->assertFileExists($workers[1][2]);
            touch($gate);
            $counts = [];
            foreach ($workers as [$process, $pipes]) {
                $counts[] = trim(stream_get_contents($pipes[1]));
                $error = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $this->assertSame(0, proc_close($process), $error);
            }
            $workers = [];
            sort($counts);
            $this->assertSame(['0', '1'], $counts);
            $this->assertSame('expired_unstarted', $reservation->fresh()->status);
            $this->assertDatabaseCount('charging_request_expirations', 1);
            $this->assertDatabaseCount('finance_invoices', 0);
            $link = SessionLink::sole();
            $this->assertNotNull($link->closed_without_dispatch_at);
            $this->assertNull($link->ended_at);
            $this->assertNull($link->transaction_id);
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
            foreach (glob($directory.'/*') as $file) {
                unlink($file);
            }
            rmdir($directory);
            DB::unprepared('DROP TRIGGER IF EXISTS charging_expiry_delay ON charging_request_expirations');
            DB::unprepared('DROP FUNCTION IF EXISTS charging_expiry_delay()');
        }
    }
}
