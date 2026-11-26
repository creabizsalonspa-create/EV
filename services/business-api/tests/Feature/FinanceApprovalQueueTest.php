<?php

namespace Tests\Feature;

use App\Http\Controllers\FinanceApprovalQueueController;
use App\Models\ChargingSession;
use App\Models\Reservation;
use App\Models\Station;
use App\Models\User;
use App\Services\Finance\FinanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class FinanceApprovalQueueTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $vendor;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();
        Route::middleware(['web', 'auth', 'throttle:platform-api'])
            ->prefix('approval-test')->group(base_path('routes/commercial_finance.php'));
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->vendor = User::factory()->create(['role' => 'vendor']);
        $this->customer = User::factory()->create();
    }

    private function base(): string
    {
        return '/approval-test/finance/approval-queue';
    }

    public function test_indexed_canonical_review_sources_on_sqlite_and_postgresql(): void
    {
        foreach ([
            'finance_refund_requests' => 'fin_ref_req_status_created_idx',
            'commercial_settlements' => 'comm_settle_state_created_idx',
            'commercial_adjustments' => 'comm_adjust_created_idx',
        ] as $table => $expected) {
            $indexes = DB::getDriverName() === 'pgsql'
                ? DB::select('SELECT indexname AS name FROM pg_indexes WHERE schemaname = current_schema() AND tablename = ?', [$table])
                : DB::select("PRAGMA index_list('".$table."')");
            $names = array_map(static fn ($index) => $index->name, $indexes);
            $this->assertContains($expected, $names, 'Missing review source index for '.$table);
        }
    }

    private function seedApprovals(): array
    {
        $station = Station::create([
            'vendor_id' => $this->vendor->id, 'name' => 'Finance approvals',
            'country' => 'AE', 'currency' => 'AED', 'timezone' => 'Asia/Dubai',
            'latitude' => 25, 'longitude' => 55, 'source' => 'simulator',
            'connector_type' => 'CCS2', 'power_kw' => 50,
        ]);
        $booking = Reservation::create([
            'user_id' => $this->customer->id, 'station_id' => $station->id,
            'starts_at' => now(), 'idempotency_key' => (string) Str::uuid(),
        ]);
        $session = ChargingSession::create([
            'reservation_id' => $booking->id, 'status' => 'stopped',
            'energy_wh' => 1000, 'rate_minor_per_kwh' => 100, 'currency' => 'AED',
            'total_minor' => 100, 'stopped_at' => now(),
        ]);
        $finance = app(FinanceService::class);
        $invoice = $finance->issue($session, $this->customer->id);
        $finance->pay($invoice, $this->customer->id, 100, 'approval-queue-payment');
        $refund = $this->actingAs($this->customer)->postJson(
            '/approval-test/finance/invoices/'.$invoice->id.'/refund-requests',
            ['amount_minor' => 25, 'reason' => 'Private customer complaint',
                'idempotency_key' => 'approval-queue-refund'],
        )->assertCreated()->json('id');

        $settlement = DB::table('commercial_settlements')->insertGetId([
            'number' => 'SIM-SET-APPROVAL-QUEUE',
            'vendor_id' => $this->vendor->id,
            'currency' => 'AED', 'minor_units' => 2, 'amount_minor' => 60,
            'state' => 'pending', 'request_key' => hash('sha256', 'approval-queue-settlement'),
            'created_by' => $this->vendor->id, 'evidence' => 'SIMULATED',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $adjustment = DB::table('commercial_adjustments')->insertGetId([
            'vendor_id' => $this->vendor->id, 'currency' => 'AED',
            'amount_minor' => -10, 'minor_units' => 2, 'reason' => 'Private merchant adjustment',
            'request_key' => hash('sha256', 'approval-queue-adjustment'),
            'created_by' => $this->vendor->id, 'created_at' => now(),
        ]);
        $approved = DB::table('commercial_adjustments')->insertGetId([
            'vendor_id' => $this->vendor->id, 'currency' => 'AED',
            'amount_minor' => 50, 'minor_units' => 2, 'reason' => 'Already approved and not pending',
            'request_key' => hash('sha256', 'approval-queue-approved'),
            'created_by' => $this->vendor->id, 'created_at' => now(),
        ]);
        DB::table('commercial_adjustment_approvals')->insert([
            'adjustment_id' => $approved, 'approved_by' => $this->admin->id,
            'created_at' => now(),
        ]);

        return [$invoice, $refund, $settlement, $adjustment];
    }

    public function test_financial_review_source_exact_minor_contract_rejects_hidden_type_coercion(): void
    {
        $exact = new \ReflectionMethod(
            FinanceApprovalQueueController::class,
            'exactMinor'
        );
        $this->assertSame(-9_007_199_254_740_991,
            $exact->invoke(null, '-9007199254740991'));
        $this->assertSame(9_007_199_254_740_991,
            $exact->invoke(null, '9007199254740991'));
        $this->assertSame(0, $exact->invoke(null, 0));
        foreach ([
            100.0, -100.0, true, false, 0.1, '1.0', '1e2',
            '9007199254740992', '-9007199254740992', 'invalid',
        ] as $unsafe) {
            try {
                $exact->invoke(null, $unsafe);
                $this->fail('Unsafe Admin finance source became an exact-looking amount.');
            } catch (HttpException $error) {
                $this->assertSame(409, $error->getStatusCode());
            }
        }

        $this->seedApprovals();
        $before = DB::table('finance_journals')->count();
        $this->actingAs($this->admin)
            ->getJson($this->base().'?per_page=1&page=2')->assertOk()
            ->assertJsonCount(3, 'summary.groups')
            ->assertJsonPath('summary.not_a_payment_or_payout_authority', true);
        $this->assertSame($before, DB::table('finance_journals')->count());
        $this->assertDatabaseCount('finance_refunds', 0);
    }

    public function test_admin_only_review_unifies_pending_sources_without_financial_mutation(): void
    {
        [$invoice, $refund, $settlement, $adjustment] = $this->seedApprovals();
        $this->actingAs($this->vendor)->getJson($this->base())->assertForbidden();
        $this->actingAs($this->customer)->getJson($this->base())->assertForbidden();

        $response = $this->actingAs($this->admin)->getJson($this->base())
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('total', 3)
            ->assertJsonPath('read_only', true)
            ->assertJsonPath('admin_only', true)
            ->assertJsonPath('mutations_performed', false)
            ->assertJsonPath('real_money_moved', false)
            ->assertJsonPath('not_a_payment_or_payout_authority', true);
        $rows = collect($response->json('data'))->keyBy('kind');
        $this->assertEqualsCanonicalizing(['refund', 'settlement', 'adjustment'], $rows->keys()->all());
        $this->assertSame($refund, $rows['refund']['record_id']);
        $this->assertSame($invoice->id, $rows['refund']['invoice_id']);
        $this->assertSame(25, $rows['refund']['amount_minor']);
        $this->assertSame(2, $rows['refund']['minor_units']);
        $this->assertSame($settlement, $rows['settlement']['record_id']);
        $this->assertNull($rows['settlement']['invoice_id']);
        $this->assertSame($adjustment, $rows['adjustment']['record_id']);
        $this->assertSame(-10, $rows['adjustment']['amount_minor']);
        $this->assertSame(2, $rows['adjustment']['minor_units']);
        foreach (['Private customer complaint', 'Private merchant adjustment',
            'idempotency_key', 'request_key', 'provider_reference'] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
        }
        $this->assertDatabaseCount('finance_journals', 2);
        $this->assertDatabaseCount('finance_refunds', 0);
        $this->assertDatabaseCount('commercial_settlement_events', 0);
        $this->assertDatabaseCount('commercial_adjustment_approvals', 1);
    }

    public function test_admin_approval_summary_is_all_filtered_pending_source_and_currency_isolated_not_a_payout(): void
    {
        [$invoice, $refund, $settlement, $adjustment] = $this->seedApprovals();
        $preJournal = DB::table('finance_journals')->count();
        $preRefunds = DB::table('finance_refunds')->count();
        $preSettlements = DB::table('commercial_settlement_events')->count();
        $preApproval = DB::table('commercial_adjustment_approvals')->count();

        $this->actingAs($this->admin);
        $page = $this->getJson($this->base().'?per_page=1&page=2')
            ->assertOk()->assertJsonPath('total', 3)
            ->assertJsonCount(1, 'data')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('summary.scope',
                'ALL_CURRENT_FILTERED_PENDING_RECORDS_NOT_PAGE')
            ->assertJsonPath('summary.currency_and_precision_isolated', true)
            ->assertJsonPath('summary.not_a_payment_or_payout_authority', true)
            ->assertJsonCount(3, 'summary.groups');
        $groups = collect($page->json('summary.groups'))->keyBy('kind');
        foreach (['refund', 'settlement', 'adjustment'] as $kind) {
            $this->assertSame('AED', $groups[$kind]['currency']);
            $this->assertSame(2, $groups[$kind]['minor_units']);
            $this->assertSame(1, $groups[$kind]['review_count']);
            $this->assertSame(
                'PENDING_RECORDS_NOT_FINANCIAL_BALANCE',
                $groups[$kind]['basis']
            );
        }
        $this->assertSame(25, $groups['refund']['signed_amount_minor']);
        $this->assertSame(60, $groups['settlement']['signed_amount_minor']);
        $this->assertSame(-10, $groups['adjustment']['signed_amount_minor']);

        $refundOnly = $this->getJson($this->base().'?kind=refund&per_page=1')
            ->assertOk()->assertJsonCount(1, 'summary.groups')
            ->assertJsonPath('summary.groups.0.kind', 'refund')
            ->assertJsonPath('summary.groups.0.signed_amount_minor', 25)
            ->assertJsonPath('summary.groups.0.review_count', 1);
        $this->assertStringNotContainsString(
            'Private customer complaint', $refundOnly->getContent()
        );
        $this->getJson($this->base().'?currency=USD')
            ->assertOk()->assertJsonPath('total', 0)
            ->assertJsonCount(0, 'summary.groups');
        $this->getJson($this->base().'?vendor_id='.($this->vendor->id + 99999))
            ->assertOk()->assertJsonCount(0, 'summary.groups');
        $this->assertSame($preJournal, DB::table('finance_journals')->count());
        $this->assertSame($preRefunds, DB::table('finance_refunds')->count());
        $this->assertSame($preSettlements, DB::table('commercial_settlement_events')->count());
        $this->assertSame($preApproval, DB::table('commercial_adjustment_approvals')->count());
    }

    public function test_revoked_admin_session_cannot_read_approval_queue_or_export(): void
    {
        $this->seedApprovals();
        $this->actingAs($this->admin);
        $this->getJson($this->base())->assertOk();
        $record = DB::table('users')->where('id', $this->admin->id)
            ->first(['role', 'account_status', 'auth_epoch', 'password']);
        $original = [
            'role' => $record->role,
            'account_status' => $record->account_status,
            'auth_epoch' => $record->auth_epoch,
            'password' => $record->password,
        ];
        $beforeAudits = DB::table('audit_events')->count();
        foreach ([
            ['role' => 'vendor'],
            ['account_status' => 'suspended'],
            ['auth_epoch' => (int) $record->auth_epoch + 1],
            ['password' => Hash::make('rotated-approval-session')],
        ] as $change) {
            DB::table('users')->where('id', $this->admin->id)->update($change);
            $this->getJson($this->base())->assertConflict();
            $this->get($this->base().'/export')->assertConflict();
            DB::table('users')->where('id', $this->admin->id)->update($original);
        }
        $this->assertSame($beforeAudits, DB::table('audit_events')->count());
        $this->assertDatabaseCount('finance_refunds', 0);
        // The one pre-existing approved adjustment is preserved, not erased.
        $this->assertDatabaseCount('commercial_adjustment_approvals', 1);
    }

    public function test_revoked_admin_cannot_approve_refunds_or_read_private_financial_summary(): void
    {
        [$invoice, $refund] = $this->seedApprovals();
        $this->actingAs($this->admin);
        $record = DB::table('users')->where('id', $this->admin->id)
            ->first(['role', 'account_status', 'auth_epoch', 'password']);
        $original = [
            'role' => $record->role,
            'account_status' => $record->account_status,
            'auth_epoch' => $record->auth_epoch,
            'password' => $record->password,
        ];
        $beforeAudits = DB::table('audit_events')->count();
        foreach ([
            ['role' => 'vendor'],
            ['account_status' => 'suspended'],
            ['auth_epoch' => (int) $record->auth_epoch + 1],
            ['password' => Hash::make('revoked-finance-approver')],
        ] as $change) {
            DB::table('users')->where('id', $this->admin->id)->update($change);
            $this->getJson('/approval-test/finance/report')->assertConflict();
            $this->getJson('/approval-test/finance/refund-requests')->assertConflict();
            $this->postJson('/approval-test/finance/refund-requests/'.$refund.'/review', [
                'decision' => 'approve', 'note' => 'Must not approve under stale role',
            ])->assertConflict();
            DB::table('users')->where('id', $this->admin->id)->update($original);
        }
        $this->assertDatabaseHas('finance_refund_requests', [
            'id' => $refund, 'status' => 'pending',
        ]);
        $this->assertDatabaseCount('finance_refunds', 0);
        $this->assertSame($beforeAudits, DB::table('audit_events')->count());
        $this->assertDatabaseCount('finance_journals', 2);
    }

    public function test_suspended_customer_cannot_submit_new_refund_requests_using_cached_session(): void
    {
        [$invoice, $refund] = $this->seedApprovals();
        $this->actingAs($this->customer);
        DB::table('users')->where('id', $this->customer->id)
            ->update(['account_status' => 'suspended']);
        $this->postJson('/approval-test/finance/invoices/'.$invoice->id.'/refund-requests', [
            'amount_minor' => 10, 'reason' => 'Suspended customer denied',
            'idempotency_key' => 'must-not-create-second-refund',
        ])->assertConflict();
        $this->assertDatabaseCount('finance_refund_requests', 1);
        $this->assertDatabaseHas('finance_refund_requests', [
            'id' => $refund, 'status' => 'pending',
        ]);
        $this->assertDatabaseCount('finance_refunds', 0);
    }

    public function test_unrepresentable_large_settlement_fails_closed_without_corrupting_invoice_or_payout(): void
    {
        $this->seedApprovals();
        DB::table('commercial_settlements')->insert([
            'number' => 'SIM-SET-TOO-LARGE-FOR-JS',
            'vendor_id' => $this->vendor->id, 'currency' => 'AED',
            'minor_units' => 2, 'amount_minor' => 9_007_199_254_740_992,
            'state' => 'pending', 'request_key' => hash('sha256', 'too-large-approval'),
            'created_by' => $this->vendor->id, 'evidence' => 'SIMULATED',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->actingAs($this->admin)->getJson($this->base())
            ->assertConflict();
        $this->getJson($this->base().'?kind=refund')
            ->assertOk()->assertJsonPath('total', 1);
        $this->assertDatabaseCount('commercial_settlements', 2);
        $this->assertDatabaseCount('finance_refunds', 0);
        $this->assertDatabaseCount('finance_journals', 2);
    }

    public function test_opposite_unsafe_adjustments_outside_page_cannot_cancel_into_valid_summary(): void
    {
        $this->seedApprovals();
        foreach ([9_007_199_254_740_992, -9_007_199_254_740_992] as $index => $amount) {
            DB::table('commercial_adjustments')->insert([
                'vendor_id' => $this->vendor->id, 'currency' => 'AED',
                'minor_units' => 2, 'amount_minor' => $amount,
                'reason' => 'Synthetic out-of-range source',
                'request_key' => hash('sha256', 'unsafe-offset-'.$index),
                'created_by' => $this->vendor->id, 'created_at' => now()->subDay(),
            ]);
        }
        $before = DB::table('finance_journals')->count();
        $query = '?kind=adjustment&per_page=1&page=1';
        $this->actingAs($this->admin)->getJson($this->base().$query)->assertConflict();
        $this->get($this->base().'/export'.$query)->assertConflict();
        $this->getJson($this->base().'?kind=refund')->assertOk()->assertJsonPath('total', 1);
        $this->assertSame($before, DB::table('finance_journals')->count());
        $this->assertDatabaseCount('commercial_adjustments', 4);
        $this->assertDatabaseCount('commercial_adjustment_approvals', 1);
        $this->assertDatabaseCount('finance_refunds', 0);
    }

    public function test_admin_csv_export_is_same_private_single_page_and_contains_no_secret_or_approval(): void
    {
        [$invoice, $refund, $settlement, $adjustment] = $this->seedApprovals();
        $url = $this->base().'/export?kind=refund&per_page=1';
        $this->actingAs($this->customer)->get($url)->assertForbidden();
        $this->actingAs($this->vendor)->get($url)->assertForbidden();
        $csv = $this->actingAs($this->admin)->get($url)
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->streamedContent();
        $this->assertStringContainsString('kind,record_id,vendor_id,invoice_id,currency', $csv);
        $this->assertStringContainsString('refund,'.$refund.','.$this->vendor->id.','.$invoice->id.',AED,2,25,pending', $csv);
        $this->assertStringNotContainsString('Private customer complaint', $csv);
        $this->assertStringNotContainsString('Private merchant adjustment', $csv);
        $this->assertStringNotContainsString("\nsettlement,", $csv);
        $this->assertStringNotContainsString("\nadjustment,", $csv);
        $this->get($this->base().'/export?per_page=26')->assertUnprocessable();
        $this->assertDatabaseCount('commercial_settlement_events', 0);
        $this->assertDatabaseCount('finance_journals', 2);
        $this->assertDatabaseCount('finance_refunds', 0);
    }

    public function test_kind_currency_vendor_paging_and_native_admin_token_boundaries(): void
    {
        [$invoice, $refund, $settlement, $adjustment] = $this->seedApprovals();
        $path = $this->base();
        $this->actingAs($this->admin)->getJson($path.'?kind=refund')
            ->assertOk()->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.record_id', $refund);
        $this->getJson($path.'?kind=settlement')
            ->assertOk()->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.record_id', $settlement);
        $this->getJson($path.'?kind=adjustment')
            ->assertOk()->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.record_id', $adjustment);
        $this->getJson($path.'?currency=USD')->assertOk()->assertJsonPath('total', 0);
        $this->getJson($path.'?vendor_id='.($this->vendor->id + 99999))
            ->assertOk()->assertJsonPath('total', 0);
        $this->getJson($path.'?per_page=1&page=2')->assertOk()
            ->assertJsonPath('total', 3)->assertJsonCount(1, 'data');
        $this->getJson($path.'?per_page=26')->assertUnprocessable();
        $this->getJson($path.'?page=0')->assertUnprocessable();
        $this->getJson($path.'?kind=paid')->assertUnprocessable();
        $this->getJson($path.'?currency=AE%0AD')->assertUnprocessable();
        $this->getJson($path.'?vendor_id=0001')->assertUnprocessable();

        $this->app['auth']->forgetGuards();
        $this->withToken($this->vendor->createToken('approval-native-vendor')->plainTextToken)
            ->getJson('/api/v1/finance/approval-queue')->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->withToken($this->admin->createToken('approval-admin-pat')->plainTextToken)
            ->getJson('/api/v1/finance/approval-queue')->assertForbidden();
        $this->get('/api/v1/finance/approval-queue/export?kind=refund')
            ->assertForbidden();
        $this->assertDatabaseCount('finance_journals', 2);
        $this->assertDatabaseCount('finance_refund_requests', 1);
    }
}
