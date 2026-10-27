<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Commercial\SubscriptionCreditService;
use App\Services\Commercial\SubscriptionGateway;
use App\Services\Commercial\SubscriptionService;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class SubscriptionCreditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['payments.provider' => 'mock', 'payments.stripe_test_secret' => 'sk_test_contractonly', 'payments.stripe_test_webhook_secret' => 'whsec_'.str_repeat('contractonly', 4)]);
        Http::preventStrayRequests();
        Route::middleware(['web', 'auth'])->prefix('subscription-credit-test')->group(base_path('routes/subscription_credits.php'));
        Route::prefix('subscription-credit-test')->group(base_path('routes/subscription_credit_webhooks.php'));
    }

    private function fixture(bool $stripe = false): array
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $user = User::factory()->create();
        $service = app(SubscriptionService::class);
        $plan = $service->publish($admin, ['code' => 'customer-pro', 'name' => 'Customer Pro', 'audience' => 'customer', 'currency' => 'AED', 'minor_units' => 2, 'price_minor' => 1000, 'tax_bps' => 500, 'interval' => 'month', 'entitlements' => json_encode(['premium_reports' => true])]);
        $summary = $service->subscribe($user, $plan->id, 'enroll');
        $subscription = $summary['subscription']->id;
        $invoice = $summary['invoices']->first()->id;
        $checkout = null;
        if ($stripe) {
            config(['payments.provider' => 'stripe_test']);
            $gateway = app(SubscriptionGateway::class);
            $checkout = $gateway->checkout($user, $subscription, $invoice, 'checkout');
            $gateway->operate($user, $checkout->id, 'authorize', 'authorize', 'pm_testcard');
            $gateway->operate($user, $checkout->id, 'capture', 'capture', null);
        } else {
            $service->mockPay($user, $subscription, $invoice, 'succeeded', 'pay');
        }
        $this->actingAs($admin);

        return [$admin, $user, $subscription, $invoice, $checkout, '/subscription-credit-test/commercial/subscriptions/'.$subscription.'/credits'];
    }

    private function refund(array $f, int $amount, string $key = 'refund', string $reason = 'Approved subscription refund')
    {
        return $this->postJson($f[5], ['invoice_id' => $f[3], 'amount_minor' => $amount, 'reason' => $reason, 'idempotency_key' => $key]);
    }

    private function stripeFake(?callable $refund = null): void
    {
        Http::fake(function ($r) use ($refund) {
            if (str_ends_with($r->url(), '/refunds')) {
                return $refund ? $refund($r) : Http::response(['id' => 're_SubscriptionCredit', 'payment_intent' => 'pi_SubscriptionCredit', 'amount' => (int) $r['amount'], 'currency' => 'aed', 'status' => 'succeeded', 'livemode' => false]);
            }

            return Http::response(['id' => 'pi_SubscriptionCredit', 'amount' => 1050, 'amount_received' => 1050, 'currency' => 'aed', 'status' => str_ends_with($r->url(), '/capture') ? 'succeeded' : 'requires_capture', 'livemode' => false]);
        });
    }

    private function webhook(array $object, string $id = 'evt_SubscriptionCredit', ?string $signature = null)
    {
        $raw = json_encode(['id' => $id, 'livemode' => false, 'type' => 'refund.updated', 'data' => ['object' => $object]], JSON_THROW_ON_ERROR);
        $timestamp = time();
        $signature ??= 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$raw, config('payments.stripe_test_webhook_secret'));

        return $this->call('POST', '/subscription-credit-test/subscription-credit-payments/stripe-test/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => $signature], $raw);
    }

    public function test_partial_and_full_refunds_allocate_exactly_without_mutating_invoice_or_receipt(): void
    {
        $f = $this->fixture();
        $invoice = (array) DB::table('commercial_subscription_invoices')->find($f[3]);
        $receipt = (array) DB::table('commercial_subscription_receipts')->where('invoice_id', $f[3])->first();
        $first = $this->refund($f, 1)->assertCreated()->assertJsonPath('subscription.entitlements_active', true)->assertJsonPath('subscription.invoices.0.credit_state', 'partially_refunded')->json();
        $this->refund($f, 1)->assertCreated()->assertJsonPath('credit.id', $first['credit']['id']);
        $this->refund($f, 1049, 'rest')->assertCreated()->assertJsonPath('subscription.entitlements_active', false)->assertJsonPath('subscription.subscription.status', 'cancelled')->assertJsonPath('subscription.net_collected_minor', 0)->assertJsonPath('subscription.ledger_balanced', true);
        $this->assertSame(1000, (int) DB::table('subscription_credits')->sum('subtotal_minor'));
        $this->assertSame(50, (int) DB::table('subscription_credits')->sum('tax_minor'));
        $this->assertSame($invoice, (array) DB::table('commercial_subscription_invoices')->find($f[3]));
        $this->assertSame($receipt, (array) DB::table('commercial_subscription_receipts')->where('invoice_id', $f[3])->first());
        $this->assertDatabaseCount('subscription_credits', 2);
        Http::assertNothingSent();
    }

    public function test_overrefund_conflicting_key_and_unpaid_invoice_fail_without_financial_posting(): void
    {
        $f = $this->fixture();
        $this->refund($f, 500)->assertCreated();
        $this->refund($f, 501)->assertConflict();
        $this->refund($f, 551, 'over')->assertConflict();
        $service = app(SubscriptionService::class);
        $summary = $service->summary($f[2]);
        $this->travelTo(Carbon::parse($summary['subscription']->period_end)->addSecond());
        $renewal = $service->renew($f[2]);
        $f[3] = $renewal['invoices']->last()->id;
        $this->refund($f, 1, 'unpaid')->assertConflict();
        $this->assertDatabaseCount('subscription_credits', 1);
    }

    public function test_only_administrators_can_refund_and_credit_reads_preserve_tenant_isolation(): void
    {
        $f = $this->fixture();
        $credit = $this->refund($f, 100)->assertCreated()->json('credit.id');
        $this->actingAs($f[1])->getJson($f[5])->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/subscription-credit-test/commercial/subscription-credits/'.$credit)->assertOk();
        $this->refund($f, 1, 'customer')->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'vendor']))->getJson($f[5])->assertNotFound();
        $this->getJson('/subscription-credit-test/commercial/subscription-credits/'.$credit)->assertNotFound();
        $this->refund($f, 1, 'vendor')->assertForbidden();
    }

    public function test_historical_cycle_full_refund_preserves_later_paid_entitlement(): void
    {
        $f = $this->fixture();
        $service = app(SubscriptionService::class);
        $summary = $service->summary($f[2]);
        $this->travelTo(Carbon::parse($summary['subscription']->period_end)->addSecond());
        $next = $service->renew($f[2]);
        $service->mockPay($f[1], $f[2], $next['invoices']->last()->id, 'succeeded', 'next-payment');
        $this->refund($f, 1050)->assertCreated()->assertJsonPath('subscription.entitlements_active', true)->assertJsonPath('subscription.subscription.status', 'active')->assertJsonPath('subscription.net_collected_minor', 1050);
    }

    public function test_stripe_refund_is_provider_backed_and_replays_do_not_call_provider_again(): void
    {
        $this->stripeFake();
        $f = $this->fixture(true);
        $this->refund($f, 1050)->assertCreated()->assertJsonPath('credit.evidence', 'STRIPE_TEST_CONTRACT')->assertJsonPath('credit.provider_reference', 're_SubscriptionCredit')->assertJsonPath('subscription.entitlements_active', false)->assertJsonPath('subscription.ledger_balanced', true);
        $this->refund($f, 1050)->assertCreated();
        Http::assertSentCount(3);
        $this->assertDatabaseHas('payment_recovery_intents', ['scope' => 'subscription', 'kind' => 'refund', 'status' => 'completed']);
        $this->assertDatabaseCount('subscription_credits', 1);
    }

    public function test_external_receipt_cannot_be_locally_credited_without_verified_capture_link(): void
    {
        $f = $this->fixture();
        // A new direct internal receipt is intentionally unlinked to a gateway capture.
        $service = app(SubscriptionService::class);
        $summary = $service->summary($f[2]);
        $this->travelTo(Carbon::parse($summary['subscription']->period_end)->addSecond());
        $next = $service->renew($f[2]);
        $f[3] = $next['invoices']->last()->id;
        $service->recordProviderReceipt($f[2], $f[3], 'pi_Unlinked:capture', 1050, 'AED', 'STRIPE_TEST_CONTRACT', $f[0]->id);
        $this->refund($f, 1)->assertConflict();
        $this->assertDatabaseCount('subscription_credits', 0);
        Http::assertNothingSent();
    }

    public function test_wrong_provider_amount_and_failed_refund_never_create_credit_or_revoke_features(): void
    {
        $this->stripeFake(fn () => Http::response(['id' => 're_WrongAmount', 'payment_intent' => 'pi_SubscriptionCredit', 'amount' => 999, 'status' => 'succeeded', 'livemode' => false]));
        $f = $this->fixture(true);
        $this->refund($f, 1050)->assertConflict();
        $this->assertDatabaseCount('subscription_credits', 0);
        $this->assertTrue(app(SubscriptionService::class)->summary($f[2])['entitlements_active']);
        $this->assertDatabaseHas('payment_recovery_intents', ['kind' => 'refund', 'status' => 'uncertain']);
    }

    public function test_uncertain_refund_retries_use_same_provider_identity_and_post_only_once(): void
    {
        $keys = [];
        $calls = 0;
        $this->stripeFake(function ($r) use (&$keys, &$calls) {
            $keys[] = $r->header('Idempotency-Key')[0];
            if (++$calls === 1) {
                throw new ConnectionException('test timeout');
            }

            return Http::response(['id' => 're_RetryCredit', 'payment_intent' => 'pi_SubscriptionCredit', 'amount' => 1050, 'currency' => 'aed', 'status' => 'succeeded', 'livemode' => false]);
        });
        $f = $this->fixture(true);
        $this->refund($f, 1050)->assertStatus(503);
        $this->assertDatabaseCount('subscription_credits', 0);
        $this->refund($f, 1050)->assertCreated();
        $this->assertSame($keys[0], $keys[1]);
        $this->assertDatabaseCount('subscription_credits', 1);
    }

    public function test_signed_refund_recovers_durable_request_after_timeout_and_is_idempotent(): void
    {
        $recoveryId = null;
        $this->stripeFake(function ($r) use (&$recoveryId) {
            $recoveryId = (int) $r['metadata']['creabiz_recovery'];
            throw new ConnectionException('test timeout');
        });
        $f = $this->fixture(true);
        $this->refund($f, 1050)->assertStatus(503);
        $object = ['id' => 're_WebhookCredit', 'payment_intent' => 'pi_SubscriptionCredit', 'amount' => 1050, 'currency' => 'aed', 'status' => 'succeeded', 'livemode' => false, 'metadata' => ['creabiz_recovery' => (string) $recoveryId]];
        $this->webhook($object)->assertOk()->assertJsonPath('duplicate', false);
        $this->webhook($object)->assertOk()->assertJsonPath('duplicate', true);
        $this->webhook(array_replace($object, ['amount' => 1049]))->assertConflict();
        $this->assertDatabaseCount('subscription_credits', 1);
        $this->assertDatabaseHas('payment_recovery_intents', ['id' => $recoveryId, 'status' => 'completed']);
        $this->assertFalse(app(SubscriptionService::class)->summary($f[2])['entitlements_active']);
    }

    public function test_invalid_signature_live_events_and_uncorrelated_refunds_never_post_credits(): void
    {
        $this->stripeFake();
        $f = $this->fixture(true);
        $object = ['id' => 're_Uncorrelated', 'payment_intent' => 'pi_SubscriptionCredit', 'amount' => 1050, 'currency' => 'aed', 'status' => 'succeeded', 'livemode' => false];
        $this->webhook($object, 'evt_Invalid', 't='.time().',v1='.str_repeat('0', 64))->assertUnauthorized();
        $this->webhook(array_replace($object, ['livemode' => true]))->assertUnprocessable();
        $this->webhook($object)->assertConflict();
        $this->assertDatabaseCount('subscription_credits', 0);
    }

    public function test_credit_requests_notes_and_journal_are_immutable_and_production_refunds_fail_closed(): void
    {
        $f = $this->fixture();
        $this->refund($f, 1)->assertCreated();
        foreach (['subscription_credit_requests', 'subscription_credits'] as $table) {
            try {
                DB::transaction(fn () => DB::table($table)->delete());
                $this->fail('Immutable credit evidence was deleted');
            } catch (QueryException $e) {
                $this->assertStringContainsString('immutable', $e->getMessage());
            }
        }
        $this->app->detectEnvironment(fn () => 'production');
        try {
            app(SubscriptionCreditService::class)->refund($f[0], $f[2], $f[3], 1, 'Production forbidden', 'prod');
            $this->fail('Production refund was accepted');
        } catch (HttpException $e) {
            $this->assertSame(503, $e->getStatusCode());
        }
        $this->assertDatabaseCount('subscription_credits', 1);
    }

    public function test_read_only_provider_recovery_after_retry_window_posts_credit_without_another_refund(): void
    {
        $recoveryId = null;
        $refundCalls = 0;
        $getCalls = 0;
        Http::fake(function ($r) use (&$recoveryId, &$refundCalls, &$getCalls) {
            if ($r->method() === 'GET') {
                $getCalls++;

                return Http::response(['object' => 'list', 'data' => [['id' => 're_ProviderRecovery', 'payment_intent' => 'pi_SubscriptionCredit', 'amount' => 1050, 'currency' => 'aed', 'status' => 'succeeded', 'livemode' => false, 'metadata' => ['creabiz_recovery' => (string) $recoveryId]]], 'has_more' => false]);
            }
            if (str_ends_with($r->url(), '/refunds')) {
                $refundCalls++;
                $recoveryId = (int) $r['metadata']['creabiz_recovery'];
                throw new ConnectionException('test lost refund response');
            }

            return Http::response(['id' => 'pi_SubscriptionCredit', 'amount' => 1050, 'amount_received' => 1050, 'currency' => 'aed', 'status' => str_ends_with($r->url(), '/capture') ? 'succeeded' : 'requires_capture', 'livemode' => false]);
        });
        $f = $this->fixture(true);
        $this->refund($f, 1050)->assertStatus(503);
        DB::table('payment_recovery_intents')->where('id', $recoveryId)->update(['retry_until' => now()->subDay()]);
        $intent = DB::table('payment_recovery_intents')->find($recoveryId);
        $result = app(SubscriptionCreditService::class)->recoverFromProvider($intent);
        $this->assertTrue($result['recovered']);
        $this->assertFalse($result['external_provider_verified']);
        $this->assertSame(1, $refundCalls);
        $this->assertSame(1, $getCalls);
        $this->assertDatabaseCount('subscription_credits', 1);
        $this->assertDatabaseHas('payment_recovery_intents', ['id' => $recoveryId, 'status' => 'completed']);
        $this->assertFalse(app(SubscriptionService::class)->summary($f[2])['entitlements_active']);
    }
}
