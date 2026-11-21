<?php

namespace Tests\Postgres;

use App\Models\User;
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

    private function reward(array $f, array $overrides = []): object
    {
        return app(LoyaltyService::class)->publish('loyalty_rewards', $f['maker']->id, array_replace(['currency' => 'AED', 'name' => 'TEST limited bottle', 'name_ar' => 'زجاجة اختبار', 'description' => 'Manual test benefit', 'description_ar' => 'مكافأة اختبار', 'points_cost' => 80, 'stock' => 1, 'per_user_limit' => 1, 'starts_at' => now()->subHour()->format('Y-m-d H:i:s'), 'ends_at' => now()->addDay()->format('Y-m-d H:i:s')], $overrides), 'race-reward');
    }

    private function coupon(array $f, object $reward, string $code, int $globalLimit, int $userLimit, bool $listed = true): int
    {
        return $this->actingAs($f['maker'])->postJson('/api/commercial/coupons', [
            'code' => $code,
            'reward_id' => $reward->id,
            'claim_limit' => $globalLimit,
            'per_user_limit' => $userLimit,
            'listed' => $listed,
            'starts_at' => now()->subMinute()->toISOString(),
            'ends_at' => now()->addHour()->toISOString(),
            'idempotency_key' => 'publish-'.$code,
        ])->assertCreated()->json('id');
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

    public function test_concurrent_coupon_claims_with_identical_keys_create_one_receipt(): void
    {
        $f = $this->fixture();
        $coupon = $this->coupon($f, $this->reward($f, ['stock' => 2, 'per_user_limit' => 2]), 'RACE-REPLAY-01', 2, 2);
        $op = ['claim-listed', $f['customer']->id, 0, $coupon, 'same-claim'];
        $this->assertSame(['201', '201'], $this->race([$op, $op]));
        $this->assertDatabaseCount('loyalty_coupon_claims', 1);
        $this->assertDatabaseCount('loyalty_redemptions', 1);
        $this->assertDatabaseCount('loyalty_ledger', 0);
        $this->assertDatabaseCount('finance_journals', 2);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'loyalty.coupon.claimed')->count());
    }

    public function test_concurrent_coupon_claims_respect_customer_and_global_limits(): void
    {
        $f = $this->fixture();
        $second = User::factory()->create();
        $third = User::factory()->create();
        $coupon = $this->coupon($f, $this->reward($f, ['stock' => 10, 'per_user_limit' => 5]), 'RACE-LIMITS-01', 2, 1);
        $this->assertSame(['201', '409'], $this->race([
            ['claim-listed', $f['customer']->id, 0, $coupon, 'first'],
            ['claim-listed', $f['customer']->id, 0, $coupon, 'second'],
        ]));
        $this->assertSame(['201', '409'], $this->race([
            ['claim-listed', $second->id, 0, $coupon, 'third'],
            ['claim-listed', $third->id, 0, $coupon, 'fourth'],
        ]));
        $this->assertDatabaseCount('loyalty_coupon_claims', 2);
        $this->assertDatabaseCount('loyalty_redemptions', 2);
        $this->assertSame(1, DB::table('loyalty_coupon_claims')->where('user_id', $f['customer']->id)->count());
    }

    public function test_private_and_listed_coupons_share_the_last_reward_unit(): void
    {
        $f = $this->fixture();
        $second = User::factory()->create();
        $reward = $this->reward($f);
        $privateCode = 'RACE-PRIVATE-01';
        $this->coupon($f, $reward, $privateCode, 2, 1, false);
        $listed = $this->coupon($f, $reward, 'RACE-LISTED-01', 2, 1);
        $this->assertSame(['201', '409'], $this->race([
            ['claim-code', $f['customer']->id, $privateCode, 0, 'private'],
            ['claim-listed', $second->id, 0, $listed, 'listed'],
        ]));
        $this->assertDatabaseCount('loyalty_coupon_claims', 1);
        $this->assertDatabaseHas('loyalty_redemptions', ['reward_id' => $reward->id, 'points_cost' => 0, 'status' => 'pending']);
        $this->assertDatabaseCount('loyalty_allocations', 0);
    }

    private function privateCoupon(array $f, object $reward, int $limit = 1): string
    {
        $code = 'SYNTHETIC-COUPON-'.$reward->id;
        $this->actingAs($f['maker'])->postJson('/api/commercial/coupons', [
            'code' => $code, 'reward_id' => $reward->id, 'claim_limit' => $limit,
            'per_user_limit' => 1, 'starts_at' => now()->subMinute()->toIso8601String(),
            'ends_at' => now()->addHour()->toIso8601String(), 'idempotency_key' => 'coupon-'.$reward->id,
        ])->assertCreated();

        return $code;
    }

    public function test_coupon_identical_process_claims_share_one_canonical_redemption(): void
    {
        $f = $this->fixture();
        $reward = $this->reward($f);
        $code = $this->privateCoupon($f, $reward);
        $op = ['coupon', $f['customer']->id, $code, $reward->id, 'same-coupon-key'];
        $this->assertSame(['201', '201'], $this->race([$op, $op]));
        $this->assertDatabaseCount('loyalty_coupon_claims', 1);
        $this->assertDatabaseCount('loyalty_redemptions', 1);
        $this->assertDatabaseCount('loyalty_ledger', 0);
        $this->assertDatabaseCount('finance_journals', 2);
    }

    public function test_two_members_cannot_consume_the_last_coupon_unit_twice(): void
    {
        $f = $this->fixture();
        $g = $this->fixture();
        $reward = $this->reward($f);
        $code = $this->privateCoupon($f, $reward);
        $this->assertSame(['201', '409'], $this->race([
            ['coupon', $f['customer']->id, $code, $reward->id, 'first'],
            ['coupon', $g['customer']->id, $code, $reward->id, 'second'],
        ]));
        $this->assertDatabaseCount('loyalty_coupon_claims', 1);
        $this->assertDatabaseCount('loyalty_redemptions', 1);
        $this->assertDatabaseCount('loyalty_ledger', 0);
    }

    public function test_coupon_and_points_redemption_share_the_same_last_stock(): void
    {
        $f = $this->fixture();
        $g = $this->fixture();
        $s = app(LoyaltyService::class);
        $s->earn($g['invoice']->id, $g['customer']->id);
        $reward = $this->reward($f);
        $code = $this->privateCoupon($f, $reward);
        $this->assertSame(['201', '409'], $this->race([
            ['coupon', $f['customer']->id, $code, $reward->id, 'coupon'],
            ['redeem', $g['customer']->id, $g['invoice']->id, $reward->id, 'points'],
        ]));
        $this->assertDatabaseCount('loyalty_redemptions', 1);
        $redemption = DB::table('loyalty_redemptions')->first();
        $this->assertSame((int) $redemption->points_cost, 100 - $s->account($g['customer']->id)['balance_points']);
        $this->assertDatabaseCount('finance_journals', 4);
    }

    public function test_claim_and_cancellation_race_has_exactly_one_durable_outcome(): void
    {
        $f = $this->fixture();
        $reward = $this->reward($f);
        $code = $this->privateCoupon($f, $reward);
        $statuses = $this->race([
            ['coupon', $f['customer']->id, $code, $reward->id, 'uncertain-race'],
            ['coupon-cancel', $f['customer']->id, $code, $reward->id, 'uncertain-race'],
        ]);
        $this->assertContains($statuses, [['201', '201'], ['201', '409']]);
        $claims = DB::table('loyalty_coupon_claims')->count();
        $cancelled = DB::table('loyalty_coupon_claim_intents')->count();
        $this->assertSame(1, $claims + $cancelled);
        $this->assertSame($claims, DB::table('loyalty_redemptions')->count());
        $this->assertDatabaseCount('loyalty_ledger', 0);
    }
}
