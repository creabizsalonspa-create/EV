<?php

namespace Tests\Feature;

use App\Services\Commercial\Loyalty\LoyaltyService;
use App\Services\Commercial\Payouts\PayoutService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FinancialAuditIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private function history(): array
    {
        $f = (require base_path('tests/Fixtures/vendor-payout.php'))();
        $payouts = app(PayoutService::class);
        $payouts->verify($f['account']->id, $f['maker']->id);
        $payout = $payouts->create($f['settlement']->id, $f['account']->id, $f['maker']->id, 'audit-dates');
        $payouts->approve($payout->id, $f['checker']->id);
        $payouts->execute($payout->id, $f['checker']->id);
        config(['loyalty.enabled' => true]);
        $loyalty = app(LoyaltyService::class);
        $loyalty->publish('loyalty_policies', $f['maker']->id, ['currency' => 'AED', 'minor_units' => 2, 'enabled' => true, 'points_numerator' => 1, 'minor_denominator' => 10, 'gold_threshold' => 100, 'platinum_threshold' => 500, 'gold_multiplier_bps' => 12500, 'platinum_multiplier_bps' => 15000, 'invoice_cap' => 1000, 'daily_cap' => 10000, 'expiry_days' => 30, 'daily_redemption_limit' => 5, 'effective_from' => now()->subDay()->toDateTimeString()], 'audit-policy');
        $reward = $loyalty->publish('loyalty_rewards', $f['maker']->id, ['currency' => 'AED', 'name' => 'Synthetic noncash reward', 'name_ar' => 'مكافأة تجريبية', 'description' => 'No partner fulfillment claimed.', 'description_ar' => 'لا يوجد تحقق من تسليم شريك', 'points_cost' => 20, 'stock' => 2, 'per_user_limit' => 1, 'starts_at' => now()->subHour()->toDateTimeString(), 'ends_at' => now()->addDay()->toDateTimeString()], 'audit-reward');
        $loyalty->earn($f['invoice']->id, $f['customer']->id);
        $redemption = $loyalty->redeem($f['customer']->id, $reward->id, $f['customer']->id, 'audit-redemption');

        return [
            'commercial_settlements' => $f['settlement']->id,
            'commercial_payout_accounts' => $f['account']->id,
            'commercial_payouts' => $payout->id,
            'commercial_payout_attempts' => DB::table('commercial_payout_attempts')->value('id'),
            'loyalty_redemptions' => $redemption->id,
        ];
    }

    public function test_financial_audit_dates_cannot_be_erased_using_sql_null_semantics(): void
    {
        $unguarded = [];
        foreach ($this->history() as $table => $id) {
            $before = DB::table($table)->find($id)->created_at;
            try {
                DB::transaction(function () use ($table, $id) {
                    DB::table($table)->where('id', $id)->update(['created_at' => null]);
                    // Always roll back the attempted write, including on the old
                    // vulnerable schema. A failed test must not alter its fixture.
                    throw new \LogicException('NULL audit-date write was accepted');
                });
            } catch (QueryException $error) {
                $this->assertMatchesRegularExpression('/immutable|not null/i', $error->getMessage());
            } catch (\LogicException $error) {
                $unguarded[] = $table;
            }
            $this->assertNotNull($before);
            $this->assertSame($before, DB::table($table)->find($id)->created_at);
        }
        $this->assertSame([], $unguarded, 'SQL NULL bypassed immutable dates in: '.implode(', ', $unguarded));
    }

    public function test_new_payout_accounts_require_a_real_creation_date(): void
    {
        $records = $this->history();
        $row = (array) DB::table('commercial_payout_accounts')->find($records['commercial_payout_accounts']);
        unset($row['id']);
        $row['request_key'] = hash('sha256', 'new-null-audit-account');
        $row['created_at'] = null;
        try {
            DB::transaction(fn () => DB::table('commercial_payout_accounts')->insert($row));
            $this->fail('A new payout account accepted a missing audit creation date.');
        } catch (QueryException $error) {
            $this->assertStringContainsString('audit creation date is required', $error->getMessage());
        }
        $this->assertDatabaseCount('commercial_payout_accounts', 1);
    }

    public function test_hardened_terms_still_allow_operational_updates_without_new_cash_postings(): void
    {
        $records = $this->history();
        $journals = DB::table('finance_journals')->count();
        foreach ($records as $table => $id) {
            $created = DB::table($table)->find($id)->created_at;
            $this->assertSame(1, DB::table($table)->where('id', $id)->update(['updated_at' => now()->addMinute()]));
            $this->assertSame($created, DB::table($table)->find($id)->created_at);
        }
        $this->assertSame($journals, DB::table('finance_journals')->count());
        $this->assertDatabaseCount('finance_refunds', 0);
    }

    public function test_upgrade_refuses_existing_missing_audit_dates_without_fabricating_history(): void
    {
        $records = $this->history();
        $id = $records['commercial_payout_accounts'];
        $original = DB::table('commercial_payout_accounts')->find($id)->created_at;
        $rollback = new \LogicException('Rollback isolated legacy audit-date fixture');
        try {
            DB::transaction(function () use ($id, $rollback) {
                $suffix = DB::getDriverName() === 'pgsql' ? ' ON commercial_payout_accounts' : '';
                DB::unprepared('DROP TRIGGER commercial_payout_accounts_terms'.$suffix);
                DB::table('commercial_payout_accounts')->where('id', $id)->update(['created_at' => null]);
                $migration = require database_path('migrations/2026_11_04_230001_harden_financial_audit_dates.php');
                try {
                    $migration->up();
                    $this->fail('Upgrade fabricated or ignored a missing historical audit date.');
                } catch (\RuntimeException $error) {
                    $this->assertStringContainsString('Reconcile NULL immutable audit dates', $error->getMessage());
                }
                $this->assertNull(DB::table('commercial_payout_accounts')->find($id)->created_at);
                throw $rollback;
            });
        } catch (\LogicException $error) {
            $this->assertSame($rollback, $error);
        }
        $this->assertSame($original, DB::table('commercial_payout_accounts')->find($id)->created_at);
    }

    public function test_populated_payout_history_refuses_non_testing_downgrade(): void
    {
        $this->history();
        $migration = require database_path('migrations/2026_10_28_180001_create_vendor_payouts.php');
        $this->app['env'] = 'local';
        try {
            $migration->down();
            $this->fail('Populated immutable payout history was discarded.');
        } catch (\RuntimeException $error) {
            $this->assertMatchesRegularExpression('/Preserve .*history before downgrade/', $error->getMessage());
        } finally {
            $this->app['env'] = 'testing';
        }
        $this->assertDatabaseCount('commercial_payouts', 1);
        $this->assertDatabaseCount('commercial_payout_attempts', 1);
    }

    public function test_populated_loyalty_history_refuses_non_testing_downgrade(): void
    {
        $this->history();
        $migration = require database_path('migrations/2026_10_28_220001_create_loyalty_economics.php');
        $this->app['env'] = 'local';
        try {
            $migration->down();
            $this->fail('Populated immutable loyalty history was discarded.');
        } catch (\RuntimeException $error) {
            $this->assertMatchesRegularExpression('/Preserve .*history before downgrade/', $error->getMessage());
        } finally {
            $this->app['env'] = 'testing';
        }
        $this->assertDatabaseCount('loyalty_ledger', 2);
        $this->assertDatabaseCount('loyalty_redemptions', 1);
    }
}
