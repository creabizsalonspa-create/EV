<?php

namespace Tests\Postgres;

use App\Models\ChargingSession;
use App\Models\Finance\TariffVersion;
use App\Models\Reservation;
use App\Models\Station;
use App\Models\User;
use App\Services\Finance\FinanceService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SettlementConcurrencyTest extends TestCase
{
    use DatabaseMigrations { runDatabaseMigrations as private migrateDisposable; }

    protected function setUp(): void
    {
        if (getenv('DB_CONNECTION') !== 'pgsql' || getenv('APP_ENV') !== 'testing') {
            $this->markTestSkipped('Requires isolated PostgreSQL test database.');
        }
        parent::setUp();
    }

    public function runDatabaseMigrations(): void
    {
        $this->assertSame('pgsql', DB::getDriverName());
        $this->assertMatchesRegularExpression('/_(review|test|testing)$/', DB::connection()->getDatabaseName());
        $this->migrateDisposable();
    }

    private function runRace(bool $sameKey): void
    {
        $vendor = User::factory()->create(['role' => 'vendor']);
        $customer = User::factory()->create();
        $station = Station::create(['vendor_id' => $vendor->id, 'name' => 'Settlement race simulator', 'country' => 'AE', 'currency' => 'AED', 'timezone' => 'Asia/Dubai', 'latitude' => 25, 'longitude' => 55, 'source' => 'simulator', 'connector_type' => 'CCS2', 'power_kw' => 50]);
        $tariff = TariffVersion::create(['station_id' => $station->id, 'created_by' => $vendor->id, 'rate_minor_per_kwh' => 100, 'tax_bps' => 0, 'commission_bps' => 1000, 'currency' => 'AED', 'minor_units' => 2, 'effective_from' => now()->subMinute()]);
        $r = Reservation::create(['user_id' => $customer->id, 'station_id' => $station->id, 'starts_at' => now(), 'idempotency_key' => 'race']);
        $s = ChargingSession::create(['reservation_id' => $r->id, 'status' => 'stopped', 'energy_wh' => 1000, 'rate_minor_per_kwh' => 100, 'currency' => 'AED', 'total_minor' => 100, 'stopped_at' => now()]);
        $finance = app(FinanceService::class);
        $finance->snapshotSession($s, $tariff);
        $invoice = $finance->issue($s, $customer->id);
        $finance->pay($invoice, $customer->id, 100, 'paid');
        DB::table('commercial_settlement_schedules')->insert(['vendor_id' => $vendor->id, 'currency' => 'AED', 'hold_days' => 0, 'interval_days' => 30, 'minimum_minor' => 1, 'next_due_at' => now()->subMinute(), 'enabled' => true, 'configured_by' => $vendor->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::unprepared('CREATE FUNCTION settlement_test_delay() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN PERFORM pg_sleep(1); RETURN NEW; END; $$');
        DB::unprepared('CREATE TRIGGER settlement_test_delay BEFORE INSERT ON commercial_settlements FOR EACH ROW EXECUTE FUNCTION settlement_test_delay()');
        $dir = sys_get_temp_dir().'/settlement-race-'.bin2hex(random_bytes(8));
        mkdir($dir, 0700);
        $workers = [];
        try {
            for ($i = 0; $i < 2; $i++) {
                $pipes = [];
                $ready = $dir.'/ready-'.$i;
                $process = proc_open([PHP_BINARY, base_path('tests/Support/settlement-concurrency-worker.php'), (string) $vendor->id, (string) $vendor->id, $sameKey ? 'same' : 'key-'.$i, $dir.'/go', $ready], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
                $this->assertIsResource($process);
                $workers[] = [$process, $pipes, $ready];
            }
            $deadline = microtime(true) + 15;
            while ((! is_file($workers[0][2]) || ! is_file($workers[1][2])) && microtime(true) < $deadline) {
                usleep(5000);
            }
            $this->assertFileExists($workers[0][2]);
            $this->assertFileExists($workers[1][2]);
            touch($dir.'/go');
            $statuses = [];
            foreach ($workers as [$process, $pipes]) {
                $statuses[] = trim(stream_get_contents($pipes[1]));
                $error = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $this->assertSame(0, proc_close($process), $error);
            }
            $workers = [];
            sort($statuses);
            $this->assertSame($sameKey ? ['201', '201'] : ['201', '409'], $statuses);
            $this->assertSame(1, DB::table('commercial_settlements')->count());
            $this->assertSame(90, (int) DB::table('commercial_settlement_lines')->sum('amount_minor'));
        } finally {
            foreach ($workers as [$process, $pipes]) {
                proc_terminate($process);
                foreach ($pipes as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                } proc_close($process);
            }
            foreach (glob($dir.'/*') as $file) {
                unlink($file);
            } rmdir($dir);
            DB::unprepared('DROP TRIGGER IF EXISTS settlement_test_delay ON commercial_settlements');
            DB::unprepared('DROP FUNCTION IF EXISTS settlement_test_delay()');
        }
    }

    public function test_concurrent_drafts_cannot_reserve_the_same_payable_twice(): void
    {
        $this->runRace(false);
    }

    public function test_concurrent_identical_draft_retries_return_one_settlement(): void
    {
        $this->runRace(true);
    }
}
