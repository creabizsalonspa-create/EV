<?php

namespace Tests\Feature;

use App\Models\ChargingSession;
use App\Models\Finance\TariffVersion;
use App\Models\Reservation;
use App\Models\Station;
use App\Models\User;
use App\Services\Commercial\SettlementService;
use App\Services\Finance\FinanceService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class SettlementTest extends TestCase
{
    use RefreshDatabase;

    private User $vendor;

    private User $customer;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Route::middleware(['web', 'auth'])->prefix('settlement-test')->group(base_path('routes/settlements.php'));
        $this->vendor = User::factory()->create(['role' => 'vendor']);
        $this->customer = User::factory()->create();
        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    private function invoice(bool $paid = true): object
    {
        $station = Station::create(['vendor_id' => $this->vendor->id, 'name' => 'Settlement simulator', 'country' => 'AE', 'currency' => 'AED', 'timezone' => 'Asia/Dubai', 'latitude' => 25, 'longitude' => 55, 'source' => 'simulator', 'connector_type' => 'CCS2', 'power_kw' => 50]);
        $tariff = TariffVersion::create(['station_id' => $station->id, 'created_by' => $this->admin->id, 'rate_minor_per_kwh' => 100, 'tax_bps' => 500, 'commission_bps' => 1000, 'currency' => 'AED', 'minor_units' => 2, 'effective_from' => now()->subMinute()]);
        $b = Reservation::create(['user_id' => $this->customer->id, 'station_id' => $station->id, 'starts_at' => now()->startOfHour(), 'status' => 'confirmed', 'idempotency_key' => (string) Str::uuid()]);
        $session = ChargingSession::create(['reservation_id' => $b->id, 'status' => 'stopped', 'energy_wh' => 10000, 'rate_minor_per_kwh' => 100, 'currency' => 'AED', 'total_minor' => 1000, 'stopped_at' => now()]);
        $finance = app(FinanceService::class);
        $finance->snapshotSession($session, $tariff);
        $invoice = $finance->issue($session, $this->customer->id);
        if ($paid) {
            $finance->pay($invoice, $this->customer->id, 1050, (string) Str::uuid());
        }

        return $invoice;
    }

    private function configure(int $hold = 0): void
    {
        $this->actingAs($this->admin)->putJson('/settlement-test/commercial/vendors/'.$this->vendor->id.'/schedule', ['currency' => 'AED', 'hold_days' => $hold, 'interval_days' => 30, 'minimum_minor' => 1, 'next_due_at' => now()->subMinute()->toIso8601String(), 'enabled' => true])->assertOk();
    }

    private function draft(string $key = 'draft'): array
    {
        return $this->actingAs($this->vendor)->postJson('/settlement-test/commercial/vendors/'.$this->vendor->id.'/settlements', ['currency' => 'AED', 'idempotency_key' => $key])->assertCreated()->json();
    }

    public function test_full_capture_commission_reservation_approval_and_payout_simulation_reconcile(): void
    {
        $this->invoice();
        $this->configure();
        $s = $this->draft();
        $this->assertSame(900, $s['amount_minor']);
        $this->assertSame($s['id'], $this->draft()['id']);
        $this->getJson('/settlement-test/commercial/vendors/'.$this->vendor->id.'/statement?currency=AED')->assertOk()->assertJsonPath('available_minor', 0)->assertJsonPath('real_payout_enabled', false);
        $base = '/settlement-test/commercial/settlements/'.$s['id'];
        $this->postJson($base.'/approve')->assertForbidden();
        $this->actingAs($this->admin)->postJson($base.'/simulate-payout')->assertConflict();
        $this->postJson($base.'/approve')->assertOk()->assertJsonPath('state', 'approved');
        $this->postJson($base.'/approve')->assertOk();
        $this->postJson($base.'/simulate-payout')->assertOk()->assertJsonPath('state', 'simulated_paid');
        $this->postJson($base.'/simulate-payout')->assertOk();
        $this->getJson($base)->assertOk()->assertJsonPath('reconciled', true)->assertJsonCount(3, 'events');
        $this->assertDatabaseCount('commercial_settlements', 1);
        $this->assertDatabaseCount('finance_payments', 1);
        $this->assertDatabaseCount('finance_journals', 2); // Settlement simulation never mutates invoice cash journals.
    }

    public function test_unpaid_partial_and_unmatured_invoices_are_not_payable(): void
    {
        $invoice = $this->invoice(false);
        $this->configure();
        $finance = app(FinanceService::class);
        $finance->pay($invoice, $this->customer->id, 500, 'part');
        $path = '/settlement-test/commercial/vendors/'.$this->vendor->id;
        $this->actingAs($this->vendor)->getJson($path.'/statement?currency=AED')->assertOk()->assertJsonPath('available_minor', 0);
        $this->postJson($path.'/settlements', ['currency' => 'AED', 'idempotency_key' => 'unpaid'])->assertConflict();
        $finance->pay($invoice, $this->customer->id, 550, 'rest');
        $this->configure(7);
        $this->actingAs($this->vendor)->postJson($path.'/settlements', ['currency' => 'AED', 'idempotency_key' => 'held'])->assertConflict();
        $this->travel(8)->days();
        $this->assertSame(900, $this->draft()['amount_minor']);
    }

    public function test_refund_after_reservation_blocks_approval_and_rejection_releases_revised_funds(): void
    {
        $invoice = $this->invoice();
        $this->configure();
        $s = $this->draft();
        app(FinanceService::class)->refund($invoice, $this->admin->id, 525, 'half', 'Half refund');
        $base = '/settlement-test/commercial/settlements/'.$s['id'];
        $this->actingAs($this->admin)->postJson($base.'/approve')->assertConflict();
        $this->postJson($base.'/reject')->assertOk()->assertJsonPath('state', 'rejected');
        $next = $this->draft('revised');
        $this->assertSame(450, $next['amount_minor']);
    }

    public function test_post_settlement_refund_debt_is_carried_forward_and_offset_against_next_earnings(): void
    {
        $invoice = $this->invoice();
        $this->configure();
        $s = $this->draft();
        $base = '/settlement-test/commercial/settlements/'.$s['id'];
        $this->actingAs($this->admin)->postJson($base.'/approve')->assertOk();
        $this->postJson($base.'/simulate-payout')->assertOk();
        app(FinanceService::class)->refund($invoice, $this->admin->id, 525, 'late-refund', 'Refund after simulated payout');
        $this->actingAs($this->vendor)->getJson('/settlement-test/commercial/vendors/'.$this->vendor->id.'/statement?currency=AED')->assertOk()->assertJsonPath('available_minor', -450);
        $this->invoice();
        $this->configure();
        $next = $this->draft('offset');
        $this->assertSame(450, $next['amount_minor']);
        $this->getJson('/settlement-test/commercial/settlements/'.$next['id'])->assertOk()->assertJsonPath('reconciled', true)->assertJsonCount(2, 'lines');
    }

    public function test_tenant_isolation_currency_and_admin_adjustment_dual_control(): void
    {
        $invoice = $this->invoice();
        $this->configure();
        $s = $this->draft();
        $other = User::factory()->create(['role' => 'vendor']);
        $path = '/settlement-test/commercial/vendors/'.$this->vendor->id;
        $this->actingAs($other)->getJson('/settlement-test/commercial/settlements/'.$s['id'])->assertNotFound();
        $this->getJson('/settlement-test/commercial/settlements')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($path.'/statement?currency=AED')->assertNotFound();
        $this->postJson($path.'/settlements', ['currency' => 'AED', 'idempotency_key' => 'other'])->assertNotFound();
        $this->actingAs($this->customer)->getJson('/settlement-test/commercial/settlements')->assertForbidden();
        $body = ['currency' => 'AED', 'amount_minor' => -100, 'reason' => 'Contract adjustment', 'idempotency_key' => 'adjust'];
        $a = $this->actingAs($this->admin)->postJson($path.'/adjustments', $body)->assertCreated()->json();
        $this->postJson($path.'/adjustments', $body)->assertCreated()->assertJsonPath('id', $a['id']);
        $this->postJson($path.'/adjustments', array_replace($body, ['amount_minor' => -101]))->assertConflict();
        $this->postJson('/settlement-test/commercial/adjustments/'.$a['id'].'/approve')->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'admin']))->postJson('/settlement-test/commercial/adjustments/'.$a['id'].'/approve')->assertOk();
        $this->postJson('/settlement-test/commercial/adjustments/'.$a['id'].'/approve')->assertOk();
        $this->actingAs($this->vendor)->getJson($path.'/statement?currency=AED')->assertOk()->assertJsonPath('available_minor', -100);
        $this->getJson($path.'/statement?currency=USD')->assertConflict();
        $this->assertDatabaseCount('commercial_adjustment_entries', 2);
        $this->assertSame(100, (int) DB::table('commercial_adjustment_entries')->sum('debit_minor'));
        $this->assertSame(100, (int) DB::table('commercial_adjustment_entries')->sum('credit_minor'));
        $this->expectException(QueryException::class);
        DB::table('commercial_adjustments')->where('id', $a['id'])->update(['amount_minor' => 0]);
    }

    public function test_statements_return_configured_three_digit_precision_and_reject_mixed_history(): void
    {
        DB::table('regions')->where('country', 'AE')->update(['currency' => 'KWD', 'minor_units' => 3]);
        $this->actingAs($this->vendor)->getJson('/settlement-test/commercial/vendors/'.$this->vendor->id.'/statement?currency=KWD')->assertOk()->assertJsonPath('minor_units', 3);
        $body = ['currency' => 'KWD', 'amount_minor' => 1250, 'reason' => 'Three decimal contract', 'idempotency_key' => 'precision'];
        $this->actingAs($this->admin)->postJson('/settlement-test/commercial/vendors/'.$this->vendor->id.'/adjustments', $body)->assertCreated()->assertJsonPath('minor_units', 3);
        DB::table('regions')->where('country', 'AE')->update(['minor_units' => 2]);
        $this->actingAs($this->vendor)->getJson('/settlement-test/commercial/vendors/'.$this->vendor->id.'/statement?currency=KWD')->assertConflict();
    }

    public function test_precision_migration_backfills_existing_adjustments_without_changing_economics(): void
    {
        $migration = require database_path('migrations/2026_10_28_120002_snapshot_adjustment_precision.php');
        $migration->down();
        $id = DB::table('commercial_adjustments')->insertGetId(['vendor_id' => $this->vendor->id, 'currency' => 'AED', 'amount_minor' => 333, 'reason' => 'Legacy metadata backfill', 'request_key' => hash('sha256', 'legacy'), 'created_by' => $this->admin->id, 'created_at' => now()]);
        $migration->up();
        $this->assertDatabaseHas('commercial_adjustments', ['id' => $id, 'amount_minor' => 333, 'minor_units' => 2]);
        $this->expectException(QueryException::class);
        DB::table('commercial_adjustments')->where('id', $id)->update(['amount_minor' => 1]);
    }

    public function test_precision_backfill_rejects_ambiguous_currency_before_schema_change(): void
    {
        $migration = require database_path('migrations/2026_10_28_120002_snapshot_adjustment_precision.php');
        $migration->down();
        DB::table('commercial_adjustments')->insert(['vendor_id' => $this->vendor->id, 'currency' => 'ZZZ', 'amount_minor' => 333, 'reason' => 'Unknown currency', 'request_key' => hash('sha256', 'unknown'), 'created_by' => $this->admin->id, 'created_at' => now()]);
        try {
            $migration->up();
            $this->fail('Unknown precision accepted');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Reconcile historical', $e->getMessage());
            $this->assertFalse(Schema::hasColumn('commercial_adjustments', 'minor_units'));
        }
    }

    public function test_simulated_payout_is_disabled_in_production(): void
    {
        $this->invoice();
        $this->configure();
        $s = $this->draft();
        $this->app->detectEnvironment(fn () => 'production');
        try {
            app(SettlementService::class)->transition($s['id'], $this->admin->id, 'approve');
            $this->fail('Production simulation accepted');
        } catch (HttpException $e) {
            $this->assertSame(503, $e->getStatusCode());
        }
    }
}
