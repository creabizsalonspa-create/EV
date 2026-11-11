<?php

namespace Tests\Postgres;

use App\Services\Finance\FinanceService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\CreatesUnpaidCreditInvoice;
use Tests\TestCase;

class UnpaidCreditConcurrencyTest extends TestCase
{
    use CreatesUnpaidCreditInvoice;
    use DatabaseMigrations { runDatabaseMigrations as private migrateDisposable; }

    protected function setUp(): void
    {
        if (getenv('DB_CONNECTION') !== 'pgsql' || getenv('APP_ENV') !== 'testing') {
            $this->markTestSkipped('Requires isolated PostgreSQL testing database.');
        }
        parent::setUp();
    }

    public function runDatabaseMigrations(): void
    {
        $this->assertSame('pgsql', DB::getDriverName());
        $this->assertMatchesRegularExpression('/_(review|test|testing)$/D', DB::connection()->getDatabaseName());
        $this->migrateDisposable();
    }

    private function race(string $kind): void
    {
        [$actor,$driver,,$invoice,,$intent] = $this->scenario();
        $directory = sys_get_temp_dir().'/ev-unpaid-race-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $workers = [];
        DB::unprepared('CREATE FUNCTION unpaid_credit_test_delay() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN PERFORM pg_sleep(0.4); RETURN NEW; END; $$');
        foreach (['finance_unpaid_credits', 'finance_unpaid_credit_cancellations', 'finance_payments'] as $table) {
            DB::unprepared('CREATE TRIGGER unpaid_credit_test_delay BEFORE INSERT ON '.$table.' FOR EACH ROW EXECUTE FUNCTION unpaid_credit_test_delay()');
        }
        try {
            for ($i = 0; $i < 2; $i++) {
                $v = $intent;
                if ($kind === 'distinct' && $i === 1) {
                    $v['idempotency_key'] = (string) Str::uuid();
                }
                $fixture = $directory.'/fixture-'.$i.'.json';
                file_put_contents($fixture, json_encode(['actor' => $actor->id, 'driver' => $driver->id, 'invoice' => $invoice->id, 'intent' => $v, 'configuration' => ['ocpi.test_billing_enabled' => true, 'app.key' => config('app.key')]], JSON_THROW_ON_ERROR));
                chmod($fixture, 0600);
                $pipes = [];
                $operation = $i === 1 && in_array($kind, ['payment', 'cancel'], true) ? $kind : 'credit';
                $process = proc_open([PHP_BINARY, base_path('tests/Support/unpaid-credit-concurrency-worker.php'), $operation, $fixture, $directory.'/start', $directory.'/ready-'.$i], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
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
            $credited = DB::table('finance_unpaid_credits')->exists();
            if ($kind === 'same') {
                $this->assertSame(['200', '200'], $statuses);
            } elseif ($kind === 'cancel') {
                $this->assertSame($credited ? ['200', '200'] : ['200', '409'], $statuses);
            } else {
                $this->assertSame(['200', '409'], $statuses);
            }
            $payments = DB::table('finance_payments')->count();
            $this->assertSame($kind === 'payment' && ! $credited ? 1 : 0, $payments);
            $this->assertSame($credited ? 1 : 0, DB::table('finance_unpaid_credits')->count());
            $this->assertSame($kind === 'cancel' && ! $credited ? 1 : 0, DB::table('finance_unpaid_credit_cancellations')->count());
            $this->assertDatabaseCount('finance_journals', 1 + $payments + ($credited ? 1 : 0));
            $this->assertDatabaseCount('finance_refunds', 0);
            $summary = app(FinanceService::class)->summary($invoice);
            $this->assertTrue($summary['ledger_balanced']);
            $this->assertSame($credited ? 25 : 0, $summary['unpaid_credited_minor']);
            $this->assertSame($payments ? 'paid' : ($credited ? 'credited' : 'unpaid'), $summary['state']);
        } finally {
            foreach ($workers as [$process,$pipes]) {
                proc_terminate($process);
                foreach ($pipes as $pipe) {
                    fclose($pipe);
                } proc_close($process);
            }
            foreach (['finance_unpaid_credits', 'finance_unpaid_credit_cancellations', 'finance_payments'] as $table) {
                DB::unprepared('DROP TRIGGER IF EXISTS unpaid_credit_test_delay ON '.$table);
            }
            DB::unprepared('DROP FUNCTION IF EXISTS unpaid_credit_test_delay()');
            foreach (glob($directory.'/*') as $file) {
                unlink($file);
            } rmdir($directory);
        }
    }

    public function test_same_key_retry_posts_one_credit(): void
    {
        $this->race('same');
    }

    public function test_competing_credit_keys_post_one_credit(): void
    {
        $this->race('distinct');
    }

    public function test_payment_and_non_cash_credit_have_one_winner(): void
    {
        $this->race('payment');
    }

    public function test_cancel_and_late_credit_have_one_truth(): void
    {
        $this->race('cancel');
    }
}
