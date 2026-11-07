<?php

namespace Tests\Postgres;

use App\Services\Commercial\Payouts\PayoutService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PayoutConcurrencyTest extends TestCase
{
    use DatabaseMigrations { runDatabaseMigrations as private migrateDisposableDatabase; }

    public function runDatabaseMigrations(): void
    {
        $this->assertSame('pgsql', DB::getDriverName());
        $this->assertMatchesRegularExpression('/_(review|test|testing)$/', DB::connection()->getDatabaseName());
        $this->migrateDisposableDatabase();
    }

    protected function setUp(): void
    {
        if (getenv('DB_CONNECTION') !== 'pgsql' || getenv('APP_ENV') !== 'testing') {
            $this->markTestSkipped('Requires an isolated disposable PostgreSQL testing database.');
        }
        parent::setUp();
        config(['payouts.enabled' => true, 'payouts.mock_outcome' => 'paid']);
    }

    private function fixture(): array
    {
        $f = (require base_path('tests/Fixtures/vendor-payout.php'))();
        app(PayoutService::class)->verify($f['account']->id, $f['maker']->id);

        return $f;
    }

    private function race(array $operations, array $f, int $payout = 0): array
    {
        $dir = sys_get_temp_dir().'/payout-race-'.bin2hex(random_bytes(8));
        mkdir($dir, 0700);
        $workers = [];
        try {
            foreach ($operations as $index => $operation) {
                $pipes = [];
                $ready = $dir.'/ready-'.$index;
                $process = proc_open([PHP_BINARY, base_path('tests/Support/payout-concurrency-worker.php'), $operation, (string) $f['settlement']->id, (string) $f['account']->id, (string) $f['maker']->id, (string) $payout, $dir.'/go', $ready], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
                $this->assertIsResource($process);
                $workers[] = [$process, $pipes, $ready, $operation];
            }
            $deadline = microtime(true) + 15;
            while (count(array_filter($workers, fn ($w) => is_file($w[2]))) !== count($workers) && microtime(true) < $deadline) {
                usleep(5000);
            }
            foreach ($workers as $worker) {
                $this->assertFileExists($worker[2]);
            } touch($dir.'/go');
            $statuses = [];
            foreach ($workers as [$process,$pipes,$ready,$operation]) {
                $statuses[] = trim(stream_get_contents($pipes[1]));
                $error = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $this->assertSame($operation === 'crash' ? 67 : 0, proc_close($process), $error);
            }
            $workers = [];
            sort($statuses);

            return $statuses;
        } finally {
            foreach ($workers as [$process,$pipes]) {
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
        }
    }

    public function test_identical_concurrent_payout_requests_share_one_durable_claim(): void
    {
        $f = $this->fixture();
        $this->assertSame(['201', '201'], $this->race(['create_same', 'create_same'], $f));
        $this->assertDatabaseCount('commercial_payouts', 1);
    }

    public function test_distinct_concurrent_payout_requests_cannot_claim_a_settlement_twice(): void
    {
        $f = $this->fixture();
        $this->assertSame(['201', '409'], $this->race(['create_same', 'create_other'], $f));
        $this->assertDatabaseCount('commercial_payouts', 1);
    }

    public function test_parallel_execution_and_legacy_bypass_do_not_duplicate_payout(): void
    {
        $f = $this->fixture();
        $service = app(PayoutService::class);
        $p = $service->create($f['settlement']->id, $f['account']->id, $f['maker']->id, 'prepared');
        $service->approve($p->id, $f['checker']->id);
        $this->assertSame(['200', '409'], $this->race(['execute', 'execute'], $f, $p->id));
        $this->assertDatabaseCount('commercial_payout_attempts', 1);
        $this->assertDatabaseHas('commercial_payouts', ['id' => $p->id, 'status' => 'paid']);
        $this->assertSame(['200', '409'], $this->race(['execute', 'legacy'], $f, $p->id));
        $this->assertDatabaseCount('commercial_payout_attempts', 1);
    }

    public function test_process_death_keeps_sent_attempt_and_expired_lease_replay_keeps_its_identity(): void
    {
        $f = $this->fixture();
        $service = app(PayoutService::class);
        $p = $service->create($f['settlement']->id, $f['account']->id, $f['maker']->id, 'prepared');
        $service->approve($p->id, $f['checker']->id);
        $this->race(['crash'], $f, $p->id);
        $a = DB::table('commercial_payout_attempts')->sole();
        $this->assertTrue((bool) $a->request_sent);
        $this->assertSame('executing', $a->status);
        DB::table('commercial_payout_attempts')->where('id', $a->id)->update(['lease_until' => now()->subMinute()]);
        $service->execute($p->id, $f['maker']->id);
        $this->assertDatabaseCount('commercial_payout_attempts', 1);
        $this->assertDatabaseHas('commercial_payout_attempts', ['id' => $a->id, 'reference' => $a->reference, 'status' => 'paid']);
        $this->assertDatabaseCount('finance_payments', 1);
        $this->assertDatabaseCount('finance_journals', 2);
    }
}
