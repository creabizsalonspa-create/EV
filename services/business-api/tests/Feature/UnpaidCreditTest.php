<?php

namespace Tests\Feature;

use App\Models\OcpiRecord;
use App\Models\User;
use App\Services\Commercial\Documents\FinancialDocuments;
use App\Services\Commercial\Documents\FinancialProfiles;
use App\Services\Commercial\Documents\FinancialSources;
use App\Services\Finance\FinanceService;
use App\Services\Ocpi\RecordSynchronizer;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\Support\CreatesUnpaidCreditInvoice;
use Tests\TestCase;

class UnpaidCreditTest extends TestCase
{
    use CreatesUnpaidCreditInvoice, RefreshDatabase;

    public function test_full_unpaid_source_credit_appends_balanced_non_cash_reversal_and_blocks_future_collection(): void
    {
        [$owner,$driver,,$invoice,$credit,$intent,$original] = $this->scenario();
        $before = $invoice->getAttributes();
        $path = '/api/finance/invoices/'.$invoice->id;
        $this->actingAs($owner)->getJson($path)->assertOk()->assertJsonPath('can_issue_unpaid_credit', true);
        $this->getJson($path.'/source-credits?full_reversal=1')->assertOk()->assertJsonPath('data.0.full_reversal_source_match', true);
        $note = $this->postJson($path.'/unpaid-credit', $intent)->assertOk()->assertJsonPath('cash_moved', false)->assertJsonPath('refund_created', false)->json('credit_note');
        $this->postJson($path.'/unpaid-credit', $intent)->assertOk()->assertJsonPath('credit_note.id', $note['id']);
        $this->postJson($path.'/unpaid-credit', array_replace($intent, ['reason' => 'Changed original request details']))->assertConflict();
        $this->getJson($path.'/source-credits')->assertOk()->assertJsonPath('data.0.available_for_review', false)->assertJsonPath('data.0.existing_unpaid_credit_id', $note['id']);
        $summary = $this->actingAs($driver)->getJson($path)->assertOk()->assertJsonPath('state', 'credited')->assertJsonPath('paid_minor', 0)->assertJsonPath('refunded_minor', 0)->assertJsonPath('amount_due_minor', 0)->assertJsonPath('unpaid_credited_minor', 25)->assertJsonPath('ledger_balanced', true)->assertJsonCount(1, 'unpaid_credit_notes')->json();
        $this->assertFalse($summary['real_payment_processed']);
        $this->postJson('/api/commercial/checkouts', ['invoice_id' => $invoice->id, 'amount_minor' => 25, 'idempotency_key' => 'late-checkout'])->assertConflict();
        $this->actingAs($owner)->getJson('/api/finance/report')->assertOk()->assertJsonPath('groups.0.unpaid_credited_minor', 25)->assertJsonPath('groups.0.outstanding_minor', 0)->assertJsonPath('groups.0.net_vendor_allocated_minor', 0)->assertJsonPath('groups.0.net_commission_allocated_minor', 0);
        $documentSource = app(FinancialSources::class)->read($driver, 'charging_unpaid_credit', $note['id']);
        $this->assertSame($intent['reason'], $documentSource['reason']);
        $this->assertSame(25, $documentSource['total_minor']);
        $this->assertStringContainsString('no cash', $documentSource['description_en']);
        $this->actingAs($driver);
        $this->postJson($path.'/simulate-payment', ['amount_minor' => 25, 'idempotency_key' => 'late-payment'])->assertConflict();
        $this->assertSame($before, $invoice->fresh()->getAttributes());
        $this->assertSame($original->content_hash, $original->fresh()->content_hash);
        $this->assertDatabaseCount('finance_journals', 2);
        $this->assertDatabaseCount('finance_unpaid_credits', 1);
        $this->assertDatabaseCount('finance_payments', 0);
        $this->assertDatabaseCount('finance_refunds', 0);
        $this->assertDatabaseCount('finance_refund_requests', 0);
        $this->assertSame(0, (int) DB::table('finance_entries')->where('account', 'like', 'cash:%')->sum('debit_minor'));
        $this->assertStringNotContainsString($intent['reason'], DB::table('finance_unpaid_credits')->value('reason'));
        foreach (['update', 'delete'] as $mutation) {
            try {
                DB::transaction(function () use ($note, $mutation) {
                    $query = DB::table('finance_unpaid_credits')->where('id', $note['id']);
                    $mutation === 'update' ? $query->update(['amount_minor' => 1]) : $query->delete();
                });
                $this->fail('Unpaid credit history must reject '.$mutation);
            } catch (QueryException $e) {
                $this->assertDatabaseHas('finance_unpaid_credits', ['id' => $note['id'], 'amount_minor' => 25]);
            }
        }
    }

