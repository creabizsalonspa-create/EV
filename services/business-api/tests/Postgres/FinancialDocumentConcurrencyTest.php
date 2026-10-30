<?php

namespace Tests\Postgres;

use App\Models\ChargingSession;
use App\Models\Fleet;
use App\Models\Reservation;
use App\Models\Station;
use App\Services\Commercial\CorporateBilling;
use App\Services\Commercial\Documents\FinancialDocuments;
use App\Services\Commercial\SettlementService;
use App\Services\Finance\FinanceService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\Support\FinancialDocumentFixtures;
use Tests\TestCase;

class FinancialDocumentConcurrencyTest extends TestCase
{
    use DatabaseMigrations { runDatabaseMigrations as private migrateDisposableDatabase; }
    use FinancialDocumentFixtures;

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

    protected function tearDown(): void
    {
        try {
            if ($this->app && DB::getSchemaBuilder()->hasTable('financial_documents')) {
                foreach (DB::table('financial_documents')->get() as $row) {
                    app(FinancialDocuments::class)->disk()->delete([$row->pdf_path, $row->csv_path]);
                }
            }
        } finally {
            parent::tearDown();
        }
    }

    private function race(int $actor, array $sources, string $kind = 'charging_invoice'): array
    {
        $directory = sys_get_temp_dir().'/document-race-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $workers = [];
        try {
            foreach ($sources as $index => $source) {
                $pipes = [];
                $ready = $directory.'/ready-'.$index;
                $process = proc_open([PHP_BINARY, base_path('tests/Support/financial-document-worker.php'), (string) $actor, (string) $source, $ready, $directory.'/start', $kind], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
                $this->assertIsResource($process);
                $workers[] = [$process, $pipes, $ready];
            }
            $deadline = microtime(true) + 15;
            while ((! is_file($workers[0][2]) || ! is_file($workers[1][2])) && microtime(true) < $deadline) {
                usleep(5000);
            }
            foreach ($workers as $worker) {
                $this->assertFileExists($worker[2]);
            }
            touch($directory.'/start');
            $results = [];
            foreach ($workers as [$process, $pipes]) {
                $out = stream_get_contents($pipes[1]);
                $error = stream_get_contents($pipes[2]);
                foreach ($pipes as $pipe) {
                    fclose($pipe);
                }
                $this->assertSame(0, proc_close($process), $error);
                $results[] = json_decode($out, true, 16, JSON_THROW_ON_ERROR);
            }
            $workers = [];

            return $results;
        } finally {
            foreach ($workers as [$process, $pipes]) {
                if (is_resource($process)) {
                    proc_terminate($process);
                    foreach ($pipes as $pipe) {
                        if (is_resource($pipe)) {
                            fclose($pipe);
                        }
                    }
                    proc_close($process);
                }
            }
            foreach (glob($directory.'/*') as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }

    public function test_simultaneous_generation_of_same_source_returns_one_immutable_pdf_and_number(): void
    {
        $f = $this->documentFixture();
        $results = $this->race($f['customer']->id, [$f['invoice']->id, $f['invoice']->id]);
        $this->assertSame($results[0], $results[1]);
        $this->assertDatabaseCount('financial_documents', 1);
        $this->assertDatabaseHas('financial_document_sequences', ['next_number' => 2]);
        $row = DB::table('financial_documents')->sole();
        $bytes = app(FinancialDocuments::class)->bytes($row, 'pdf');
        $this->assertSame($results[0]['pdf_sha256'], hash('sha256', $bytes));
        $this->assertSame(1, DB::table('audit_events')->where('action', 'finance.private_test_document_generated')->count());
    }

    public function test_concurrent_vendor_statement_generation_has_one_snapshot_and_no_payout(): void
    {
        $f = $this->documentFixture();
        app(FinanceService::class)->pay($f['invoice'], $f['customer']->id, $f['invoice']->total_minor, 'document-settlement-payment');
        DB::table('commercial_settlement_schedules')->insert(['vendor_id' => $f['vendor']->id, 'currency' => 'AED', 'hold_days' => 0, 'interval_days' => 7, 'minimum_minor' => 1, 'next_due_at' => now()->subMinute(), 'enabled' => true, 'configured_by' => $f['admin']->id, 'created_at' => now(), 'updated_at' => now()]);
        $settlement = app(SettlementService::class)->draft($f['vendor']->id, 'AED', $f['admin']->id, 'document-settlement');
        $results = $this->race($f['vendor']->id, [$settlement->id, $settlement->id], 'vendor_settlement');
        $this->assertSame($results[0], $results[1]);
        $this->assertDatabaseCount('financial_documents', 1);
        $this->assertDatabaseCount('commercial_payouts', 0);
        $this->assertDatabaseHas('commercial_settlements', ['id' => $settlement->id, 'state' => 'pending']);
        $row = DB::table('financial_documents')->sole();
        $this->assertSame('vendor_settlement', $row->source_kind);
        $this->assertSame('pending', app(FinancialDocuments::class)->metadata($row, true)['snapshot']['source']['state_at_generation']);
    }

    public function test_concurrent_corporate_statement_document_generation_never_collects_payment(): void
    {
        $f = $this->documentFixture();
        $owner = $f['customer'];
        $fleet = Fleet::create(['owner_id' => $owner->id, 'name' => 'Document corporate race', 'country' => 'AE']);
        $center = DB::table('cost_centers')->insertGetId(['fleet_id' => $fleet->id, 'code' => 'ENGINEERING', 'name' => 'Engineering', 'created_at' => now(), 'updated_at' => now()]);
        $corporate = app(CorporateBilling::class);
        $agreement = $corporate->publish($fleet->id, $f['admin']->id, ['currency' => 'AED', 'minor_units' => 2, 'credit_limit_minor' => 100000, 'payment_terms_days' => 30, 'billing_day' => 1, 'effective_from' => now()->subDay()->toDateTimeString(), 'expires_at' => now()->addYear()->toDateTimeString(), 'legal_name' => 'Synthetic company', 'terms' => 'No real credit facility', 'idempotency_key' => 'doc-race-agreement']);
        $corporate->accept($agreement->id, $owner->id);
        $claim = $corporate->claim($agreement->id, $owner->id, ['invoice_id' => $f['invoice']->id, 'cost_center_id' => $center, 'idempotency_key' => 'doc-race-claim']);
        $corporate->decide($claim->id, $owner->id, 'approved', 'Business charging');
        $statement = $corporate->issue($agreement->id, $owner->id, ['period' => now()->format('Y-m'), 'claim_ids' => [$claim->id], 'idempotency_key' => 'doc-race-statement']);
        $results = $this->race($owner->id, [$statement->id, $statement->id], 'corporate_statement');
        $this->assertSame($results[0], $results[1]);
        $this->assertDatabaseCount('financial_documents', 1);
        $this->assertDatabaseCount('corporate_receipts', 0);
        $this->assertDatabaseCount('finance_payments', 0);
        $row = DB::table('financial_documents')->sole();
        $this->assertSame('corporate_statement', $row->source_kind);
        $this->assertSame('issued', app(FinancialDocuments::class)->metadata($row, true)['snapshot']['source']['state_at_generation']);
    }

    public function test_different_sources_serialize_first_issuer_sequence_allocation_without_duplicate_numbers(): void
    {
        $f = $this->documentFixture();
        $station = Station::where('vendor_id', $f['vendor']->id)->firstOrFail();
        $reservation = Reservation::create(['user_id' => $f['customer']->id, 'station_id' => $station->id, 'starts_at' => now()->addHour(), 'status' => 'confirmed', 'idempotency_key' => 'document-second-fixture']);
        $session = ChargingSession::create(['reservation_id' => $reservation->id, 'status' => 'stopped', 'energy_wh' => 1000, 'rate_minor_per_kwh' => 100, 'currency' => 'AED', 'total_minor' => 100, 'stopped_at' => now()]);
        $finance = app(FinanceService::class);
        $finance->snapshotSession($session, $finance->activeTariff($station));
        $second = $finance->issue($session, $f['customer']->id);
        $results = $this->race($f['customer']->id, [$f['invoice']->id, $second->id]);
        $this->assertNotSame($results[0]['number'], $results[1]['number']);
        $this->assertDatabaseCount('financial_documents', 2);
        $this->assertDatabaseHas('financial_document_sequences', ['next_number' => 3]);
        $this->assertSame(2, DB::table('finance_journals')->count());
        $this->assertSame(0, DB::table('finance_payments')->count());
    }
}
