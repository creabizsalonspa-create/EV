<?php

namespace Tests\Feature;

use App\Models\ChargingSession;
use App\Models\Finance\TariffVersion;
use App\Models\Reservation;
use App\Models\User;
use App\Services\Commercial\Loyalty\LoyaltyService;
use App\Services\Finance\FinanceService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class LoyaltyEconomicsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['loyalty.enabled' => true]);
        Route::middleware(['web', 'auth'])->prefix('loyalty-test')->group(base_path('routes/loyalty.php'));
    }

    private function path(string $part): string
    {
        return '/loyalty-test/commercial/loyalty/'.$part;
    }

    private function fixture(): array
    {
        return (require base_path('tests/Fixtures/vendor-payout.php'))();
    }

    private function policy(array $f, array $extra = []): object
    {
        return app(LoyaltyService::class)->publish('loyalty_policies', $f['maker']->id, array_replace(['currency' => 'AED', 'minor_units' => 2, 'enabled' => true, 'points_numerator' => 1, 'minor_denominator' => 10, 'gold_threshold' => 100, 'platinum_threshold' => 500, 'gold_multiplier_bps' => 12500, 'platinum_multiplier_bps' => 15000, 'invoice_cap' => 1000, 'daily_cap' => 10000, 'expiry_days' => 30, 'daily_redemption_limit' => 5, 'effective_from' => now()->subDay()->format('Y-m-d H:i:s')], $extra), 'policy-'.bin2hex(random_bytes(5)));
    }

    private function reward(array $f, array $extra = []): object
    {
        return app(LoyaltyService::class)->publish('loyalty_rewards', $f['maker']->id, array_replace(['currency' => 'AED', 'name' => 'Reusable bottle TEST', 'name_ar' => 'زجاجة قابلة لإعادة الاستخدام', 'description' => 'Manual test reward; no verified partner delivery.', 'description_ar' => 'مكافأة اختبار يدوية', 'points_cost' => 80, 'stock' => 2, 'per_user_limit' => 1, 'starts_at' => now()->subHour()->format('Y-m-d H:i:s'), 'ends_at' => now()->addDays(10)->format('Y-m-d H:i:s')], $extra), 'reward-'.bin2hex(random_bytes(5)));
    }

    public function test_qualifying_invoice_earns_exact_points_once_and_keeps_cash_ledger_unchanged(): void
    {
        $f = $this->fixture();
        $this->policy($f);
        $this->actingAs($f['customer'])->postJson($this->path('invoices/'.$f['invoice']->id.'/earn'))->assertCreated()->assertJsonPath('award.points', 100)->assertJsonPath('award.tier', 'Silver')->assertJsonPath('account.spendable_points', 100);
        $this->postJson($this->path('invoices/'.$f['invoice']->id.'/earn'))->assertCreated();
        $this->getJson($this->path('account'))->assertOk()->assertJsonPath('balance_points', 100)->assertJsonPath('cash_value', false)->assertJsonPath('tier_by_currency.AED.tier', 'Gold');
        $this->assertDatabaseCount('loyalty_awards', 1);
        $this->assertDatabaseCount('loyalty_ledger', 1);
        $this->assertDatabaseCount('finance_payments', 1);
        $this->assertDatabaseCount('finance_journals', 2);
    }

    public function test_partial_refund_reverses_proportional_points_and_repeated_reconcile_is_idempotent(): void
    {
        $f = $this->fixture();
        $this->policy($f);
        $service = app(LoyaltyService::class);
        $service->earn($f['invoice']->id, $f['customer']->id);
        app(FinanceService::class)->refund($f['invoice'], $f['maker']->id, 525, 'loyalty-half', 'Half refund');
        $this->actingAs($f['customer'])->getJson($this->path('account'))->assertJsonPath('balance_points', 100)->assertJsonPath('pending_reconciliation_points', 50)->assertJsonPath('spendable_points', 50);
        $this->postJson($this->path('reconcile'))->assertOk()->assertJsonPath('account.balance_points', 50);
        $this->postJson($this->path('reconcile'))->assertOk()->assertJsonPath('account.balance_points', 50);
        $this->assertDatabaseCount('loyalty_ledger', 2);
    }

    public function test_redemption_reserves_lots_inventory_and_is_payload_bound_then_admin_fulfills(): void
    {
        $f = $this->fixture();
        $this->policy($f);
        $reward = $this->reward($f);
        $other = $this->reward($f);
        app(LoyaltyService::class)->earn($f['invoice']->id, $f['customer']->id);
        $this->actingAs($f['customer']);
        $id = $this->postJson($this->path('rewards/'.$reward->id.'/redeem'), ['idempotency_key' => 'reward'])->assertCreated()->assertJsonPath('account.balance_points', 20)->assertJsonPath('redemption.status', 'pending')->json('redemption.id');
        $this->postJson($this->path('rewards/'.$reward->id.'/redeem'), ['idempotency_key' => 'reward'])->assertCreated();
        $this->postJson($this->path('rewards/'.$other->id.'/redeem'), ['idempotency_key' => 'reward'])->assertConflict();
        $this->postJson($this->path('redemptions/'.$id.'/fulfill'), ['reason' => 'Physical bottle handed over'])->assertForbidden();
        $this->actingAs($f['maker'])->postJson($this->path('redemptions/'.$id.'/fulfill'), ['reason' => 'TEST bottle collection confirmed'])->assertOk()->assertJsonPath('redemption.status', 'fulfilled');
        $this->postJson($this->path('redemptions/'.$id.'/fulfill'), ['reason' => 'TEST bottle collection confirmed'])->assertOk();
        $this->postJson($this->path('redemptions/'.$id.'/fulfill'), ['reason' => 'Changed decision reason'])->assertConflict();
        $this->assertDatabaseCount('loyalty_redemptions', 1);
        $this->assertDatabaseCount('loyalty_allocations', 1);
        $this->assertDatabaseCount('finance_journals', 2);
    }

    public function test_refund_after_redemption_creates_points_debt_blocks_fulfillment_and_rejection_releases_safely(): void
    {
        $f = $this->fixture();
        $this->policy($f);
        $reward = $this->reward($f);
        $service = app(LoyaltyService::class);
        $service->earn($f['invoice']->id, $f['customer']->id);
        $redemption = $service->redeem($f['customer']->id, $reward->id, $f['customer']->id, 'reward');
        app(FinanceService::class)->refund($f['invoice'], $f['maker']->id, 1050, 'full-after-reward', 'Full source refund');
        $this->actingAs($f['customer'])->postJson($this->path('reconcile'))->assertOk()->assertJsonPath('account.balance_points', -80)->assertJsonPath('account.spendable_points', 0);
        $this->actingAs($f['maker'])->postJson($this->path('redemptions/'.$redemption->id.'/fulfill'), ['reason' => 'Do not fulfill debt'])->assertConflict();
        $this->postJson($this->path('redemptions/'.$redemption->id.'/reject'), ['reason' => 'Refunded source; reward cancelled'])->assertOk();
        $this->actingAs($f['customer'])->getJson($this->path('account'))->assertJsonPath('balance_points', 0);
        $this->assertDatabaseCount('loyalty_ledger', 4);
    }

    public function test_expiration_then_refund_does_not_double_claw_back_expired_points_and_rejection_does_not_revive_them(): void
    {
        $f = $this->fixture();
        $this->policy($f, ['expiry_days' => 1]);
        $reward = $this->reward($f);
        $service = app(LoyaltyService::class);
        $service->earn($f['invoice']->id, $f['customer']->id);
        $r = $service->redeem($f['customer']->id, $reward->id, $f['customer']->id, 'before-expiry');
        $this->travel(2)->days();
        $this->assertSame(0, $service->reconcile($f['customer']->id, $f['customer']->id)['balance_points']);
        app(FinanceService::class)->refund($f['invoice'], $f['maker']->id, 1050, 'after-expiry', 'Refund after points expiry');
        $this->assertSame(-80, $service->reconcile($f['customer']->id, $f['customer']->id)['balance_points']);
        $service->transition($r->id, $f['maker']->id, 'reject', 'Reward cannot be collected');
        $this->assertSame(0, $service->account($f['customer']->id)['balance_points']);
        $service->reconcile($f['customer']->id, $f['customer']->id);
        $this->assertSame(0, $service->account($f['customer']->id)['balance_points']);
    }

    public function test_partial_refund_before_first_earn_grants_only_net_points_and_future_policy_does_not_rewrite_award(): void
    {
        $f = $this->fixture();
        $p = $this->policy($f);
        app(FinanceService::class)->refund($f['invoice'], $f['maker']->id, 525, 'before-earn', 'Partial source refund');
        $service = app(LoyaltyService::class);
        $award = $service->earn($f['invoice']->id, $f['customer']->id);
        $this->assertSame($p->id, $award->policy_id);
        $this->assertSame(50, $service->account($f['customer']->id)['balance_points']);
        $this->policy($f, ['points_numerator' => 50, 'effective_from' => now()->addHour()->format('Y-m-d H:i:s')]);
        $service->earn($f['invoice']->id, $f['customer']->id);
        $this->assertSame(50, $service->account($f['customer']->id)['balance_points']);
        $this->assertDatabaseCount('loyalty_awards', 1);
    }

    public function test_pending_refund_hold_prevents_spending_previously_earned_points(): void
    {
        $f = $this->fixture();
        $this->policy($f);
        $service = app(LoyaltyService::class);
        $service->earn($f['invoice']->id, $f['customer']->id);
        $reward = $this->reward($f);
        DB::table('finance_refund_requests')->insert(['invoice_id' => $f['invoice']->id, 'user_id' => $f['customer']->id, 'amount_minor' => 100, 'reason' => 'Pending refund request', 'request_key' => hash('sha256', 'hold'), 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($f['customer'])->getJson($this->path('account'))->assertJsonPath('redemption_hold', true)->assertJsonPath('spendable_points', 0);
        $this->postJson($this->path('rewards/'.$reward->id.'/redeem'), ['idempotency_key' => 'held'])->assertConflict();
        $this->assertDatabaseCount('loyalty_redemptions', 0);
    }

    public function test_roles_privacy_payload_limits_and_production_gate_are_enforced(): void
    {
        $f = $this->fixture();
        $this->policy($f);
        $reward = $this->reward($f);
        app(LoyaltyService::class)->earn($f['invoice']->id, $f['customer']->id);
        $r = app(LoyaltyService::class)->redeem($f['customer']->id, $reward->id, $f['customer']->id, 'private');
        $this->actingAs(User::factory()->create())->getJson($this->path('redemptions/'.$r->id))->assertNotFound();
        $this->getJson($this->path('redemptions'))->assertJsonCount(0, 'data');
        $this->getJson($this->path('ledger'))->assertJsonCount(0, 'data');
        $this->postJson($this->path('invoices/'.$f['invoice']->id.'/earn'))->assertNotFound();
        $this->postJson($this->path('policies'), [])->assertForbidden();
        $this->actingAs($f['maker'])->postJson($this->path('policies'), ['currency' => 'AED', 'points_numerator' => 1.5])->assertUnprocessable();
        $this->app->detectEnvironment(fn () => 'production');
        try {
            app(LoyaltyService::class)->reconcile($f['customer']->id, $f['maker']->id);
            $this->fail('Production loyalty mutation accepted');
        } catch (HttpException $e) {
            $this->assertSame(503, $e->getStatusCode());
        }
    }

    public function test_points_ledger_is_database_immutable(): void
    {
        $f = $this->fixture();
        $this->policy($f);
        app(LoyaltyService::class)->earn($f['invoice']->id, $f['customer']->id);
        $this->expectException(QueryException::class);
        DB::table('loyalty_ledger')->update(['points' => 999]);
    }

    private function additionalInvoice(array $f, int $paid): object
    {
        $session = ChargingSession::findOrFail($f['invoice']->charging_session_id);
        $reservation = Reservation::findOrFail($session->reservation_id);
        $next = Reservation::create(['user_id' => $f['customer']->id, 'station_id' => $reservation->station_id, 'starts_at' => Carbon::parse(Reservation::where('station_id', $reservation->station_id)->max('starts_at'))->addHour(), 'status' => 'confirmed', 'idempotency_key' => bin2hex(random_bytes(10))]);
        $charging = ChargingSession::create(['reservation_id' => $next->id, 'status' => 'stopped', 'energy_wh' => 10000, 'rate_minor_per_kwh' => 100, 'currency' => 'AED', 'total_minor' => 1000, 'stopped_at' => now()]);
        $quote = DB::table('finance_session_quotes')->find($f['invoice']->quote_id);
        $service = app(FinanceService::class);
        $service->snapshotSession($charging, TariffVersion::findOrFail($quote->tariff_version_id));
        $invoice = $service->issue($charging, $f['customer']->id);
        if ($paid > 0) {
            $service->pay($invoice, $f['customer']->id, $paid, 'additional-'.$invoice->id);
        }

        return $invoice;
    }

    public function test_invoice_fixtures_allocate_distinct_slots_with_a_frozen_clock(): void
    {
        $this->travelTo(now()->startOfHour());
        $f = $this->fixture();
        $this->additionalInvoice($f, 0);
        $this->additionalInvoice($f, 0);
        $starts = Reservation::orderBy('starts_at')->pluck('starts_at')->map(fn ($date) => (string) $date)->all();
        $this->assertSame([now()->toDateTimeString(), now()->addHour()->toDateTimeString(), now()->addHours(2)->toDateTimeString()], $starts);
    }

    public function test_partial_and_unpaid_sources_cannot_mint_points(): void
    {
        $f = $this->fixture();
        $this->policy($f);
        foreach ([0, 500] as $paid) {
            $invoice = $this->additionalInvoice($f, $paid);
            $this->actingAs($f['customer'])->postJson($this->path('invoices/'.$invoice->id.'/earn'))->assertConflict();
        }
        $this->assertDatabaseCount('loyalty_awards', 0);
        $this->assertDatabaseCount('loyalty_ledger', 0);
    }

    public function test_tier_multipliers_and_per_member_reward_limit_are_server_enforced(): void
    {
        $f = $this->fixture();
        $this->policy($f, ['gold_threshold' => 100, 'platinum_threshold' => 200, 'daily_cap' => 1000, 'invoice_cap' => 1000]);
        $service = app(LoyaltyService::class);
        $a = $service->earn($f['invoice']->id, $f['customer']->id);
        $b = $service->earn($this->additionalInvoice($f, 1050)->id, $f['customer']->id);
        $c = $service->earn($this->additionalInvoice($f, 1050)->id, $f['customer']->id);
        $this->assertSame(['Silver', 'Gold', 'Platinum'], [$a->tier, $b->tier, $c->tier]);
        $this->assertSame([100, 125, 150], [(int) $a->points, (int) $b->points, (int) $c->points]);
        $reward = $this->reward($f);
        $service->redeem($f['customer']->id, $reward->id, $f['customer']->id, 'first');
        $this->actingAs($f['customer'])->postJson($this->path('rewards/'.$reward->id.'/redeem'), ['idempotency_key' => 'second'])->assertConflict();
        $this->assertDatabaseCount('loyalty_redemptions', 1);
        $this->assertSame(295, $service->account($f['customer']->id)['balance_points']);
    }

    public function test_daily_earn_cap_is_enforced_and_expired_rejection_does_not_revive_points(): void
    {
        $f = $this->fixture();
        $this->policy($f, ['invoice_cap' => 100, 'daily_cap' => 100, 'expiry_days' => 1]);
        $service = app(LoyaltyService::class);
        $service->earn($f['invoice']->id, $f['customer']->id);
        $next = $this->additionalInvoice($f, 1050);
        $this->actingAs($f['customer'])->postJson($this->path('invoices/'.$next->id.'/earn'))->assertConflict();
        $reward = $this->reward($f);
        $r = $service->redeem($f['customer']->id, $reward->id, $f['customer']->id, 'expiry-reject');
        $this->travel(2)->days();
        $service->transition($r->id, $f['maker']->id, 'reject', 'Expired uncollected test reward');
        $this->assertSame(0, $service->account($f['customer']->id)['balance_points']);
        $this->assertSame(100, -(int) DB::table('loyalty_ledger')->where('kind', 'expiry')->sum('points'));
    }

    public function test_uncertain_psp_cannot_mint_points_and_confirmed_lost_chargeback_claws_back_without_cash_mutation(): void
    {
        $f = $this->fixture();
        $this->policy($f);
        $service = app(LoyaltyService::class);
        // Construct operational source observations, not a client success assertion or real merchant response.
        $checkout = DB::table('gateway_checkouts')->insertGetId(['invoice_id' => $f['invoice']->id, 'user_id' => $f['customer']->id, 'provider' => 'stripe_test', 'provider_reference' => 'pi_Loyalty', 'request_key' => hash('sha256', 'checkout'), 'fingerprint' => hash('sha256', 'checkout'), 'currency' => 'AED', 'amount_minor' => 1050, 'status' => 'captured', 'created_at' => now(), 'updated_at' => now()]);
        $intent = DB::table('payment_recovery_intents')->insertGetId(['identity' => hash('sha256', 'intent'), 'scope' => 'charging', 'checkout_id' => $checkout, 'actor_id' => $f['customer']->id, 'kind' => 'refund', 'fingerprint' => hash('sha256', 'intent'), 'provider' => 'stripe_test', 'currency' => 'AED', 'amount_minor' => 100, 'status' => 'uncertain', 'retry_until' => now()->addHours(23), 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($f['customer'])->postJson($this->path('invoices/'.$f['invoice']->id.'/earn'))->assertConflict();
        $this->assertDatabaseCount('loyalty_awards', 0);
        DB::table('payment_recovery_intents')->where('id', $intent)->update(['status' => 'completed']);
        $service->earn($f['invoice']->id, $f['customer']->id);
        $case = DB::table('gateway_cases')->insertGetId(['invoice_id' => $f['invoice']->id, 'user_id' => $f['customer']->id, 'kind' => 'dispute', 'request_key' => hash('sha256', 'lost'), 'fingerprint' => hash('sha256', 'lost'), 'amount_minor' => 1050, 'reason' => 'TEST lost chargeback source', 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('external_disputes')->insert(['checkout_id' => $checkout, 'case_id' => $case, 'provider_reference' => 'dp_Loyalty', 'charge_reference' => 'ch_Loyalty', 'currency' => 'AED', 'amount_minor' => 1050, 'status' => 'lost', 'reason' => 'fraudulent', 'settlement_hold' => true, 'provider_observed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $this->postJson($this->path('reconcile'))->assertOk()->assertJsonPath('account.balance_points', 0)->assertJsonPath('account.qualified_lifetime_points', 0)->assertJsonPath('account.redemption_hold', true);
        $this->assertDatabaseCount('finance_journals', 2);
        $this->assertDatabaseCount('finance_refunds', 0);
    }
}
