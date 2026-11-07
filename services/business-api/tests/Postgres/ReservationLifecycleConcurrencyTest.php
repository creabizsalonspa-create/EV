<?php

namespace Tests\Postgres;

use App\Models\Reservation;
use App\Models\Station;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ReservationLifecycleConcurrencyTest extends TestCase
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

    public static function operations(): array
    {
        return [['arrival', 'arrival'], ['expire', 'expire'], ['arrival', 'cancel']];
    }

    #[DataProvider('operations')]
    public function test_simultaneous_lifecycle_requests_are_scoped_idempotent_and_do_not_create_charging(string $one, string $two): void
    {
        $owner = User::factory()->create(['role' => 'vendor']);
        $customer = User::factory()->create();
        $station = Station::create(['vendor_id' => $owner->id, 'name' => 'Lifecycle PG', 'country' => 'AE', 'currency' => 'AED', 'timezone' => 'Asia/Dubai', 'latitude' => 25, 'longitude' => 55, 'source' => 'simulator', 'connector_type' => 'CCS2', 'power_kw' => 50]);
        $start = $one === 'expire' ? now()->subHour() : now()->subMinute();
        $r = Reservation::create(['user_id' => $customer->id, 'station_id' => $station->id, 'starts_at' => $start->format('Y-m-d H:i:s'), 'idempotency_key' => 'lifecycle-race']);
        DB::unprepared('CREATE FUNCTION lifecycle_delay() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN PERFORM pg_sleep(1); RETURN NEW; END; $$');
        DB::unprepared('CREATE TRIGGER lifecycle_delay BEFORE INSERT ON reservation_lifecycle_events FOR EACH ROW EXECUTE FUNCTION lifecycle_delay()');
        $dir = sys_get_temp_dir().'/reservation-lifecycle-'.bin2hex(random_bytes(8));
        mkdir($dir, 0700);
        $gate = $dir.'/start';
        $workers = [];
        try {
            foreach ([$one, $two] as $i => $operation) {
                $pipes = [];
                $ready = $dir.'/ready-'.$i;
                $body = base64_encode(json_encode(['reservation_id' => $r->id, 'self_reported_arrival' => true]));
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
            if ($two === 'cancel') {
                $this->assertContains($results, [['200', '201'], ['200', '409']]);
                $this->assertSame('cancelled', $r->fresh()->status);
                $this->assertSame($r->fresh()->checked_in_at !== null, DB::table('reservation_lifecycle_events')->where('event_type', 'self_reported_arrival')->exists());
            } else {
                $this->assertSame($one === 'expire' ? ['0', '1'] : ['200', '201'], $results);
                $this->assertDatabaseCount('reservation_lifecycle_events', 1);
            }
            $this->assertDatabaseCount('charging_sessions', 0);
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
            DB::unprepared('DROP TRIGGER IF EXISTS lifecycle_delay ON reservation_lifecycle_events');
            DB::unprepared('DROP FUNCTION IF EXISTS lifecycle_delay()');
        }
    }
}
