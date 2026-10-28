<?php

namespace Tests\Feature;

use App\Services\Commercial\Loyalty\LoyaltyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FinanceHistoryRollbackTest extends TestCase
{
    use RefreshDatabase;

    private function refuse(string $migration, array $tables): void
    {
        $counts = [];
        foreach ($tables as $table) {
            $counts[$table] = DB::table($table)->count();
        }
        $environment = app()['env'];
        $failure = null;
        try {
            app()['env'] = 'staging';
            $instance = require database_path('migrations/'.$migration.'.php');
            $instance->down();
        } catch (\RuntimeException $e) {
            $failure = $e;
        } finally {
            app()['env'] = $environment;
        }
        $this->assertInstanceOf(\RuntimeException::class, $failure, 'Populated history must reject downgrade.');
        $this->assertStringContainsString('history', $failure->getMessage());
        foreach ($tables as $table) {
            $this->assertTrue(Schema::hasTable($table), 'Preflight must happen before any table is dropped.');
            $this->assertSame($counts[$table], DB::table($table)->count());
        }
    }

    public function test_payout_history_refuses_populated_downgrade_before_any_ddl(): void
    {
        config(['payouts.enabled' => true]);
        (require base_path('tests/Fixtures/vendor-payout.php'))();
        $this->refuse('2026_10_28_180001_create_vendor_payouts', ['commercial_payout_webhooks', 'commercial_payout_events', 'commercial_payout_attempts', 'commercial_payouts', 'commercial_payout_accounts']);
    }

    public function test_payout_identity_guard_cannot_be_removed_before_a_populated_base_rollback(): void
    {
        config(['payouts.enabled' => true]);
        (require base_path('tests/Fixtures/vendor-payout.php'))();
        $this->refuse('2026_10_28_180002_unique_payout_source_identity', ['commercial_payout_accounts', 'commercial_payout_attempts']);
        $index = collect(Schema::getIndexes('commercial_payout_attempts'))->firstWhere('name', 'commercial_payout_attempts_provider_unique');
        $this->assertNotNull($index);
        $this->assertTrue($index['unique']);
    }

    public function test_loyalty_history_refuses_populated_downgrade_before_any_ddl(): void
    {
        config(['payouts.enabled' => true, 'loyalty.enabled' => true]);
        $fixture = (require base_path('tests/Fixtures/vendor-payout.php'))();
        app(LoyaltyService::class)->publish('loyalty_rewards', $fixture['maker']->id, ['currency' => 'AED', 'name' => 'Synthetic reward', 'name_ar' => 'مكافأة اختبار', 'description' => 'No real fulfillment', 'description_ar' => 'بدون تسليم حقيقي', 'points_cost' => 10, 'stock' => 1, 'per_user_limit' => 1, 'starts_at' => now()->subHour(), 'ends_at' => now()->addDay()], 'rollback-history-fixture');
        $this->refuse('2026_10_28_220001_create_loyalty_economics', ['loyalty_redemption_events', 'loyalty_ledger', 'loyalty_allocations', 'loyalty_redemptions', 'loyalty_awards', 'loyalty_rewards', 'loyalty_policies']);
    }
}
