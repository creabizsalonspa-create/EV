<?php

namespace Tests\Feature;

use App\Http\Controllers\VendorEarningsController;
use App\Models\ChargingSession;
use App\Models\Finance\TariffVersion;
use App\Models\Reservation;
use App\Models\Station;
use App\Models\User;
use App\Services\Commercial\SettlementService;
use App\Services\Finance\FinanceService;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Grammars\SQLiteGrammar;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
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
        $this->postJson($base.'/approve')->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('state', 'approved')
            ->assertJsonMissingPath('request_key');
        $this->postJson($base.'/approve')->assertOk();
        $this->postJson($base.'/simulate-payout')->assertOk()->assertJsonPath('state', 'simulated_paid');
        $this->postJson($base.'/simulate-payout')->assertOk();
        $this->getJson($base)->assertOk()->assertJsonPath('reconciled', true)->assertJsonCount(3, 'events');
        $this->assertDatabaseCount('commercial_settlements', 1);
        $this->assertDatabaseCount('finance_payments', 1);
        $this->assertDatabaseCount('finance_journals', 2); // Settlement simulation never mutates invoice cash journals.
    }

    public function test_revoked_vendor_or_admin_cannot_view_settlements_or_post_financial_transitions(): void
    {
        $this->invoice();
        $this->configure();
        $settlement = $this->draft('revoked-read');
        $base = '/settlement-test/commercial';
        $eventsBefore = DB::table('commercial_settlement_events')->count();
        $journalsBefore = DB::table('finance_journals')->count();
        $auditBefore = DB::table('audit_events')->count();

        foreach ([$this->vendor, $this->admin] as $reader) {
            $row = DB::table('users')->where('id', $reader->id)
                ->first(['role', 'account_status', 'auth_epoch', 'password']);
            $original = [
                'role' => $row->role,
                'account_status' => $row->account_status,
                'auth_epoch' => $row->auth_epoch,
                'password' => $row->password,
            ];
            $this->actingAs($reader);
            $this->getJson($base.'/settlements')->assertOk();
            $this->getJson($base.'/settlements/'.$settlement['id'])->assertOk();
            foreach ([
                ['role' => 'customer'],
                ['account_status' => 'suspended'],
                ['auth_epoch' => (int) $row->auth_epoch + 1],
                ['password' => Hash::make('rotated-settlement-session')],
            ] as $change) {
                DB::table('users')->where('id', $reader->id)->update($change);
                $this->getJson($base.'/settlements')->assertConflict();
                $this->getJson($base.'/settlements/'.$settlement['id'])->assertConflict();
                $this->getJson($base.'/vendors/'.$this->vendor->id.'/statement?currency=AED')
                    ->assertConflict();
                $this->postJson($base.'/vendors/'.$this->vendor->id.'/settlements', [
                    'currency' => 'AED',
                    'idempotency_key' => 'revoked-'.$reader->id,
                ])->assertConflict();
                if ($row->role === 'admin') {
                    $this->postJson($base.'/settlements/'.$settlement['id'].'/approve')
                        ->assertConflict();
                    $this->postJson($base.'/vendors/'.$this->vendor->id.'/adjustments', [
                        'currency' => 'AED', 'amount_minor' => 10,
                        'reason' => 'Must not post revoked adjustment',
                        'idempotency_key' => 'revoked-adjustment',
                    ])->assertConflict();
                }
                DB::table('users')->where('id', $reader->id)->update($original);
            }
        }

        $this->assertDatabaseHas('commercial_settlements', [
            'id' => $settlement['id'], 'state' => 'pending',
        ]);
        $this->assertSame($eventsBefore, DB::table('commercial_settlement_events')->count());
        $this->assertSame($journalsBefore, DB::table('finance_journals')->count());
        $this->assertSame($auditBefore, DB::table('audit_events')->count());
        $this->assertDatabaseCount('commercial_adjustments', 0);
    }

    public function test_private_settlement_and_adjustment_receipts_never_expose_replay_keys(): void
    {
        $this->invoice();
        $this->configure();
        $draft = $this->draft('private-finance-retry');
        $this->assertArrayNotHasKey('request_key', $draft);
        $this->assertArrayNotHasKey('fingerprint', $draft);
        $base = '/settlement-test/commercial';
        $this->actingAs($this->vendor)
            ->getJson($base.'/settlements')->assertOk()
            ->assertJsonPath('data.0.id', $draft['id'])
            ->assertJsonMissingPath('data.0.request_key');
        $this->getJson($base.'/settlements/'.$draft['id'])->assertOk()
            ->assertJsonPath('reconciled', true)
            ->assertJsonMissingPath('settlement.request_key');
        $this->postJson($base.'/vendors/'.$this->vendor->id.'/settlements', [
            'currency' => 'AED', 'idempotency_key' => 'private-finance-retry',
        ])->assertCreated()->assertJsonPath('id', $draft['id'])
            ->assertJsonMissingPath('request_key');

        $body = [
            'currency' => 'AED', 'amount_minor' => -20,
            'reason' => 'Synthetic source correction',
            'idempotency_key' => 'private-adjustment-retry',
        ];
        $url = $base.'/vendors/'.$this->vendor->id.'/adjustments';
        $adjustment = $this->actingAs($this->admin)
            ->postJson($url, $body)->assertCreated()
            ->assertJsonMissingPath('request_key')->json();
        $this->postJson($url, $body)->assertCreated()
            ->assertJsonPath('id', $adjustment['id'])
            ->assertJsonMissingPath('request_key');
        $this->assertSame(64, strlen(DB::table('commercial_settlements')
            ->where('id', $draft['id'])->value('request_key')));
        $this->assertSame(64, strlen(DB::table('commercial_adjustments')
            ->where('id', $adjustment['id'])->value('request_key')));
        $this->assertDatabaseCount('commercial_settlements', 1);
        $this->assertDatabaseCount('commercial_adjustments', 1);
        $this->assertDatabaseCount('finance_payments', 1);
        $this->assertDatabaseCount('finance_journals', 2);
    }

    public function test_unreconciled_pending_settlement_cannot_be_approved_but_may_be_rejected(): void
    {
        $this->invoice();
        $this->configure();

        // Synthetic historical reservation with no supporting lines.
        // Do not change or bypass any immutable financial source.
        $id = DB::table('commercial_settlements')->insertGetId([
            'number' => 'SIM-SET-UNRECONCILED-TEST', 'vendor_id' => $this->vendor->id,
            'currency' => 'AED', 'minor_units' => 2, 'amount_minor' => 100,
            'state' => 'pending', 'request_key' => hash('sha256', 'unreconciled-pending-test'),
            'created_by' => $this->vendor->id, 'evidence' => 'SIMULATED',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $base = '/settlement-test/commercial/settlements/'.$id;
        $before = DB::table('finance_journals')->count();
        $this->actingAs($this->admin)->getJson($base)->assertOk()
            ->assertJsonPath('reconciled', false);
        $this->postJson($base.'/approve')->assertConflict();
        $this->assertDatabaseHas('commercial_settlements', [
            'id' => $id, 'state' => 'pending',
        ]);
        $this->assertDatabaseCount('commercial_settlement_events', 0);
        $this->postJson($base.'/reject')->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('state', 'rejected')
            ->assertJsonMissingPath('request_key')
            ->assertJsonMissingPath('fingerprint');
        $this->assertSame($before, DB::table('finance_journals')->count());
    }

    public function test_settlement_detail_reconciliation_refuses_lossy_sums(): void
    {
        $service = app(SettlementService::class);
        $statement = (object) ['amount_minor' => 100];
        $this->assertTrue($service->reconciles($statement, [
            (object) ['amount_minor' => 150],
            (object) ['amount_minor' => -50],
        ]));
        $this->assertFalse($service->reconciles($statement, [
            (object) ['amount_minor' => 99],
        ]));
        foreach ([
            [(object) ['amount_minor' => 100.0], []],
            [$statement, [(object) ['amount_minor' => 100.0]]],
            [$statement, [
                (object) ['amount_minor' => 4_503_599_627_370_496],
                (object) ['amount_minor' => 4_503_599_627_370_496],
            ]],
        ] as [$source, $lines]) {
            try {
                $service->reconciles($source, $lines);
                $this->fail('Inexact settlement detail was reported reconciled.');
            } catch (HttpException $error) {
                $this->assertSame(409, $error->getStatusCode());
            }
        }
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

    public function test_suspended_or_demoted_vendor_holds_new_settlement_and_execution_but_allows_rejection(): void
    {
        $this->invoice();
        $this->configure();
        $pending = $this->draft('before-moderation');
        $beforeJournals = DB::table('finance_journals')->count();
        $base = '/settlement-test/commercial';
        $path = $base.'/settlements/'.$pending['id'];
        DB::table('users')->where('id', $this->vendor->id)
            ->update(['account_status' => 'suspended']);

        $this->actingAs($this->admin)->postJson($path.'/approve')->assertConflict();
        $this->postJson($path.'/simulate-payout')->assertConflict();
        $this->postJson($base.'/vendors/'.$this->vendor->id.'/settlements', [
            'currency' => 'AED', 'idempotency_key' => 'must-not-draft-suspended',
        ])->assertConflict();
        // A private Admin can still reject an old reservation and release
        // its funds for supervised resolution, never paying a suspended vendor.
        $this->postJson($path.'/reject')->assertOk()
            ->assertJsonPath('state', 'rejected');

        DB::table('users')->where('id', $this->vendor->id)
            ->update(['account_status' => 'active']);
        $approved = $this->postJson(
            $base.'/vendors/'.$this->vendor->id.'/settlements',
            ['currency' => 'AED', 'idempotency_key' => 'after-reviewed-reinstatement']
        )->assertCreated()->json();
        $approvedPath = $base.'/settlements/'.$approved['id'];
        $this->postJson($approvedPath.'/approve')->assertOk()
            ->assertJsonPath('state', 'approved');

        DB::table('users')->where('id', $this->vendor->id)
            ->update(['role' => 'customer']);
        $this->postJson($approvedPath.'/simulate-payout')->assertConflict();
        $this->postJson($approvedPath.'/reject')->assertOk()
            ->assertJsonPath('state', 'rejected');

        $this->assertDatabaseCount('commercial_settlements', 2);
        $this->assertSame(2, DB::table('commercial_settlements')
            ->where('state', 'rejected')->count());
        $this->assertDatabaseCount('commercial_payouts', 0);
        $this->assertDatabaseCount('commercial_settlement_events', 5);
        $this->assertSame($beforeJournals, DB::table('finance_journals')->count());
        $this->assertDatabaseCount('finance_refunds', 0);
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

    public function test_legacy_sqlite_precision_rollback_preserves_update_and_delete_guards(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Exercises the legacy SQLite table-rebuild path.');
        }

        $connection = DB::connection();
        $connection->getSchemaBuilder();
        $grammar = $connection->getSchemaGrammar();
        $legacy = new class($connection->getPdo(), '', '', $connection->getConfig()) extends SQLiteConnection
        {
            public function getServerVersion(): string
            {
                return '3.34.0';
            }
        };
        $connection->setSchemaGrammar(new SQLiteGrammar($legacy));
        $migration = require database_path('migrations/2026_10_28_120002_snapshot_adjustment_precision.php');
        try {
            $migration->down();
            $this->assertFalse(Schema::hasColumn('commercial_adjustments', 'minor_units'));
            $id = DB::table('commercial_adjustments')->insertGetId(['vendor_id' => $this->vendor->id, 'currency' => 'AED', 'amount_minor' => 333, 'reason' => 'Legacy SQLite backfill', 'request_key' => hash('sha256', 'legacy-sqlite'), 'created_by' => $this->admin->id, 'created_at' => now()]);
            foreach (['rollback', 'reapplied'] as $stage) {
                foreach (['update', 'delete'] as $operation) {
                    try {
                        $query = DB::table('commercial_adjustments')->where('id', $id);
                        $operation === 'update' ? $query->update(['amount_minor' => 1]) : $query->delete();
                        $this->fail("Missing {$operation} immutability guard after {$stage}");
                    } catch (QueryException $e) {
                        $this->assertStringContainsString('Settlement records are immutable', $e->getMessage());
                    }
                }
                if ($stage === 'rollback') {
                    $migration->up();
                    $this->assertDatabaseHas('commercial_adjustments', ['id' => $id, 'amount_minor' => 333, 'minor_units' => 2]);
                }
            }
        } finally {
            $connection->setSchemaGrammar($grammar);
        }
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

    public function test_pending_refund_or_dispute_holds_earnings_and_approved_settlement_can_be_rejected(): void
    {
        $invoice = $this->invoice();
        $this->configure();
        $settlement = $this->draft();
        $this->actingAs($this->admin)->postJson('/settlement-test/commercial/settlements/'.$settlement['id'].'/approve')->assertOk();
        $request = $this->actingAs($this->customer)->postJson('/api/finance/invoices/'.$invoice->id.'/refund-requests', ['amount_minor' => 10, 'reason' => 'Issue', 'idempotency_key' => 'hold'])->assertCreated()->json();
        $this->actingAs($this->admin)->postJson('/settlement-test/commercial/settlements/'.$settlement['id'].'/simulate-payout')->assertConflict();
        $this->postJson('/settlement-test/commercial/settlements/'.$settlement['id'].'/reject')->assertOk();
        $this->getJson('/settlement-test/commercial/vendors/'.$this->vendor->id.'/statement?currency=AED')->assertJsonPath('available_minor', 0);
        $this->postJson('/api/finance/refund-requests/'.$request['id'].'/review', ['decision' => 'reject', 'note' => 'Resolved'])->assertOk();
        $case = $this->actingAs($this->customer)->postJson('/api/commercial/cases', ['invoice_id' => $invoice->id, 'kind' => 'dispute', 'amount_minor' => 100, 'reason' => 'Disputed', 'idempotency_key' => 'hold'])->assertCreated()->json();
        $this->actingAs($this->admin)->getJson('/settlement-test/commercial/vendors/'.$this->vendor->id.'/statement?currency=AED')->assertJsonPath('available_minor', 0);
        $this->postJson('/api/commercial/cases/'.$case['id'].'/resolve', ['outcome' => 'rejected', 'resolution' => 'Resolved'])->assertOk();
        $this->getJson('/settlement-test/commercial/vendors/'.$this->vendor->id.'/statement?currency=AED')->assertJsonPath('available_minor', 900);
    }

    public function test_settlement_amounts_refuse_float_coercion_and_overflow_before_booking(): void
    {
        $minor = new \ReflectionMethod(SettlementService::class, 'exactMinor');
        $add = new \ReflectionMethod(SettlementService::class, 'addMinor');
        $max = 9_007_199_254_740_991;

        $this->assertSame($max, $minor->invoke(null, (string) $max));
        $this->assertSame(-$max, $minor->invoke(null, (string) -$max, true));
        $this->assertSame($max, $add->invoke(null, $max - 5, 5));
        $this->assertSame(-100, $add->invoke(null, -150, 50, true));

        foreach ([100.0, -100.0, true, false, '3.1',
            '1e3', '9007199254740992', -1] as $bad) {
            try {
                $minor->invoke(null, $bad);
                $this->fail('Inexact settlement source amount was accepted.');
            } catch (HttpException $error) {
                $this->assertSame(409, $error->getStatusCode());
            }
        }
        foreach ([
            [$max, 1, false],
            [-$max, -1, true],
            [0, -1, false],
        ] as [$left, $right, $signed]) {
            try {
                $add->invoke(null, $left, $right, $signed);
                $this->fail('Unbounded settlement aggregation was accepted.');
            } catch (HttpException $error) {
                $this->assertSame(409, $error->getStatusCode());
            }
        }
    }

    public function test_overcaptured_import_blocks_statements_earnings_and_new_settlements(): void
    {
        $invoice = $this->invoice();
        $this->configure();
        $base = '/settlement-test/commercial/vendors/'.$this->vendor->id;
        $this->actingAs($this->vendor)->getJson($base.'/statement?currency=AED')->assertOk()
            ->assertJsonPath('available_minor', 900);
        // Insert a contradictory historical receipt without disabling immutable
        // storage. The original invoice was already fully paid.
        $journal = DB::table('finance_journals')->insertGetId([
            'invoice_id' => $invoice->id, 'kind' => 'payment',
            'idempotency_key' => 'settlement-import-overcapture',
            'fingerprint' => hash('sha256', 'settlement-import-overcapture'),
            'currency' => 'AED', 'amount_minor' => 1, 'created_at' => now(),
        ]);
        DB::table('finance_entries')->insert([
            ['journal_id' => $journal, 'account' => 'cash:simulator', 'debit_minor' => 1, 'credit_minor' => 0, 'created_at' => now()],
            ['journal_id' => $journal, 'account' => 'receivable:user:'.$this->customer->id, 'debit_minor' => 0, 'credit_minor' => 1, 'created_at' => now()],
        ]);
        DB::table('finance_payments')->insert([
            'invoice_id' => $invoice->id, 'journal_id' => $journal,
            'actor_id' => $this->customer->id, 'amount_minor' => 1,
            'evidence' => 'SIMULATED', 'created_at' => now(),
        ]);
        $before = DB::table('finance_journals')->count();
        $this->getJson($base.'/statement?currency=AED')->assertConflict();
        $this->getJson($base.'/earnings?currency=AED')->assertConflict();
        $this->postJson($base.'/settlements', [
            'currency' => 'AED', 'idempotency_key' => 'overcapture-protected-draft',
        ])->assertConflict();
        $this->assertDatabaseCount('commercial_settlements', 0);
        $this->assertDatabaseCount('finance_payments', 2);
        $this->assertDatabaseCount('finance_journals', $before);
    }

    public function test_invoice_allocation_and_cumulative_reversal_bounds_are_exact(): void
    {
        $bounds = new \ReflectionMethod(SettlementService::class, 'requireInvoiceBounds');
        // Original invoice 1050 = 900 vendor + 100 commission + 50 tax.
        foreach ([
            [1050, 1000, 900, 100, 50, 1050, 0, 0, 0, 0, 0],
            [1050, 1000, 900, 100, 50, 1050, 0, 525, 450, 50, 25],
            [1050, 1000, 900, 100, 50, 0, 1050, 0, 0, 0, 0],
        ] as $valid) {
            $bounds->invoke(null, ...$valid);
            $this->addToAssertionCount(1);
        }
        foreach ([
            [1050, 1000, 899, 100, 50, 1050, 0, 0, 0, 0, 0],
            [1050, 1000, 900, 100, 49, 1050, 0, 0, 0, 0, 0],
            [1050, 1000, 900, 100, 50, 1051, 0, 0, 0, 0, 0],
            [1050, 1000, 900, 100, 50, 1, 1050, 0, 0, 0, 0],
            [1050, 1000, 900, 100, 50, 10, 0, 11, 11, 0, 0],
            [1050, 1000, 900, 100, 50, 1050, 0, 901, 901, 0, 0],
            [1050, 1000, 900, 100, 50, 1050, 0, 101, 0, 101, 0],
            [1050, 1000, 900, 100, 50, 1050, 0, 51, 0, 0, 51],
            [1050, 1000, 900, 100, 50, 1050, 0, 50, 49, 0, 0],
        ] as $invalid) {
            try {
                $bounds->invoke(null, ...$invalid);
                $this->fail('Inconsistent settlement invoice amounts were accepted.');
            } catch (HttpException $error) {
                $this->assertSame(409, $error->getStatusCode());
            }
        }
    }

    public function test_checked_balance_rejects_cross_adjustment_overflow_before_any_payable_draft(): void
    {
        $this->configure();
        $beforeJournals = DB::table('finance_journals')->count();
        $reviewer = User::factory()->create(['role' => 'admin']);
        // Source-integrity fixture only: two individually representable
        // immutable signed adjustments whose aggregate exceeds JS precision.
        // These are NOT credited as a real admin workflow or payout.
        foreach (['first', 'second'] as $label) {
            $id = DB::table('commercial_adjustments')->insertGetId([
                'vendor_id' => $this->vendor->id,
                'currency' => 'AED', 'minor_units' => 2,
                'amount_minor' => 4_503_599_627_370_496,
                'reason' => 'Synthetic overflow source '.$label,
                'request_key' => hash('sha256', 'settlement-bound-'.$label),
                'created_by' => $this->admin->id, 'created_at' => now(),
            ]);
            DB::table('commercial_adjustment_approvals')->insert([
                'adjustment_id' => $id, 'approved_by' => $reviewer->id,
                'created_at' => now(),
            ]);
        }

        $base = '/settlement-test/commercial/vendors/'.$this->vendor->id;
        $this->actingAs($this->vendor)
            ->getJson($base.'/statement?currency=AED')->assertConflict();
        $this->getJson($base.'/earnings?currency=AED')->assertConflict();
        $this->postJson($base.'/settlements', [
            'currency' => 'AED', 'idempotency_key' => 'overflow-protected-draft',
        ])->assertConflict();
        $this->assertDatabaseCount('commercial_settlements', 0);
        $this->assertSame($beforeJournals, DB::table('finance_journals')->count());
        $this->assertDatabaseCount('finance_payments', 0);
    }

    public function test_vendor_earnings_report_reuses_settlement_policy_and_refund_debt(): void
    {
        $invoice = $this->invoice();
        $this->configure();
        $path = '/settlement-test/commercial/vendors/'.$this->vendor->id.'/earnings?currency=AED';

        $this->actingAs($this->vendor)->getJson($path)->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.invoice_id', $invoice->id)
            ->assertJsonPath('data.0.evidence', $invoice->evidence)
            ->assertJsonPath('data.0.subtotal_minor', 1000)
            ->assertJsonPath('data.0.commission_minor', 100)
            ->assertJsonPath('data.0.vendor_allocated_minor', 900)
            ->assertJsonPath('data.0.paid_minor', 1050)
            ->assertJsonPath('data.0.available_minor', 900)
            ->assertJsonPath('overall_available_minor', 900)
            ->assertJsonPath('external_psp_or_bank_verified', false);

        $settlement = $this->draft('earnings');
        $this->getJson($path)->assertOk()
            ->assertJsonPath('data.0.available_minor', 0)
            ->assertJsonPath('data.0.pending_reserved_minor', 900);
        $base = '/settlement-test/commercial/settlements/'.$settlement['id'];
        $this->actingAs($this->admin)->postJson($base.'/approve')->assertOk();
        $this->postJson($base.'/simulate-payout')->assertOk();
        app(FinanceService::class)->refund(
            $invoice, $this->admin->id, 525, 'earnings-refund', 'Credit after settlement'
        );

        $this->actingAs($this->vendor)->getJson($path)->assertOk()
            ->assertJsonPath('data.0.refunded_minor', 525)
            ->assertJsonPath('data.0.vendor_refunded_minor', 450)
            ->assertJsonPath('data.0.completed_settlement_minor', 900)
            ->assertJsonPath('data.0.pending_reserved_minor', 0)
            ->assertJsonPath('data.0.available_minor', -450)
            ->assertJsonPath('overall_available_minor', -450)
            ->assertJsonPath('real_payout_enabled', false);
        $this->assertDatabaseCount('finance_journals', 3);
    }

    public function test_revoked_vendor_or_admin_cannot_read_private_earnings_or_csv(): void
    {
        $this->invoice();
        $this->configure();
        $url = '/settlement-test/commercial/vendors/'.$this->vendor->id.'/earnings?currency=AED';
        $csv = '/settlement-test/commercial/vendors/'.$this->vendor->id.'/earnings/export?currency=AED';
        $before = DB::table('finance_journals')->count();

        foreach ([$this->vendor, $this->admin] as $actor) {
            $row = DB::table('users')->where('id', $actor->id)
                ->first(['role', 'account_status', 'auth_epoch', 'password']);
            $original = [
                'role' => $row->role,
                'account_status' => $row->account_status,
                'auth_epoch' => $row->auth_epoch,
                'password' => $row->password,
            ];
            $this->actingAs($actor);
            $this->getJson($url)->assertOk()->assertJsonPath('total', 1);
            foreach ([
                ['role' => 'customer'],
                ['account_status' => 'suspended'],
                ['auth_epoch' => (int) $row->auth_epoch + 1],
                ['password' => Hash::make('revoked-earnings-export-session')],
            ] as $change) {
                DB::table('users')->where('id', $actor->id)->update($change);
                $this->getJson($url)->assertConflict();
                $this->get($csv)->assertConflict();
                DB::table('users')->where('id', $actor->id)->update($original);
            }
        }

        $this->assertSame($before, DB::table('finance_journals')->count());
        $this->assertDatabaseCount('commercial_settlements', 0);
    }

    public function test_earnings_export_is_page_bounded_currency_exact_and_tenant_private(): void
    {
        $invoice = $this->invoice();
        $this->configure();
        $base = '/settlement-test/commercial/vendors/'.$this->vendor->id.'/earnings';
        $other = User::factory()->create(['role' => 'vendor']);
        $this->actingAs($other)->getJson($base.'?currency=AED')->assertNotFound();
        $this->get($base.'/export?currency=AED')->assertNotFound();
        $this->actingAs($this->customer)->getJson($base.'?currency=AED')->assertNotFound();
        $this->actingAs($this->vendor)->getJson($base)->assertUnprocessable();
        $this->getJson($base.'?currency=AED&per_page=26')->assertUnprocessable();
        $this->getJson($base.'?currency=AED&from=2027-01-02&to=2027-01-01')->assertUnprocessable();
        $this->getJson($base.'?currency=AED&from=2035-01-01')->assertOk()->assertJsonCount(0, 'data');
        $export = $this->get($base.'/export?currency=AED&per_page=1');
        $export->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $csv = $export->streamedContent();
        $this->assertStringContainsString($invoice->number, $csv);
        $this->assertStringContainsString('SIMULATED', $csv);
        $this->actingAs($this->admin)->getJson($base.'?currency=AED')
            ->assertOk()->assertJsonPath('data.0.invoice_id', $invoice->id);
        $this->assertDatabaseCount('finance_journals', 2);
    }

    public function test_vendor_earnings_json_and_plain_csv_reject_unbounded_or_non_numeric_pages(): void
    {
        $invoice = $this->invoice();
        $this->configure();
        $path = '/settlement-test/commercial/vendors/'.$this->vendor->id.'/earnings';
        $this->actingAs($this->vendor);
        $before = DB::table('finance_journals')->count();

        foreach (['page=0', 'page=-1', 'page=10001', 'page=not-a-number'] as $filter) {
            $this->getJson($path.'?currency=AED&'.$filter)->assertUnprocessable();
            $this->get($path.'/export?currency=AED&'.$filter)
                ->assertUnprocessable()->assertHeader('Cache-Control', 'no-store, private');
        }

        $this->getJson($path.'?currency=AED&per_page=1&page=10000')
            ->assertOk()->assertJsonPath('total', 1)->assertJsonCount(0, 'data');
        $csv = $this->get($path.'/export?currency=AED&per_page=1&page=10000');
        $csv->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringNotContainsString($invoice->number, $csv->streamedContent());
        $this->assertSame($before, DB::table('finance_journals')->count());
    }

    public function test_vendor_earnings_rejects_unsafe_financial_numbers_without_mutating_immutable_invoices(): void
    {
        $invoice = $this->invoice();
        $this->configure();
        $path = '/settlement-test/commercial/vendors/'.$this->vendor->id.'/earnings';
        $before = DB::table('finance_journals')->count();

        // Issued finance invoices are protected by SQLite/PostgreSQL
        // immutability triggers: do not bypass those safeguards in tests.
        // Exercise the exact primitive on malformed external aggregates instead.
        $minor = new \ReflectionMethod(VendorEarningsController::class, 'minor');
        foreach ([9007199254740992, -9007199254740992, 'not-a-number', 1.5, 100.0, -100.0, true, false] as $unsafe) {
            try {
                $minor->invoke(null, $unsafe, true);
                $this->fail('Inexact source value reached financial output.');
            } catch (HttpException $e) {
                $this->assertSame(409, $e->getStatusCode());
            }
        }
        $this->assertSame(-450, $minor->invoke(null, -450, true));
        try {
            $minor->invoke(null, -1, false);
            $this->fail('Negative unsigned amount accepted.');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }

        $this->actingAs($this->vendor)->getJson($path.'?currency=AED')
            ->assertOk()->assertJsonPath('total', 1);
        $this->get($path.'/export?currency=AED')->assertOk();
        $this->assertDatabaseHas('finance_invoices', [
            'id' => $invoice->id, 'subtotal_minor' => 1000,
        ]);
        $this->assertSame($before, DB::table('finance_journals')->count());
    }

    public function test_vendor_earnings_csv_encodes_unsafe_numbers_as_spreadsheet_literals_only(): void
    {
        $invoice = $this->invoice();
        $this->configure();
        $path = '/settlement-test/commercial/vendors/'.$this->vendor->id.'/earnings';
        $before = DB::table('finance_journals')->count();

        // Invoice/provider evidence share the CSV-only cell encoder; never change a signed,
        // immutable finance invoice to a fabricated provider filename.
        $cell = new \ReflectionMethod(VendorEarningsController::class, 'csvLiteral');
        foreach (['=HYPERLINK("https://untrusted.test","open")', '+SUM(1,2)',
            '-cmd', '@danger', "\t=malicious", "\u{200B}=hidden",
            "\u{202E}@bidi", "\u{FEFF}+bom", "\u{00A0}=nbsp"] as $formula) {
            $this->assertSame("'".$formula, $cell->invoke(null, $formula));
        }
        $this->assertSame('INV-2026-AE-0001', $cell->invoke(null, 'INV-2026-AE-0001'));

        $this->actingAs($this->vendor)
            ->getJson($path.'?currency=AED')->assertOk()
            ->assertJsonPath('data.0.invoice_number', $invoice->number);
        $csv = $this->get($path.'/export?currency=AED');
        $csv->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString($invoice->number, $csv->streamedContent());
        $this->assertSame($before, DB::table('finance_journals')->count());
    }
}
