<?php

namespace Tests\Postgres;

use App\Models\ChargingSession;
use App\Models\Finance\Payment;
use App\Models\Finance\Refund;
use App\Models\Finance\TariffVersion;
use App\Models\Reservation;
use App\Models\Station;
use App\Models\User;
use App\Services\Finance\FinanceService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Explicitly run only against a disposable PostgreSQL database. */
class FinanceConcurrencyTest extends TestCase
{
    use DatabaseMigrations {
        runDatabaseMigrations as private migrateDisposableDatabase;
    }

    public function runDatabaseMigrations(): void
    {
        $this->assertSame('pgsql', DB::connection()->getDriverName(), 'Cached configuration must not redirect this suite to the application database.');
        $database = DB::connection()->getDatabaseName();
        $this->assertTrue(str_ends_with($database, '_review') || str_ends_with($database, '_test') || str_ends_with($database, '_testing'), 'Use an explicitly disposable database name ending in _review, _test or _testing.');
        $this->migrateDisposableDatabase();
    }

    protected function setUp(): void
    {
        if (getenv('DB_CONNECTION') !== 'pgsql' || getenv('APP_ENV') !== 'testing') {
            $this->markTestSkipped('Requires an explicitly configured disposable PostgreSQL testing database.');
        }
        parent::setUp();
    }

    private function invoices(): array
    {
        $vendor = User::factory()->create(['role' => 'vendor']);
        $customer = User::factory()->create();
        $station = Station::create(['vendor_id' => $vendor->id, 'name' => 'Concurrent finance simulator', 'country' => 'AE', 'currency' => 'AED', 'timezone' => 'Asia/Dubai', 'latitude' => 25, 'longitude' => 55, 'source' => 'simulator', 'connector_type' => 'CCS2', 'power_kw' => 50]);
        $invoices = [];
        for ($i = 0; $i < 2; $i++) {
            $reservation = Reservation::create(['user_id' => $customer->id, 'station_id' => $station->id, 'starts_at' => now()->addHours($i), 'idempotency_key' => 'concurrency-fixture-'.$i]);
            $session = ChargingSession::create(['reservation_id' => $reservation->id, 'status' => 'stopped', 'energy_wh' => 1000, 'rate_minor_per_kwh' => 100, 'currency' => 'AED', 'total_minor' => 100, 'stopped_at' => now()]);
            $invoices[] = app(FinanceService::class)->issue($session, $customer->id);
        }

        return [$vendor, $customer, $invoices];
    }

    private function race(string $operation, array $invoices, int $actorId): void
    {
        // Delay insertion after initial lookup; a shared station lock must serialize tariff retries.
        $table = $operation === 'tariff' ? 'finance_tariff_versions' : 'finance_journals';
        DB::unprepared('CREATE FUNCTION finance_test_delay() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN PERFORM pg_sleep(1); RETURN NEW; END; $$');
        DB::unprepared('CREATE TRIGGER finance_test_delay BEFORE INSERT ON '.$table.' FOR EACH ROW EXECUTE FUNCTION finance_test_delay()');
        $directory = sys_get_temp_dir().'/finance-race-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $gate = $directory.'/start';
        $workers = [];
        try {
            foreach ($invoices as $index => $invoice) {
                $ready = $directory.'/ready-'.$index;
                $pipes = [];
                $process = proc_open([PHP_BINARY, base_path('tests/Support/finance-concurrency-worker.php'), $operation, (string) $invoice->id, (string) $actorId, $gate, $ready], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
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
            $this->assertSame($operation === 'tariff' ? ['201', '201'] : ['201', '409'], $statuses, 'Idempotent tariff retries must both resolve; conflicting cross-invoice postings must reject one actor.');
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
            DB::unprepared('DROP TRIGGER IF EXISTS finance_test_delay ON '.$table);
            DB::unprepared('DROP FUNCTION IF EXISTS finance_test_delay()');
        }
    }

    public function test_concurrent_tariff_retries_publish_once_and_keep_commission_policy(): void
    {
        [$vendor] = $this->invoices();
        $station = Station::where('vendor_id', $vendor->id)->sole();
        TariffVersion::create(['station_id' => $station->id, 'created_by' => $vendor->id, 'rate_minor_per_kwh' => 100, 'tax_bps' => 500, 'commission_bps' => 1000, 'currency' => 'AED', 'minor_units' => 2, 'effective_from' => now()->subMinute()]);
        $this->race('tariff', [$station, $station], $vendor->id);
        $this->assertSame(2, TariffVersion::count());
        $published = TariffVersion::orderByDesc('id')->firstOrFail();
        $this->assertSame(1000, $published->commission_bps);
        $this->assertSame(250, $published->rate_minor_per_kwh);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'finance.simulated_tariff_published')->count());
    }

    public function test_cross_invoice_payment_key_race_returns_conflict_without_double_posting(): void
    {
        [$vendor, $customer, $invoices] = $this->invoices();
        $this->race('payment', $invoices, $customer->id);
        $this->assertSame(1, Payment::count());
        $this->assertSame(100, (int) Payment::sum('amount_minor'));
        foreach ($invoices as $invoice) {
            $this->assertTrue(app(FinanceService::class)->summary($invoice)['ledger_balanced']);
        }
    }

    public function test_cross_invoice_refund_key_race_returns_conflict_without_double_refund(): void
    {
        [$vendor, $customer, $invoices] = $this->invoices();
        foreach ($invoices as $invoice) {
            app(FinanceService::class)->pay($invoice, $customer->id, 100, 'capture-'.$invoice->id);
        }
        $this->race('refund', $invoices, $vendor->id);
        $this->assertSame(1, Refund::count());
        $this->assertSame(100, (int) Refund::sum('amount_minor'));
        foreach ($invoices as $invoice) {
            $this->assertTrue(app(FinanceService::class)->summary($invoice)['ledger_balanced']);
        }
    }
}
