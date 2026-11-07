<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\VendorOps\VendorCompany;
use App\Services\Ocpi\TestInvoiceLineage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\CreatesSandboxRoaming;
use Tests\TestCase;

class OcpiTestInvoiceLineageTest extends TestCase
{
    use CreatesSandboxRoaming, RefreshDatabase;

    private function billable(): array
    {
        [$owner,$driver,$other,$p,$location] = $this->fixture();
        [$id] = $this->start($driver, $location);
        $order = DB::table('ocpi_roaming_orders')->find($id);
        $token = $this->writes[0]['body'];
        $session = $this->sourceSessionFixture($p, $order, $token, 'COMPLETED');
        $session['id'] = 'BILL-'.$id;
        $session['kwh'] = '1.0';
        $session['total_cost'] = ['excl_vat' => '0.25'];
        $session['last_updated'] = now()->addSecond()->toISOString();
        $this->withHeaders($this->peer())->putJson('/api/roaming-protocol/2.2.1/sessions/NL/ABC/'.$session['id'], $session)->assertCreated();
        $cdr = ['country_code' => 'NL', 'party_id' => 'ABC', 'id' => 'CDR-'.$id, 'session_id' => $session['id'], 'last_updated' => $session['last_updated'], 'start_date_time' => $session['start_date_time'], 'end_date_time' => $session['end_date_time'], 'currency' => 'EUR', 'total_energy' => '1.0', 'total_time' => '0.001', 'total_cost' => $session['total_cost'], 'cdr_location' => ['id' => 'L1', 'evse_uid' => 'E1', 'connector_id' => '1'], 'cdr_token' => $session['cdr_token'], 'authorization_reference' => $order->authorization_reference];
        $this->postJson('/api/roaming-protocol/2.2.1/cdrs', $cdr)->assertCreated();
        $this->withHeaders(['Authorization' => ''])->postJson('/api/roaming/sandbox-orders/'.$id.'/reconcile')->assertOk()->assertJsonPath('state', 'source_completed')->assertJsonPath('reconciliation_state', 'source_consistent');
        $company = VendorCompany::create(['owner_id' => $owner->id, 'legal_name' => 'Synthetic approved TEST merchant', 'country' => 'AE', 'trade_license_number' => 'SYNTHETIC-ONLY', 'license_expires_on' => now()->addYear()->toDateString(), 'status' => 'approved']);
        config(['ocpi.test_billing_enabled' => true, 'ocpi.connections.roaming.test_billing_contract' => ['reference' => 'isolated_billing_v1', 'revision' => 1, 'command_contract' => 'isolated_contract_v1', 'company_id' => $company->id, 'country' => 'NL', 'currency' => 'EUR', 'minor_units' => 2, 'rate_minor_per_kwh' => 25, 'tax_bps' => 0, 'commission_bps' => 1000]]);

        return [$owner, $driver, $other, $p, $company, $id, $session];
    }

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
}