    public function test_operator_scope_price_binding_and_resolution_cancel_late_writes_without_a_ledger_entry(): void
    {
        [$owner,$driver,$other,$invoice,,$intent] = $this->scenario();
        $path = '/api/finance/invoices/'.$invoice->id;
        $this->actingAs($driver)->postJson($path.'/unpaid-credit', $intent)->assertNotFound();
        $this->actingAs($other)->getJson($path)->assertNotFound();
        $this->actingAs($owner)->postJson($path.'/unpaid-credit', array_replace($intent, ['expected_total_minor' => 26]))->assertConflict();
        $this->postJson($path.'/unpaid-credit', array_replace($intent, ['expected_actor_id' => $driver->id]))->assertConflict();
        $resolve = ['expected_actor_id' => $owner->id, 'expected_invoice_number' => $invoice->number, 'idempotency_key' => $intent['idempotency_key'], 'cancel_unissued' => true];
        $this->postJson($path.'/unpaid-credit-resolve', $resolve)->assertOk()->assertJsonPath('state', 'cancelled_unissued')->assertJsonPath('cash_moved', false);
        $this->postJson($path.'/unpaid-credit-resolve', $resolve)->assertOk()->assertJsonPath('state', 'cancelled_unissued');
        $this->postJson($path.'/unpaid-credit', $intent)->assertConflict();
        $this->assertDatabaseCount('finance_unpaid_credit_cancellations', 1);
        $this->assertDatabaseCount('finance_unpaid_credits', 0);
        $this->assertDatabaseCount('finance_journals', 1);
    }

    public function test_paid_or_provider_attempted_invoices_cannot_be_non_cash_credited(): void
    {
        [$owner,$driver,,$invoice,,$intent] = $this->scenario();
        app(FinanceService::class)->pay($invoice, $driver->id, 1, 'partial');
        $this->actingAs($owner)->postJson('/api/finance/invoices/'.$invoice->id.'/unpaid-credit', $intent)->assertConflict();
        $this->assertDatabaseCount('finance_unpaid_credits', 0);
    }

    #[TestWith(['JPY', 0, '25', 25, 1000])]
    #[TestWith(['KWD', 3, '0.025', 25, 500])]
    public function test_full_credit_reverses_original_currency_precision_tax_and_allocations(string $currency, int $units, string $rate, int $minor, int $tax): void
    {
        [$owner,,$other,$invoice,,$intent] = $this->scenario($currency, $units, $rate, $minor, $tax);
        $note = $this->actingAs($owner)->postJson('/api/finance/invoices/'.$invoice->id.'/unpaid-credit', $intent)->assertOk()->json('credit_note');
        $this->assertSame($invoice->total_minor, $note['amount_minor']);
        $this->assertSame($invoice->tax_minor, $note['tax_minor']);
        $this->assertSame($units, $note['minor_units']);
        $this->assertSame($currency, $note['currency']);
        $entries = DB::table('finance_entries')->where('journal_id', $note['journal_id']);
        $this->assertSame($invoice->total_minor, (int) (clone $entries)->sum('debit_minor'));
        $this->assertSame($invoice->total_minor, (int) (clone $entries)->sum('credit_minor'));
        $this->actingAs($other)->getJson('/api/finance/invoices/'.$invoice->id)->assertNotFound();
    }

