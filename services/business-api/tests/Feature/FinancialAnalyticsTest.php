<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Commercial\Analytics\AnalyticsPresentation;
use App\Services\Commercial\Analytics\FinancialAnalytics;
use App\Services\Commercial\CorporateBilling;
use App\Services\Commercial\Loyalty\LoyaltyService;
use App\Services\Commercial\Payouts\PayoutService;
use App\Services\Commercial\SubscriptionCreditService;
use App\Services\Commercial\SubscriptionService;
use App\Services\Finance\FinanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class FinancialAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function fixture(): array
    {
        return (require base_path('tests/Fixtures/vendor-payout.php'))();
    }

    private function group(array $report, string $category, string $period = 'current'): array
    {
        return collect($report['groups'])->first(fn ($g) => $g['category'] === $category && $g['period'] === $period) ?? [];
    }

    public function test_actual_postings_refunds_and_reservations_are_distinct_and_never_multiply(): void
    {
        $f = $this->fixture();
        app(FinanceService::class)->refund($f['invoice'], $f['maker']->id, 100, 'analytics-partial-a', 'First exact refund');
        app(FinanceService::class)->refund($f['invoice'], $f['maker']->id, 200, 'analytics-partial-b', 'Second exact refund');
        $before = DB::table('finance_journals')->count();
        $report = app(FinancialAnalytics::class)->report($f['maker'], []);
        $this->assertSame(1050, $this->group($report, 'charging_invoiced')['amount_minor']);
        $this->assertSame(1050, $this->group($report, 'charging_collected')['amount_minor']);
        $this->assertSame(300, $this->group($report, 'charging_refunded')['amount_minor']);
        $this->assertSame(2, $this->group($report, 'charging_refunded')['record_count']);
        $this->assertSame(900, $this->group($report, 'settlement_instruction')['amount_minor']);
        $this->assertSame($before, DB::table('finance_journals')->count());
        $this->assertFalse($report['real_money_verified']);
        Http::assertNothingSent();
    }

    public function test_customer_vendor_and_native_api_scopes_cannot_select_another_actor(): void
    {
        $f = $this->fixture();
        $this->actingAs($f['customer']);
        $r = $this->getJson('/api/financial/analytics')->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json();
        $this->assertSame(1050, $this->group($r, 'charging_collected')['amount_minor']);
        foreach (['commission_allocated', 'vendor_allocated', 'settlement_instruction', 'payout_instruction'] as $private) {
            $this->assertSame([], $this->group($r, $private));
        }
        $this->getJson('/api/financial/analytics?user_id='.$f['vendor']->id)->assertUnprocessable();
        $this->actingAs($f['vendor']);
        $vendor = $this->getJson('/api/v1/financial/analytics')->assertOk()->json();
        $this->assertSame(900, $this->group($vendor, 'vendor_allocated')['amount_minor']);
        $this->actingAs(User::factory()->create());
        $this->getJson('/api/financial/analytics')->assertOk()->assertJsonCount(0, 'groups');
    }

    public function test_period_comparison_uses_posting_time_even_for_an_older_invoice(): void
    {
        $this->travelTo(now()->startOfDay()->subDays(35));
        $f = $this->fixture();
        $this->travelBack();
        app(FinanceService::class)->refund($f['invoice'], $f['maker']->id, 100, 'current-refund', 'Refund posted this period');
        $report = app(FinancialAnalytics::class)->report($f['customer'], []);
        $this->assertSame([], $this->group($report, 'charging_invoiced'));
        $this->assertSame(1050, $this->group($report, 'charging_invoiced', 'previous')['amount_minor']);
        $this->assertSame(100, $this->group($report, 'charging_refunded')['amount_minor']);
    }

    public function test_provider_payout_is_test_evidence_and_separate_from_original_customer_capture(): void
    {
        $f = $this->fixture();
        $p = app(PayoutService::class);
        $p->verify($f['account']->id, $f['maker']->id);
        $out = $p->create($f['settlement']->id, $f['account']->id, $f['maker']->id, 'analytics-payout');
        $p->approve($out->id, $f['checker']->id);
        $p->execute($out->id, $f['maker']->id);
        $report = app(FinancialAnalytics::class)->report($f['vendor'], []);
        $this->assertSame('MOCK_PAYOUT', $this->group($report, 'payout_instruction')['evidence']);
        $this->assertSame('paid', $this->group($report, 'payout_instruction')['state']);
        $this->assertSame(900, $this->group($report, 'payout_instruction')['amount_minor']);
        $this->assertSame(1050, $this->group($report, 'charging_collected')['amount_minor']);
    }

    public function test_corporate_aging_is_current_authorized_receivable_not_duplicate_revenue(): void
    {
        $f = (require base_path('tests/Fixtures/corporate-billing.php'))();
        $service = app(CorporateBilling::class);
        $s = $service->issue($f['a']->id, $f['owner']->id, ['period' => now()->format('Y-m'), 'claim_ids' => [$f['claims'][0]->id], 'idempotency_key' => 'aging']);
        $report = app(FinancialAnalytics::class)->report($f['owner'], []);
        $this->assertSame(1050, $this->group($report, 'corporate_invoiced')['amount_minor']);
        $this->assertSame('not_due', $this->group($report, 'corporate_receivable')['state']);
        $this->assertSame([], $this->group(app(FinancialAnalytics::class)->report($f['driver'], []), 'corporate_invoiced'));
        $this->travel(35)->days();
        $aged = app(FinancialAnalytics::class)->report($f['owner'], ['from' => now()->subDays(50)->toDateString()]);
        $this->assertSame('overdue_1_30', $this->group($aged, 'corporate_receivable')['state']);
        $service->simulatePayment($s->id, $f['owner']->id, 'aging-pay');
        $this->assertSame([], $this->group(app(FinancialAnalytics::class)->report($f['owner'], ['from' => now()->subDays(50)->toDateString()]), 'corporate_receivable'));
    }

    public function test_filters_date_bounds_and_private_csv_export_are_fail_closed(): void
    {
        $f = $this->fixture();
        $this->actingAs($f['customer']);
        foreach (['from=2026-02-30', 'from=2020-01-01', 'from=2099-01-01', 'currency=usd', 'vendor_id=2'] as $query) {
            $this->getJson('/api/financial/analytics?'.$query)->assertUnprocessable();
        }
        $this->getJson('/api/financial/analytics?currency=USD')->assertOk()->assertJsonCount(0, 'groups');
        $csv = $this->get('/api/financial/analytics/export?currency=AED')->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8')->getContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('charging_collected', $csv);
        $this->assertStringNotContainsString('commission_allocated', $csv);
        $this->assertDatabaseHas('audit_events', ['actor_id' => $f['customer']->id, 'action' => 'finance.analytics.view']);
    }

    public function test_recurring_equivalents_use_paid_cycles_exact_group_rounding_and_credits(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $service = app(SubscriptionService::class);
        $users = [];
        foreach (['month' => 100, 'year' => 125] as $interval => $price) {
            $user = User::factory()->create();
            $plan = $service->publish($admin, ['code' => 'analytics-'.$interval, 'name' => 'Analytics '.$interval, 'audience' => 'customer', 'currency' => 'AED', 'minor_units' => 2, 'price_minor' => $price, 'tax_bps' => 0, 'interval' => $interval, 'entitlements' => '{}']);
            $s = $service->subscribe($user, $plan->id, 'analytics-subscribe');
            $invoice = $s['invoices']->first();
            $service->mockPay($user, $s['subscription']->id, $invoice->id, 'succeeded', 'analytics-paid');
            $users[$interval] = [$user, $s['subscription']->id, $invoice->id];
        }
        $report = app(FinancialAnalytics::class)->report($admin, []);
        $this->assertCount(1, $report['recurring_run_rates']);
        $this->assertSame(1325, $report['recurring_run_rates'][0]['annual_equivalent_minor']);
        $this->assertSame(110, $report['recurring_run_rates'][0]['monthly_equivalent_minor']);
        $this->assertSame(2, $report['recurring_run_rates'][0]['subscriptions']);
        [$yearUser, $yearSubscription, $yearInvoice] = $users['year'];
        $own = app(FinancialAnalytics::class)->report($yearUser, []);
        $this->assertSame(10, $own['recurring_run_rates'][0]['monthly_equivalent_minor']);
        app(SubscriptionCreditService::class)->refund($admin, $yearSubscription, $yearInvoice, 5, 'Approved exact analytic credit', 'analytics-credit');
        $this->assertSame(1320, app(FinancialAnalytics::class)->report($admin, [])['recurring_run_rates'][0]['annual_equivalent_minor']);
        $service->change($yearUser, $yearSubscription, 'cancel_at_period_end', null, 'cancel-analytics');
        $this->assertCount(0, app(FinancialAnalytics::class)->report($yearUser, [])['recurring_run_rates']);
    }

    public function test_posted_points_are_not_a_currency_or_a_cash_liability(): void
    {
        $f = $this->fixture();
        config(['loyalty.enabled' => true]);
        $loyalty = app(LoyaltyService::class);
        $loyalty->publish('loyalty_policies', $f['maker']->id, ['currency' => 'AED', 'minor_units' => 2, 'enabled' => true, 'points_numerator' => 1, 'minor_denominator' => 10, 'gold_threshold' => 100, 'platinum_threshold' => 500, 'gold_multiplier_bps' => 12500, 'platinum_multiplier_bps' => 15000, 'invoice_cap' => 1000, 'daily_cap' => 10000, 'expiry_days' => 30, 'daily_redemption_limit' => 5, 'effective_from' => now()->subDay()->toDateTimeString()], 'analytics-loyalty');
        $loyalty->earn($f['invoice']->id, $f['customer']->id);
        app(FinanceService::class)->refund($f['invoice'], $f['maker']->id, 1050, 'analytics-full-refund', 'Full source reversal');
        $loyalty->reconcile($f['customer']->id, $f['customer']->id);
        $report = app(FinancialAnalytics::class)->report($f['customer'], []);
        $this->assertSame('NON_MONETARY_POINTS', $report['points']['unit']);
        $this->assertFalse($report['points']['cash_value']);
        $points = collect($report['points']['groups'])->keyBy('kind');
        $this->assertSame(100, $points['earn']['points']);
        $this->assertSame(-100, $points['reversal']['points']);
        $this->assertFalse(collect($report['groups'])->contains(fn ($g) => $g['currency'] === 'POINTS'));
        $foreign = app(FinancialAnalytics::class)->report(User::factory()->create(), []);
        $this->assertSame([], $foreign['points']['groups']);
    }

    public function test_real_private_arabic_management_pdf_is_not_a_tax_invoice(): void
    {
        $f = $this->fixture();
        $this->actingAs($f['customer']);
        $response = $this->get('/api/financial/analytics/pdf?locale=ar')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $response->getContent());
        $this->assertStringContainsString('%%EOF', substr($response->getContent(), -64));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertDatabaseHas('audit_events', ['actor_id' => $f['customer']->id, 'action' => 'finance.analytics.pdf']);
        $this->get('/api/financial/analytics/export')->assertOk();
        $this->assertDatabaseHas('audit_events', ['actor_id' => $f['customer']->id, 'action' => 'finance.analytics.export']);
        $this->getJson('/api/financial/analytics/pdf?locale=xx')->assertUnprocessable();
    }

    public function test_currency_and_precision_groups_are_never_combined(): void
    {
        $f = $this->fixture();
        DB::table('regions')->insert(['country' => 'BH', 'currency' => 'BHD', 'minor_units' => 3, 'timezone' => 'Asia/Bahrain', 'commerce_enabled' => false, 'created_at' => now(), 'updated_at' => now()]);
        $subscriptions = app(SubscriptionService::class);
        $plan = $subscriptions->publish($f['maker'], ['code' => 'bhd-report', 'name' => 'BHD cycle', 'audience' => 'customer', 'currency' => 'BHD', 'minor_units' => 3, 'price_minor' => 1234, 'tax_bps' => 0, 'interval' => 'month', 'entitlements' => '{}']);
        $s = $subscriptions->subscribe($f['customer'], $plan->id, 'bhd-enroll');
        $subscriptions->mockPay($f['customer'], $s['subscription']->id, $s['invoices']->first()->id, 'succeeded', 'bhd-paid');
        $report = app(FinancialAnalytics::class)->report($f['customer'], []);
        $this->assertSame('AED', $this->group($report, 'charging_collected')['currency']);
        $this->assertSame(2, $this->group($report, 'charging_collected')['minor_units']);
        $this->assertSame('BHD', $this->group($report, 'subscription_collected')['currency']);
        $this->assertSame(3, $this->group($report, 'subscription_collected')['minor_units']);
        $this->assertSame(1234, $this->group($report, 'subscription_collected')['amount_minor']);
        $this->assertSame([], $this->group(app(FinancialAnalytics::class)->report($f['customer'], ['currency' => 'AED']), 'subscription_collected'));
    }

    public function test_malformed_imported_receipt_currency_never_gets_relabelled_as_invoice_currency(): void
    {
        $f = $this->fixture();
        $subscriptions = app(SubscriptionService::class);
        $plan = $subscriptions->publish($f['maker'], ['code' => 'bad-import', 'name' => 'Imported source test', 'audience' => 'customer', 'currency' => 'AED', 'minor_units' => 2, 'price_minor' => 100, 'tax_bps' => 0, 'interval' => 'month', 'entitlements' => '{}']);
        $s = $subscriptions->subscribe($f['customer'], $plan->id, 'bad-import-enroll');
        // Explicitly corrupt imported source evidence, not a permitted PSP workflow.
        DB::table('commercial_subscription_receipts')->insert(['invoice_id' => $s['invoices']->first()->id, 'provider_reference' => 'invalid-import', 'amount_minor' => 100, 'currency' => 'USD', 'evidence' => 'MOCK_PROVIDER', 'created_at' => now()]);
        $this->actingAs($f['customer'])->getJson('/api/financial/analytics')->assertConflict();
        $this->getJson('/api/financial/analytics/export')->assertConflict();
    }

    public function test_current_cycle_integrity_is_checked_even_when_receipt_posted_before_both_report_periods(): void
    {
        $old = now()->subDays(10);
        $this->travelTo($old);
        $f = $this->fixture();
        $service = app(SubscriptionService::class);
        $plan = $service->publish($f['maker'], ['code' => 'old-malformed-cycle', 'name' => 'Imported cycle', 'audience' => 'customer', 'currency' => 'AED', 'minor_units' => 2, 'price_minor' => 100, 'tax_bps' => 0, 'interval' => 'month', 'entitlements' => '{}']);
        $s = $service->subscribe($f['customer'], $plan->id, 'old-import-enroll');
        $invoice = $s['invoices']->first();
        // Simulate a historically imported receipt: operational activation must
        // not make a wrong-currency source a valid current paid-cycle equivalent.
        DB::table('commercial_subscription_receipts')->insert(['invoice_id' => $invoice->id, 'provider_reference' => 'old-invalid-import', 'amount_minor' => 100, 'currency' => 'USD', 'evidence' => 'MOCK_PROVIDER', 'created_at' => now()]);
        DB::table('commercial_subscriptions')->where('id', $s['subscription']->id)->update(['status' => 'active', 'cycle_number' => $invoice->cycle_number, 'period_start' => $invoice->period_start, 'period_end' => $invoice->period_end]);
        $this->travelTo($old->copy()->addDays(10));
        $date = now()->utc()->toDateString();
        $this->actingAs($f['customer'])->getJson('/api/financial/analytics?from='.$date.'&to='.$date)->assertConflict();
    }

    public function test_json_client_integer_bounds_reject_an_oversized_imported_invoice(): void
    {
        $f = $this->fixture();
        $subscriptions = app(SubscriptionService::class);
        $plan = $subscriptions->publish($f['maker'], ['code' => 'large-import', 'name' => 'Imported magnitude test', 'audience' => 'customer', 'currency' => 'AED', 'minor_units' => 2, 'price_minor' => 9007199254740992, 'tax_bps' => 0, 'interval' => 'month', 'entitlements' => '{}']);
        $subscriptions->subscribe($f['customer'], $plan->id, 'large-import-enroll');
        $this->actingAs($f['customer'])->getJson('/api/financial/analytics')->assertConflict();
        $this->assertDatabaseCount('finance_payments', 1);
    }

    public function test_pdf_bound_includes_recurring_components_before_rendering(): void
    {
        try {
            app(AnalyticsPresentation::class)->pdf(['groups' => [], 'points' => ['groups' => []], 'recurring_run_rates' => array_fill(0, 601, [])], 'ar');
            $this->fail('An oversized financial PDF was rendered.');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
    }

    public function test_as_of_cutoff_excludes_future_posted_records_within_the_same_day(): void
    {
        $base = now()->startOfDay()->addHours(8);
        $this->travelTo($base->copy()->addHour());
        $f = $this->fixture();
        $this->travelTo($base);
        $report = app(FinancialAnalytics::class)->report($f['customer'], []);
        $this->assertSame([], $report['groups']);
        $this->travelTo($base->copy()->addHours(2));
        $this->assertSame(1050, $this->group(app(FinancialAnalytics::class)->report($f['customer'], []), 'charging_collected')['amount_minor']);
    }
}
