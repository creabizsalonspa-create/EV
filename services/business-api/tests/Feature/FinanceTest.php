<?php

namespace Tests\Feature;

use App\Models\ChargingSession;
use App\Models\Finance\Invoice;
use App\Models\Finance\Refund;
use App\Models\Finance\SessionQuote;
use App\Models\Finance\TariffVersion;
use App\Models\Reservation;
use App\Models\Station;
use App\Models\User;
use App\Services\Finance\FinanceService;
use App\Services\Finance\Money;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class FinanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Route::middleware(['web', 'auth', 'throttle:platform-api'])->prefix('finance-test-api')->group(base_path('routes/finance.php'));
    }

    private function fixture(int $energy = 12345, int $rate = 100, bool $tariff = true): array
    {
        $vendor = User::factory()->create(['role' => 'vendor']);
        $customer = User::factory()->create();
        $station = Station::create(['vendor_id' => $vendor->id, 'name' => 'Finance simulator', 'country' => 'AE', 'currency' => 'AED', 'timezone' => 'Asia/Dubai', 'latitude' => 25, 'longitude' => 55, 'source' => 'simulator', 'connector_type' => 'CCS2', 'power_kw' => 50]);
        $t = $tariff ? TariffVersion::create(['station_id' => $station->id, 'created_by' => $vendor->id, 'rate_minor_per_kwh' => $rate, 'tax_bps' => 500, 'commission_bps' => 1000, 'currency' => 'AED', 'minor_units' => 2, 'effective_from' => now()->subMinute()]) : null;
        $b = Reservation::create(['user_id' => $customer->id, 'station_id' => $station->id, 'starts_at' => now()->startOfHour(), 'status' => 'confirmed', 'idempotency_key' => 'finance-fixture']);
        $session = ChargingSession::create(['reservation_id' => $b->id, 'status' => 'stopped', 'energy_wh' => $energy, 'rate_minor_per_kwh' => $rate, 'currency' => 'AED', 'total_minor' => Money::multiplyDivide($energy, $rate, 1000), 'stopped_at' => now()]);
        if ($t) {
            app(FinanceService::class)->snapshotSession($session, $t);
        }

        return [$vendor, $customer, $station, $session, $t];
    }

    private function invoice(array $f): Invoice
    {
        return app(FinanceService::class)->issue($f[3], $f[1]->id);
    }

    public function test_integer_rounding_tariff_snapshot_and_invoice_are_immutable_and_reconcile(): void
    {
        $f = $this->fixture();
        $invoice = $this->invoice($f);
        $this->assertSame(1235, $invoice->subtotal_minor);
        $this->assertSame(62, $invoice->tax_minor);
        $this->assertSame(124, $invoice->commission_minor);
        $this->assertSame(1111, $invoice->vendor_minor);
        $this->assertSame(1297, $invoice->total_minor);
        $this->assertSame($invoice->id, $this->invoice($f)->id);
        $this->assertDatabaseCount('finance_invoices', 1);
        $this->assertDatabaseCount('finance_journals', 1);
        TariffVersion::create(['station_id' => $f[2]->id, 'created_by' => $f[0]->id, 'rate_minor_per_kwh' => 999, 'tax_bps' => 9000, 'commission_bps' => 9000, 'currency' => 'AED', 'minor_units' => 2, 'effective_from' => now()]);
        $this->assertSame(100, SessionQuote::first()->rate_minor_per_kwh);
        $this->assertTrue(app(FinanceService::class)->summary($invoice)['ledger_balanced']);
        $this->expectException(\LogicException::class);
        $invoice->update(['total_minor' => 1]);
    }

    public function test_database_rejects_direct_financial_record_mutation(): void
    {
        $invoice = $this->invoice($this->fixture());
        try {
            DB::transaction(fn () => DB::table('finance_invoices')->where('id', $invoice->id)->update(['total_minor' => 1]));
            $this->fail('Database accepted mutation');
        } catch (QueryException $e) {
            $this->assertStringContainsString('immutable', $e->getMessage());
        }
        $this->assertSame(1297, $invoice->fresh()->total_minor);
    }

    public function test_legacy_session_does_not_pick_up_a_new_tariff_retroactively(): void
    {
        $f = $this->fixture(tariff: false);
        TariffVersion::create(['station_id' => $f[2]->id, 'created_by' => $f[0]->id, 'rate_minor_per_kwh' => 999, 'tax_bps' => 500, 'commission_bps' => 1000, 'currency' => 'AED', 'minor_units' => 2, 'effective_from' => now()]);
        $invoice = $this->invoice($f);
        $this->assertSame(1235, $invoice->total_minor);
        $this->assertSame(0, $invoice->tax_minor);
    }

    public function test_partial_payments_idempotency_credit_notes_and_full_refund_reconcile(): void
    {
        $f = $this->fixture();
        $invoice = $this->invoice($f);
        $service = app(FinanceService::class);
        $payment = $service->pay($invoice, $f[1]->id, 500, 'part-one');
        $this->assertSame($payment->id, $service->pay($invoice, $f[1]->id, 500, 'part-one')->id);
        $this->assertSame(797, $service->summary($invoice)['amount_due_minor']);
        $service->pay($invoice, $f[1]->id, 797, 'part-two');
        $this->assertSame('paid', $service->summary($invoice)['state']);
        $r = $service->refund($invoice, $f[0]->id, 1, 'refund-one', 'Test credit note');
        $this->assertSame($r->id, $service->refund($invoice, $f[0]->id, 1, 'refund-one', 'Test credit note')->id);
        $service->refund($invoice, $f[0]->id, 1296, 'refund-rest', 'Remaining credit');
        $summary = $service->summary($invoice);
        $this->assertSame('refunded', $summary['state']);
        $this->assertSame(0, $summary['amount_due_minor']);
        $this->assertSame(0, $summary['net_collected_minor']);
        $this->assertTrue($summary['ledger_balanced']);
        $this->assertFalse($summary['real_payment_processed']);
        foreach (['tax_minor', 'commission_minor', 'vendor_minor'] as $k) {
            $this->assertSame($invoice->$k, (int) Refund::where('invoice_id', $invoice->id)->sum($k));
        }
        $this->assertDatabaseCount('finance_payments', 2);
        $this->assertDatabaseCount('finance_refunds', 2);
    }

    public function test_overpayment_overrefund_and_idempotency_payload_mismatch_are_rejected(): void
    {
        $f = $this->fixture();
        $invoice = $this->invoice($f);
        $service = app(FinanceService::class);
        $service->pay($invoice, $f[1]->id, 500, 'once');
        foreach ([fn () => $service->pay($invoice, $f[1]->id, 501, 'once'), fn () => $service->pay($invoice, $f[1]->id, 798, 'over'), fn () => $service->refund($invoice, $f[0]->id, 501, 'over-refund', 'Reason')] as $operation) {
            try {
                $operation();
                $this->fail('Invalid money movement accepted');
            } catch (HttpException $e) {
                $this->assertSame(409, $e->getStatusCode());
            }
        }
        $this->assertDatabaseCount('finance_payments', 1);
        $this->assertDatabaseCount('finance_refunds', 0);
    }

    public function test_unfinished_or_inconsistent_sessions_cannot_be_invoiced(): void
    {
        $f = $this->fixture();
        $f[3]->update(['status' => 'charging']);
        try {
            $this->invoice($f);
            $this->fail('Unfinished invoice accepted');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        $f[3]->update(['status' => 'stopped', 'total_minor' => 1]);
        try {
            $this->invoice($f);
            $this->fail('Inconsistent invoice accepted');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    public function test_finance_http_authorization_and_tenant_scope(): void
    {
        $f = $this->fixture();
        $other = User::factory()->create(['role' => 'vendor']);
        $invoice = $this->invoice($f);
        $base = '/finance-test-api/finance/invoices/'.$invoice->id;
        $this->actingAs($other)->getJson($base)->assertNotFound();
        $this->getJson('/finance-test-api/finance/invoices')->assertJsonCount(0, 'data');
        $this->postJson($base.'/simulate-refund', ['amount_minor' => 1, 'idempotency_key' => 'x', 'reason' => 'Test'])->assertNotFound();
        $this->actingAs($f[1])->getJson($base)->assertOk()->assertJsonPath('invoice.evidence', 'SIMULATED');
        $this->getJson($base.'/journal')->assertForbidden();
        $this->postJson($base.'/simulate-payment', ['amount_minor' => 1297, 'idempotency_key' => 'pay'])->assertCreated();
        $this->actingAs($f[0])->getJson($base.'/journal')->assertOk();
        $this->postJson($base.'/simulate-refund', ['amount_minor' => 1297, 'idempotency_key' => 'refund', 'reason' => 'Simulation refund'])->assertCreated();
    }

    public function test_invoice_history_filters_preserve_tenant_scope_and_validate_dates_and_literal_prefix(): void
    {
        $f = $this->fixture();
        $invoice = $this->invoice($f);
        $base = '/finance-test-api/finance/invoices';
        $this->actingAs($f[1])->getJson($base.'?'.http_build_query(['q' => $invoice->number, 'currency' => 'AED', 'from' => now()->toDateString(), 'to' => now()->toDateString()]))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $invoice->id)->assertJsonPath('data.0.evidence', 'SIMULATED');
        $this->getJson($base.'?currency=USD')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($base.'?to='.now()->subDay()->toDateString())->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($base.'?to=9999-12-31')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson($base.'?from=0000-01-01')->assertUnprocessable();
        $this->getJson($base.'?q=%25')->assertUnprocessable();
        $this->getJson($base.'?from=2026-02-30')->assertUnprocessable();
        $this->getJson($base.'?from=2026-10-02&to=2026-10-01')->assertUnprocessable();
        $this->actingAs(User::factory()->create())->getJson($base.'?q='.$invoice->number)->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($f[0])->getJson($base)->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs(User::factory()->create(['role' => 'vendor']))->getJson($base)->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_only_vendor_owner_or_admin_can_publish_tariff_and_vendor_cannot_set_commission(): void
    {
        $f = $this->fixture();
        $v = ['rate_minor_per_kwh' => 125, 'tax_bps' => 500, 'commission_bps' => 100];
        $path = '/finance-test-api/stations/'.$f[2]->id.'/tariffs';
        $this->actingAs(User::factory()->create(['role' => 'vendor']))->postJson($path, $v)->assertNotFound();
        $this->actingAs($f[0])->postJson($path, $v)->assertForbidden();
        $v['commission_bps'] = 0;
        $this->postJson($path, $v)->assertCreated();
        $this->actingAs(User::factory()->create(['role' => 'admin']))->postJson($path, $v + ['effective_from' => '1990-01-01'])->assertCreated();
    }

    public function test_vendor_pricing_retains_admin_commission_and_publish_retries_are_payload_bound(): void
    {
        $f = $this->fixture();
        $base = '/finance-test-api/stations/'.$f[2]->id.'/tariffs';
        $body = ['rate_minor_per_kwh' => 250, 'tax_bps' => 500, 'commission_bps' => 0, 'idempotency_key' => 'vendor-publish'];
        $first = $this->actingAs($f[0])->postJson($base, $body)->assertCreated()->assertJsonPath('commission_bps', 1000)->assertJsonMissingPath('publishing_key')->assertJsonMissingPath('publishing_fingerprint')->json();
        $this->postJson($base, array_map(fn ($v) => (string) $v, $body))->assertCreated()->assertJsonPath('id', $first['id']);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'finance.simulated_tariff_published')->count());
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->postJson($base, ['rate_minor_per_kwh' => 300, 'tax_bps' => 500, 'commission_bps' => 2000, 'idempotency_key' => 'policy'])->assertCreated();
        $this->actingAs($f[0])->postJson($base, $body)->assertCreated()->assertJsonPath('id', $first['id'])->assertJsonPath('commission_bps', 1000);
        $this->postJson($base, array_replace($body, ['idempotency_key' => 'next-price']))->assertCreated()->assertJsonPath('commission_bps', 2000);
        $this->postJson($base, array_replace($body, ['rate_minor_per_kwh' => 251]))->assertConflict();
        $this->postJson($base, array_replace($body, ['idempotency_key' => 'stale-precision', 'expected_minor_units' => 3, 'expected_currency' => 'AED']))->assertConflict();
        $this->postJson($base, array_replace($body, ['idempotency_key' => 'stale-currency', 'expected_minor_units' => 2, 'expected_currency' => 'USD']))->assertConflict();
        $this->assertSame(1000, SessionQuote::first()->commission_bps);
        $this->assertDatabaseCount('finance_tariff_versions', 4);
        $this->assertSame(3, DB::table('audit_events')->where('action', 'finance.simulated_tariff_published')->count());
    }

    public function test_pricing_station_selector_is_role_scoped_and_requires_matching_currency_configuration(): void
    {
        $f = $this->fixture();
        $base = '/finance-test-api/finance/stations';
        $this->actingAs($f[0])->getJson($base)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.evidence', 'SIMULATED')->assertJsonPath('data.0.minor_units', 2);
        $this->actingAs($f[1])->getJson($base)->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'vendor']))->getJson($base)->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs(User::factory()->create(['role' => 'admin']))->getJson($base)->assertOk()->assertJsonCount(1, 'data');
        $f[0]->role = 'customer';
        $f[0]->save();
        $this->actingAs($f[0])->getJson('/finance-test-api/stations/'.$f[2]->id.'/tariffs')->assertNotFound();
        $f[0]->role = 'vendor';
        $f[0]->save();
        $f[2]->update(['currency' => 'USD']);
        $this->actingAs($f[0])->getJson($base)->assertOk()->assertJsonPath('data.0.minor_units', null);
        $this->postJson('/finance-test-api/stations/'.$f[2]->id.'/tariffs', ['rate_minor_per_kwh' => 250, 'tax_bps' => 500, 'commission_bps' => 0])->assertConflict();
    }

    public function test_numeric_form_tariffs_keep_integer_contract_and_snapshot_comparisons(): void
    {
        $f = $this->fixture(tariff: false);
        $this->actingAs($f[0])->postJson('/finance-test-api/stations/'.$f[2]->id.'/tariffs', [
            'rate_minor_per_kwh' => '125', 'tax_bps' => '500', 'commission_bps' => '0',
        ])->assertCreated()->assertJsonPath('rate_minor_per_kwh', 125)->assertJsonPath('tax_bps', 500);
        $tariff = TariffVersion::create([
            'station_id' => (string) $f[2]->id, 'created_by' => (string) $f[0]->id,
            'rate_minor_per_kwh' => '100', 'tax_bps' => '500', 'commission_bps' => '1000',
            'currency' => 'AED', 'minor_units' => '2', 'effective_from' => now()->subMinute(),
        ]);
        $quote = app(FinanceService::class)->snapshotSession($f[3], $tariff);
        $this->assertSame(100, $quote->rate_minor_per_kwh);
        $this->assertSame(500, $quote->tax_bps);
    }

    public function test_money_allocator_has_no_negative_or_lost_minor_units(): void
    {
        $left = ['tax_minor' => 1, 'commission_minor' => 1, 'vendor_minor' => 0];
        $first = Money::allocate(1, $left);
        $this->assertSame(1, array_sum($first));
        foreach ($left as $k => $v) {
            $this->assertGreaterThanOrEqual(0, $first[$k]);
            $left[$k] -= $first[$k];
        }
        $second = Money::allocate(1, $left);
        $this->assertSame(1, array_sum($second));
        foreach ($left as $k => $v) {
            $this->assertSame($v, $second[$k]);
        }
    }

    public function test_production_never_processes_simulated_money(): void
    {
        $f = $this->fixture();
        $invoice = $this->invoice($f);
        $this->app->detectEnvironment(fn () => 'production');
        $this->expectException(HttpException::class);
        app(FinanceService::class)->pay($invoice, $f[1]->id, 1, 'production');
    }
}
