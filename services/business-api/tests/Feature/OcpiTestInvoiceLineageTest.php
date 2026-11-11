<?php

namespace Tests\Feature;

use App\Models\Finance\Invoice;
use App\Models\OcpiProvider;
use App\Models\OcpiRecord;
use App\Models\User;
use App\Services\Finance\FinanceService;
use App\Services\Ocpi\RecordSynchronizer;
use App\Services\Ocpi\TestInvoiceLineage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Support\CreatesBillableSandboxRoaming;
use Tests\TestCase;

class OcpiTestInvoiceLineageTest extends TestCase
{
    use CreatesBillableSandboxRoaming, RefreshDatabase;

    private function accept(int $id): array
    {
        $preview = $this->getJson('/api/roaming/sandbox-orders/'.$id.'/test-invoice-preview')->assertOk()->assertJsonPath('total_minor', 25)->assertJsonPath('automatic_charge', false)->assertJsonPath('physical_charging_verified', false)->json();

        return ['request_key' => (string) Str::uuid(), 'preview_token' => $preview['preview_token'], 'terms_version' => TestInvoiceLineage::TERMS, 'consent' => true, 'expected_total_minor' => $preview['total_minor'], 'expected_currency' => $preview['currency'], 'expected_minor_units' => $preview['minor_units'], 'expected_energy_wh' => $preview['energy_wh']];
    }

