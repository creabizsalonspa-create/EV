<?php

namespace Tests\Feature;

use App\Models\ChargingSession;
use App\Models\Finance\TariffVersion;
use App\Models\Fleet;
use App\Models\Reservation;
use App\Models\Station;
use App\Models\User;
use App\Services\Commercial\Payments\GatewayService;
use App\Services\Finance\FinanceService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class CorporateBillingTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $driver;

    private User $admin;

    private User $vendor;

    private Fleet $fleet;

    private int $center;

    protected function setUp(): void
    {
        parent::setUp();
        Route::middleware(['web', 'auth'])->prefix('corporate-test')->group(base_path('routes/corporate_billing.php'));
        $this->owner = User::factory()->create();
        $this->driver = User::factory()->create();
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->vendor = User::factory()->create(['role' => 'vendor']);
        $this->fleet = Fleet::create(['owner_id' => $this->owner->id, 'name' => 'Corporate fixture', 'country' => 'AE']);
        DB::table('fleet_members')->insert(['fleet_id' => $this->fleet->id, 'user_id' => $this->driver->id, 'status' => 'active', 'role' => 'driver', 'created_at' => now(), 'updated_at' => now()]);
        $this->center = DB::table('cost_centers')->insertGetId(['fleet_id' => $this->fleet->id, 'code' => 'ENGINEERING', 'name' => 'Engineering', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function url(string $path): string
    {
        return '/corporate-test/commercial/corporate/'.$path;
    }

    private function agreement(int $limit = 100000): array
    {
        $a = $this->actingAs($this->admin)->postJson($this->url('fleets/'.$this->fleet->id.'/agreements'), ['currency' => 'AED', 'minor_units' => 2, 'credit_limit_minor' => $limit, 'payment_terms_days' => 30, 'billing_day' => 1, 'effective_from' => now()->subDay()->toIso8601String(), 'expires_at' => now()->addYear()->toIso8601String(), 'legal_name' => 'Test corporate issuer', 'terms' => 'Test contract; no real credit granted', 'idempotency_key' => 'agreement'])->assertCreated()->json('agreement');
        $this->actingAs($this->owner)->postJson($this->url('agreements/'.$a['id'].'/accept'), ['consent' => true])->assertOk();

        return $a;
    }

    private function invoice(): object
    {
        $station = Station::create(['vendor_id' => $this->vendor->id, 'name' => 'Corporate simulation', 'country' => 'AE', 'currency' => 'AED', 'timezone' => 'Asia/Dubai', 'latitude' => 25, 'longitude' => 55, 'source' => 'simulator', 'connector_type' => 'CCS2', 'power_kw' => 50]);
        $tariff = TariffVersion::create(['station_id' => $station->id, 'created_by' => $this->admin->id, 'rate_minor_per_kwh' => 100, 'tax_bps' => 500, 'commission_bps' => 1000, 'currency' => 'AED', 'minor_units' => 2, 'effective_from' => now()->subMinute()]);
        $b = Reservation::create(['user_id' => $this->driver->id, 'station_id' => $station->id, 'starts_at' => now()->startOfHour(), 'status' => 'confirmed', 'idempotency_key' => (string) Str::uuid()]);
        $s = ChargingSession::create(['reservation_id' => $b->id, 'status' => 'stopped', 'energy_wh' => 10000, 'rate_minor_per_kwh' => 100, 'currency' => 'AED', 'total_minor' => 1000, 'stopped_at' => now()]);
        $finance = app(FinanceService::class);
        $finance->snapshotSession($s, $tariff);

        return $finance->issue($s, $this->driver->id);
    }

    private function claim(array $a, object $invoice): array
    {
        return $this->actingAs($this->driver)->postJson($this->url('agreements/'.$a['id'].'/claims'), ['invoice_id' => $invoice->id, 'cost_center_id' => $this->center, 'idempotency_key' => 'claim-'.$invoice->id])->assertCreated()->json('claim');
    }

    private function approved(array $a, object $invoice): array
    {
        $c = $this->claim($a, $invoice);
        $this->actingAs($this->owner)->postJson($this->url('claims/'.$c['id'].'/review'), ['decision' => 'approved', 'reason' => 'Authorized business charging'])->assertOk();

        return $c;
    }

    private function issue(array $a, array $claims, string $key = 'statement')
    {
        return $this->actingAs($this->owner)->postJson($this->url('agreements/'.$a['id'].'/statements'), ['period' => now()->format('Y-m'), 'claim_ids' => array_column($claims, 'id'), 'idempotency_key' => $key]);
    }

    public function test_complete_claim_consolidation_cost_center_statement_and_atomic_canonical_simulated_receipts(): void
    {
        $a = $this->agreement();
        $invoice = $this->invoice();
        $c = $this->approved($a, $invoice);
        $c2 = $this->approved($a, $this->invoice());
        $s = $this->issue($a, [$c, $c2])->assertCreated()->json('statement');
        $this->assertSame(2100, $s['total_minor']);
        $this->assertSame(100, $s['tax_minor']);
        $this->issue($a, [$c2, $c])->assertCreated()->assertJsonPath('statement.id', $s['id']);
        $this->getJson($this->url('statements/'.$s['id']))->assertOk()->assertJsonCount(2, 'lines')->assertJsonPath('state', 'issued');
        $this->get($this->url('statements/'.$s['id'].'/export'))->assertOk()->assertHeader('Content-Type', 'text/csv; charset=utf-8')->assertSee('ENGINEERING');
        $this->postJson($this->url('statements/'.$s['id'].'/simulate-payment'), ['idempotency_key' => 'pay'])->assertOk()->assertJsonPath('receipt.amount_minor', 2100)->assertJsonPath('real_payment_processed', false);
        $this->postJson($this->url('statements/'.$s['id'].'/simulate-payment'), ['idempotency_key' => 'pay'])->assertOk();
        $this->assertDatabaseCount('finance_payments', 2);
        $this->assertDatabaseCount('corporate_receipts', 1);
        $this->assertDatabaseCount('corporate_receipt_payments', 2);
        $this->assertSame(1050, app(FinanceService::class)->summary($invoice)['paid_minor']);
        $this->assertSame(0, app(FinanceService::class)->summary($invoice)['amount_due_minor']);
        $this->assertTrue(app(FinanceService::class)->summary($invoice)['ledger_balanced']);
        $this->getJson($this->url('statements/'.$s['id']))->assertOk()->assertJsonPath('state', 'simulated_paid');
    }

    public function test_unaccepted_expired_foreign_and_revoked_membership_cannot_claim(): void
    {
        $a = $this->agreement();
        $i = $this->invoice();
        DB::table('fleet_members')->where('fleet_id', $this->fleet->id)->update(['status' => 'revoked']);
        $this->actingAs($this->driver)->postJson($this->url('agreements/'.$a['id'].'/claims'), ['invoice_id' => $i->id, 'cost_center_id' => $this->center, 'idempotency_key' => 'revoked'])->assertNotFound();
        $this->actingAs($this->owner)->postJson($this->url('agreements/'.$a['id'].'/claims'), ['invoice_id' => $i->id, 'cost_center_id' => $this->center, 'idempotency_key' => 'foreign'])->assertNotFound();
        $this->travel(367)->days();
        $this->actingAs($this->driver);
        DB::table('fleet_members')->where('fleet_id', $this->fleet->id)->update(['status' => 'active']);
        $this->postJson($this->url('agreements/'.$a['id'].'/claims'), ['invoice_id' => $i->id, 'cost_center_id' => $this->center, 'idempotency_key' => 'expired'])->assertConflict();
    }

    public function test_duplicate_invoice_claim_idempotency_and_decisions_cannot_be_rewritten(): void
    {
        $a = $this->agreement();
        $i = $this->invoice();
        $c = $this->claim($a, $i);
        $this->claim($a, $i);
        $this->postJson($this->url('agreements/'.$a['id'].'/claims'), ['invoice_id' => $i->id, 'cost_center_id' => $this->center, 'idempotency_key' => 'second'])->assertConflict();
        $this->actingAs($this->owner)->postJson($this->url('claims/'.$c['id'].'/review'), ['decision' => 'rejected', 'reason' => 'Personal charging'])->assertOk();
        $this->postJson($this->url('claims/'.$c['id'].'/review'), ['decision' => 'approved', 'reason' => 'Changed mind'])->assertConflict();
        $this->issue($a, [$c])->assertConflict();
        $this->assertDatabaseCount('corporate_statements', 0);
    }

    public function test_manager_privacy_claim_approval_credit_limit_and_month_guards(): void
    {
        $a = $this->agreement(1500);
        $c = $this->approved($a, $this->invoice());
        $c2 = $this->approved($a, $this->invoice());
        $this->issue($a, [$c, $c2])->assertConflict();
        $s = $this->issue($a, [$c])->assertCreated()->json('statement');
        $this->issue($a, [$c2], 'second')->assertConflict();
        $this->actingAs($this->driver)->getJson($this->url('statements/'.$s['id']))->assertNotFound();
        $this->getJson($this->url('agreements/'.$a['id'].'/statements'))->assertNotFound();
        $this->postJson($this->url('claims/'.$c2['id'].'/review'), ['decision' => 'approved', 'reason' => 'Unauthorized'])->assertNotFound();
        $this->postJson($this->url('statements/'.$s['id'].'/simulate-payment'), ['idempotency_key' => 'driver'])->assertNotFound();
        $foreign = User::factory()->create();
        $this->actingAs($foreign)->getJson('/corporate-test/commercial/corporate?fleet_id='.$this->fleet->id)->assertNotFound();
    }

    public function test_partial_payment_gateway_attempt_and_changed_invoice_stop_collection_without_partial_receipts(): void
    {
        $a = $this->agreement();
        $i = $this->invoice();
        $c = $this->approved($a, $i);
        $i2 = $this->invoice();
        $c2 = $this->approved($a, $i2);
        $s = $this->issue($a, [$c, $c2])->assertCreated()->json('statement');
        app(FinanceService::class)->pay($i2, $this->driver->id, 100, 'outside');
        $this->actingAs($this->owner)->postJson($this->url('statements/'.$s['id'].'/simulate-payment'), ['idempotency_key' => 'pay'])->assertConflict();
        $this->assertDatabaseCount('finance_payments', 1);
        $this->assertDatabaseCount('corporate_receipts', 0);
        $this->assertSame(0, app(FinanceService::class)->summary($i)['paid_minor']);
        $this->getJson($this->url('statements/'.$s['id']))->assertOk()->assertJsonPath('state', 'collection_attention');
    }

    public function test_gateway_checkout_never_becomes_a_corporate_mock_payment_bypass(): void
    {
        $a = $this->agreement();
        $invoice = $this->invoice();
        app(GatewayService::class)->checkout($invoice, $this->driver->id, 1050, 'provider');
        $this->actingAs($this->driver)->postJson($this->url('agreements/'.$a['id'].'/claims'), ['invoice_id' => $invoice->id, 'cost_center_id' => $this->center, 'idempotency_key' => 'psp'])->assertConflict();
        $this->assertDatabaseCount('corporate_claims', 0);
        $this->assertDatabaseCount('finance_payments', 0);
    }

    public function test_wrong_cost_center_month_duplicate_claims_and_changed_key_payload_are_rejected(): void
    {
        $a = $this->agreement();
        $invoice = $this->invoice();
        $c = $this->approved($a, $invoice);
        $this->actingAs($this->owner)->postJson($this->url('agreements/'.$a['id'].'/statements'), ['period' => now()->subMonth()->format('Y-m'), 'claim_ids' => [$c['id']], 'idempotency_key' => 'month'])->assertConflict();
        $this->postJson($this->url('agreements/'.$a['id'].'/statements'), ['period' => now()->format('Y-m'), 'claim_ids' => [$c['id'], $c['id']], 'idempotency_key' => 'duplicate'])->assertUnprocessable();
        $this->issue($a, [$c])->assertCreated();
        $c2 = $this->approved($a, $this->invoice());
        $this->issue($a, [$c2])->assertConflict();
        $foreign = Fleet::create(['name' => 'Foreign fleet', 'country' => 'AE', 'owner_id' => $this->owner->id]);
        $center = DB::table('cost_centers')->insertGetId(['fleet_id' => $foreign->id, 'code' => 'FOREIGN', 'name' => 'Private', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($this->driver)->postJson($this->url('agreements/'.$a['id'].'/claims'), ['invoice_id' => $invoice->id, 'cost_center_id' => $center, 'idempotency_key' => 'foreign-center'])->assertNotFound();
    }

    public function test_only_administrator_can_publish_and_only_owner_can_accept(): void
    {
        $this->actingAs($this->owner)->postJson($this->url('fleets/'.$this->fleet->id.'/agreements'), ['currency' => 'AED', 'minor_units' => 2, 'credit_limit_minor' => 10000, 'payment_terms_days' => 30, 'billing_day' => 1, 'effective_from' => now()->toIso8601String(), 'expires_at' => now()->addYear()->toIso8601String(), 'legal_name' => 'Test', 'terms' => 'Test', 'idempotency_key' => 'a'])->assertForbidden();
        $a = $this->agreement();
        $this->actingAs($this->admin)->postJson($this->url('agreements/'.$a['id'].'/accept'), ['consent' => true])->assertNotFound();
        $this->actingAs($this->owner)->postJson($this->url('agreements/'.$a['id'].'/accept'), ['consent' => false])->assertUnprocessable();
        $this->getJson('/corporate-test/commercial/corporate?fleet_id='.$this->fleet->id)->assertOk()->assertJsonPath('can_manage', true)->assertJsonPath('agreements.0.acceptance.accepted_by', $this->owner->id);
    }

    public function test_financial_history_database_protects_claims_statements_and_acceptances(): void
    {
        $a = $this->agreement();
        $c = $this->approved($a, $this->invoice());
        $this->issue($a, [$c])->assertCreated();
        $this->expectException(QueryException::class);
        DB::table('corporate_statement_lines')->update(['total_minor' => 1]);
    }

    public function test_company_credit_history_redacts_vendor_allocations_and_csv_neutralizes_hidden_formulas(): void
    {
        $a = $this->agreement();
        $invoice = $this->invoice();
        $code = "\u{200f}  =2+2";
        DB::table('cost_centers')->where('id', $this->center)->update(['code' => $code]);
        $c = $this->approved($a, $invoice);
        $s = $this->issue($a, [$c])->assertCreated()->json('statement');
        $csv = $this->get($this->url('statements/'.$s['id'].'/export'))->assertOk()->getContent();
        $this->assertStringContainsString("'".$code, $csv);
        $this->postJson($this->url('statements/'.$s['id'].'/simulate-payment'), ['idempotency_key' => 'refund-test'])->assertOk();
        app(FinanceService::class)->refund($invoice, $this->vendor->id, 100, 'company-credit', 'Original invoice correction');
        $this->getJson($this->url('statements/'.$s['id']))->assertOk()->assertJsonPath('refunded_minor', 100)->assertJsonPath('net_collected_minor', 950)->assertJsonCount(1, 'credit_notes')->assertJsonMissingPath('credit_notes.0.vendor_minor')->assertJsonMissingPath('credit_notes.0.commission_minor')->assertJsonMissingPath('credit_notes.0.journal_id');
    }

    public function test_current_locked_owner_controls_decisions_and_new_statement_payer_snapshot(): void
    {
        $a = $this->agreement();
        $c = $this->claim($a, $this->invoice());
        $newOwner = User::factory()->create();
        DB::table('fleets')->where('id', $this->fleet->id)->update(['owner_id' => $newOwner->id]);
        $this->actingAs($this->owner)->postJson($this->url('claims/'.$c['id'].'/review'), ['decision' => 'approved', 'reason' => 'Authorized'])->assertNotFound();
        $this->issue($a, [$c])->assertNotFound();
        $this->actingAs($newOwner)->postJson($this->url('claims/'.$c['id'].'/review'), ['decision' => 'approved', 'reason' => 'Authorized'])->assertOk();
        $this->postJson($this->url('agreements/'.$a['id'].'/statements'), ['period' => now()->format('Y-m'), 'claim_ids' => [$c['id']], 'idempotency_key' => 'new-owner'])->assertCreated()->assertJsonPath('statement.payer_id', $newOwner->id);
    }

    public function test_production_or_nonmock_collection_is_disabled(): void
    {
        $a = $this->agreement();
        $c = $this->approved($a, $this->invoice());
        $s = $this->issue($a, [$c])->assertCreated()->json('statement');
        config(['payments.provider' => 'stripe_test']);
        $this->postJson($this->url('statements/'.$s['id'].'/simulate-payment'), ['idempotency_key' => 'p'])->assertStatus(503);
        config(['payments.provider' => 'mock']);
        $this->withoutMiddleware(ValidateCsrfToken::class);
        app()->detectEnvironment(fn () => 'production');
        $this->postJson($this->url('statements/'.$s['id'].'/simulate-payment'), ['idempotency_key' => 'p'])->assertStatus(503);
        $this->assertDatabaseCount('finance_payments', 0);
    }
}
