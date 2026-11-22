<?php

namespace Tests\Postgres;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LoyaltyReferralConcurrencyTest extends TestCase
{
    use DatabaseMigrations { runDatabaseMigrations as private migrateDisposableDatabase; }

    protected function setUp(): void
    {
        if (getenv('DB_CONNECTION') !== 'pgsql' || getenv('APP_ENV') !== 'testing') {
            $this->markTestSkipped('Requires disposable PostgreSQL.');
        }
        parent::setUp();
        config(['loyalty.enabled' => true]);
    }

    public function runDatabaseMigrations(): void
    {
        $this->assertSame('pgsql', DB::getDriverName());
        $this->assertMatchesRegularExpression('/_(review|test|testing)$/', DB::connection()->getDatabaseName());
        $this->migrateDisposableDatabase();
    }

    private function race(array $operations): array
    {
        $dir = sys_get_temp_dir().'/referral-race-'.bin2hex(random_bytes(8));
        mkdir($dir, 0700);
        $workers = [];
        try {
            foreach ($operations as $i => [$operation, $actor, $invoice, $key]) {
                $pipes = [];
                $ready = $dir.'/ready-'.$i;
                $p = proc_open([PHP_BINARY, base_path('tests/Support/referral-worker.php'), $operation, (string) $actor, (string) $invoice, $key, $dir.'/go', $ready], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
                $this->assertIsResource($p);
                $workers[] = [$p, $pipes, $ready];
            }
            $deadline = microtime(true) + 15;
            while (count(array_filter($workers, fn ($w) => is_file($w[2]))) < count($workers) && microtime(true) < $deadline) {
                usleep(5000);
            }
            foreach ($workers as $w) {
                $this->assertFileExists($w[2]);
            }
            touch($dir.'/go');
            $statuses = [];
            foreach ($workers as [$p, $pipes]) {
                $statuses[] = trim(stream_get_contents($pipes[1]));
                $errors = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $this->assertSame(0, proc_close($p), $errors);
            }
            $workers = [];
            sort($statuses);

            return $statuses;
        } finally {
            foreach ($workers as [$p, $pipes]) {
                proc_terminate($p);
                foreach ($pipes as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                } proc_close($p);
            }
            foreach (glob($dir.'/*') as $file) {
                unlink($file);
            } rmdir($dir);
        }
    }

    public function test_duplicate_qualification_has_one_source_and_exactly_two_manual_entitlements(): void
    {
        $f = (require base_path('tests/Fixtures/referral-programme.php'))();
        $op = ['qualify', $f['member']->id, $f['member_invoice']->id, 'same'];
        $cash = DB::table('finance_journals')->orderBy('id')->get()->toJson();
        $this->assertSame(['200', '200'], $this->race([$op, $op]));
        $this->assertDatabaseCount('loyalty_referral_qualifications', 1);
        $this->assertDatabaseCount('loyalty_redemptions', 2);
        $this->assertDatabaseCount('loyalty_ledger', 0);
        $this->assertSame($cash, DB::table('finance_journals')->orderBy('id')->get()->toJson());
    }

    public function test_two_paid_members_cannot_spend_the_final_programme_qualification_twice(): void
    {
        $f = (require base_path('tests/Fixtures/referral-programme.php'))(1);
        $other = User::factory()->create();
        ($f['accept'])($other);
        $invoice = ($f['invoiceFor'])($other);
        $this->assertSame(['200', '409'], $this->race([
            ['qualify', $f['member']->id, $f['member_invoice']->id, 'first'],
            ['qualify', $other->id, $invoice->id, 'second'],
        ]));
        $this->assertDatabaseCount('loyalty_referral_qualifications', 1);
        $this->assertDatabaseCount('loyalty_redemptions', 2);
        $this->assertDatabaseCount('loyalty_ledger', 0);
    }

    public function test_concurrent_withdrawal_and_qualification_cannot_both_win(): void
    {
        $f = (require base_path('tests/Fixtures/referral-programme.php'))();
        $this->assertSame(['200', '409'], $this->race([
            ['qualify', $f['member']->id, $f['member_invoice']->id, 'qualify'],
            ['withdraw', $f['member']->id, 0, 'withdraw'],
        ]));
        $qualifications = DB::table('loyalty_referral_qualifications')->count();
        $this->assertSame(1, $qualifications + DB::table('loyalty_referral_withdrawals')->count());
        $this->assertSame($qualifications * 2, DB::table('loyalty_redemptions')->count());
    }
}