    public function test_source_completion_never_bills_without_separate_signed_consent_and_uses_one_canonical_balanced_ledger(): void
    {
        [$owner,$driver,,,,$id] = $this->billable();
        $this->assertDatabaseCount('charging_sessions', 0);
        $this->assertDatabaseCount('finance_invoices', 0);
        $this->assertDatabaseCount('reservations', 0);
        $this->assertDatabaseCount('stations', 0);
        $v = $this->accept($id);
        $url = '/api/roaming/sandbox-orders/'.$id.'/test-invoice';
        $this->postJson($url, array_replace($v, ['consent' => false]))->assertUnprocessable();
        $this->postJson($url, array_replace($v, ['preview_token' => $v['preview_token'].'BAD']))->assertConflict();
        $this->assertDatabaseCount('finance_journals', 0);
        $invoice = $this->postJson($url, $v)->assertOk()->assertJsonPath('invoice.total_minor', 25)->assertJsonPath('invoice.vendor_minor', 22)->assertJsonPath('ledger_balanced', true)->assertJsonPath('real_payment_processed', false)->json('invoice');
        $this->postJson($url, $v)->assertOk()->assertJsonPath('invoice.id', $invoice['id']);
        $this->postJson($url, array_replace($v, ['request_key' => (string) Str::uuid()]))->assertConflict();
        $this->assertDatabaseCount('charging_sessions', 1);
        $this->assertDatabaseCount('finance_invoices', 1);
        $this->assertDatabaseCount('finance_session_quotes', 1);
        $this->assertDatabaseCount('finance_journals', 1);
        $this->assertDatabaseCount('ocpi_test_invoice_receipts', 1);
        $this->assertDatabaseCount('reservations', 0);
        $this->assertDatabaseCount('stations', 0);
        $this->assertDatabaseCount('finance_payments', 0);
        $this->assertNull(DB::table('charging_sessions')->value('reservation_id'));
        $this->assertSame($id, DB::table('charging_sessions')->value('roaming_order_id'));
        $this->actingAs($owner)->getJson('/api/finance/invoices/'.$invoice['id'])->assertOk();
        $proofPath = '/api/finance/invoices/'.$invoice['id'].'/source-evidence';
        $proof = $this->getJson($proofPath)->assertOk()->assertJsonPath('kind', 'OCPI_SANDBOX')->assertJsonPath('source_verified', true)->assertJsonPath('physical_charging_verified', false)->assertJsonPath('payment_authority', false)->assertJsonCount(2, 'proofs')->json();
        $this->assertStringNotContainsString('ciphertext', json_encode($proof));
        $this->assertStringNotContainsString('token', json_encode($proof));
        $receipt = DB::table('ocpi_test_invoice_receipts')->where('invoice_id', $invoice['id'])->first();
        $facts = json_decode(Crypt::decryptString($receipt->snapshot_ciphertext), true, flags: JSON_THROW_ON_ERROR);
        DB::table('ocpi_test_invoice_receipts')->where('id', $receipt->id)->update(['actor_id' => $owner->id]);
        $this->getJson($proofPath)->assertOk()->assertJsonPath('source_verified', false)->assertJsonPath('status', 'requires_review')->assertJsonCount(0, 'proofs');
        DB::table('ocpi_test_invoice_receipts')->where('id', $receipt->id)->update(['actor_id' => $receipt->actor_id]);
        foreach (['vendor_id', 'provider_id', 'order_id', 'source_session_record_id', 'source_cdr_record_id'] as $field) {
            $changed = $facts;
            $changed[$field]++;
            DB::table('ocpi_test_invoice_receipts')->where('id', $receipt->id)->update(['snapshot_ciphertext' => Crypt::encryptString(json_encode($changed, JSON_THROW_ON_ERROR))]);
            $this->getJson($proofPath)->assertOk()->assertJsonPath('source_verified', false)->assertJsonPath('status', 'requires_review')->assertJsonCount(0, 'proofs');
        }
        DB::table('ocpi_test_invoice_receipts')->where('invoice_id', $invoice['id'])->update(['snapshot_ciphertext' => 'corrupt private proof']);
        $this->getJson($proofPath)->assertOk()->assertJsonPath('source_verified', false)->assertJsonPath('status', 'requires_review')->assertJsonCount(0, 'proofs');

        $this->actingAs($driver)->getJson('/api/simulations')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs(User::factory()->create(['role' => 'admin']))->getJson('/api/simulations')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_preview_is_bound_to_current_merchant_policy_actor_source_and_five_minute_expiry(): void
    {
        [,$driver,$other,,$company,$id] = $this->billable();
        $v = $this->accept($id);
        $url = '/api/roaming/sandbox-orders/'.$id.'/test-invoice';
        $this->actingAs($other)->getJson('/api/roaming/sandbox-orders/'.$id.'/test-invoice-preview')->assertNotFound();
        $this->postJson($url, $v)->assertNotFound();
        $this->actingAs($driver);
        config(['ocpi.connections.roaming.test_billing_contract.commission_bps' => 2000]);
        $this->postJson($url, $v)->assertConflict();
        config(['ocpi.connections.roaming.test_billing_contract.commission_bps' => 1000]);
        $company->update(['status' => 'suspended']);
        $this->postJson($url, $v)->assertConflict();
        $company->update(['status' => 'approved']);
        $v = $this->accept($id);
        $this->travel(6)->minutes();
        $this->postJson($url, $v)->assertConflict();
        $this->travelBack();
        config(['ocpi.test_billing_enabled' => false]);
        $this->postJson($url, $v)->assertConflict();
        $this->assertDatabaseCount('finance_invoices', 0);
        $this->assertDatabaseCount('charging_sessions', 0);
    }

    public function test_financed_source_is_immutable_and_legacy_manual_meter_stop_and_issuance_cannot_fabricate_roaming_updates(): void
    {
        [, $driver, , , , $id,$source] = $this->billable();
        $v = $this->accept($id);
        $invoice = $this->postJson('/api/roaming/sandbox-orders/'.$id.'/test-invoice', $v)->assertOk()->json('invoice');
        $source['last_updated'] = now()->addSeconds(2)->toISOString();
        $source['kwh'] = '2.0';
        $this->withHeaders($this->peer())->putJson('/api/roaming-protocol/2.2.1/sessions/NL/ABC/'.$source['id'], $source)->assertConflict();
        $this->withHeaders(['Authorization' => ''])->putJson('/api/simulations/'.$invoice['charging_session_id'].'/meter', ['energy_wh' => 2000])->assertNotFound();
        $this->postJson('/api/simulations/'.$invoice['charging_session_id'].'/stop')->assertNotFound();
        $this->postJson('/api/finance/sessions/'.$invoice['charging_session_id'].'/invoice')->assertNotFound();
        $this->getJson('/api/finance/invoices/'.$invoice['id'])->assertOk()->assertJsonPath('invoice.energy_wh', 1000)->assertJsonPath('invoice.total_minor', 25);
        $this->assertDatabaseCount('finance_journals', 1);
    }

    public function test_explicit_resolution_cancels_only_unissued_identity_and_late_posts_cannot_create_an_invoice(): void
    {
        [,,,,,$id] = $this->billable();
        $v = $this->accept($id);
        $path = '/api/roaming/sandbox-orders/'.$id;
        $this->postJson($path.'/test-invoice-resolve', $v)->assertUnprocessable();
        $this->postJson($path.'/test-invoice-resolve', $v + ['cancel_unissued' => true])->assertOk()->assertJsonPath('state', 'cancelled_unissued')->assertJsonPath('financial_records_changed', false);
        $this->postJson($path.'/test-invoice', $v)->assertConflict();
        $this->assertDatabaseCount('finance_invoices', 0);
        $next = $this->accept($id);
        $invoice = $this->postJson($path.'/test-invoice', $next)->assertOk()->assertJsonPath('billing_origin', 'OCPI_TEST_EXPLICIT_CONSENT')->json('invoice');
        $this->postJson($path.'/test-invoice-resolve', $next + ['cancel_unissued' => true])->assertOk()->assertJsonPath('state', 'issued')->assertJsonPath('invoice_id', $invoice['id'])->assertJsonPath('financial_records_changed', false);
        $this->assertDatabaseCount('finance_invoices', 1);
        $this->assertDatabaseCount('finance_journals', 1);
    }

    public function test_region_currency_and_precision_changes_block_new_consent_without_rewriting_issued_history(): void
    {
        [,,,,,$id] = $this->billable();
        $v = $this->accept($id);
        $path = '/api/roaming/sandbox-orders/'.$id;
        foreach ([['currency' => 'USD'], ['minor_units' => 3]] as $change) {
            DB::table('regions')->where('country', 'NL')->update($change);
            $this->getJson($path.'/test-invoice-preview')->assertConflict();
            $this->postJson($path.'/test-invoice', $v)->assertConflict();
            DB::table('regions')->where('country', 'NL')->update(['currency' => 'EUR', 'minor_units' => 2]);
        }
        DB::table('regions')->where('country', 'NL')->update(['updated_at' => now()->addSecond()]);
        $this->postJson($path.'/test-invoice', $v)->assertConflict();
        $this->assertDatabaseCount('finance_invoices', 0);
        $v = $this->accept($id);
        $invoice = $this->postJson($path.'/test-invoice', $v)->assertOk()->json('invoice');
        DB::table('regions')->where('country', 'NL')->delete();
        $this->postJson($path.'/test-invoice', $v)->assertOk()->assertJsonPath('invoice.id', $invoice['id'])->assertJsonPath('invoice.minor_units', 2);
        $this->getJson($path.'/test-invoice-preview')->assertConflict();
        $this->assertDatabaseCount('finance_journals', 1);
    }

    #[TestWith(['JPY', 0, '25', 25])]
    #[TestWith(['KWD', 3, '0.125', 125])]
    public function test_zero_and_three_decimal_source_currencies_use_exact_regional_minor_units(string $currency, int $units, string $rate, int $minor): void
    {
        [,,,,,$id] = $this->billable($currency, $units, $rate, $minor);
        $path = '/api/roaming/sandbox-orders/'.$id;
        $p = $this->getJson($path.'/test-invoice-preview')->assertOk()->assertJsonPath('total_minor', $minor)->assertJsonPath('minor_units', $units)->json();
        $v = ['request_key' => (string) Str::uuid(), 'preview_token' => $p['preview_token'], 'terms_version' => TestInvoiceLineage::TERMS, 'consent' => true, 'expected_total_minor' => $minor, 'expected_currency' => $currency, 'expected_minor_units' => $units, 'expected_energy_wh' => 1000];
        $this->postJson($path.'/test-invoice', $v)->assertOk()->assertJsonPath('invoice.currency', $currency)->assertJsonPath('invoice.minor_units', $units)->assertJsonPath('invoice.total_minor', $minor)->assertJsonPath('ledger_balanced', true);
        $this->assertDatabaseCount('finance_invoices', 1);
        $this->assertDatabaseCount('finance_payments', 0);
    }

    public function test_partner_cost_requiring_minor_unit_rounding_is_not_a_billable_source(): void
    {
        [,,,,,$id] = $this->billable(sourceCost: '0.251');
        $this->getJson('/api/roaming/sandbox-orders/'.$id.'/test-invoice-preview')->assertConflict();
        $this->assertDatabaseCount('finance_invoices', 0);
        $this->assertDatabaseCount('charging_sessions', 0);
    }

    public function test_additive_lineage_migration_preserves_exact_active_evse_predicate_and_schema_round_trip(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->assertStringContainsString('WHERE', DB::table('pg_indexes')->where('tablename', 'charging_sessions')->where('indexname', 'charging_active_evse')->value('indexdef'));

            return;
        }
        $expected = "CREATE UNIQUE INDEX charging_active_evse ON charging_sessions (charging_evse_id) WHERE charging_evse_id IS NOT NULL AND status IN ('pending_start','charging','stopping')";
        $index = fn () => DB::table('sqlite_master')->where('name', 'charging_active_evse')->value('sql');
        $this->assertSame($expected, $index());
        $migration = require database_path('migrations/2026_11_10_000002_add_ocpi_test_invoice_lineage.php');
        $migration->down();
        $this->assertSame($expected, $index());
        $migration->up();
        $this->assertSame($expected, $index());
        DB::statement('DROP INDEX charging_active_evse');
        DB::statement('CREATE UNIQUE INDEX charging_active_evse ON charging_sessions (charging_evse_id)');
        $repair = require database_path('migrations/2026_11_10_000004_restore_sqlite_charging_capacity_index.php');
        $repair->up();
        $this->assertSame($expected, $index());
        $repair->down();
        $this->assertSame($expected, $index());
        $this->assertSame(2, DB::table('sqlite_master')->where('type', 'trigger')->whereIn('name', ['charging_source_insert', 'charging_source_update'])->count());
    }

