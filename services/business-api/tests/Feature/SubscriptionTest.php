<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Commercial\SubscriptionService;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class SubscriptionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Route::middleware(['web', 'auth', 'throttle:platform-api'])->prefix('subscription-test')->group(base_path('routes/subscriptions.php'));
    }

    private function plan(array $extra = []): array
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $v = array_replace(['code' => 'vendor-pro', 'name' => 'Operator Pro', 'audience' => 'vendor', 'currency' => 'AED', 'minor_units' => 2, 'price_minor' => 999, 'tax_bps' => 500, 'interval' => 'month', 'entitlements' => ['analytics' => true, 'staff_limit' => 5]], $extra);

        return $this->actingAs($admin)->postJson('/subscription-test/commercial/plans', $v)->assertCreated()->json();
    }

    private function subscribe(array $p, ?User $u = null): array
    {
        $u ??= User::factory()->create(['role' => $p['audience']]);
        $d = $this->actingAs($u)->postJson('/subscription-test/commercial/subscriptions', ['plan_id' => $p['id'], 'idempotency_key' => 'enroll'])->assertCreated()->assertHeader('Cache-Control', 'no-store, private')->json();

        return [$u, $d, '/subscription-test/commercial/subscriptions/'.$d['subscription']['id']];
    }

    private function pay(array $f, string $key = 'pay', string $outcome = 'succeeded'): array
    {
        return $this->actingAs($f[0])->postJson($f[2].'/mock-payment', ['invoice_id' => end($f[1]['invoices'])['id'], 'outcome' => $outcome, 'idempotency_key' => $key])->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json();
    }

    public function test_paginated_plan_subscription_and_private_event_reads_enforce_exact_pages_after_authority(): void
    {
        $p = $this->plan();
        $f = $this->subscribe($p);
        $id = $f[1]['subscription']['id'];
        $base = '/subscription-test/commercial';
        $this->actingAs($f[0]);

        foreach ([
            $base.'/plans',
            $base.'/subscriptions',
            $base.'/subscriptions/'.$id.'/events',
        ] as $url) {
            $this->getJson($url.'?page=1')->assertOk()
                ->assertHeader('Cache-Control', 'no-store, private')
                ->assertJsonPath('current_page', 1);
            $this->getJson($url.'?page=10000')->assertOk()
                ->assertHeader('Cache-Control', 'no-store, private')
                ->assertJsonPath('current_page', 10000)
                ->assertJsonCount(0, 'data');
            foreach (['0', '-1', 'abc', '100001', '1e5'] as $bad) {
                $this->getJson($url.'?page='.urlencode($bad))
                    ->assertUnprocessable();
            }
        }

        $other = User::factory()->create(['role' => 'vendor']);
        $this->actingAs($other)
            ->getJson($base.'/subscriptions/'.$id.'/events?page=0')
            ->assertNotFound();
        $this->assertDatabaseCount('commercial_subscriptions', 1);
        $this->assertDatabaseCount('commercial_subscription_events', 1);
        $this->assertDatabaseCount('commercial_subscription_receipts', 0);
    }

    public function test_subscription_financial_sources_are_exact_before_summary_and_mobile_display(): void
    {
        $minor = new \ReflectionMethod(SubscriptionService::class, 'exactMinor');
        $sum = new \ReflectionMethod(SubscriptionService::class, 'checkedSum');
        $add = new \ReflectionMethod(SubscriptionService::class, 'checkedAdd');
        $max = 9_007_199_254_740_991;

        $this->assertSame($max, $minor->invoke(null, (string) $max));
        $this->assertSame(0, $minor->invoke(null, '0'));
        $this->assertSame($max, $add->invoke(null, $max - 5, 5));
        $this->assertSame(0, $add->invoke(null, $max, -$max, true));
        $this->assertSame($max, $sum->invoke(null, [
            (object) ['amount_minor' => '4503599627370495'],
            (object) ['amount_minor' => 4_503_599_627_370_496],
        ], 'amount_minor'));

        foreach ([true, false, 100.0, -100.0, '-1', '1.5', '1e3',
            '9007199254740992', 'non-money'] as $bad) {
            try {
                $minor->invoke(null, $bad);
                $this->fail('Unsafe subscription source was rounded into minor units.');
            } catch (HttpException $error) {
                $this->assertSame(409, $error->getStatusCode());
            }
        }
        foreach ([
            [(object) ['amount_minor' => 4_503_599_627_370_496],
                (object) ['amount_minor' => 4_503_599_627_370_496]],
            [(object) ['amount_minor' => true]],
            [(object) ['amount_minor' => -1]],
        ] as $unsafe) {
            try {
                $sum->invoke(null, $unsafe, 'amount_minor');
                $this->fail('Unsafe subscription aggregate was accepted.');
            } catch (HttpException $error) {
                $this->assertSame(409, $error->getStatusCode());
            }
        }

        $f = $this->subscribe($this->plan());
        $before = DB::table('commercial_subscription_ledger')->count();
        $response = $this->actingAs($f[0])->getJson($f[2])->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private');
        $response->assertJsonPath('ledger_balanced', true)
            ->assertJsonPath('refunded_minor', 0)
            ->assertJsonPath('net_collected_minor', 0)
            ->assertJsonPath('invoices.0.total_minor', 1049);
        $this->assertSame($before, DB::table('commercial_subscription_ledger')->count());
        $this->assertDatabaseCount('commercial_subscription_receipts', 0);
        $this->assertDatabaseCount('subscription_credits', 0);
    }

    public function test_invoice_ledger_rejects_unsafe_minor_units_before_any_partial_journal_post(): void
    {
        $ledger = new \ReflectionMethod(SubscriptionService::class, 'ledger');
        $service = app(SubscriptionService::class);
        $before = DB::table('commercial_subscription_ledger')->count();
        $max = 9_007_199_254_740_991;
        $unsafe = [
            [['cash:mock_subscription', 100.0, 0],
                ['subscription_receivable', 0, 100]],
            [['cash:mock_subscription', true, 0],
                ['subscription_receivable', 0, 1]],
            [['cash:mock_subscription', -1, 0],
                ['subscription_receivable', 0, -1]],
            [['cash:mock_subscription', $max, 0],
                ['cash:mock_subscription', 1, 0],
                ['subscription_receivable', 0, $max],
                ['subscription_receivable', 0, 1]],
        ];

        foreach ($unsafe as $entries) {
            try {
                $ledger->invoke($service, 1, 'test-invalid', $entries);
                $this->fail('Inexact ledger was accepted or partially posted.');
            } catch (HttpException $error) {
                $this->assertSame(409, $error->getStatusCode());
            }
            $this->assertSame($before,
                DB::table('commercial_subscription_ledger')->count());
        }

        $f = $this->subscribe($this->plan());
        $posted = DB::table('commercial_subscription_ledger')->count();
        $this->actingAs($f[0])->postJson($f[2].'/mock-payment', [
            'invoice_id' => $f[1]['invoices'][0]['id'],
            'outcome' => 'succeeded',
            'idempotency_key' => 'test-exact-posting',
        ])->assertOk()->assertJsonPath('ledger_balanced', true)
            ->assertJsonPath('net_collected_minor', 1049);
        $this->assertSame($posted + 2,
            DB::table('commercial_subscription_ledger')->count());
        $this->assertDatabaseCount('commercial_subscription_receipts', 1);
    }

    public function test_enrollment_payment_entitlements_and_replays_are_connected(): void
    {
        $p = $this->plan();
        $f = $this->subscribe($p);
        $this->assertFalse($f[1]['entitlements_active']);
        $this->assertSame(1049, $f[1]['invoices'][0]['total_minor']);
        $this->assertSame(50, $f[1]['invoices'][0]['tax_minor']);
        $paid = $this->pay($f);
        $this->assertTrue($paid['entitlements_active']);
        $this->assertTrue($paid['ledger_balanced']);
        $this->assertSame(['analytics' => true, 'staff_limit' => 5], $paid['entitlements']);
        $this->assertSame('MOCK_PROVIDER', $paid['billing_provider']);
        $this->assertFalse($paid['real_payment_processed']);
        $this->pay($f);
        $this->postJson('/subscription-test/commercial/subscriptions', ['plan_id' => $p['id'], 'idempotency_key' => 'enroll'])->assertCreated()->assertJsonPath('subscription.id', $paid['subscription']['id']);
        $this->postJson('/subscription-test/commercial/subscriptions', ['plan_id' => $p['id'], 'idempotency_key' => 'other'])->assertConflict();
        $this->postJson($f[2].'/mock-payment', ['invoice_id' => $f[1]['invoices'][0]['id'], 'outcome' => 'failed', 'idempotency_key' => 'pay'])->assertConflict();
        $this->assertDatabaseCount('commercial_subscription_receipts', 1);
        $this->assertDatabaseCount('commercial_subscription_events', 2);
    }

    public function test_subscription_operation_receipts_bind_immutable_event_after_later_lifecycle_change(): void
    {
        $f = $this->subscribe($this->plan());
        $id = $f[1]['subscription']['id'];
        $owner = $f[0]->id;
        $enrollment = $f[1]['operation_receipt'];
        $this->assertSame('enroll', $enrollment['action']);
        $this->assertSame($f[1]['plan']['id'], $enrollment['plan_id']);
        $this->assertSame(hash('sha256', "subscription-operation-v1:{$owner}:{$id}:{$enrollment['event_id']}:enroll:{$enrollment['plan_id']}:enroll"), $enrollment['request_receipt']);
        $this->pay($f);
        $payload = ['action' => 'cancel_at_period_end', 'idempotency_key' => 'receipt-cancel'];
        $first = $this->postJson($f[2].'/change', $payload)->assertOk()
            ->assertJsonPath('subscription.cancel_at_period_end', true)
            ->assertJsonPath('operation_receipt.actor_id', $owner)
            ->assertJsonPath('operation_receipt.subscription_id', $id)
            ->assertJsonPath('operation_receipt.action', 'cancel_at_period_end')
            ->assertJsonPath('operation_receipt.plan_id', null)
            ->assertJsonMissingPath('operation_receipt.request_key')
            ->assertJsonMissingPath('operation_receipt.fingerprint')
            ->json('operation_receipt');
        $this->assertSame(hash('sha256', "subscription-operation-v1:{$owner}:{$id}:{$first['event_id']}:cancel_at_period_end:0:receipt-cancel"), $first['request_receipt']);
        $this->postJson($f[2].'/change', ['action' => 'resume', 'idempotency_key' => 'receipt-resume'])
            ->assertOk()->assertJsonPath('operation_receipt.action', 'resume');
        $this->postJson($f[2].'/change', $payload)->assertOk()
            ->assertJsonPath('subscription.cancel_at_period_end', false)
            ->assertJsonPath('operation_receipt', $first);
        $this->postJson($f[2].'/change', ['action' => 'resume', 'idempotency_key' => 'receipt-cancel'])->assertConflict();
        $this->getJson($f[2])->assertOk()->assertJsonMissingPath('operation_receipt');
        $this->assertDatabaseCount('commercial_subscription_events', 4);
        $this->assertDatabaseCount('commercial_subscription_receipts', 1);
        $this->actingAs(User::factory()->create(['role' => 'vendor']))->postJson($f[2].'/change', $payload)->assertNotFound();
    }

    public function test_payment_failure_retry_and_period_expiry_never_grant_unpaid_features(): void
    {
        $f = $this->subscribe($this->plan());
        $failed = $this->pay($f, 'fail', 'failed');
        $this->assertSame('past_due', $failed['subscription']['status']);
        $this->assertFalse($failed['entitlements_active']);
        $paid = $this->pay($f, 'retry');
        $this->travelTo(Carbon::parse($paid['subscription']['period_end'])->addSecond());
        $this->getJson($f[2])->assertOk()->assertJsonPath('entitlements_active', false);
        $renewed = $this->postJson($f[2].'/renew')->assertOk()->assertJsonCount(2, 'invoices')->assertJsonPath('subscription.status', 'past_due')->json();
        $this->postJson($f[2].'/renew')->assertOk()->assertJsonCount(2, 'invoices');
        $f[1] = $renewed;
        $this->assertTrue($this->pay($f, 'cycle-two')['entitlements_active']);
        $this->assertDatabaseCount('commercial_subscription_invoices', 2);
        $this->assertDatabaseCount('commercial_subscription_receipts', 2);
    }

    public function test_upgrades_and_downgrades_are_scheduled_with_frozen_current_cycle_prices(): void
    {
        $basic = $this->plan();
        $pro = $this->plan(['code' => 'vendor-advanced', 'price_minor' => 2999, 'entitlements' => ['staff_limit' => 20]]);
        $f = $this->subscribe($basic);
        $paid = $this->pay($f);
        $this->actingAs($f[0])->postJson($f[2].'/change', ['action' => 'change_plan', 'plan_id' => $pro['id'], 'idempotency_key' => 'upgrade'])->assertOk()->assertJsonPath('subscription.next_plan_id', $pro['id'])->assertJsonPath('entitlements.staff_limit', 5);
        $this->postJson($f[2].'/change', ['action' => 'change_plan', 'plan_id' => $basic['id'], 'idempotency_key' => 'upgrade'])->assertConflict();
        $this->travelTo(Carbon::parse($paid['subscription']['period_end'])->addSecond());
        $renewed = $this->postJson($f[2].'/renew')->assertOk()->assertJsonPath('invoices.0.subtotal_minor', 999)->assertJsonPath('invoices.1.subtotal_minor', 2999)->json();
        $f[1] = $renewed;
        $next = $this->pay($f, 'upgrade-payment');
        $this->assertSame(20, $next['entitlements']['staff_limit']);
        $this->postJson($f[2].'/change', ['action' => 'change_plan', 'plan_id' => $basic['id'], 'idempotency_key' => 'downgrade'])->assertOk()->assertJsonPath('subscription.next_plan_id', $basic['id'])->assertJsonPath('entitlements.staff_limit', 20);
    }

    public function test_cancellation_resume_and_due_command_do_not_charge_or_reenable_cancelled_subscription(): void
    {
        $f = $this->subscribe($this->plan());
        $paid = $this->pay($f);
        $this->postJson($f[2].'/change', ['action' => 'cancel_at_period_end', 'idempotency_key' => 'cancel'])->assertOk()->assertJsonPath('entitlements_active', true);
        $this->postJson($f[2].'/change', ['action' => 'resume', 'idempotency_key' => 'resume'])->assertOk()->assertJsonPath('subscription.cancel_at_period_end', false);
        $this->postJson($f[2].'/change', ['action' => 'cancel_at_period_end', 'idempotency_key' => 'cancel-again'])->assertOk();
        $this->travelTo(Carbon::parse($paid['subscription']['period_end'])->addSecond());
        $this->artisan('commercial:renew-subscriptions')->assertSuccessful();
        $this->getJson($f[2])->assertOk()->assertJsonPath('subscription.status', 'cancelled')->assertJsonPath('entitlements_active', false);
        $this->postJson($f[2].'/mock-payment', ['invoice_id' => $f[1]['invoices'][0]['id'], 'outcome' => 'succeeded', 'idempotency_key' => 'new'])->assertConflict();
        $this->assertDatabaseCount('commercial_subscription_invoices', 1);
        $this->assertDatabaseCount('commercial_subscription_receipts', 1);
    }

    public function test_tenant_isolation_and_audience_apply_to_every_subscription_operation(): void
    {
        $f = $this->subscribe($this->plan());
        $other = User::factory()->create(['role' => 'vendor']);
        $this->actingAs($other)->getJson('/subscription-test/commercial/subscriptions')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($f[2])->assertNotFound();
        $this->getJson($f[2].'/events')->assertNotFound();
        $this->postJson($f[2].'/renew')->assertNotFound();
        $this->postJson($f[2].'/change', [])->assertNotFound();
        $this->postJson($f[2].'/mock-payment', [])->assertNotFound();
        $this->actingAs(User::factory()->create())->postJson('/subscription-test/commercial/subscriptions', ['plan_id' => $f[1]['plan']['id'], 'idempotency_key' => 'wrong-audience'])->assertConflict();
        $this->getJson('/subscription-test/commercial/plans')->assertJsonCount(0, 'data');
        $this->actingAs(User::factory()->create(['role' => 'admin']))->getJson($f[2])->assertOk();
    }

    public function test_plan_versioning_retirement_and_validation_preserve_historical_terms(): void
    {
        $p = $this->plan();
        $f = $this->subscribe($p);
        $new = $this->plan(['price_minor' => 3000]);
        $this->assertSame(2, $new['version']);
        $this->actingAs($f[0])->getJson($f[2])->assertJsonPath('invoices.0.subtotal_minor', 999);
        $this->postJson('/subscription-test/commercial/plans', [])->assertForbidden();
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->patchJson('/subscription-test/commercial/plans/'.$p['id'].'/availability', ['available' => false])->assertOk();
        $this->actingAs(User::factory()->create(['role' => 'vendor']))->postJson('/subscription-test/commercial/subscriptions', ['plan_id' => $p['id'], 'idempotency_key' => 'retired'])->assertConflict();
        $this->actingAs($f[0]);
        $this->pay($f);
        $this->postJson($f[2].'/change', ['action' => 'cancel_now', 'idempotency_key' => 'cancel'])->assertOk()->assertJsonPath('entitlements_active', false);
    }

    public function test_database_guards_invoice_receipt_plan_and_event_immutability(): void
    {
        $f = $this->subscribe($this->plan());
        $this->pay($f);
        foreach (['commercial_plan_versions', 'commercial_subscription_invoices', 'commercial_subscription_receipts', 'commercial_subscription_events', 'commercial_subscription_ledger'] as $table) {
            try {
                DB::transaction(fn () => DB::table($table)->delete());
                $this->fail('Immutable records were deleted');
            } catch (QueryException $e) {
                $this->assertStringContainsString('immutable', $e->getMessage());
            }
        }
    }

    public function test_yearly_billing_leap_day_and_production_mock_guard(): void
    {
        $this->travelTo(Carbon::parse('2028-02-29 12:00:00'));
        $f = $this->subscribe($this->plan(['interval' => 'year']));
        $this->assertStringStartsWith('2029-02-28', $f[1]['invoices'][0]['period_end']);
        $this->app->detectEnvironment(fn () => 'production');
        try {
            app(SubscriptionService::class)->mockPay($f[0], $f[1]['subscription']['id'], $f[1]['invoices'][0]['id'], 'succeeded', 'prod');
            $this->fail('Production mock charge accepted');
        } catch (HttpException $e) {
            $this->assertSame(503, $e->getStatusCode());
        }
        $this->assertDatabaseCount('commercial_subscription_receipts', 0);
    }

    public function test_month_end_anniversary_is_restored_after_short_months(): void
    {
        $this->travelTo(Carbon::parse('2027-01-31 12:00:00'));
        $f = $this->subscribe($this->plan());
        $this->assertStringStartsWith('2027-02-28', $f[1]['invoices'][0]['period_end']);
        $this->pay($f);
        $this->travelTo(Carbon::parse('2027-02-28 12:00:01'));
        $next = $this->postJson($f[2].'/renew')->assertOk()->json();
        $this->assertStringStartsWith('2027-03-31', $next['invoices'][1]['period_end']);
    }
}
