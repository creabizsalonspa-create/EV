<?php

namespace Tests\Postgres;

use App\Services\Commercial\Loyalty\LoyaltyService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LoyaltyConcurrencyTest extends TestCase
{
    use DatabaseMigrations { runDatabaseMigrations as private migrateDisposableDatabase; }

    protected function setUp(): void
    {
        if (getenv('DB_CONNECTION') !== 'pgsql' || getenv('APP_ENV') !== 'testing') {
            $this->markTestSkipped('Requires a disposable PostgreSQL testing database.');
        }
        parent::setUp();
        config(['loyalty.enabled' => true]);
    }

    public function runDatabaseMigrations(): void
    {
        $this->assertSame('pgsql', DB::getDriverName());
        $this->assertMatchesRegularExpression('/_(review|test|testing)$/D', DB::connection()->getDatabaseName());
        $this->migrateDisposableDatabase();
    }

    private function fixture(): array
    {
        $f = (require base_path('tests/Fixtures/vendor-payout.php'))();
        app(LoyaltyService::class)->publish('loyalty_policies', $f['maker']->id, ['currency' => 'AED', 'minor_units' => 2, 'enabled' => true, 'points_numerator' => 1, 'minor_denominator' => 10, 'gold_threshold' => 100, 'platinum_threshold' => 500, 'gold_multiplier_bps' => 12500, 'platinum_multiplier_bps' => 15000, 'invoice_cap' => 1000, 'daily_cap' => 10000, 'expiry_days' => 30, 'daily_redemption_limit' => 5, 'effective_from' => now()->subDay()->format('Y-m-d H:i:s')], 'race-policy');

        return $f;
    }

    private function reward(array $f): object
    {
        return app(LoyaltyService::class)->publish('loyalty_rewards', $f['maker']->id, ['currency' => 'AED', 'name' => 'TEST limited bottle', 'name_ar' => 'زجاجة اختبار', 'description' => 'Manual test benefit', 'description_ar' => 'مكافأة اختبار', 'points_cost' => 80, 'stock' => 1, 'per_user_limit' => 1, 'starts_at' => now()->subHour()->format('Y-m-d H:i:s'), 'ends_at' => now()->addDay()->format('Y-m-d H:i:s')], 'race-reward');
    }

    private function race(array $operations): array
    {
        $dir = sys_get_temp_dir().'/loyalty-race-'.bin2hex(random_bytes(8));
        mkdir($dir, 0700);
        $workers = [];
        try {
            foreach ($operations as $i => [$operation, $user, $invoice, $reward, $key]) {
                $pipes = [];
                $ready = $dir.'/ready-'.$i;
                $process = proc_open([PHP_BINARY, base_path('tests/Support/loyalty-concurrency-worker.php'), $operation, (string) $user, (string) $invoice, (string) $reward, $key, $dir.'/go', $ready], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
                $this->assertIsResource($process);
                $workers[] = [$process, $pipes, $ready];
            }
            $deadline = microtime(true) + 15;
            while (count(array_filter($workers, fn ($w) => is_file($w[2]))) !== count($workers) && microtime(true) < $deadline) {
                usleep(5000);
            }
            foreach ($workers as $w) {
                $this->assertFileExists($w[2]);
            } touch($dir.'/go');
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

            return $statuses;
        } finally {
            foreach ($workers as [$process, $pipes]) {
                proc_terminate($process);
                foreach ($pipes as $p) {
                    if (is_resource($p)) {
                        fclose($p);
                    }
                } proc_close($process);
            }
            foreach (glob($dir.'/*') as $file) {
                unlink($file);
            } rmdir($dir);
        }
    }

    public function test_two_process_earning_grants_one_invoice_award_and_one_points_credit(): void
    {
        $f = $this->fixture();
        $op = ['earn', $f['customer']->id, $f['invoice']->id, 0, ''];
        $this->assertSame(['201', '201'], $this->race([$op, $op]));
        $this->assertDatabaseCount('loyalty_awards', 1);
        $this->assertDatabaseCount('loyalty_ledger', 1);
        $this->assertSame(100, app(LoyaltyService::class)->account($f['customer']->id)['balance_points']);
        $this->assertDatabaseCount('finance_journals', 2);
    }

    public function test_two_process_identical_redemptions_share_one_hold_and_debit(): void
    {
        $f = $this->fixture();
        $s = app(LoyaltyService::class);
        $s->earn($f['invoice']->id, $f['customer']->id);
        $reward = $this->reward($f);
        $op = ['redeem', $f['customer']->id, $f['invoice']->id, $reward->id, 'same-key'];
        $this->assertSame(['201', '201'], $this->race([$op, $op]));
        $this->assertDatabaseCount('loyalty_redemptions', 1);
        $this->assertDatabaseCount('loyalty_allocations', 1);
        $this->assertSame(20, $s->account($f['customer']->id)['balance_points']);
    }

    public function test_two_members_cannot_oversell_last_reward_or_lose_the_rejected_members_points(): void
    {
        $f = $this->fixture();
        $g = $this->fixture();
        $s = app(LoyaltyService::class);
        $s->earn($f['invoice']->id, $f['customer']->id);
        $s->earn($g['invoice']->id, $g['customer']->id);
        $reward = $this->reward($f);
        $this->assertSame(['201', '409'], $this->race([['redeem', $f['customer']->id, $f['invoice']->id, $reward->id, 'one'], ['redeem', $g['customer']->id, $g['invoice']->id, $reward->id, 'two']]));
        $this->assertDatabaseCount('loyalty_redemptions', 1);
        $this->assertSame(120, $s->account($f['customer']->id)['balance_points'] + $s->account($g['customer']->id)['balance_points']);
        $this->assertDatabaseCount('finance_journals', 4);
    }
}