    public function test_credit_evidence_uses_existing_customer_request_vendor_review_and_canonical_refund_once(): void
    {
        [$owner,$driver,,,, $order] = $this->billable();
        $consent = $this->accept($order);
        $invoice = $this->postJson('/api/roaming/sandbox-orders/'.$order.'/test-invoice', $consent)->assertOk()->json('invoice');
        $model = Invoice::findOrFail($invoice['id']);
        $receipt = DB::table('ocpi_test_invoice_receipts')->where('invoice_id', $model->id)->first();
        $original = OcpiRecord::findOrFail($receipt->source_cdr_record_id);
        $provider = OcpiProvider::findOrFail($original->ocpi_provider_id);
        app(RecordSynchronizer::class)->ingest($provider, 'cdrs', [array_replace($original->payload, ['id' => 'source-credit', 'credit' => true, 'credit_reference_id' => $original->external_id])]);
        $credit = OcpiRecord::where('external_id', 'source-credit')->sole();
        $options = '/api/finance/invoices/'.$model->id.'/source-credits';
        $this->actingAs($driver)->getJson($options)->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('invoice_id', $model->id)->assertJsonPath('financial_authority', false)->assertJsonPath('automatic_refund', false)
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $credit->id)->assertJsonPath('data.0.available_for_review', true);
        $this->actingAs(User::factory()->create())->getJson($options)->assertNotFound();
        $this->actingAs($owner)->getJson($options)->assertOk();
        $this->actingAs($driver);
        $body = ['amount_minor' => 25, 'reason' => 'Review source correction', 'idempotency_key' => 'credit-refund-review', 'ocpi_credit_record_id' => $credit->id];
        $path = '/api/finance/invoices/'.$model->id.'/refund-requests';
        $this->actingAs($driver)->postJson($path, $body)->assertConflict();
        $this->assertDatabaseCount('finance_refund_requests', 0);
        app(FinanceService::class)->pay($model, $driver->id, 25, 'credit-test-payment');
        $this->postJson($path, array_replace($body, ['ocpi_credit_record_id' => $original->id]))->assertConflict();
        $request = $this->actingAs($driver)->postJson($path, $body)->assertCreated()->assertJsonPath('ocpi_credit_record_id', $credit->id)->json();
        $this->postJson($path, $body)->assertOk()->assertJsonPath('id', $request['id']);
        $this->getJson($options)->assertOk()->assertJsonPath('data.0.available_for_review', false)
            ->assertJsonPath('data.0.existing_refund_request_id', $request['id'])->assertJsonPath('data.0.existing_refund_state', 'pending');
        $this->postJson($path, array_replace($body, ['ocpi_credit_record_id' => $original->id]))->assertConflict();
        $this->postJson($path, array_replace($body, ['idempotency_key' => 'second-credit-request']))->assertConflict();
        $evidence = $this->actingAs($owner)->getJson('/api/finance/refund-requests')->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('data.0.source_credit_evidence.status', 'source_reference_matched')->assertJsonPath('data.0.source_credit_evidence.reported_cost.excl_vat', '0.25')->assertJsonPath('data.0.source_credit_evidence.financial_authority', false)->json('data.0.source_credit_evidence');
        $this->assertStringNotContainsString('token', json_encode($evidence));
        $this->assertStringNotContainsString('payload', json_encode($evidence));
        $this->actingAs(User::factory()->create())->getJson('/api/finance/refund-requests')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($owner);
        $review = '/api/finance/refund-requests/'.$request['id'].'/review';
        $this->actingAs($owner)->postJson($review, ['decision' => 'approve', 'note' => 'Reviewed captured TEST funds'])->assertOk();
        $this->postJson($review, ['decision' => 'approve', 'note' => 'Reviewed captured TEST funds'])->assertOk();
        $this->assertDatabaseCount('finance_refunds', 1);
        $this->assertSame(25, (int) DB::table('finance_refunds')->sum('amount_minor'));
        $this->assertSame($original->payload, $original->fresh()->payload);
        $this->assertTrue(app(FinanceService::class)->summary($model)['ledger_balanced']);
    }

    public function test_source_credit_picker_is_scoped_to_original_partner_party_currency_and_reference_and_paginates(): void
    {
        [$owner,$driver,,,, $order] = $this->billable();
        $invoice = $this->postJson('/api/roaming/sandbox-orders/'.$order.'/test-invoice', $this->accept($order))->assertOk()->json('invoice');
        $receipt = DB::table('ocpi_test_invoice_receipts')->where('invoice_id', $invoice['id'])->first();
        $original = OcpiRecord::findOrFail($receipt->source_cdr_record_id);
        $provider = OcpiProvider::findOrFail($original->ocpi_provider_id);
        $credits = [];
        for ($i = 0; $i < 27; $i++) {
            $credits[] = array_replace($original->payload, ['id' => 'candidate-'.$i, 'credit' => true, 'credit_reference_id' => $original->external_id]);
        }
        app(RecordSynchronizer::class)->ingest($provider, 'cdrs', $credits);
        $base = OcpiRecord::where('external_id', 'candidate-0')->sole();
        $otherProvider = $provider->replicate();
        $otherProvider->connection_key = 'foreign-picker-fixture';
        $otherProvider->save();
        foreach ([['country_code' => 'DE'], ['party_id' => 'XYZ'], ['ocpi_provider_id' => $otherProvider->id], ['normalized' => array_replace($base->normalized, ['currency' => 'USD'])], ['normalized' => array_replace($base->normalized, ['credit_reference_id' => 'OTHER-CDR'])]] as $i => $changes) {
            $other = $base->replicate();
            $other->fill($changes + ['external_id' => 'private-other-'.$i])->save();
        }
        $path = '/api/finance/invoices/'.$invoice['id'].'/source-credits';
        $first = $this->actingAs($driver)->getJson($path)->assertOk()->assertJsonPath('total', 27)->assertJsonCount(25, 'data')->json();
        $second = $this->getJson($path.'?page=2')->assertOk()->assertJsonCount(2, 'data')->json();
        $this->assertCount(27, array_unique(array_column(array_merge($first['data'], $second['data']), 'id')));
        foreach (['private-other', 'payload', 'cdr_token', 'authorization_reference', 'connection_key'] as $secret) {
            $this->assertStringNotContainsString($secret, json_encode($first));
        }
        $this->getJson($path.'?page=-1')->assertUnprocessable();
        $this->assertDatabaseCount('finance_payments', 0);
        $this->assertDatabaseCount('finance_refunds', 0);
        $this->assertDatabaseCount('finance_refund_requests', 0);
        $this->assertDatabaseCount('finance_journals', 1);
    }
}
