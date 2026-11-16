<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Commercial\SubscriptionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class SubscriptionChangePreviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Route::middleware(['web', 'auth', 'throttle:platform-api'])->prefix('preview-test')
            ->group(base_path('routes/subscriptions.php'));
    }

    private function scenario(): array
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $vendor = User::factory()->create(['role' => 'vendor']);
        $basic = $this->actingAs($admin)->postJson('/preview-test/commercial/plans', [
            'code' => 'preview_basic', 'name' => 'Preview Basic', 'audience' => 'vendor',
            'currency' => 'AED', 'minor_units' => 2, 'price_minor' => 999,
            'tax_bps' => 500, 'interval' => 'month', 'entitlements' => ['staff_limit' => 2],
        ])->assertCreated()->json();
        $pro = $this->postJson('/preview-test/commercial/plans', [
            'code' => 'preview_pro', 'name' => 'Preview Pro', 'audience' => 'vendor',
            'currency' => 'AED', 'minor_units' => 2, 'price_minor' => 2999,
            'tax_bps' => 500, 'interval' => 'year', 'entitlements' => ['staff_limit' => 20],
        ])->assertCreated()->json();
        $subscribed = $this->actingAs($vendor)->postJson('/preview-test/commercial/subscriptions', [
            'plan_id' => $basic['id'], 'idempotency_key' => 'preview-enroll',
        ])->assertCreated()->json();
        $id = $subscribed['subscription']['id'];
        $invoice = $subscribed['invoices'][0]['id'];
        $this->postJson('/preview-test/commercial/subscriptions/'.$id.'/mock-payment', [
            'invoice_id' => $invoice, 'outcome' => 'succeeded', 'idempotency_key' => 'preview-pay',
        ])->assertOk();

        return [$admin, $vendor, $basic, $pro, $id, $invoice];
    }

    public function test_next_cycle_quote_is_tax_exact_and_cannot_mutate_present_entitlements_or_money(): void
    {
        [$admin, $vendor, $basic, $pro, $id, $invoice] = $this->scenario();
        $url = '/preview-test/commercial/subscriptions/'.$id;
        $before = [];
        foreach (['commercial_subscription_invoices', 'commercial_subscription_ledger',
            'commercial_subscription_receipts', 'commercial_subscription_events'] as $table) {
            $before[$table] = DB::table($table)->count();
        }

        $preview = $this->actingAs($vendor)->getJson($url.'/change-preview?plan_id='.$pro['id'])
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('subscription_id', $id)
            ->assertJsonPath('current_plan_id', $basic['id'])
            ->assertJsonPath('proposed_plan_id', $pro['id'])
            ->assertJsonPath('proposed_plan_version', 1)
            ->assertJsonPath('currency', 'AED')
            ->assertJsonPath('minor_units', 2)
            ->assertJsonPath('interval', 'year')
            ->assertJsonPath('next_cycle_subtotal_minor', 2999)
            ->assertJsonPath('next_cycle_tax_minor', 150)
            ->assertJsonPath('next_cycle_total_minor', 3149)
            ->assertJsonPath('current_plan_list_total_minor', 1049)
            ->assertJsonPath('current_plan_interval', 'month')
            ->assertJsonPath('cycles_comparable', false)
            ->assertJsonPath('nominal_plan_list_difference_minor', null)
            ->assertJsonPath('comparison_is_proration', false)
            ->assertJsonPath('amount_due_now_minor', 0)
            ->assertJsonPath('proration_supported', false)
            ->assertJsonPath('next_cycle_invoice_issued', false)
            ->assertJsonPath('quote_is_binding', false)
            ->assertJsonPath('real_payment_processed', false)->json();

        $current = $this->getJson($url)->assertOk()
            ->assertJsonPath('subscription.next_plan_id', null)
            ->assertJsonPath('plan.id', $basic['id'])
            ->assertJsonPath('invoices.0.id', $invoice)
            ->assertJsonPath('invoices.0.subtotal_minor', 999)
            ->assertJsonPath('entitlements.staff_limit', 2)->json();
        $this->assertSame(
            Carbon::parse($current['subscription']['period_end'])->utc()->toIso8601String(),
            $preview['effective_at_utc']
        );
        foreach ($before as $table => $count) {
            $this->assertSame($count, DB::table($table)->count(), 'Preview wrote to '.$table);
        }

        $changed = $this->postJson($url.'/change', [
            'action' => 'change_plan', 'plan_id' => $pro['id'],
            'idempotency_key' => 'preview-schedule',
        ])->assertOk()->assertJsonPath('subscription.next_plan_id', $pro['id'])
            ->assertJsonPath('entitlements.staff_limit', 2)->json();
        $this->assertCount(1, $changed['invoices']);
    }

    public function test_same_cadence_upgrade_and_downgrade_expose_only_nominal_list_difference(): void
    {
        [$admin, $vendor, $basic, $pro, $id] = $this->scenario();
        $monthly = $this->actingAs($admin)->postJson('/preview-test/commercial/plans', [
            'code' => 'preview_monthly_plus', 'name' => 'Monthly Plus',
            'audience' => 'vendor', 'currency' => 'AED',
            'minor_units' => 2, 'price_minor' => 1899,
            'tax_bps' => 500, 'interval' => 'month',
            'entitlements' => ['staff_limit' => 8],
        ])->assertCreated()->json();
        $url = '/preview-test/commercial/subscriptions/'.$id;
        $beforeInvoices = DB::table('commercial_subscription_invoices')->count();
        $beforeLedger = DB::table('commercial_subscription_ledger')->count();
        $this->actingAs($vendor)->getJson($url.'/change-preview?plan_id='.$monthly['id'])
            ->assertOk()
            ->assertJsonPath('current_plan_list_total_minor', 1049)
            ->assertJsonPath('next_cycle_total_minor', 1994)
            ->assertJsonPath('cycles_comparable', true)
            ->assertJsonPath('nominal_plan_list_difference_minor', 945)
            ->assertJsonPath('comparison_is_proration', false)
            ->assertJsonPath('amount_due_now_minor', 0);
        $this->assertSame($beforeInvoices, DB::table('commercial_subscription_invoices')->count());
        $this->assertSame($beforeLedger, DB::table('commercial_subscription_ledger')->count());

        $this->postJson($url.'/change', [
            'action' => 'change_plan',
            'plan_id' => $monthly['id'],
            'idempotency_key' => 'month-upgrade-scheduled',
        ])->assertOk()->assertJsonPath('entitlements.staff_limit', 2);
        $periodEnd = DB::table('commercial_subscriptions')->where('id', $id)->value('period_end');
        $this->travelTo(Carbon::parse($periodEnd)->addSecond());
        $service = app(SubscriptionService::class);
        $renewed = $service->renew($id);
        $nextInvoice = $renewed['invoices']->last()->id;
        $service->mockPay($vendor, $id, $nextInvoice, 'succeeded', 'monthly-upgrade-paid');

        $this->actingAs($vendor)->getJson($url.'/change-preview?plan_id='.$basic['id'])
            ->assertOk()
            ->assertJsonPath('current_plan_list_total_minor', 1994)
            ->assertJsonPath('next_cycle_total_minor', 1049)
            ->assertJsonPath('cycles_comparable', true)
            ->assertJsonPath('nominal_plan_list_difference_minor', -945)
            ->assertJsonPath('comparison_is_proration', false)
            ->assertJsonPath('amount_due_now_minor', 0);
        $this->assertDatabaseCount('commercial_subscription_invoices', 2);
        $this->assertDatabaseCount('commercial_subscription_receipts', 2);
        $this->assertDatabaseCount('subscription_credits', 0);
    }

    public function test_preview_rejects_foreign_subscription_retired_plan_and_expired_period(): void
    {
        [$admin, $vendor, $basic, $pro, $id] = $this->scenario();
        $url = '/preview-test/commercial/subscriptions/'.$id.'/change-preview';
        $other = User::factory()->create(['role' => 'vendor']);
        $this->actingAs($other)->getJson($url.'?plan_id='.$pro['id'])->assertNotFound();
        $this->actingAs(User::factory()->create())->getJson($url.'?plan_id='.$pro['id'])->assertNotFound();
        $this->actingAs($vendor)->getJson($url)->assertUnprocessable();
        $this->getJson($url.'?plan_id=not-a-number')->assertUnprocessable();
        $this->getJson($url.'?plan_id='.str_repeat('9', 100))->assertUnprocessable();
        $this->actingAs($admin)->getJson($url.'?plan_id='.$pro['id'])->assertOk();

        $this->patchJson('/preview-test/commercial/plans/'.$pro['id'].'/availability', [
            'available' => false,
        ])->assertOk();
        $this->actingAs($vendor)->getJson($url.'?plan_id='.$pro['id'])->assertConflict();

        $periodEnd = DB::table('commercial_subscriptions')->where('id', $id)->value('period_end');
        $this->travelTo(Carbon::parse($periodEnd)->addSecond());
        $this->getJson($url.'?plan_id='.$basic['id'])->assertConflict();
        $this->assertDatabaseCount('commercial_subscription_invoices', 1);
        $this->assertDatabaseCount('commercial_subscription_receipts', 1);
    }

    public function test_cached_vendor_and_admin_credentials_cannot_read_next_cycle_terms_after_revocation(): void
    {
        [$admin, $vendor, $basic, $pro, $id] = $this->scenario();
        $path = '/preview-test/commercial/subscriptions/'.$id.
            '/change-preview?plan_id='.$pro['id'];
        $beforeEvents = DB::table('commercial_subscription_events')->count();
        $beforeInvoices = DB::table('commercial_subscription_invoices')->count();
        $beforeReceipts = DB::table('commercial_subscription_receipts')->count();

        foreach ([$vendor, $admin] as $reader) {
            $snapshot = DB::table('users')->where('id', $reader->id)
                ->first(['role', 'account_status', 'auth_epoch', 'password']);
            $original = [
                'role' => $snapshot->role,
                'account_status' => $snapshot->account_status,
                'auth_epoch' => $snapshot->auth_epoch,
                'password' => $snapshot->password,
            ];
            $this->actingAs($reader);
            $this->getJson($path)->assertOk()->assertJsonPath('amount_due_now_minor', 0);

            // DB-only updates keep this authenticated PHP model stale, as a
            // concurrent suspension/credential change could in a web session.
            foreach ([
                ['role' => 'customer'],
                ['account_status' => 'suspended'],
                ['auth_epoch' => (int) $snapshot->auth_epoch + 1],
                ['password' => Hash::make('revoked-preview-session')],
            ] as $mutation) {
                DB::table('users')->where('id', $reader->id)->update($mutation);
                $this->getJson($path)->assertConflict();
                DB::table('users')->where('id', $reader->id)->update($original);
            }
            $this->getJson($path)->assertOk()->assertJsonPath('quote_is_binding', false);
        }

        $this->assertSame($beforeEvents, DB::table('commercial_subscription_events')->count());
        $this->assertSame($beforeInvoices, DB::table('commercial_subscription_invoices')->count());
        $this->assertSame($beforeReceipts, DB::table('commercial_subscription_receipts')->count());
    }

    public function test_bearer_native_preview_is_same_scoped_read_only_quote_without_admin_token_access(): void
    {
        [$admin, $vendor, $basic, $pro, $id] = $this->scenario();
        $path = '/api/v1/commercial/subscriptions/'.$id.'/change-preview?plan_id='.$pro['id'];
        $eventsBefore = DB::table('commercial_subscription_events')->count();
        $vendorToken = $vendor->createToken('preview-native-owner')->plainTextToken;
        $this->app['auth']->forgetGuards();
        $this->withToken($vendorToken)->getJson($path)->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('next_cycle_total_minor', 3149)
            ->assertJsonPath('amount_due_now_minor', 0)
            ->assertJsonPath('real_payment_processed', false);

        $this->getJson('/api/v1/commercial/subscriptions/'.str_repeat('9', 19).
            '/change-preview?plan_id='.$pro['id'])->assertNotFound();
        $other = User::factory()->create(['role' => 'vendor']);
        $this->app['auth']->forgetGuards();
        $this->withToken($other->createToken('preview-native-foreign')->plainTextToken)
            ->getJson($path)->assertNotFound();

        $this->app['auth']->forgetGuards();
        $this->withToken($admin->createToken('preview-native-admin-denied')->plainTextToken)
            ->getJson($path)->assertForbidden();
        $this->assertSame($eventsBefore, DB::table('commercial_subscription_events')->count());
        $this->assertDatabaseCount('commercial_subscription_invoices', 1);
        $this->assertDatabaseCount('commercial_subscription_receipts', 1);
    }
}
