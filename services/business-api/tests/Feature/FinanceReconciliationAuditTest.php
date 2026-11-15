<?php

namespace Tests\Feature;

use App\Models\ChargingSession;
use App\Models\Reservation;
use App\Models\Station;
use App\Models\User;
use App\Services\Finance\FinanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class FinanceReconciliationAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Route::middleware(['web', 'auth', 'throttle:platform-api'])->prefix('audit-test')
            ->group(base_path('routes/commercial_finance.php'));
    }

    private function fixture(int $rate = 100): array
    {
        $vendor = User::factory()->create(['role' => 'vendor']);
        $customer = User::factory()->create();
        $station = Station::create([
            'vendor_id' => $vendor->id, 'name' => 'Ledger review', 'country' => 'AE',
            'currency' => 'AED', 'timezone' => 'Asia/Dubai', 'latitude' => 25, 'longitude' => 55,
            'source' => 'simulator', 'connector_type' => 'CCS2', 'power_kw' => 50,
        ]);
        $reservation = Reservation::create([
            'user_id' => $customer->id, 'station_id' => $station->id,
            'starts_at' => now(), 'idempotency_key' => 'audit-'.$station->id,
        ]);
        $session = ChargingSession::create([
            'reservation_id' => $reservation->id, 'status' => 'stopped', 'energy_wh' => 1000,
            'rate_minor_per_kwh' => $rate, 'currency' => 'AED', 'total_minor' => $rate,
            'stopped_at' => now(),
        ]);

        return [$vendor, $customer, app(FinanceService::class)->issue($session, $customer->id)];
    }

    private function url(): string
    {
        return '/audit-test/finance/reconciliation-audit';
    }

    public function test_balanced_invoice_capture_refund_and_read_only_audit(): void
    {
        [$vendor, $customer, $invoice] = $this->fixture();
        $finance = app(FinanceService::class);
        $finance->pay($invoice, $customer->id, 100, 'audit-payment');
        $finance->refund($invoice, $vendor->id, 40, 'audit-refund', 'Review refund');
        $before = DB::table('finance_journals')->count();
        $this->actingAs($vendor)->getJson($this->url())->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.0.status', 'reconciled')
            ->assertJsonPath('data.0.issues', [])
            ->assertJsonPath('data.0.invoiced_minor', 100)
            ->assertJsonPath('data.0.paid_minor', 100)
            ->assertJsonPath('data.0.refunded_minor', 40)
            ->assertJsonPath('data.0.net_collected_minor', 60)
            ->assertJsonPath('data.0.amount_due_minor', 0)
            ->assertJsonPath('data.0.journal_count', 3)
            ->assertJsonPath('external_psp_or_bank_verified', false);
        $this->assertSame($before, DB::table('finance_journals')->count());
    }

    public function test_role_tenant_filters_and_scoped_csv_export(): void
    {
        [$owner, $customer, $invoice] = $this->fixture();
        [$foreignOwner, $foreignCustomer, $foreignInvoice] = $this->fixture();
        $this->actingAs($customer)->getJson($this->url())->assertForbidden();
        $this->actingAs($owner)->getJson($this->url())->assertOk()
            ->assertJsonPath('total', 1)->assertJsonPath('data.0.invoice_id', $invoice->id);
        $this->getJson($this->url().'?currency=USD')->assertOk()->assertJsonPath('total', 0);
        $this->getJson($this->url().'?per_page=26')->assertUnprocessable();
        $this->getJson($this->url().'?from=2027-10-02&to=2027-10-01')->assertUnprocessable();
        $csv = $this->get($this->url().'/export?per_page=1');
        $csv->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $body = $csv->streamedContent();
        $this->assertStringContainsString($invoice->number, $body);
        $this->assertStringNotContainsString($foreignInvoice->number, $body);
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->getJson($this->url().'?per_page=1')->assertOk()
            ->assertJsonPath('total', 2)->assertJsonCount(1, 'data');
        $this->actingAs($foreignOwner)->getJson($this->url())->assertOk()->assertJsonPath('total', 1);
    }

    public function test_zero_value_invoice_does_not_require_artificial_ledger_lines(): void
    {
        [$vendor, $customer, $invoice] = $this->fixture(0);
        $this->assertDatabaseCount('finance_entries', 0);
        $this->actingAs($vendor)->getJson($this->url())->assertOk()
            ->assertJsonPath('data.0.status', 'reconciled')
            ->assertJsonPath('data.0.invoiced_minor', 0)
            ->assertJsonPath('data.0.journal_count', 1);
    }

    public function test_balanced_but_misposted_payment_is_flagged_without_rewriting_the_ledger(): void
    {
        [$vendor, $customer, $invoice] = $this->fixture();
        // Construct a balanced, source-linked journal with the wrong credit
        // account: traditional debit=credit tests alone would accept this.
        $journal = DB::table('finance_journals')->insertGetId([
            'invoice_id' => $invoice->id,
            'kind' => 'payment',
            'idempotency_key' => 'audit-mispost-'.$invoice->id,
            'fingerprint' => str_repeat('a', 64),
            'currency' => $invoice->currency,
            'amount_minor' => 25,
            'created_at' => now(),
        ]);
        DB::table('finance_entries')->insert([
            [
                'journal_id' => $journal, 'account' => 'cash:simulator',
                'debit_minor' => 25, 'credit_minor' => 0, 'created_at' => now(),
            ],
            [
                'journal_id' => $journal, 'account' => 'vendor_payable:'.$vendor->id,
                'debit_minor' => 0, 'credit_minor' => 25, 'created_at' => now(),
            ],
        ]);
        DB::table('finance_payments')->insert([
            'invoice_id' => $invoice->id, 'journal_id' => $journal,
            'actor_id' => $customer->id, 'amount_minor' => 25,
            'evidence' => 'SIMULATED', 'created_at' => now(),
        ]);

        $this->actingAs($vendor)->getJson($this->url())->assertOk()
            ->assertJsonPath('data.0.status', 'attention_required')
            ->assertJsonPath('data.0.issues.0', 'journal_accounts_mismatch')
            ->assertJsonPath('data.0.paid_minor', 25);
        $this->assertDatabaseCount('finance_journals', 2);
        $this->assertDatabaseCount('finance_payments', 1);
    }

    public function test_orphan_journal_is_detected_without_mutating_financial_records(): void
    {
        [$vendor, $customer, $invoice] = $this->fixture();
        DB::table('finance_journals')->insert([
            'invoice_id' => $invoice->id, 'kind' => 'payment',
            'idempotency_key' => 'audit-orphan-'.$invoice->id,
            'fingerprint' => str_repeat('f', 64), 'currency' => $invoice->currency,
            'amount_minor' => 50, 'created_at' => now(),
        ]);
        $this->actingAs($vendor)->getJson($this->url())->assertOk()
            ->assertJsonPath('data.0.status', 'attention_required')
            ->assertJsonPath('data.0.journal_count', 2)
            ->assertJsonPath('data.0.issues.0', 'journal_unbalanced')
            ->assertJsonPath('data.0.issues.1', 'unlinked_financial_journal');
        $this->assertDatabaseCount('finance_payments', 0);
        $this->assertDatabaseCount('finance_journals', 2);
    }
}
