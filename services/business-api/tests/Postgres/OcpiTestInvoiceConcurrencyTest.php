<?php

namespace Tests\Postgres;

use App\Models\Finance\Invoice;
use App\Services\Finance\FinanceService;
use App\Services\Ocpi\TestInvoiceLineage;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\CreatesBillableSandboxRoaming;
use Tests\TestCase;

class OcpiTestInvoiceConcurrencyTest extends TestCase
{
    use CreatesBillableSandboxRoaming;
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
        $this->assertSame('pgsql', DB::getDriverName());
        $this->assertMatchesRegularExpression('/_(review|test|testing)$/D', DB::connection()->getDatabaseName());
        $this->migrateDisposableDatabase();
    }

    private function race(bool $differentKeys = false, bool $cancel = false): void
    {
        [,$actor,,,,$order] = $this->billable();
        $p = $this->getJson('/api/roaming/sandbox-orders/'.$order.'/test-invoice-preview')->assertOk()->json();
        $intent = ['request_key' => (string) Str::uuid(), 'preview_token' => $p['preview_token'], 'terms_version' => TestInvoiceLineage::TERMS, 'consent' => true, 'expected_total_minor' => 25, 'expected_currency' => 'EUR', 'expected_minor_units' => 2, 'expected_energy_wh' => 1000];
        $directory = sys_get_temp_dir().'/ocpi-invoice-race-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $workers = [];
        // Delay the first committed effect, forcing the second request to contend
        // on the shared provider/actor/order locks rather than race by accident.
        DB::unprepared('CREATE FUNCTION ocpi_invoice_test_delay() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN PERFORM pg_sleep(0.4); RETURN NEW; END; $$');
        foreach (['ocpi_test_invoice_receipts', 'ocpi_test_invoice_cancellations'] as $table) {
            DB::unprepared('CREATE TRIGGER ocpi_invoice_test_delay BEFORE INSERT ON '.$table.' FOR EACH ROW EXECUTE FUNCTION ocpi_invoice_test_delay()');
        }
        try {
            for ($i = 0; $i < 2; $i++) {
                $v = $intent;
                if ($differentKeys && $i === 1) {
                    $v['request_key'] = (string) Str::uuid();
                }
                $fixture = $directory.'/fixture-'.$i.'.json';
                file_put_contents($fixture, json_encode(['actor' => $actor->id, 'order' => $order, 'intent' => $v, 'configuration' => ['ocpi' => config('ocpi'), 'app.key' => config('app.key')]], JSON_THROW_ON_ERROR));
                chmod($fixture, 0600);
                $pipes = [];
                $process = proc_open([PHP_BINARY, base_path('tests/Support/ocpi-invoice-concurrency-worker.php'), $cancel && $i === 1 ? 'resolve' : 'issue', $fixture, $directory.'/start', $directory.'/ready-'.$i], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
                $this->assertIsResource($process);
                $workers[] = [$process, $pipes];
            }
            $deadline = microtime(true) + 15;
            while ((! is_file($directory.'/ready-0') || ! is_file($directory.'/ready-1')) && microtime(true) < $deadline) {
                usleep(5000);
            }
            $this->assertFileExists($directory.'/ready-0');
            $this->assertFileExists($directory.'/ready-1');
            touch($directory.'/start');
            $statuses = [];
            foreach ($workers as [$process,$pipes]) {
                $statuses[] = trim(stream_get_contents($pipes[1]));
                $error = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $this->assertSame(0, proc_close($process), $error);
            }
            $workers = [];
            sort($statuses);
            if ($cancel) {
                $issued = DB::table('ocpi_test_invoice_receipts')->exists();
                $this->assertSame($issued ? ['200', '200'] : ['200', '409'], $statuses);
                $this->assertSame($issued ? 0 : 1, DB::table('ocpi_test_invoice_cancellations')->count());
                $this->assertSame($issued ? 1 : 0, Invoice::count());
            } else {
                $this->assertSame($differentKeys ? ['200', '409'] : ['200', '200'], $statuses);
                $this->assertDatabaseCount('ocpi_test_invoice_receipts', 1);
                $this->assertDatabaseCount('ocpi_test_invoice_cancellations', 0);
                $this->assertDatabaseCount('finance_invoices', 1);
            }
            $this->assertSame(Invoice::count(), DB::table('charging_sessions')->count());
            $this->assertSame(Invoice::count(), DB::table('finance_journals')->count());
            $this->assertSame(Invoice::count(), DB::table('finance_session_quotes')->count());
            foreach (Invoice::all() as $invoice) {
                $this->assertTrue(app(FinanceService::class)->summary($invoice)['ledger_balanced']);
            }
            $this->assertDatabaseCount('reservations', 0);
            $this->assertDatabaseCount('finance_payments', 0);
        } finally {
            foreach ($workers as [$process,$pipes]) {
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
            foreach (['ocpi_test_invoice_receipts', 'ocpi_test_invoice_cancellations'] as $table) {
                DB::unprepared('DROP TRIGGER IF EXISTS ocpi_invoice_test_delay ON '.$table);
            }
            DB::unprepared('DROP FUNCTION IF EXISTS ocpi_invoice_test_delay()');
        }
    }

    public function test_same_consent_retry_creates_one_source_session_invoice_and_balanced_journal(): void
    {
        $this->race();
    }

    public function test_distinct_consent_identities_cannot_invoice_one_source_twice(): void
    {
        $this->race(differentKeys: true);
    }

    public function test_cancellation_and_delayed_issuance_serialize_to_one_truth(): void
    {
        $this->race(cancel: true);
    }
}
