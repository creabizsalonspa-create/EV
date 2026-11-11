<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Finance\FinanceService;
use App\Services\Ocpi\RecordSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreatesUnpaidCreditInvoice;
use Tests\TestCase;

class PartnerBillingReconciliationTest extends TestCase
{
    use CreatesUnpaidCreditInvoice, RefreshDatabase;

    public function test_owner_and_admin_can_follow_source_credit_to_the_original_non_cash_invoice_without_money_mutation(): void
    {
        [$owner,$driver,$other,$invoice,$credit,$intent,,$provider] = $this->scenario('KWD', 3, '0.251', 251, 500);
        app(FinanceService::class)->creditUnpaid($invoice, $owner->id, $intent);
        $path = '/api/ocpi/providers/'.$provider->id.'/billing-reconciliation';
        $before = DB::table('finance_journals')->count();
        $result = $this->actingAs($owner)->getJson($path.'?kind=credits&currency=KWD')->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('provider_id', $provider->id)
            ->assertJsonPath('read_only', true)->assertJsonPath('billing_authority', false)->assertJsonPath('payment_execution', false)
            ->assertJsonPath('data.0.id', $credit->id)->assertJsonPath('data.0.billing.status', 'test_invoice_linked')
            ->assertJsonPath('data.0.billing.invoice.id', $invoice->id)->assertJsonPath('data.0.billing.invoice.minor_units', 3)
            ->assertJsonPath('data.0.billing.state', 'credited')->assertJsonPath('data.0.billing.due_minor', 0)
            ->assertJsonPath('data.0.billing.paid_minor', 0)->assertJsonPath('data.0.billing.refunded_minor', 0)
            ->assertJsonPath('data.0.billing.noncash_credit_count', 1)->assertJsonPath('data.0.billing.ledger_balanced', true);
        foreach (['payload', 'content_hash', 'user_id', 'reason', 'token', 'journal_id'] as $secret) {
            $this->assertStringNotContainsString('"'.$secret.'":', $result->getContent());
        }
        $this->actingAs($driver)->getJson($path)->assertNotFound();
        $this->actingAs($other)->getJson($path)->assertNotFound();
        $this->actingAs(User::factory()->create(['role' => 'admin']))->getJson($path)->assertOk();
        $this->assertDatabaseCount('finance_journals', $before);
        $this->assertDatabaseCount('finance_payments', 0);
        $this->assertDatabaseCount('finance_refunds', 0);
    }

    public function test_payment_and_credit_review_remain_distinct_and_unknown_source_cannot_invent_an_invoice(): void
    {
        [$owner,$driver,,$invoice,$credit,,,$provider] = $this->scenario();
        $base = '/api/finance/invoices/'.$invoice->id;
        $this->actingAs($driver)->postJson($base.'/simulate-payment', ['amount_minor' => $invoice->total_minor, 'idempotency_key' => 'partner-paid'])->assertCreated();
        $path = '/api/ocpi/providers/'.$provider->id.'/billing-reconciliation';
        $this->actingAs($owner)->getJson($path.'?kind=charges')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.credit', false)->assertJsonPath('data.0.billing.state', 'paid')
            ->assertJsonPath('data.0.billing.paid_minor', $invoice->total_minor)->assertJsonPath('data.0.billing.refunded_minor', 0);
        $this->actingAs($driver)->postJson($base.'/refund-requests', ['amount_minor' => 5, 'reason' => 'Partner source credit requires review', 'idempotency_key' => 'partner-review', 'ocpi_credit_record_id' => $credit->id])->assertCreated();
        $this->actingAs($owner)->getJson($path.'?kind=credits')->assertOk()->assertJsonPath('data.0.billing.refund_review_count', 1)
            ->assertJsonPath('data.0.billing.refunded_minor', 0)->assertJsonCount(1, 'data.0.billing.refund_reviews');
        $this->getJson($path.'?currency=USD')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($path.'?country_code=XX&party_id=ZZZ')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($path.'?kind=anything')->assertUnprocessable();
        $this->getJson($path.'?page=0')->assertUnprocessable();
        $this->assertDatabaseCount('finance_payments', 1);
        $this->assertDatabaseCount('finance_refunds', 0);
    }

    public function test_pagination_and_uninvoiced_source_never_create_or_repeat_financial_records(): void
    {
        [$owner,,,$invoice,,,$original,$provider] = $this->scenario();
        $batch = [];
        for ($i = 0; $i < 26; $i++) {
            $batch[] = array_replace($original->payload, ['id' => 'uninvoiced-'.$i]);
        }
        app(RecordSynchronizer::class)->ingest($provider, 'cdrs', $batch);
        $url = '/api/ocpi/providers/'.$provider->id.'/billing-reconciliation';
        $this->actingAs($owner)->getJson($url)->assertOk()->assertJsonCount(25, 'data')
            ->assertJsonPath('total', 28)->assertJsonPath('last_page', 2)->assertJsonPath('data.0.billing.status', 'not_invoiced');
        $this->getJson($url.'?page=2')->assertOk()->assertJsonCount(3, 'data');
        $this->assertDatabaseCount('finance_invoices', 1);
        $this->assertDatabaseCount('finance_journals', 1);
        $this->assertDatabaseCount('finance_payments', 0);
    }

    public function test_provider_reassignment_and_changed_source_cannot_reveal_the_previous_vendor_invoice(): void
    {
        [$owner,,$other,$invoice,,$intent,,$provider] = $this->scenario();
        $path = '/api/ocpi/providers/'.$provider->id.'/billing-reconciliation';
        $other = User::factory()->create(['role' => 'vendor']);
        DB::table('ocpi_providers')->where('id', $provider->id)->update(['owner_id' => $other->id]);
        $result = $this->actingAs($other)->getJson($path)->assertOk()->assertJsonPath('data.0.billing.status', 'scope_requires_review');
        $this->assertStringNotContainsString($invoice->number, $result->getContent());
        $this->actingAs($owner)->getJson($path)->assertNotFound();
        DB::table('ocpi_providers')->where('id', $provider->id)->update(['owner_id' => $owner->id]);
        $receipt = DB::table('ocpi_test_invoice_receipts')->where('invoice_id', $invoice->id)->first();
        DB::table('ocpi_records')->where('id', $receipt->source_session_record_id)->update(['content_hash' => str_repeat('a', 64)]);
        $this->actingAs($owner)->getJson($path)->assertOk()->assertJsonPath('data.0.billing.status', 'source_requires_review')->assertJsonMissingPath('data.0.billing.invoice');
        $this->assertDatabaseCount('finance_invoices', 1);
        $this->assertDatabaseCount('finance_payments', 0);
    }
}
