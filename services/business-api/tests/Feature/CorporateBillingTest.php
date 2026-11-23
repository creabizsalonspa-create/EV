<?php

namespace Tests\Feature;

use App\Http\Controllers\CorporateBillingController;
use App\Http\Controllers\FleetReportController;
use App\Models\ChargingSession;
use App\Models\Finance\TariffVersion;
use App\Models\Fleet;
use App\Models\Reservation;
use App\Models\Station;
use App\Models\User;
use App\Services\Commercial\Payments\GatewayService;
use App\Services\Enterprise\FleetReport;
use App\Services\Finance\FinanceService;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
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

    public function test_corporate_business_receipts_hide_internal_idempotency_material_across_all_clients(): void
    {
        $agreement = $this->agreement();
        $before = DB::table('finance_journals')->count();
        $this->assertArrayNotHasKey('request_key', $agreement);
        $this->assertArrayNotHasKey('fingerprint', $agreement);
        $this->actingAs($this->owner)->getJson(
            '/corporate-test/commercial/corporate?fleet_id='.$this->fleet->id
        )->assertOk()
            ->assertJsonMissingPath('agreements.0.request_key')
            ->assertJsonMissingPath('agreements.0.fingerprint');

        $invoice = $this->invoice();
        $claim = $this->claim($agreement, $invoice);
        $this->assertArrayNotHasKey('request_key', $claim);
        $this->assertArrayNotHasKey('fingerprint', $claim);
        $this->actingAs($this->owner)->getJson(
            $this->url('agreements/'.$agreement['id'].'/claims')
        )->assertOk()
            ->assertJsonMissingPath('data.0.request_key')
            ->assertJsonMissingPath('data.0.fingerprint');
        $this->postJson($this->url('claims/'.$claim['id'].'/review'), [
            'decision' => 'approved', 'reason' => 'Corporate test review',
        ])->assertOk();

        $statement = $this->issue($agreement, [$claim])->assertCreated()
            ->assertJsonMissingPath('statement.request_key')
            ->assertJsonMissingPath('statement.fingerprint')->json('statement');
        $this->getJson($this->url('agreements/'.$agreement['id'].'/statements'))
            ->assertOk()->assertJsonMissingPath('data.0.request_key')
            ->assertJsonMissingPath('data.0.fingerprint');
        $this->getJson($this->url('statements/'.$statement['id']))
            ->assertOk()->assertJsonMissingPath('statement.request_key')
            ->assertJsonMissingPath('statement.fingerprint');

        $this->postJson($this->url('statements/'.$statement['id'].'/simulate-payment'), [
            'idempotency_key' => 'corporate-secret-output',
        ])->assertOk()->assertJsonPath('real_payment_processed', false)
            ->assertJsonMissingPath('receipt.request_key')
            ->assertJsonMissingPath('receipt.fingerprint');
        $this->getJson($this->url('statements/'.$statement['id']))
            ->assertOk()->assertJsonMissingPath('receipt.request_key')
            ->assertJsonMissingPath('receipt.fingerprint');
        $this->getJson($this->url('agreements/'.$agreement['id'].'/statements'))
            ->assertOk()->assertJsonMissingPath('data.0.receipt.request_key')
            ->assertJsonMissingPath('data.0.receipt.fingerprint');

        // Server-owned immutable replay identity remains for same-key retries.
        $this->assertSame(64, strlen(DB::table('corporate_agreements')
            ->where('id', $agreement['id'])->value('request_key')));
        $this->assertSame(64, strlen(DB::table('corporate_claims')
            ->where('id', $claim['id'])->value('fingerprint')));
        $this->assertSame(64, strlen(DB::table('corporate_statements')
            ->where('id', $statement['id'])->value('request_key')));
        $this->assertSame(64, strlen(DB::table('corporate_receipts')
            ->where('statement_id', $statement['id'])->value('fingerprint')));
        $this->assertSame($before + 2, DB::table('finance_journals')->count());
    }

    public function test_corporate_statement_export_refuses_rounded_source_money_and_hidden_formulas(): void
    {
        $exact = new \ReflectionMethod(
            CorporateBillingController::class,
            'exactMinor'
        );
        $this->assertSame(9_007_199_254_740_991,
            $exact->invoke(null, '9007199254740991'));
        $this->assertSame(0, $exact->invoke(null, '0'));
        foreach ([100.0, true, false, 0.1, '-1', '1e2',
            '9007199254740992', 'wrong-source'] as $unsafe) {
            try {
                $exact->invoke(null, $unsafe);
                $this->fail('Unsafe corporate source money was rounded into a CSV.');
            } catch (HttpException $error) {
                $this->assertSame(409, $error->getStatusCode());
            }
        }

        $literal = new \ReflectionMethod(
            CorporateBillingController::class,
            'safeCsvCell'
        );
        foreach (["\u{00A0}=SUM(1,1)", "\u{200B}@hidden",
            "\u{202E}+bidi", "\u{FEFF}-bom"] as $formula) {
            $this->assertSame("'".$formula, $literal->invoke(null, $formula));
        }

        $a = $this->agreement();
        $claim = $this->approved($a, $this->invoice());
        $statement = $this->issue($a, [$claim])
            ->assertCreated()->json('statement');
        $cash = DB::table('finance_journals')->count();
        $this->actingAs($this->owner)->get(
            $this->url('statements/'.$statement['id'].'/export')
        )->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertSee('ENGINEERING');
        $this->assertSame($cash, DB::table('finance_journals')->count());
        $this->assertDatabaseCount('corporate_receipts', 0);
    }

    public function test_corporate_statement_csv_keeps_formula_like_cost_centers_literal(): void
    {
        $agreement = $this->agreement();
        $this->center = DB::table('cost_centers')->insertGetId([
            'fleet_id' => $this->fleet->id,
            'code' => '  =SUM(1+1)',
            'name' => 'Synthetic spreadsheet formula identifier',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $claim = $this->approved($agreement, $this->invoice());
        $statement = $this->issue($agreement, [$claim])
            ->assertCreated()->json('statement');
        $before = DB::table('finance_journals')->count();

        $this->actingAs($this->owner);
        $this->getJson($this->url('statements/'.$statement['id']))
            ->assertOk()->assertJsonPath('lines.0.cost_center_code', '  =SUM(1+1)');
        $csv = $this->get($this->url('statements/'.$statement['id'].'/export'))
            ->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Cache-Control', 'no-store, private')->getContent();
        $this->assertStringContainsString("'  =SUM(1+1)", $csv);
        $this->assertSame($before, DB::table('finance_journals')->count());
        $this->assertDatabaseHas('corporate_claims', [
            'id' => $claim['id'], 'cost_center_code' => '  =SUM(1+1)',
        ]);
    }

    public function test_revoked_corporate_owner_and_admin_cannot_read_or_collect_private_statements(): void
    {
        $agreement = $this->agreement();
        $claim = $this->approved($agreement, $this->invoice());
        $statement = $this->issue($agreement, [$claim])->assertCreated()->json('statement');
        $before = DB::table('finance_journals')->count();

        foreach ([$this->owner, $this->admin] as $reader) {
            $snapshot = DB::table('users')->where('id', $reader->id)
                ->first(['role', 'account_status', 'auth_epoch', 'password']);
            $original = [
                'role' => $snapshot->role,
                'account_status' => $snapshot->account_status,
                'auth_epoch' => $snapshot->auth_epoch,
                'password' => $snapshot->password,
            ];
            $this->actingAs($reader);
            $this->getJson('/corporate-test/commercial/corporate?fleet_id='.$this->fleet->id)->assertOk();
            $this->getJson($this->url('agreements/'.$agreement['id'].'/claims'))->assertOk();
            $this->getJson($this->url('agreements/'.$agreement['id'].'/statements'))->assertOk();
            $this->getJson($this->url('statements/'.$statement['id']))->assertOk();

            foreach ([
                ['role' => 'vendor'],
                ['account_status' => 'suspended'],
                ['auth_epoch' => (int) $snapshot->auth_epoch + 1],
                ['password' => Hash::make('rotated-corporate-session')],
            ] as $change) {
                DB::table('users')->where('id', $reader->id)->update($change);
                $this->getJson('/corporate-test/commercial/corporate?fleet_id='.$this->fleet->id)->assertConflict();
                $this->getJson($this->url('agreements/'.$agreement['id'].'/claims'))
                    ->assertConflict();
                $this->getJson($this->url('agreements/'.$agreement['id'].'/statements'))
                    ->assertConflict();
                $this->getJson($this->url('statements/'.$statement['id']))->assertConflict();
                $this->get($this->url('statements/'.$statement['id'].'/export'))
                    ->assertConflict();
                $this->postJson($this->url('statements/'.$statement['id'].'/simulate-payment'), [
                    'idempotency_key' => 'revoked-no-corporate-ledger-post',
                ])->assertConflict();
                $this->postJson($this->url('agreements/'.$agreement['id'].'/accept'), [
                    'consent' => true,
                ])->assertConflict();
                $this->postJson($this->url('agreements/'.$agreement['id'].'/claims'), [
                    'invoice_id' => $claim['invoice_id'], 'cost_center_id' => $this->center,
                    'idempotency_key' => 'revoked-corporate-claim',
                ])->assertConflict();
                $this->postJson($this->url('claims/'.$claim['id'].'/review'), [
                    'decision' => 'approved', 'reason' => 'Revoked actor must not review',
                ])->assertConflict();
                $this->postJson($this->url('agreements/'.$agreement['id'].'/statements'), [
                    'period' => now()->format('Y-m'), 'claim_ids' => [$claim['id']],
                    'idempotency_key' => 'revoked-corporate-invoice',
                ])->assertConflict();
                if ($snapshot->role === 'admin') {
                    $this->postJson($this->url('fleets/'.$this->fleet->id.'/agreements'), [
                        'currency' => 'AED', 'minor_units' => 2,
                        'credit_limit_minor' => 100000, 'payment_terms_days' => 30,
                        'billing_day' => 1,
                        'effective_from' => now()->addDays(2)->toIso8601String(),
                        'expires_at' => now()->addYear()->toIso8601String(),
                        'legal_name' => 'Must not publish when revoked',
                        'terms' => 'A revoked Admin cannot create this agreement.',
                        'idempotency_key' => 'revoked-new-agreement',
                    ])->assertConflict();
                }
                DB::table('users')->where('id', $reader->id)->update($original);
            }
        }
        $this->assertSame($before, DB::table('finance_journals')->count());
        $this->assertDatabaseCount('corporate_receipts', 0);
        $this->assertDatabaseCount('finance_payments', 0);
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

    public function test_fleet_report_only_counts_approved_shared_claims_and_does_not_mutate_finance(): void
    {
        $this->travelTo(Carbon::parse('2026-11-15T10:00:00Z'));
        $a = $this->agreement();
        $this->approved($a, $this->invoice());
        $this->claim($a, $this->invoice()); // Pending claim is excluded.
        $this->invoice(); // Personal invoice is not shared with the fleet.
        $payments = DB::table('finance_payments')->count();
        $url = '/api/fleets/'.$this->fleet->id.'/report?from=2026-11-15&through=2026-11-15';
        $this->actingAs($this->owner)->getJson($url)->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('approved_claims', 1)->assertJsonPath('groups.0.amount_minor', '1050')
            ->assertJsonPath('period.from_utc', '2026-11-14T20:00:00+00:00')
            ->assertJsonPath('physical_charging_verified', false);
        $this->actingAs($this->driver)->getJson($url)->assertNotFound();
        $this->actingAs(User::factory()->create())->getJson($url)->assertNotFound();
        $this->assertDatabaseCount('finance_payments', $payments);
        $this->assertDatabaseCount('corporate_statements', 0);
    }

    public function test_fleet_report_bounds_filters_and_neutralizes_csv_formulas(): void
    {
        $this->travelTo(Carbon::parse('2026-11-15T10:00:00Z'));
        $a = $this->agreement();
        $c = $this->approved($a, $this->invoice());
        $base = '/api/fleets/'.$this->fleet->id.'/report';
        $this->actingAs($this->owner)->getJson($base.'?from=2025-01-01&through=2026-11-15')->assertUnprocessable();
        $this->getJson($base.'?from=2026-02-30&through=2026-11-15')->assertUnprocessable();
        $this->getJson($base.'?from=2026-11-16&through=2026-11-15')->assertUnprocessable();
        $this->getJson($base.'?from=2026-11-15&through=2026-11-15&cost_center_id=99999')->assertNotFound();
        // Claim snapshots are immutable; a hostile historic fleet name must be safe in CSV.
        $this->fleet->update(['name' => " \t=HYPERLINK(1)"]);
        $response = $this->get($base.'?from=2026-11-15&through=2026-11-15&format=csv')->assertOk();
        $this->assertStringContainsString("' \t=HYPERLINK(1)", $response->getContent());
        $this->assertStringNotContainsString($this->driver->email, $response->getContent());
        $this->getJson($base.'?from=2026-11-14&through=2026-11-14')->assertJsonPath('approved_claims', 0);
        $this->actingAs($this->admin)->getJson($base.'?from=2026-11-15&through=2026-11-15')->assertOk();
        $token = $this->admin->createToken('forbidden-admin-native')->plainTextToken;
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/v1/fleets/'.$this->fleet->id.'/report?from=2026-11-15&through=2026-11-15')->assertForbidden();
    }

    public function test_native_owner_report_uses_current_credentials_and_cannot_cross_fleet(): void
    {
        $token = $this->owner->createToken('fleet-owner-report')->plainTextToken;
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/v1/fleets/'.$this->fleet->id.'/report?from=2026-11-01&through=2026-11-15')->assertOk()->assertJsonPath('approved_claims', 0);
        $other = Fleet::create(['owner_id' => $this->driver->id, 'name' => 'Not shared', 'country' => 'AE']);
        $this->getJson('/api/v1/fleets/'.$other->id.'/report?from=2026-11-01&through=2026-11-15')->assertNotFound();
        $snapshot = $this->owner->fresh();
        DB::table('users')->where('id', $snapshot->id)->increment('auth_epoch');
        $request = Request::create('/private-fleet-report', 'GET', ['from' => '2026-11-01', 'through' => '2026-11-15']);
        $request->setUserResolver(fn () => $snapshot);
        try {
            app(FleetReportController::class)->show($request, $this->fleet, app(FleetReport::class));
            $this->fail('A stale authenticated account snapshot must not read fleet claims.');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
    }

    public function test_admin_fleet_directory_is_private_literal_filtered_and_paginated(): void
    {
        Fleet::create(['owner_id' => $this->owner->id, 'name' => 'Directory %_ literal', 'country' => 'AE']);
        for ($i = 0; $i < 26; $i++) {
            Fleet::create(['owner_id' => $this->owner->id, 'name' => 'Other directory '.$i, 'country' => 'AE']);
        }
        $this->actingAs($this->owner)->getJson('/api/admin/fleets')->assertForbidden();
        $literal = $this->actingAs($this->admin)->getJson('/api/admin/fleets?q=%25_&country=AE')->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('total', 1)->assertJsonPath('data.0.name', 'Directory %_ literal');
        $this->assertSame(['id', 'name', 'country', 'created_at'], array_keys($literal->json('data.0')));
        $this->getJson('/api/admin/fleets?q=Other%20directory')->assertOk()->assertJsonPath('total', 26)->assertJsonCount(25, 'data');
        $this->getJson('/api/admin/fleets?q=Other%20directory&page=2')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('current_page', 2);
        $this->getJson('/api/admin/fleets?country=ZZ')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/admin/fleets?country=%25')->assertUnprocessable();
        $this->getJson('/api/admin/fleets?page=1000001')->assertUnprocessable();
    }

    public function test_stale_admin_cannot_use_fleet_directory_authority(): void
    {
        $snapshot = $this->admin->fresh();
        DB::table('users')->where('id', $snapshot->id)->increment('auth_epoch');
        $request = Request::create('/api/admin/fleets', 'GET');
        $request->setUserResolver(fn () => $snapshot);
        try {
            app(FleetReportController::class)->index($request);
            $this->fail('A revoked Admin snapshot cannot list fleets.');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
    }
}
