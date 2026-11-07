<?php

namespace Tests\Postgres;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CorporateBillingConcurrencyTest extends TestCase
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
            $this->markTestSkipped('Requires isolated disposable PostgreSQL testing database.');
        }
        parent::setUp();
        config(['payments.provider' => 'mock']);
    }

    private function race(array $operations, array $f, int $statement = 0): array
    {
        $dir = sys_get_temp_dir().'/corporate-race-'.bin2hex(random_bytes(8));
        mkdir($dir, 0700);
        $workers = [];
        try {
            foreach ($operations as $i => [$operation,$claim,$key]) {
                $pipes = [];
                $ready = $dir.'/ready-'.$i;
                $p = proc_open([PHP_BINARY, base_path('tests/Support/corporate-billing-worker.php'), $operation, (string) $f['a']->id, (string) $f['owner']->id, (string) $claim, (string) $statement, $key, $dir.'/go', $ready], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
                $this->assertIsResource($p);
                $workers[] = [$p, $pipes, $ready];
            }
            $deadline = microtime(true) + 15;
            while (count(array_filter($workers, fn ($w) => is_file($w[2]))) !== count($workers) && microtime(true) < $deadline) {
                usleep(5000);
            }
            foreach ($workers as $w) {
                $this->assertFileExists($w[2]);
            }touch($dir.'/go');
            $statuses = [];
            foreach ($workers as [$p,$pipes]) {
                $statuses[] = trim(stream_get_contents($pipes[1]));
                $error = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $this->assertSame(0, proc_close($p), $error);
            }
            $workers = [];
            sort($statuses);

            return $statuses;
        } finally {
            foreach ($workers as [$p,$pipes]) {
                proc_terminate($p);
                foreach ($pipes as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }proc_close($p);
            }foreach (glob($dir.'/*') as $file) {
                unlink($file);
            }rmdir($dir);
        }
    }

    public function test_competing_statements_cannot_overrun_shared_corporate_credit_limit(): void
    {
        $f = (require base_path('tests/Fixtures/corporate-billing.php'))();
        $this->assertSame(['200', '409'], $this->race([['issue', $f['claims'][0]->id, 'first'], ['issue', $f['claims'][1]->id, 'second']], $f));
        $this->assertDatabaseCount('corporate_statements', 1);
        $this->assertDatabaseCount('corporate_statement_lines', 1);
        $this->assertSame(1050, (int) DB::table('corporate_statements')->sum('total_minor'));
    }

    public function test_identical_issue_and_simulated_collection_workers_share_one_statement_and_cash_journal(): void
    {
        $f = (require base_path('tests/Fixtures/corporate-billing.php'))();
        $this->assertSame(['200', '200'], $this->race([['issue', $f['claims'][0]->id, 'same'], ['issue', $f['claims'][0]->id, 'same']], $f));
        $s = DB::table('corporate_statements')->sole();
        $this->assertSame(['200', '200'], $this->race([['pay', 0, 'same-pay'], ['pay', 0, 'same-pay']], $f, $s->id));
        $this->assertDatabaseCount('corporate_receipts', 1);
        $this->assertDatabaseCount('corporate_receipt_payments', 1);
        $this->assertDatabaseCount('finance_payments', 1);
        $this->assertSame($f['owner']->id, (int) DB::table('finance_payments')->sole()->actor_id);
    }

    private function ownershipTransferWhileWaiting(string $operation): void
    {
        $f = (require base_path('tests/Fixtures/corporate-billing.php'))($operation !== 'review');
        $newOwner = User::factory()->create();
        $dir = sys_get_temp_dir().'/corporate-owner-race-'.bin2hex(random_bytes(8));
        mkdir($dir, 0700);
        $key = 'owner-'.bin2hex(random_bytes(8));
        $pipes = [];
        $worker = null;
        DB::beginTransaction();
        try {
            DB::table('fleets')->where('id', $f['fleet']->id)->lockForUpdate()->first();
            touch($dir.'/go');
            $worker = proc_open([PHP_BINARY, base_path('tests/Support/corporate-billing-worker.php'), $operation, (string) $f['a']->id, (string) $f['owner']->id, (string) $f['claims'][0]->id, '0', $key, $dir.'/go', $dir.'/ready'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
            $this->assertIsResource($worker);
            $deadline = microtime(true) + 15;
            $blocked = false;
            while (microtime(true) < $deadline) {
                DB::select('SELECT pg_stat_clear_snapshot()');
                $blocked = DB::table('pg_stat_activity')->where('application_name', 'corporate-race-'.$key)->where('wait_event_type', 'Lock')->exists();
                if ($blocked) {
                    break;
                }
                usleep(5000);
            }
            $this->assertTrue($blocked, 'Child mutation must be observed waiting for the held corporate fleet lock.');
            DB::table('fleets')->where('id', $f['fleet']->id)->update(['owner_id' => $newOwner->id]);
            DB::commit();
            $output = trim(stream_get_contents($pipes[1]));
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $this->assertSame(0, proc_close($worker), $error);
            $worker = null;
            $pipes = [];
            $this->assertSame('404', $output, 'Former owner must lose mutation authorization after waiting for ownership transfer.');
            $this->assertDatabaseCount('corporate_statements', 0);
            $this->assertDatabaseCount('corporate_claim_decisions', $operation === 'review' ? 0 : 2);
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            if (is_resource($worker)) {
                proc_terminate($worker);
                foreach ($pipes as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }proc_close($worker);
            }
            foreach (glob($dir.'/*') as $file) {
                unlink($file);
            }rmdir($dir);
        }
    }

    public function test_statement_owner_is_reauthorized_after_waiting_for_fleet_transfer(): void
    {
        $this->ownershipTransferWhileWaiting('issue');
    }

    public function test_claim_decision_owner_is_reauthorized_after_waiting_for_fleet_transfer(): void
    {
        $this->ownershipTransferWhileWaiting('review');
    }
}
