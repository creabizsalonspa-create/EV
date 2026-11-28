<?php

namespace Tests\Feature;

use App\Models\ChargingSession;
use App\Models\Reservation;
use App\Models\Station;
use App\Models\User;
use App\Services\Finance\FinanceService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class VendorRevenueCohortTest extends TestCase
{
    use RefreshDatabase;

    private User $vendor;

    private User $customer;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Route::middleware(['web', 'auth'])->prefix('revenue-test')
            ->group(base_path('routes/commercial_finance.php'));
        $this->vendor = User::factory()->create(['role' => 'vendor']);
        $this->customer = User::factory()->create();
        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    private function invoice(int $amountMinor, string $label, bool $capture): object
    {
        $station = Station::create([
            'vendor_id' => $this->vendor->id,
            'name' => 'Cohort '.$label,
            'country' => 'AE', 'currency' => 'AED', 'timezone' => 'Asia/Dubai',
            'latitude' => 25, 'longitude' => 55, 'source' => 'simulator',
            'connector_type' => 'CCS2', 'power_kw' => 50,
        ]);
        $reservation = Reservation::create([
            'user_id' => $this->customer->id,
            'station_id' => $station->id,
            'starts_at' => now(), 'status' => 'confirmed',
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $session = ChargingSession::create([
            'reservation_id' => $reservation->id, 'status' => 'stopped',
            'energy_wh' => $amountMinor * 10,
            'rate_minor_per_kwh' => 100, 'currency' => 'AED',
            'total_minor' => $amountMinor, 'stopped_at' => now(),
        ]);
        $finance = app(FinanceService::class);
        $invoice = $finance->issue($session, $this->customer->id);
        if ($capture) {
            $finance->pay($invoice, $this->customer->id, $amountMinor, 'cohort-pay-'.$label);
        }

        return $invoice;
    }

    public function test_revenue_rows_are_utc_issuance_cohorts_and_refunds_are_attributed_to_original_invoice(): void
    {
        $this->travelTo(Carbon::parse('2026-11-10 12:00:00', 'UTC'));
        $first = $this->invoice(100, 'one', true);
        $this->travelTo(Carbon::parse('2026-11-11 12:00:00', 'UTC'));
        app(FinanceService::class)->refund($first, $this->admin->id, 20, 'cohort-refund', 'Refund posted next day');
        $second = $this->invoice(200, 'two', false);
        $path = '/revenue-test/commercial/vendors/'.$this->vendor->id.'/revenue-cohorts';
        $query = '?currency=AED&from=2026-11-10&to=2026-11-12';
        $response = $this->actingAs($this->vendor)->getJson($path.$query)
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('currency', 'AED')
            ->assertJsonPath('minor_units', 2)
            ->assertJsonPath('basis', 'INVOICE_ISSUANCE_DATE_UTC_COHORT')
            ->assertJsonPath('payment_and_refund_totals', 'LIFETIME_OF_SELECTED_INVOICE_COHORT_AS_OF_QUERY')
            ->assertJsonPath('data.0.date_utc', '2026-11-10')
            ->assertJsonPath('data.0.invoice_count', 1)
            ->assertJsonPath('data.0.invoiced_minor', 100)
            ->assertJsonPath('data.0.paid_minor', 100)
            ->assertJsonPath('data.0.refunded_minor', 20)
            ->assertJsonPath('data.0.net_collected_minor', 80)
            ->assertJsonPath('data.0.net_vendor_allocated_minor', 80)
            ->assertJsonPath('data.1.date_utc', '2026-11-11')
            ->assertJsonPath('data.1.invoice_count', 1)
            ->assertJsonPath('data.1.invoiced_minor', 200)
            ->assertJsonPath('data.1.paid_minor', 0)
            ->assertJsonPath('data.1.net_vendor_allocated_minor', 200)
            ->assertJsonPath('data.2.date_utc', '2026-11-12')
            ->assertJsonPath('data.2.invoice_count', 0)
            ->assertJsonPath('totals.invoiced_minor', 300)
            ->assertJsonPath('totals.paid_minor', 100)
            ->assertJsonPath('totals.refunded_minor', 20)
            ->assertJsonPath('totals.net_collected_minor', 80)
            ->assertJsonPath('totals.net_vendor_allocated_minor', 280)
            ->assertJsonPath('not_cashflow_or_payout_eligibility', true)
            ->assertJsonPath('external_psp_or_bank_verified', false)
            ->assertJsonPath('real_money_movement', false);
        $this->assertCount(3, $response->json('data'));
        $this->assertDatabaseCount('finance_invoices', 2);
        $this->assertDatabaseCount('finance_payments', 1);
        $this->assertDatabaseCount('finance_refunds', 1);

        $csv = $this->get($path.'/export'.$query)->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')->streamedContent();
        $this->assertStringContainsString('date_utc,invoice_count,invoiced_minor', $csv);
        $this->assertStringContainsString('2026-11-10,1,100', $csv);
        $this->assertStringContainsString('2026-11-11,1,200', $csv);
        $this->assertStringNotContainsString($this->customer->email, $csv);
        $this->assertStringNotContainsString((string) $second->number, $csv);

        $this->getJson($path.'?currency=AED&from=2026-11-08&to=2026-11-09')
            ->assertOk()
            ->assertJsonPath('totals.invoice_count', 0)
            ->assertJsonCount(2, 'data');
    }

    public function test_as_of_excludes_future_postings_and_invoices_in_the_selected_utc_day(): void
    {
        $this->travelTo(Carbon::parse('2026-11-10 08:00:00', 'UTC'));
        $invoice = $this->invoice(100, 'asof-first', false);
        $this->travelTo(Carbon::parse('2026-11-10 10:00:00', 'UTC'));
        app(FinanceService::class)->pay($invoice, $this->customer->id, 100, 'asof-later-payment');
        $this->invoice(200, 'asof-future', true);
        $base = '/revenue-test/commercial/vendors/'.$this->vendor->id.'/revenue-cohorts';
        $scope = '?currency=AED&from=2026-11-10&to=2026-11-10';
        $before = DB::table('finance_journals')->count();

        $this->travelTo(Carbon::parse('2026-11-10 09:00:00', 'UTC'));
        $this->actingAs($this->vendor)->getJson($base.$scope)->assertOk()
            ->assertJsonPath('totals.invoice_count', 1)->assertJsonPath('totals.invoiced_minor', 100)
            ->assertJsonPath('totals.paid_minor', 0);
        $csv = $this->get($base.'/export'.$scope)->assertOk()->streamedContent();
        $this->assertStringContainsString('2026-11-10,1,100,100,0,0,', $csv);
        $this->travelTo(Carbon::parse('2026-11-10 11:00:00', 'UTC'));
        $this->getJson($base.$scope)->assertOk()
            ->assertJsonPath('totals.invoice_count', 2)->assertJsonPath('totals.paid_minor', 300);
        $this->assertSame($before, DB::table('finance_journals')->count());
    }

    public function test_wrong_journal_currency_or_overpayment_is_not_hidden_in_a_cohort_total(): void
    {
        $this->travelTo(Carbon::parse('2026-11-10 12:00:00', 'UTC'));
        $invoice = $this->invoice(100, 'bad-import', true);
        $journal = DB::table('finance_journals')->insertGetId([
            'invoice_id' => $invoice->id, 'kind' => 'payment',
            'idempotency_key' => 'cohort-import-invalid',
            'fingerprint' => hash('sha256', 'cohort-import-invalid'),
            'currency' => 'USD', 'amount_minor' => 1, 'created_at' => now(),
        ]);
        DB::table('finance_payments')->insert([
            'invoice_id' => $invoice->id, 'journal_id' => $journal,
            'actor_id' => $this->customer->id, 'amount_minor' => 1,
            'evidence' => 'SIMULATED', 'created_at' => now(),
        ]);
        $base = '/revenue-test/commercial/vendors/'.$this->vendor->id.'/revenue-cohorts';
        $scope = '?currency=AED&from=2026-11-10&to=2026-11-10';
        $before = DB::table('finance_journals')->count();
        foreach ([$this->vendor, $this->admin] as $reader) {
            $this->actingAs($reader)->getJson($base.$scope)->assertConflict();
            $this->get($base.'/export'.$scope)->assertConflict();
        }
        $this->getJson($base.'?currency=AED&from=2026-11-09&to=2026-11-09')
            ->assertOk()->assertJsonPath('totals.invoice_count', 0);
        $this->assertSame($before, DB::table('finance_journals')->count());
    }

    public function test_cohort_rejects_unbalanced_postings_with_otherwise_valid_receipts(): void
    {
        $this->travelTo(Carbon::parse('2026-11-10 12:00:00', 'UTC'));
        $invoice = $this->invoice(100, 'unbalanced', true);
        $journal = DB::table('finance_journals')->where('invoice_id', $invoice->id)
            ->where('kind', 'payment')->value('id');
        $base = '/revenue-test/commercial/vendors/'.$this->vendor->id.'/revenue-cohorts';
        $scope = '?currency=AED&from=2026-11-10&to=2026-11-10';

        $this->actingAs($this->vendor)->getJson($base.$scope)->assertOk()
            ->assertJsonPath('totals.paid_minor', 100);

        // Simulate an appended legacy entry without disabling immutable storage.
        DB::table('finance_entries')->insert([
            'journal_id' => $journal, 'account' => 'cash:simulator',
            'debit_minor' => 1, 'credit_minor' => 0, 'created_at' => now(),
        ]);
        $count = DB::table('finance_entries')->count();
        $this->getJson($base.$scope)->assertConflict();
        $this->get($base.'/export'.$scope)->assertConflict();
        $this->getJson($base.'?currency=AED&from=2026-11-09&to=2026-11-09')
            ->assertOk()->assertJsonPath('totals.invoice_count', 0);
        $this->assertSame($count, DB::table('finance_entries')->count());
    }

    public function test_balanced_payment_posting_without_receipt_cannot_appear_as_zero_collection(): void
    {
        $this->travelTo(Carbon::parse('2026-11-10 12:00:00', 'UTC'));
        $invoice = $this->invoice(100, 'orphan-payment', false);
        $base = '/revenue-test/commercial/vendors/'.$this->vendor->id.'/revenue-cohorts';
        $scope = '?currency=AED&from=2026-11-10&to=2026-11-10';
        $this->actingAs($this->vendor)->getJson($base.$scope)->assertOk()
            ->assertJsonPath('totals.paid_minor', 0);
        $this->travel(2)->seconds();
        $journal = DB::table('finance_journals')->insertGetId([
            'invoice_id' => $invoice->id, 'kind' => 'payment',
            'idempotency_key' => 'cohort-orphan-legacy-payment',
            'fingerprint' => hash('sha256', 'cohort-orphan-legacy-payment'),
            'currency' => 'AED', 'amount_minor' => 100, 'created_at' => now(),
        ]);
        DB::table('finance_entries')->insert([
            ['journal_id' => $journal, 'account' => 'cash:simulator', 'debit_minor' => 100,
                'credit_minor' => 0, 'created_at' => now()],
            ['journal_id' => $journal, 'account' => 'customer_receivable', 'debit_minor' => 0,
                'credit_minor' => 100, 'created_at' => now()],
        ]);

        $this->getJson($base.$scope)->assertConflict();
        $this->get($base.'/export'.$scope)->assertConflict();
        $this->travelTo(Carbon::parse('2026-11-10 12:00:01', 'UTC'));
        $this->getJson($base.$scope)->assertOk()->assertJsonPath('totals.paid_minor', 0);
        $this->assertDatabaseCount('finance_payments', 0);
        $this->assertDatabaseCount('finance_journals', 2);
    }

    public function test_owner_only_scope_validation_and_no_financial_writes(): void
    {
        $this->travelTo(Carbon::parse('2026-11-10 12:00:00', 'UTC'));
        $this->invoice(100, 'three', true);
        $base = '/revenue-test/commercial/vendors/'.$this->vendor->id.'/revenue-cohorts';
        $query = '?currency=AED&from=2026-11-10&to=2026-11-11';
        $foreign = User::factory()->create(['role' => 'vendor']);
        $this->actingAs($foreign)->getJson($base.$query)->assertNotFound();
        $this->get($base.'/export'.$query)->assertNotFound();
        $this->actingAs($this->customer)->getJson($base.$query)->assertNotFound();
        $this->actingAs($this->admin)->getJson($base.$query)->assertOk()->assertJsonPath('totals.invoice_count', 1);
        $this->getJson($base.'?currency=aed&from=2026-11-10&to=2026-11-11')->assertUnprocessable();
        $this->getJson($base.'?currency=AE%0AD&from=2026-11-10&to=2026-11-11')->assertUnprocessable();
        $this->getJson($base.'?currency=AED&from=2026-11-10&to=2027-02-10')
            ->assertOk()->assertJsonCount(93, 'data');
        $this->getJson($base.'?currency=AED&from=2026-11-10&to=2027-02-11')->assertUnprocessable();
        $this->getJson($base.'?currency=AED&from=2026-11-10&to=2027-03-01')->assertUnprocessable();
        $this->getJson($base.'?currency=AED&from=2026-11-12&to=2026-11-10')->assertUnprocessable();
        $this->getJson($base.'?currency=AED&from=2026-02-30&to=2026-11-11')->assertUnprocessable();
        $this->getJson('/revenue-test/commercial/vendors/'.str_repeat('9', 19).'/revenue-cohorts'.$query)
            ->assertNotFound();

        $this->assertDatabaseCount('finance_invoices', 1);
        $this->assertDatabaseCount('finance_payments', 1);
        $this->assertDatabaseCount('finance_refunds', 0);
        $this->assertDatabaseCount('finance_journals', 2);
    }

    public function test_plain_browser_cohort_csv_refuses_invalid_filters_without_finance_mutations(): void
    {
        $this->travelTo(Carbon::parse('2026-11-10 12:00:00', 'UTC'));
        $this->invoice(100, 'invalid-csv', true);
        $base = '/revenue-test/commercial/vendors/'.$this->vendor->id.'/revenue-cohorts';
        $this->actingAs($this->vendor);
        $journalCount = DB::table('finance_journals')->count();
        $invoiceCount = DB::table('finance_invoices')->count();

        foreach ([
            'currency=aed&from=2026-11-10&to=2026-11-11',
            'currency=AED&from=2026-02-30&to=2026-11-11',
            'currency=AED&from=2026-11-12&to=2026-11-10',
        ] as $query) {
            $this->get($base.'/export?'.$query)->assertUnprocessable()
                ->assertHeader('Cache-Control', 'no-store, private');
        }
        $this->get($base.'/export?currency=AED&from=2026-11-10&to=2027-03-01')
            ->assertUnprocessable();
        $this->get($base.'/export?currency=AED&from=2026-11-10&to=2026-11-11')
            ->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertSame($journalCount, DB::table('finance_journals')->count());
        $this->assertSame($invoiceCount, DB::table('finance_invoices')->count());
    }

    public function test_invoice_cohort_aggregates_exclude_another_vendor_even_when_currency_and_date_match(): void
    {
        $this->travelTo(Carbon::parse('2026-11-10 12:00:00', 'UTC'));
        $this->invoice(100, 'owner', true);
        $originalVendor = $this->vendor;
        $this->vendor = User::factory()->create(['role' => 'vendor']);
        $this->invoice(200, 'foreign', true);
        $this->vendor = $originalVendor;

        $this->actingAs($originalVendor)->getJson(
            '/revenue-test/commercial/vendors/'.$originalVendor->id.
            '/revenue-cohorts?currency=AED&from=2026-11-10&to=2026-11-10'
        )->assertOk()
            ->assertJsonPath('totals.invoice_count', 1)
            ->assertJsonPath('totals.invoiced_minor', 100)
            ->assertJsonPath('totals.paid_minor', 100)
            ->assertJsonPath('totals.net_vendor_allocated_minor', 100);
        $this->assertDatabaseCount('finance_invoices', 2);
        $this->assertDatabaseCount('finance_payments', 2);
    }

    public function test_revoked_cached_vendor_and_admin_sessions_cannot_read_cohorts_or_download_csv(): void
    {
        $this->travelTo(Carbon::parse('2026-11-10 12:00:00', 'UTC'));
        $this->invoice(100, 'revoked-session', true);
        $base = '/revenue-test/commercial/vendors/'.$this->vendor->id.'/revenue-cohorts';
        $query = '?currency=AED&from=2026-11-10&to=2026-11-11';
        $original = DB::table('users')->where('id', $this->vendor->id)
            ->first(['role', 'account_status', 'auth_epoch', 'password']);
        $snapshot = [
            'role' => $original->role,
            'account_status' => $original->account_status,
            'auth_epoch' => $original->auth_epoch,
            'password' => $original->password,
        ];

        // Direct SQL deliberately leaves Laravel's authenticated model cached,
        // reproducing a session that survived a concurrent moderation change.
        $this->actingAs($this->vendor);
        foreach ([
            ['account_status' => 'suspended'],
            ['role' => 'customer'],
            ['auth_epoch' => (int) $original->auth_epoch + 1],
            ['password' => Hash::make('rotated-vendor-credential')],
        ] as $change) {
            DB::table('users')->where('id', $this->vendor->id)->update($change);
            $this->getJson($base.$query)->assertStatus(409);
            $this->get($base.'/export'.$query)->assertStatus(409);
            DB::table('users')->where('id', $this->vendor->id)->update($snapshot);
        }
        $this->getJson($base.$query)->assertOk()->assertJsonPath('totals.paid_minor', 100);

        $admin = DB::table('users')->where('id', $this->admin->id)
            ->first(['role', 'account_status', 'auth_epoch', 'password']);
        $adminSnapshot = [
            'role' => $admin->role,
            'account_status' => $admin->account_status,
            'auth_epoch' => $admin->auth_epoch,
            'password' => $admin->password,
        ];
        $this->actingAs($this->admin);
        foreach ([
            ['role' => 'customer'],
            ['account_status' => 'suspended'],
            ['auth_epoch' => (int) $admin->auth_epoch + 1],
            ['password' => Hash::make('rotated-admin-credential')],
        ] as $change) {
            DB::table('users')->where('id', $this->admin->id)->update($change);
            $this->getJson($base.$query)->assertStatus(409);
            $this->get($base.'/export'.$query)->assertStatus(409);
            DB::table('users')->where('id', $this->admin->id)->update($adminSnapshot);
        }
        $this->getJson($base.$query)->assertOk()->assertJsonPath('totals.invoice_count', 1);

        // Private failures cannot record financial events or create payouts.
        $this->assertDatabaseCount('finance_invoices', 1);
        $this->assertDatabaseCount('finance_payments', 1);
        $this->assertDatabaseCount('finance_refunds', 0);
        $this->assertDatabaseCount('finance_journals', 2);
    }

    public function test_native_revenue_cohorts_preserve_owner_and_admin_personal_token_boundaries(): void
    {
        $this->travelTo(Carbon::parse('2026-11-10 12:00:00', 'UTC'));
        $this->invoice(100, 'native', true);
        $path = '/api/v1/commercial/vendors/'.$this->vendor->id.
            '/revenue-cohorts?currency=AED&from=2026-11-10&to=2026-11-11';

        $this->app['auth']->forgetGuards();
        $this->withToken($this->vendor->createToken('cohort-native-vendor')->plainTextToken)
            ->getJson($path)->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('totals.paid_minor', 100)
            ->assertJsonPath('real_money_movement', false);

        $other = User::factory()->create(['role' => 'vendor']);
        $this->app['auth']->forgetGuards();
        $this->withToken($other->createToken('cohort-native-foreign')->plainTextToken)
            ->getJson($path)->assertNotFound();
        $this->getJson(str_replace('/revenue-cohorts?', '/revenue-cohorts/export?', $path))
            ->assertNotFound();

        $this->app['auth']->forgetGuards();
        $this->withToken($this->admin->createToken('cohort-admin-pat-disallowed')->plainTextToken)
            ->getJson($path)->assertForbidden();
        $this->assertDatabaseCount('finance_invoices', 1);
        $this->assertDatabaseCount('finance_payments', 1);
    }
}
