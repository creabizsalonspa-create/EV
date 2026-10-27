<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Commercial\SubscriptionGateway;
use App\Services\Commercial\SubscriptionService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class SubscriptionGatewayTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['payments.provider' => 'stripe_test', 'payments.stripe_test_secret' => 'sk_test_contractonly', 'payments.stripe_test_webhook_secret' => 'whsec_'.str_repeat('contractonly', 4)]);
        Http::preventStrayRequests();
        Route::middleware(['web', 'auth'])->prefix('subscription-gateway-test')->group(base_path('routes/subscriptions.php'));
        Route::middleware(['web', 'auth'])->prefix('subscription-gateway-test')->group(base_path('routes/subscription_gateway.php'));
        Route::prefix('subscription-gateway-test')->group(base_path('routes/subscription_webhooks.php'));
    }

    private function fixture(): array
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $user = User::factory()->create();
        $service = app(SubscriptionService::class);
        $plan = $service->publish($admin, ['code' => 'customer-pro', 'name' => 'Customer Pro', 'audience' => 'customer', 'currency' => 'AED', 'minor_units' => 2, 'price_minor' => 1000, 'tax_bps' => 500, 'interval' => 'month', 'entitlements' => json_encode(['premium_reports' => true])]);
        $s = $service->subscribe($user, $plan->id, 'enroll');
        $this->actingAs($user);
        $c = $this->postJson('/subscription-gateway-test/commercial/subscriptions/'.$s['subscription']->id.'/checkouts', ['invoice_id' => $s['invoices']->first()->id, 'idempotency_key' => 'checkout'])->assertCreated()->json();

        return [$user, $s, $c, '/subscription-gateway-test/commercial/subscription-checkouts/'.$c['id']];
    }

    private function authResult(array $extra = []): array
    {
        return array_replace(['id' => 'pi_Subscription', 'livemode' => false, 'amount' => 1050, 'currency' => 'aed', 'status' => 'requires_capture'], $extra);
    }

    private function event(array $object, string $type = 'payment_intent.succeeded', string $id = 'evt_Subscription'): array
    {
        $raw = json_encode(['id' => $id, 'livemode' => false, 'type' => $type, 'data' => ['object' => $object]], JSON_THROW_ON_ERROR);
        $timestamp = time();
        $signature = 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$raw, config('payments.stripe_test_webhook_secret'));

        return [$raw, $signature];
    }

    private function webhook(array $event)
    {
        return $this->call('POST', '/subscription-gateway-test/subscription-payments/stripe-test/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => $event[1]], $event[0]);
    }

    private function authorize(array $f): void
    {
        $this->postJson($f[3].'/authorize', ['token' => 'pm_testcard', 'idempotency_key' => 'authorize'])->assertOk()->assertJsonPath('status', 'authorized');
    }

    public function test_stripe_contract_capture_uses_shared_invoice_receipt_ledger_and_entitlements_once(): void
    {
        Http::fake(['https://api.stripe.com/v1/payment_intents' => Http::response($this->authResult()), 'https://api.stripe.com/v1/payment_intents/pi_Subscription/capture' => Http::response($this->authResult(['status' => 'succeeded', 'amount_received' => 1050]))]);
        $f = $this->fixture();
        $this->authorize($f);
        $this->postJson($f[3].'/capture', ['idempotency_key' => 'capture'])->assertOk()->assertJsonPath('status', 'captured');
        $this->postJson($f[3].'/capture', ['idempotency_key' => 'capture'])->assertOk();
        $detail = $this->getJson('/subscription-gateway-test/commercial/subscriptions/'.$f[1]['subscription']->id)->assertOk()->assertJsonPath('entitlements_active', true)->assertJsonPath('billing_provider', 'STRIPE_TEST_CONTRACT')->assertJsonPath('ledger_balanced', true)->assertJsonPath('real_payment_processed', false)->json();
        $this->assertSame('STRIPE_TEST_CONTRACT', $detail['receipts'][0]['evidence']);
        $this->assertDatabaseCount('commercial_subscription_receipts', 1);
        $this->assertSame(2, DB::table('commercial_subscription_ledger')->where('kind', 'payment')->count());
        Http::assertSentCount(2);
    }

    public function test_signed_webhook_recovers_capture_without_second_external_call_and_rejects_conflicts(): void
    {
        Http::fake(['https://api.stripe.com/v1/payment_intents' => Http::response($this->authResult())]);
        $f = $this->fixture();
        $this->authorize($f);
        $event = $this->event($this->authResult(['status' => 'succeeded', 'amount_received' => 1050]));
        $this->webhook($event)->assertOk()->assertJsonPath('duplicate', false);
        $this->webhook($event)->assertOk()->assertJsonPath('duplicate', true);
        $this->postJson($f[3].'/capture', ['idempotency_key' => 'after-webhook'])->assertOk()->assertJsonPath('status', 'captured');
        $this->webhook($this->event($this->authResult(['status' => 'succeeded', 'amount_received' => 1050, 'amount' => 1049])))->assertStatus(409);
        $this->assertDatabaseCount('commercial_subscription_receipts', 1);
        $this->assertDatabaseCount('subscription_gateway_webhooks', 1);
        Http::assertSentCount(1);
    }

    public function test_invalid_signatures_stale_events_wrong_amount_currency_and_live_mode_cannot_post_receipts(): void
    {
        $f = $this->fixture();
        $object = $this->authResult(['status' => 'succeeded', 'amount_received' => 1050, 'metadata' => ['creabiz_checkout' => $f[2]['provider_reference']]]);
        $event = $this->event($object);
        $this->webhook([$event[0], 't='.time().',v1='.str_repeat('0', 64)])->assertUnauthorized();
        $old = time() - 1000;
        $this->webhook([$event[0], 't='.$old.',v1='.hash_hmac('sha256', $old.'.'.$event[0], config('payments.stripe_test_webhook_secret'))])->assertUnauthorized();
        $this->webhook($this->event(array_replace($object, ['amount_received' => 1049])))->assertStatus(409);
        $this->webhook($this->event(array_replace($object, ['currency' => 'usd'])))->assertStatus(409);
        $this->webhook($this->event(array_replace($object, ['livemode' => true])))->assertUnprocessable();
        $this->assertDatabaseCount('commercial_subscription_receipts', 0);
        Http::assertNothingSent();
    }

    public function test_declined_and_action_required_authorizations_never_grant_features(): void
    {
        Http::fake(['https://api.stripe.com/v1/payment_intents' => Http::sequence()->push([], 402)->push($this->authResult(['status' => 'requires_action']))]);
        $f = $this->fixture();
        $this->postJson($f[3].'/authorize', ['token' => 'pm_decline', 'idempotency_key' => 'decline'])->assertOk()->assertJsonPath('status', 'failed');
        $this->postJson($f[3].'/capture', ['idempotency_key' => 'bad-capture'])->assertConflict();
        $this->assertFalse(app(SubscriptionService::class)->summary($f[1]['subscription']->id)['entitlements_active']);
        $this->assertDatabaseCount('commercial_subscription_receipts', 0);
        $c = $this->postJson('/subscription-gateway-test/commercial/subscriptions/'.$f[1]['subscription']->id.'/checkouts', ['invoice_id' => $f[2]['invoice_id'], 'idempotency_key' => 'replacement'])->assertCreated()->json();
        $this->postJson('/subscription-gateway-test/commercial/subscription-checkouts/'.$c['id'].'/authorize', ['token' => 'pm_sca', 'idempotency_key' => 'sca'])->assertOk()->assertJsonPath('status', 'action_required')->assertJsonMissingPath('client_secret');
        $this->assertDatabaseCount('commercial_subscription_receipts', 0);
    }

    public function test_tenant_checks_and_outstanding_gateway_guards_prevent_mixed_mock_double_settlement(): void
    {
        $f = $this->fixture();
        $this->postJson('/subscription-gateway-test/commercial/subscriptions/'.$f[1]['subscription']->id.'/mock-payment', ['invoice_id' => $f[2]['invoice_id'], 'outcome' => 'succeeded', 'idempotency_key' => 'manual'])->assertConflict();
        $this->postJson('/subscription-gateway-test/commercial/subscriptions/'.$f[1]['subscription']->id.'/change', ['action' => 'cancel_now', 'idempotency_key' => 'cancel'])->assertConflict();
        $this->actingAs(User::factory()->create())->getJson('/subscription-gateway-test/commercial/subscription-checkouts')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson($f[3].'/authorize', ['token' => 'pm_testcard', 'idempotency_key' => 'other'])->assertNotFound();
        $this->getJson($f[3].'/reconciliation')->assertNotFound();
        $this->actingAs($f[0])->postJson($f[3].'/cancel', ['idempotency_key' => 'cancel-checkout'])->assertOk()->assertJsonPath('status', 'cancelled');
        $this->postJson('/subscription-gateway-test/commercial/subscriptions/'.$f[1]['subscription']->id.'/mock-payment', ['invoice_id' => $f[2]['invoice_id'], 'outcome' => 'succeeded', 'idempotency_key' => 'manual'])->assertOk();
        $this->assertDatabaseCount('commercial_subscription_receipts', 1);
    }

    public function test_timeout_retries_keep_provider_capture_key_and_do_not_double_post(): void
    {
        $captureCalls = 0;
        $keys = [];
        Http::fake(function ($r) use (&$captureCalls, &$keys) {
            if (str_ends_with($r->url(), '/capture')) {
                $keys[] = $r->header('Idempotency-Key')[0];
                if (++$captureCalls === 1) {
                    throw new ConnectionException('test timeout');
                }

                return Http::response($this->authResult(['status' => 'succeeded', 'amount_received' => 1050]));
            }

            return Http::response($this->authResult());
        });
        $f = $this->fixture();
        $this->authorize($f);
        $this->postJson($f[3].'/capture', ['idempotency_key' => 'uncertain'])->assertStatus(503);
        $this->assertDatabaseCount('commercial_subscription_receipts', 0);
        $this->postJson($f[3].'/capture', ['idempotency_key' => 'replacement-key'])->assertOk();
        $this->assertSame($keys[0], $keys[1]);
        $this->assertDatabaseCount('commercial_subscription_receipts', 1);
    }

    public function test_reconciliation_compares_provider_currency_capture_and_refund_amounts(): void
    {
        Http::fake(['https://api.stripe.com/v1/payment_intents' => Http::response($this->authResult()), 'https://api.stripe.com/v1/payment_intents/pi_Subscription/capture' => Http::response($this->authResult(['status' => 'succeeded', 'amount_received' => 1050])), 'https://api.stripe.com/v1/payment_intents/pi_Subscription?*' => Http::sequence()->push($this->authResult(['status' => 'succeeded', 'amount_received' => 1050, 'latest_charge' => ['amount_refunded' => 0]]))->push($this->authResult(['status' => 'succeeded', 'amount_received' => 1049, 'latest_charge' => ['amount_refunded' => 0]]))]);
        $f = $this->fixture();
        $this->authorize($f);
        $this->postJson($f[3].'/capture', ['idempotency_key' => 'capture'])->assertOk();
        $this->getJson($f[3].'/reconciliation')->assertOk()->assertJsonPath('matched', true)->assertJsonPath('external_provider_verified', false);
        $this->getJson($f[3].'/reconciliation')->assertOk()->assertJsonPath('matched', false);
    }

    public function test_mock_provider_reuses_same_subscription_settlement_and_production_rejects_every_provider(): void
    {
        config(['payments.provider' => 'mock']);
        $f = $this->fixture();
        $this->postJson($f[3].'/authorize', ['token' => 'mock_success', 'idempotency_key' => 'mock-auth'])->assertOk();
        $this->postJson($f[3].'/capture', ['idempotency_key' => 'mock-capture'])->assertOk();
        $this->assertDatabaseHas('commercial_subscription_receipts', ['evidence' => 'MOCK_PROVIDER']);
        Http::assertNothingSent();
        $this->app->detectEnvironment(fn () => 'production');
        try {
            app(SubscriptionGateway::class)->operate($f[0], $f[2]['id'], 'capture', 'prod', null);
            $this->fail('Production capture accepted');
        } catch (HttpException $e) {
            $this->assertSame(503, $e->getStatusCode());
        }
        $this->assertDatabaseCount('commercial_subscription_receipts', 1);
    }

    public function test_out_of_order_events_do_not_downgrade_capture_and_gateway_evidence_is_immutable(): void
    {
        Http::fake(['https://api.stripe.com/v1/payment_intents' => Http::response($this->authResult())]);
        $f = $this->fixture();
        $this->authorize($f);
        $this->webhook($this->event($this->authResult(['status' => 'succeeded', 'amount_received' => 1050])))->assertOk();
        $this->webhook($this->event($this->authResult(['status' => 'requires_capture', 'amount_capturable' => 1050]), 'payment_intent.amount_capturable_updated', 'evt_LateAuthorization'))->assertOk();
        $this->webhook($this->event($this->authResult(['status' => 'requires_payment_method']), 'payment_intent.payment_failed', 'evt_LateFailure'))->assertOk();
        $this->assertDatabaseHas('subscription_gateway_checkouts', ['id' => $f[2]['id'], 'status' => 'captured']);
        $this->assertDatabaseCount('commercial_subscription_receipts', 1);
        foreach (['subscription_gateway_operations', 'subscription_gateway_webhooks'] as $table) {
            try {
                DB::transaction(fn () => DB::table($table)->delete());
                $this->fail('Gateway evidence was deleted');
            } catch (QueryException $e) {
                $this->assertStringContainsString('immutable', $e->getMessage());
            }
        }
    }
}
