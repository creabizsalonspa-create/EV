<?php

namespace Tests\Feature;

use App\Http\Controllers\FinanceReconciliationAuditController;
use App\Models\ChargingSession;
use App\Models\Reservation;
use App\Models\Station;
use App\Models\User;
use App\Services\Finance\FinanceService;
use App\Services\Finance\InvoiceReconciliationAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\HttpException;
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

    public function test_invoice_reconciliation_fails_closed_for_rounded_or_inexact_money_sources(): void
    {
        $exact = new \ReflectionMethod(
            InvoiceReconciliationAudit::class,
            'exactMinor'
        );
        $this->assertSame(0, $exact->invoke(null, 0));
        $this->assertSame(-9_007_199_254_740_991,
            $exact->invoke(null, '-9007199254740991'));
        $this->assertSame(9_007_199_254_740_991,
            $exact->invoke(null, '9007199254740991'));

        foreach ([
            100.0, -100.0, true, false, 0.1, '1.0', '1e2',
            '9007199254740992', '-9007199254740992',
            'not-a-minor-unit',
        ] as $invalid) {
            try {
                $exact->invoke(null, $invalid);
                $this->fail('An unsafe financial amount passed: '.var_export($invalid, true));
            } catch (HttpException $exception) {
                $this->assertSame(409, $exception->getStatusCode());
            }
        }

        [$vendor, $customer, $invoice] = $this->fixture();
        $before = DB::table('finance_journals')->count();
        $this->actingAs($vendor)->getJson($this->url())
            ->assertOk()->assertJsonPath('data.0.invoice_id', $invoice->id)
            ->assertJsonPath('data.0.status', 'reconciled')
            ->assertJsonPath('external_psp_or_bank_verified', false);
        $this->assertSame($before, DB::table('finance_journals')->count());
        $this->assertDatabaseCount('finance_payments', 0);
        $this->assertDatabaseCount('finance_refunds', 0);
    }

    public function test_raw_invoice_snapshot_cannot_hide_fractional_amount_or_precision_behind_eloquent_cast(): void
    {
        [$vendor, $customer, $invoice] = $this->fixture();
        $audit = app(InvoiceReconciliationAudit::class);
        $raw = $invoice->getRawOriginal();
        $before = DB::table('finance_journals')->count();

        foreach ([
            ['total_minor' => 100.5],
            ['minor_units' => 2.5],
            ['minor_units' => 4],
            ['total_minor' => '9007199254740992'],
        ] as $change) {
            // Hydrate the malformed snapshot in memory; never disable the
            // immutable database triggers to fabricate historical damage.
            $snapshot = $invoice->newFromBuilder(array_replace($raw, $change));
            try {
                $audit->inspect($snapshot);
                $this->fail('Inexact raw invoice source was accepted.');
            } catch (HttpException $exception) {
                $this->assertSame(409, $exception->getStatusCode());
            }
        }
        $this->assertSame('reconciled', $audit->inspect($invoice)['status']);
        $this->assertSame($before, DB::table('finance_journals')->count());
        $this->assertDatabaseCount('finance_invoices', 1);
    }

    public function test_zero_payment_source_is_flagged_even_when_journal_totals_balance(): void
    {
        [$vendor, $customer, $invoice] = $this->fixture();
        $journal = DB::table('finance_journals')->insertGetId([
            'invoice_id' => $invoice->id, 'kind' => 'payment',
            'idempotency_key' => 'audit-zero-payment-'.$invoice->id,
            'fingerprint' => str_repeat('c', 64), 'currency' => $invoice->currency,
            'amount_minor' => 0, 'created_at' => now(),
        ]);
        DB::table('finance_payments')->insert([
            'invoice_id' => $invoice->id, 'journal_id' => $journal,
            'actor_id' => $customer->id, 'amount_minor' => 0,
            'evidence' => 'SIMULATED', 'created_at' => now(),
        ]);
        $this->actingAs($vendor)->getJson($this->url())->assertOk()
            ->assertJsonPath('data.0.status', 'attention_required')
            ->assertJsonPath('data.0.issues', ['collection_out_of_bounds'])
            ->assertJsonPath('data.0.paid_minor', 0);
        $csv = $this->get($this->url().'/export')->assertOk()->streamedContent();
        $this->assertStringContainsString('attention_required,collection_out_of_bounds', $csv);
        $this->assertDatabaseCount('finance_journals', 2);
        $this->assertDatabaseCount('finance_payments', 1);
        $this->assertDatabaseCount('finance_refunds', 0);
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
        foreach (['page=0', 'page=-1', 'page=10001', 'page=not-a-page'] as $invalid) {
            $this->getJson($this->url().'?'.$invalid)->assertUnprocessable();
            $this->get($this->url().'/export?'.$invalid)->assertUnprocessable();
        }
        $this->getJson($this->url().'?per_page=1&page=2')->assertOk()
            ->assertJsonPath('current_page', 2)
            ->assertJsonPath('total', 1)
            ->assertJsonCount(0, 'data');
        $this->get($this->url().'/export?per_page=1&page=2')->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private');

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

    public function test_reconciliation_csv_is_spreadsheet_literal_and_exact_without_changing_immutable_invoices(): void
    {
        [$vendor, $customer, $invoice] = $this->fixture();
        $before = DB::table('finance_journals')->count();
        // Issued invoice numbers are immutable, so the source must not be
        // overwritten simply to simulate an untrusted imported reference.
        $encode = new \ReflectionMethod(
            FinanceReconciliationAuditController::class,
            'literalCsv'
        );
        foreach (['=HYPERLINK("https://unsafe.test","open")', '+SUM(3,5)',
            '-CMD', '@DDE', "\t=source", "\u{200B}=hidden",
            "\u{202E}@bidi", "\u{FEFF}+bom", "\u{00A0}=nbsp"] as $formula) {
            $this->assertSame("'".$formula, $encode->invoke(null, $formula));
        }
        $this->assertSame($invoice->number,
            $encode->invoke(null, $invoice->number));

        $this->actingAs($vendor)->getJson($this->url())
            ->assertOk()->assertJsonPath('data.0.invoice_number', $invoice->number);
        $csv = $this->get($this->url().'/export');
        $csv->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->assertStringContainsString($invoice->number, $csv->streamedContent());
        $this->assertDatabaseHas('finance_invoices', [
            'id' => $invoice->id, 'number' => $invoice->number,
        ]);
        $this->assertSame($before, DB::table('finance_journals')->count());
        $this->assertDatabaseCount('finance_payments', 0);
    }

    public function test_revoked_vendor_and_admin_snapshots_cannot_read_private_reconciliation_or_csv(): void
    {
        [$vendor, $customer, $invoice] = $this->fixture();
        $admin = User::factory()->create(['role' => 'admin']);
        $before = DB::table('finance_journals')->count();

        foreach ([$vendor, $admin] as $actor) {
            $record = DB::table('users')->where('id', $actor->id)
                ->first(['role', 'account_status', 'auth_epoch', 'password']);
            $original = [
                'role' => $record->role,
                'account_status' => $record->account_status,
                'auth_epoch' => $record->auth_epoch,
                'password' => $record->password,
            ];
            $this->actingAs($actor);
            $this->getJson($this->url())->assertOk()->assertJsonPath('total', 1);

            foreach ([
                ['role' => 'customer'],
                ['account_status' => 'suspended'],
                ['auth_epoch' => (int) $record->auth_epoch + 1],
                ['password' => Hash::make('changed-private-finance-session')],
            ] as $change) {
                // Simulates revocation in another request without refreshing
                // the already-authenticated in-memory request identity.
                DB::table('users')->where('id', $actor->id)->update($change);
                $this->getJson($this->url())->assertConflict();
                $this->get($this->url().'/export')->assertConflict();
                DB::table('users')->where('id', $actor->id)->update($original);
            }
        }

        $this->assertSame($before, DB::table('finance_journals')->count());
        $this->assertDatabaseCount('finance_invoices', 1);
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