    public function test_partial_or_other_rider_credit_and_any_checkout_attempt_are_not_full_unpaid_reversal_authority(): void
    {
        [$owner,$driver,,$invoice,,$intent,$original,$provider] = $this->scenario();
        $partial = array_replace($original->payload, ['id' => 'partial-source-credit', 'credit' => true, 'credit_reference_id' => $original->external_id, 'total_cost' => ['excl_vat' => '0.10']]);
        app(RecordSynchronizer::class)->ingest($provider, 'cdrs', [$partial]);
        $bad = OcpiRecord::where('external_id', 'partial-source-credit')->sole();
        $path = '/api/finance/invoices/'.$invoice->id;
        $this->actingAs($owner)->postJson($path.'/unpaid-credit', array_replace($intent, ['credit_record_id' => $bad->id]))->assertConflict();
        $this->getJson($path.'/source-credits?full_reversal=1')->assertOk()->assertJsonPath('data.0.full_reversal_source_match', false);
        $partial['id'] = 'wrong-rider-credit';
        $partial['total_cost'] = $original->payload['total_cost'];
        $partial['cdr_token']['uid'] = 'OTHER-RIDER-TOKEN';
        app(RecordSynchronizer::class)->ingest($provider, 'cdrs', [$partial]);
        $bad = OcpiRecord::where('external_id', 'wrong-rider-credit')->sole();
        $this->postJson($path.'/unpaid-credit', array_replace($intent, ['credit_record_id' => $bad->id]))->assertConflict();
        $this->actingAs($driver)->postJson('/api/commercial/checkouts', ['invoice_id' => $invoice->id, 'amount_minor' => $invoice->total_minor, 'idempotency_key' => 'existing-checkout'])->assertCreated();
        $this->actingAs($owner)->postJson($path.'/unpaid-credit', $intent)->assertConflict();
        $this->assertDatabaseCount('finance_unpaid_credits', 0);
        $this->assertDatabaseCount('finance_journals', 1);
    }

    public function test_private_non_cash_credit_document_retains_original_invoice_and_tax_lineage(): void
    {
        [$owner,$driver,,$invoice,,$intent] = $this->scenario();
        $credit = app(FinanceService::class)->creditUnpaid($invoice, $owner->id, $intent);
        $admin = User::factory()->create(['role' => 'admin']);
        app(FinancialProfiles::class)->publish($admin, ['vendor_id' => $owner->id, 'country' => $invoice->country, 'currency' => $invoice->currency, 'minor_units' => $invoice->minor_units, 'legal_name' => 'Synthetic TEST issuer', 'legal_name_ar' => 'جهة اختبار تجريبية', 'address' => 'Synthetic TEST address', 'address_ar' => 'عنوان اختبار تجريبي', 'tax_registration' => 'TEST-UNVERIFIED', 'effective_from' => now()->subDay()->toDateTimeString(), 'idempotency_key' => (string) Str::uuid()]);
        $documents = app(FinancialDocuments::class);
        $document = $documents->generate($driver, 'charging_unpaid_credit', $credit->id, 'ar');
        try {
            $snapshot = json_decode(Crypt::decryptString($document->encrypted_snapshot), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame('TEST_DOCUMENT_NOT_LEGALLY_APPROVED', $snapshot['legal_status']);
            $this->assertSame('SIMULATED_NONCASH', $snapshot['source']['evidence']);
            $this->assertSame($invoice->number, $snapshot['source']['invoice_reference']);
            $this->assertSame($invoice->total_minor, $snapshot['source']['total_minor']);
            $this->assertSame($invoice->tax_minor, $snapshot['source']['tax_minor']);
            $this->assertStringContainsString('دون حركة نقدية', $snapshot['source']['description_ar']);
            $this->assertStringStartsWith('%PDF-', Crypt::decryptString($documents->disk()->get($document->pdf_path)));
            $this->assertStringContainsString($invoice->number, Crypt::decryptString($documents->disk()->get($document->csv_path)));
        } finally {
            $documents->disk()->delete([$document->pdf_path, $document->csv_path]);
        }
    }

    public function test_admin_uses_explicit_own_identity_and_disabled_or_production_modes_cannot_issue(): void
    {
        [$owner,,,$invoice,,$intent] = $this->scenario();
        $path = '/api/finance/invoices/'.$invoice->id.'/unpaid-credit';
        config(['ocpi.test_billing_enabled' => false]);
        $this->actingAs($owner)->postJson($path, $intent)->assertConflict();
        config(['ocpi.test_billing_enabled' => true]);
        $this->app->detectEnvironment(fn () => 'production');
        try {
            app(FinanceService::class)->creditUnpaid($invoice, $owner->id, $intent);
            $this->fail('Production must not issue a TEST noncash credit.');
        } catch (HttpExceptionInterface $e) {
            $this->assertSame(409, $e->getStatusCode());
        } finally {
            $this->app->detectEnvironment(fn () => 'testing');
        }
        $admin = User::factory()->create(['role' => 'admin']);
        $intent['expected_actor_id'] = $admin->id;
        $this->actingAs($admin)->postJson($path, $intent)->assertOk()->assertJsonPath('credit_note.actor_id', $admin->id)->assertJsonPath('cash_moved', false);
        $this->assertDatabaseCount('finance_unpaid_credits', 1);
        $this->assertDatabaseCount('finance_payments', 0);
    }
}
