<?php

namespace Tests\Feature;

use App\Services\Commercial\Payments\GatewayService;
use App\Services\Commercial\SubscriptionGateway;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class HostedCheckoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['payments.provider' => 'mock']);
        Http::preventStrayRequests();
    }

    private function fakeProvider(callable $callback): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake($callback);
    }

    private function fixture(): array
    {
        return (require base_path('tests/Fixtures/hosted.php'))();
    }

    private function body(array $f, string $scope = 'charging', string $key = 'hosted'): array
    {
        return ['scope' => $scope, 'invoice_id' => $scope === 'charging' ? $f['invoice']->id : $f['subscription_invoice']->id, ...($scope === 'subscription' ? ['subscription_id' => $f['subscription']->id] : []), 'consent' => true, 'idempotency_key' => $key];
    }

    private function create(array $f, string $scope = 'charging', string $key = 'hosted'): array
    {
        return $this->actingAs($f['customer'])->postJson('/api/commercial/hosted-checkouts', $this->body($f, $scope, $key))->assertCreated()->json('checkout');
    }

    public function test_charging_mock_hosted_authorize_capture_replay_and_refund_preserve_canonical_accounting(): void
    {
        $f = $this->fixture();
        $h = $this->create($f);
        $this->assertSame(1050, $h['amount_minor']);
        $this->assertSame('open', $h['status']);
        $this->assertSame('MOCK_HOSTED', $h['evidence']);
        $this->assertStringEndsWith('+00:00', $h['expires_at']);
        $this->assertSame($h['id'], $this->create($f)['id']);
        $this->assertDatabaseCount('finance_payments', 0);
        $this->postJson('/api/commercial/hosted-checkouts/'.$h['id'].'/capture')->assertConflict();
        $this->postJson('/api/commercial/hosted-checkouts/'.$h['id'].'/simulate', ['outcome' => 'authorized', 'consent' => true])->assertOk()->assertJsonPath('checkout.status', 'authorized');
        $this->postJson('/api/commercial/hosted-checkouts/'.$h['id'].'/capture')->assertOk()->assertJsonPath('checkout.status', 'captured');
        $this->postJson('/api/commercial/hosted-checkouts/'.$h['id'].'/capture')->assertOk();
        $this->assertDatabaseCount('finance_payments', 1);
        $this->actingAs($f['vendor']);
        app(GatewayService::class)->operate($h['canonical_checkout_id'], $f['vendor']->id, 'refund', 'hosted-refund', 1050, null, 'Confirmed synthetic refund');
        $this->assertDatabaseCount('finance_refunds', 1);
        $this->getJson('/api/finance/invoices/'.$f['invoice']->id)->assertOk()->assertJsonPath('ledger_balanced', true)->assertJsonPath('net_collected_minor', 0);
    }

    public function test_subscription_checkout_uses_existing_invoice_receipt_and_enforces_held_payment_paths(): void
    {
        $f = $this->fixture();
        $h = $this->create($f, 'subscription');
        $this->postJson('/api/commercial/subscriptions/'.$f['subscription']->id.'/checkouts', ['invoice_id' => $f['subscription_invoice']->id, 'idempotency_key' => 'parallel'])->assertConflict();
        $this->postJson('/api/commercial/hosted-checkouts/'.$h['id'].'/simulate', ['outcome' => 'authorized', 'consent' => true])->assertOk();
        $this->postJson('/api/commercial/hosted-checkouts/'.$h['id'].'/capture')->assertOk()->assertJsonPath('checkout.status', 'captured');
        $this->assertDatabaseCount('commercial_subscription_receipts', 1);
        $this->assertSame('active', DB::table('commercial_subscriptions')->find($f['subscription']->id)->status);
        $this->postJson('/api/commercial/hosted-checkouts/'.$h['id'].'/capture')->assertOk();
        $this->assertDatabaseCount('commercial_subscription_receipts', 1);
    }

    public function test_vendor_can_pay_its_own_package_without_changing_a_customer_subscription(): void
    {
        $f = $this->fixture();
        $h = $this->actingAs($f['vendor'])->postJson('/api/commercial/hosted-checkouts', ['scope' => 'subscription', 'subscription_id' => $f['vendor_subscription']->id, 'invoice_id' => $f['vendor_subscription_invoice']->id, 'consent' => true, 'idempotency_key' => 'vendor-package'])->assertCreated()->assertJsonPath('checkout.owner', true)->json('checkout');
        $this->postJson('/api/commercial/hosted-checkouts/'.$h['id'].'/simulate', ['consent' => true, 'outcome' => 'authorized'])->assertOk();
        $this->postJson('/api/commercial/hosted-checkouts/'.$h['id'].'/capture')->assertOk()->assertJsonPath('checkout.status', 'captured');
        $this->assertDatabaseHas('commercial_subscription_receipts', ['invoice_id' => $f['vendor_subscription_invoice']->id, 'amount_minor' => 1050]);
        $this->assertSame('active', DB::table('commercial_subscriptions')->find($f['vendor_subscription']->id)->status);
        $this->assertDatabaseMissing('commercial_subscription_receipts', ['invoice_id' => $f['subscription_invoice']->id]);
    }

    public function test_owner_scope_consent_server_amounts_and_read_only_admin_vendor_views(): void
    {
        $f = $this->fixture();
        $this->getJson('/api/commercial/hosted-checkouts')->assertUnauthorized();
        $this->actingAs($f['customer']);
        $this->postJson('/api/commercial/hosted-checkouts', $this->body($f) + ['amount_minor' => 1])->assertUnprocessable();
        $this->postJson('/api/commercial/hosted-checkouts', array_replace($this->body($f), ['consent' => false]))->assertUnprocessable();
        $h = $this->create($f);
        foreach (['vendor', 'admin'] as $role) {
            $this->actingAs($f[$role])->getJson('/api/commercial/hosted-checkouts/'.$h['id'])->assertOk()->assertJsonPath('checkout.owner', false)->assertJsonPath('checkout.hosted_url', null)->assertJsonPath('checkout.can_capture', false);
            if ($role === 'vendor') {
                $this->postJson('/api/commercial/hosted-checkouts/'.$h['id'].'/inspect')->assertNotFound();
            } else {
                $this->postJson('/api/commercial/hosted-checkouts/'.$h['id'].'/inspect')->assertOk()->assertJsonPath('checkout.can_capture', false);
            }
        }
        $this->actingAs($f['foreign'])->getJson('/api/commercial/hosted-checkouts/'.$h['id'])->assertNotFound();
        $this->getJson('/api/commercial/hosted-checkouts/by-reference/'.$h['reference'])->assertNotFound();
        $this->getJson('/api/commercial/hosted-checkouts')->assertOk()->assertJsonPath('total', 0);
    }

    public function test_abandonment_requires_explicit_fresh_identity_and_never_replaces_the_original_replay(): void
    {
        $f = $this->fixture();
        $h = $this->create($f);
        $this->postJson('/api/commercial/hosted-checkouts', $this->body($f, key: 'replacement'))->assertConflict();
        $this->postJson('/api/commercial/hosted-checkouts/'.$h['id'].'/simulate', ['outcome' => 'abandoned', 'consent' => true])->assertOk()->assertJsonPath('checkout.can_retry', true);
        $this->assertSame($h['id'], $this->create($f)['id']);
        $new = $this->create($f, key: 'explicit-terminal-retry');
        $this->assertNotSame($h['id'], $new['id']);
        $this->getJson('/api/commercial/hosted-checkouts/'.$h['id'])->assertOk()->assertJsonPath('checkout.can_retry', false);
        $this->assertDatabaseCount('finance_payments', 0);
    }

    private function stripe(): void
    {
        config(['payments.provider' => 'stripe_test', 'payments.stripe_test_secret' => 'sk_test_hostedcontract', 'payments.stripe_test_webhook_secret' => 'whsec_'.str_repeat('h', 40), 'payments.hosted_return_url' => 'https://creabiz.example/payments/hosted-return']);
    }

    private function providerSession(array $h, ?array $pi = null, string $status = 'open'): array
    {
        return ['id' => 'cs_test_Hosted', 'livemode' => false, 'mode' => 'payment', 'client_reference_id' => $h['reference'], 'metadata' => ['creabiz_hosted' => $h['reference']], 'amount_total' => 1050, 'currency' => 'aed', 'status' => $status, 'url' => $status === 'open' ? 'https://checkout.stripe.com/c/pay/cs_test_Hosted#opaque-provider-fragment' : null, 'payment_intent' => $pi];
    }

    private function pi(array $h, string $status): array
    {
        $stored = DB::table('hosted_payment_checkouts')->find($h['id']);

        return ['id' => 'pi_Hosted', 'livemode' => false, 'capture_method' => 'manual', 'amount' => 1050, 'amount_received' => $status === 'succeeded' ? 1050 : 0, 'amount_capturable' => $status === 'requires_capture' ? 1050 : 0, 'currency' => 'aed', 'status' => $status, 'metadata' => ['creabiz_hosted' => $h['reference'], 'creabiz_checkout' => $stored->checkout_reference]];
    }

    private function stripeCreate(array $f): array
    {
        $this->stripe();
        $this->fakeProvider(function ($r) {
            $h = ['reference' => $r['client_reference_id']];

            return Http::response($this->providerSession($h));
        });

        return $this->create($f);
    }

    public function test_unknown_creation_reuses_same_provider_key_and_reconciles_without_posting_or_replacement(): void
    {
        $f = $this->fixture();
        $this->stripe();
        $keys = [];
        $reference = null;
        $this->fakeProvider(function ($r) use (&$keys, &$reference) {
            $keys[] = $r->header('Idempotency-Key')[0];
            $reference = $r['client_reference_id'];
            throw new ConnectionException('Lost synthetic provider acknowledgement');
        });
        $this->actingAs($f['customer'])->postJson('/api/commercial/hosted-checkouts', $this->body($f))->assertStatus(503);
        $h = DB::table('hosted_payment_checkouts')->first();
        $this->assertSame('uncertain', $h->status);
        $this->postJson('/api/commercial/hosted-checkouts', $this->body($f, key: 'unsafe'))->assertConflict();
        $this->fakeProvider(function ($r) use (&$keys, $reference) {
            $keys[] = $r->header('Idempotency-Key')[0];

            return Http::response($this->providerSession(['reference' => $reference]));
        });
        $this->postJson('/api/commercial/hosted-checkouts', $this->body($f))->assertCreated()->assertJsonPath('checkout.id', $h->id);
        $this->assertSame($keys[0], $keys[1]);
        $this->assertDatabaseCount('gateway_checkouts', 1);
        $this->assertDatabaseCount('finance_payments', 0);
    }

    public function test_authoritative_provider_get_then_signed_capture_duplicate_and_late_authorization_post_once(): void
    {
        $f = $this->fixture();
        $h = $this->stripeCreate($f);
        $this->fakeProvider(fn () => Http::response($this->providerSession($h, $this->pi($h, 'requires_capture'), 'complete')));
        $this->postJson('/api/commercial/hosted-checkouts/'.$h['id'].'/inspect')->assertOk()->assertJsonPath('checkout.status', 'authorized');
        $event = ['id' => 'evt_HostedCapture', 'livemode' => false, 'type' => 'payment_intent.succeeded', 'data' => ['object' => $this->pi($h, 'succeeded')]];
        $raw = json_encode($event);
        $ts = time();
        $sig = 't='.$ts.',v1='.hash_hmac('sha256', $ts.'.'.$raw, config('payments.stripe_test_webhook_secret'));
        $service = app(GatewayService::class);
        $this->assertTrue($service->stripeWebhook($raw, $sig)['accepted']);
        $this->assertTrue($service->stripeWebhook($raw, $sig)['duplicate']);
        $this->assertTrue($service->applyHostedEvidence(['id' => 'evt_LateAuthorization', 'type' => 'payment_intent.amount_capturable_updated', 'data' => ['object' => $this->pi($h, 'requires_capture')]])['accepted']);
        $this->getJson('/api/commercial/hosted-checkouts/'.$h['id'])->assertOk()->assertJsonPath('checkout.status', 'captured');
        $this->assertDatabaseCount('finance_payments', 1);
        $this->getJson('/api/finance/invoices/'.$f['invoice']->id)->assertJsonPath('ledger_balanced', true)->assertJsonPath('paid_minor', 1050);
    }

    public function test_expired_provider_with_no_live_intent_can_retry_but_clock_alone_cannot_release_hold(): void
    {
        $f = $this->fixture();
        $h = $this->stripeCreate($f);
        $this->travel(2)->hours();
        $this->postJson('/api/commercial/hosted-checkouts', $this->body($f, key: 'clock-only'))->assertConflict();
        $this->fakeProvider(fn () => Http::response($this->providerSession($h, null, 'expired')));
        $this->postJson('/api/commercial/hosted-checkouts/'.$h['id'].'/inspect')->assertOk()->assertJsonPath('checkout.status', 'expired')->assertJsonPath('checkout.can_retry', true);
        $this->assertDatabaseCount('finance_payments', 0);
    }

    public function test_uncertain_authorized_cancellation_is_reconciled_read_only_before_an_explicit_retry(): void
    {
        $f = $this->fixture();
        $h = $this->stripeCreate($f);
        $authorized = $this->providerSession($h, $this->pi($h, 'requires_capture'), 'complete');
        $this->fakeProvider(fn () => Http::response($authorized));
        $this->postJson('/api/commercial/hosted-checkouts/'.$h['id'].'/inspect')->assertOk();
        $this->fakeProvider(function ($r) use ($authorized) {
            if ($r->method() === 'POST') {
                throw new ConnectionException('Unknown cancellation outcome');
            }

            return Http::response($authorized);
        });
        $this->postJson('/api/commercial/hosted-checkouts/'.$h['id'].'/cancel')->assertStatus(503);
        $this->assertSame('uncertain', DB::table('payment_recovery_intents')->where('kind', 'cancel')->first()->status);
        $this->postJson('/api/commercial/hosted-checkouts', $this->body($f, key: 'unsafe-cancel-retry'))->assertConflict();
        $this->fakeProvider(fn () => Http::response($this->providerSession($h, $this->pi($h, 'canceled'), 'complete')));
        $this->postJson('/api/commercial/hosted-checkouts/'.$h['id'].'/inspect')->assertOk()->assertJsonPath('checkout.status', 'cancelled')->assertJsonPath('checkout.can_retry', true);
        $this->assertSame('completed', DB::table('payment_recovery_intents')->where('kind', 'cancel')->first()->status);
        Http::assertSentCount(1);
        $this->assertDatabaseCount('finance_payments', 0);
    }

    public function test_provider_session_or_intent_binding_cannot_be_replaced_after_first_observation(): void
    {
        $f = $this->fixture();
        $h = $this->stripeCreate($f);
        $this->expectException(QueryException::class);
        DB::table('hosted_payment_checkouts')->where('id', $h['id'])->update(['provider_session' => 'cs_test_Replacement']);
    }

    public function test_admin_provider_inspection_is_read_only_and_records_actual_actor(): void
    {
        $f = $this->fixture();
        $h = $this->stripeCreate($f);
        $this->fakeProvider(fn () => Http::response($this->providerSession($h, $this->pi($h, 'succeeded'), 'complete')));
        $this->actingAs($f['admin'])->postJson('/api/commercial/hosted-checkouts/'.$h['id'].'/inspect')->assertOk()->assertJsonPath('checkout.status', 'captured')->assertJsonPath('checkout.owner', false)->assertJsonPath('checkout.can_capture', false);
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => $r->method() === 'GET');
        $this->assertDatabaseHas('audit_events', ['actor_id' => $f['admin']->id, 'action' => 'finance.hosted.provider_inspected', 'subject' => (string) $h['id']]);
        $this->postJson('/api/commercial/hosted-checkouts/'.$h['id'].'/capture')->assertNotFound();
        $this->assertDatabaseCount('finance_payments', 1);
    }

    public function test_provider_configuration_change_never_retries_on_another_merchant_key(): void
    {
        $f = $this->fixture();
        $h = $this->stripeCreate($f);
        config(['payments.stripe_test_secret' => 'sk_test_othermerchant']);
        $this->fakeProvider(fn () => throw new \RuntimeException('No request should be dispatched'));
        $this->postJson('/api/commercial/hosted-checkouts/'.$h['id'].'/inspect')->assertStatus(503);
        Http::assertNothingSent();
        $this->assertDatabaseCount('finance_payments', 0);
    }

    public function test_hosted_subscription_decline_does_not_destroy_same_session_sca_recovery(): void
    {
        $f = $this->fixture();
        $this->stripe();
        $this->fakeProvider(fn ($r) => Http::response($this->providerSession(['reference' => $r['client_reference_id']])));
        $h = $this->create($f, 'subscription');
        app(SubscriptionGateway::class)->applyHostedEvidence(['id' => 'evt_Declined', 'type' => 'payment_intent.payment_failed', 'data' => ['object' => $this->pi($h, 'requires_payment_method')]]);
        $this->assertSame('pending', DB::table('subscription_gateway_checkouts')->find($h['canonical_checkout_id'])->status);
        $this->fakeProvider(fn () => Http::response($this->providerSession($h, $this->pi($h, 'requires_capture'), 'complete')));
        $this->postJson('/api/commercial/hosted-checkouts/'.$h['id'].'/inspect')->assertOk()->assertJsonPath('checkout.status', 'authorized');
        $this->fakeProvider(fn () => Http::response($this->providerSession($h, $this->pi($h, 'succeeded'), 'complete')));
        $this->postJson('/api/commercial/hosted-checkouts/'.$h['id'].'/inspect')->assertOk()->assertJsonPath('checkout.status', 'captured');
        $this->assertDatabaseCount('commercial_subscription_receipts', 1);
    }

    public function test_uncertain_capture_reconciles_through_existing_recovery_without_rewriting_hosted_metadata(): void
    {
        $f = $this->fixture();
        $h = $this->stripeCreate($f);
        $this->fakeProvider(fn () => Http::response($this->providerSession($h, $this->pi($h, 'requires_capture'), 'complete')));
        $this->postJson('/api/commercial/hosted-checkouts/'.$h['id'].'/inspect')->assertOk();
        $this->fakeProvider(fn () => throw new ConnectionException('Lost capture acknowledgement'));
        $this->postJson('/api/commercial/hosted-checkouts/'.$h['id'].'/capture')->assertStatus(503);
        $this->getJson('/api/commercial/hosted-checkouts/'.$h['id'])->assertJsonPath('checkout.status', 'uncertain')->assertJsonPath('checkout.recovery_operation', 'capture');
        $intent = DB::table('payment_recovery_intents')->where('kind', 'capture')->first();
        $this->fakeProvider(fn () => Http::response($this->pi($h, 'succeeded')));
        app(GatewayService::class)->recoverFromProvider($intent);
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => $r->method() === 'GET');
        $this->getJson('/api/commercial/hosted-checkouts/'.$h['id'])->assertJsonPath('checkout.status', 'captured');
        $this->assertDatabaseCount('finance_payments', 1);
    }

    private function signedHostedEvent(array $h, string $type, string $status, string $eventId, string $scope = 'charging')
    {
        $raw = json_encode(['id' => $eventId, 'livemode' => false, 'type' => $type, 'data' => ['object' => $this->pi($h, $status)]], JSON_THROW_ON_ERROR);
        $time = time();
        $signature = 't='.$time.',v1='.hash_hmac('sha256', $time.'.'.$raw, config('payments.stripe_test_webhook_secret'));
        $path = $scope === 'charging' ? '/api/payments/stripe-test/webhook' : '/api/subscription-payments/stripe-test/webhook';

        return $this->call('POST', $path, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => $signature], $raw);
    }

    private function assertSignedHostedOrder(string $scope): void
    {
        $f = $this->fixture();
        $this->stripe();
        $this->fakeProvider(fn ($r) => Http::response($this->providerSession(['reference' => $r['client_reference_id']])));
        $h = $this->create($f, $scope);
        // A signed capture may arrive before the earlier authorization event.
        foreach ([1, 2] as $delivery) {
            $this->signedHostedEvent($h, 'payment_intent.succeeded', 'succeeded', 'evt_HostedPaid', $scope)->assertOk();
        }
        $this->signedHostedEvent($h, 'payment_intent.amount_capturable_updated', 'requires_capture', 'evt_HostedLateAuth', $scope)->assertOk();
        $this->getJson('/api/commercial/hosted-checkouts/'.$h['id'])->assertOk()->assertJsonPath('checkout.status', 'captured')->assertJsonPath('checkout.can_retry', false);
        $this->assertDatabaseCount($scope === 'charging' ? 'finance_payments' : 'commercial_subscription_receipts', 1);
        if ($scope === 'charging') {
            $this->getJson('/api/finance/invoices/'.$f['invoice']->id)->assertJsonPath('ledger_balanced', true)->assertJsonPath('paid_minor', 1050);
        } else {
            $this->assertSame('active', DB::table('commercial_subscriptions')->find($f['subscription']->id)->status);
        }
    }

    public function test_signed_charging_webhook_duplicates_and_out_of_order_authorization_never_repost_or_rewind_capture(): void
    {
        $this->assertSignedHostedOrder('charging');
    }

    public function test_signed_subscription_webhook_duplicates_and_out_of_order_authorization_never_repost_or_rewind_capture(): void
    {
        $this->assertSignedHostedOrder('subscription');
    }

    public function test_even_signed_hosted_success_must_not_cross_a_changed_merchant_configuration(): void
    {
        $f = $this->fixture();
        $h = $this->stripeCreate($f);
        config(['payments.stripe_test_secret' => 'sk_test_othermerchant']);
        $this->signedHostedEvent($h, 'payment_intent.succeeded', 'succeeded', 'evt_WrongMerchant')->assertStatus(503);
        $this->assertDatabaseCount('finance_payments', 0);
        $this->assertNull(DB::table('hosted_payment_checkouts')->find($h['id'])->provider_intent);
    }

    public function test_legacy_refund_route_cannot_switch_merchant_credentials_for_a_hosted_capture(): void
    {
        $f = $this->fixture();
        $h = $this->stripeCreate($f);
        $this->signedHostedEvent($h, 'payment_intent.succeeded', 'succeeded', 'evt_HostedCapturedBeforeRefund')->assertOk();
        config(['payments.stripe_test_secret' => 'sk_test_anothermerchant']);
        $this->fakeProvider(fn () => throw new \RuntimeException('Must not contact a different merchant.'));
        $this->actingAs($f['admin'])->postJson('/api/commercial/checkouts/'.$h['canonical_checkout_id'].'/refund', ['amount_minor' => 100, 'reason' => 'Authorized review', 'idempotency_key' => 'wrong-merchant-refund'])->assertStatus(503);
        Http::assertNothingSent();
        $this->assertDatabaseCount('finance_refunds', 0);
    }

    public function test_signed_refund_cannot_cross_changed_hosted_merchant_configuration(): void
    {
        $f = $this->fixture();
        $h = $this->stripeCreate($f);
        $this->signedHostedEvent($h, 'payment_intent.succeeded', 'succeeded', 'evt_OriginalCapture')->assertOk();
        config(['payments.stripe_test_secret' => 'sk_test_changedmerchant']);
        $object = ['id' => 're_HostedWrongMerchant', 'payment_intent' => 'pi_Hosted', 'livemode' => false, 'currency' => 'aed', 'amount' => 100, 'status' => 'succeeded'];
        $raw = json_encode(['id' => 'evt_ChangedMerchantRefund', 'livemode' => false, 'type' => 'refund.updated', 'data' => ['object' => $object]], JSON_THROW_ON_ERROR);
        $time = time();
        $signature = 't='.$time.',v1='.hash_hmac('sha256', $time.'.'.$raw, config('payments.stripe_test_webhook_secret'));
        $this->call('POST', '/api/payments/stripe-test/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => $signature], $raw)->assertStatus(503);
        $this->assertDatabaseCount('finance_refunds', 0);
    }

    public function test_unsigned_provider_success_never_confirms_a_hosted_payment(): void
    {
        $f = $this->fixture();
        $h = $this->stripeCreate($f);
        $raw = json_encode(['id' => 'evt_Forged', 'livemode' => false, 'type' => 'payment_intent.succeeded', 'data' => ['object' => $this->pi($h, 'succeeded')]]);
        try {
            app(GatewayService::class)->stripeWebhook($raw, 't='.time().',v1='.str_repeat('0', 64));
            $this->fail('Forged success was accepted');
        } catch (HttpException $e) {
            $this->assertSame(401, $e->getStatusCode());
        }
        $this->getJson('/api/commercial/hosted-checkouts/'.$h['id'])->assertJsonPath('checkout.status', 'open');
        $this->assertDatabaseCount('finance_payments', 0);
    }

    public function test_expired_session_with_live_authorization_does_not_allow_a_replacement(): void
    {
        $f = $this->fixture();
        $h = $this->stripeCreate($f);
        $this->fakeProvider(fn () => Http::response($this->providerSession($h, $this->pi($h, 'requires_capture'), 'expired')));
        $this->postJson('/api/commercial/hosted-checkouts/'.$h['id'].'/inspect')->assertOk()->assertJsonPath('checkout.status', 'authorized')->assertJsonPath('checkout.can_retry', false);
        $this->postJson('/api/commercial/hosted-checkouts', $this->body($f, key: 'unsafe-expiry'))->assertConflict();
        $this->assertDatabaseCount('finance_payments', 0);
    }

    public function test_source_amount_mismatch_and_untrusted_hosted_destination_fail_without_posting(): void
    {
        $f = $this->fixture();
        $h = $this->stripeCreate($f);
        $bad = $this->providerSession($h, $this->pi($h, 'succeeded'), 'complete');
        $bad['amount_total'] = 1;
        $this->fakeProvider(fn () => Http::response($bad));
        $this->postJson('/api/commercial/hosted-checkouts/'.$h['id'].'/inspect')->assertConflict();
        $bad = $this->providerSession($h);
        $bad['url'] = 'https://checkout.stripe.com.evil.example/c/pay/cs_test_Hosted';
        $this->fakeProvider(fn () => Http::response($bad));
        $this->postJson('/api/commercial/hosted-checkouts/'.$h['id'].'/inspect')->assertStatus(502);
        $this->assertDatabaseCount('finance_payments', 0);
    }

    public function test_production_and_changed_provider_flags_never_advertise_mutations_or_simulation(): void
    {
        $f = $this->fixture();
        $h = $this->create($f);
        config(['payments.provider' => 'stripe_test']);
        $this->getJson('/api/commercial/hosted-checkouts/'.$h['id'])->assertJsonPath('checkout.can_simulate', false)->assertJsonPath('checkout.can_cancel', false)->assertJsonPath('checkout.can_inspect', false);
        config(['payments.provider' => 'mock']);
        $this->app->detectEnvironment(fn () => 'production');
        $this->getJson('/api/commercial/hosted-checkouts/'.$h['id'])->assertJsonPath('checkout.can_simulate', false)->assertJsonPath('checkout.can_capture', false)->assertJsonPath('checkout.simulation_url', null);
        $this->withSession(['_token' => 'production-csrf-token'])->postJson('/api/commercial/hosted-checkouts/'.$h['id'].'/simulate', ['_token' => 'production-csrf-token', 'consent' => true, 'outcome' => 'authorized'])->assertStatus(503);
    }

    private function assertOriginalPreparationRetry(string $scope): void
    {
        $f = $this->fixture();
        $this->stripe();
        $requests = [];
        $this->fakeProvider(function ($request) use (&$requests) {
            $requests[] = ['key' => $request->header('Idempotency-Key'), 'body' => $request->data()];
            throw new ConnectionException('Unconfirmed original session creation');
        });
        $this->actingAs($f['customer'])->postJson('/api/commercial/hosted-checkouts', $this->body($f, $scope))->assertStatus(503);
        $h = (array) DB::table('hosted_payment_checkouts')->first();
        $this->fakeProvider(fn () => Http::response(['object' => 'list', 'data' => [], 'has_more' => false]));
        $this->postJson('/api/commercial/hosted-checkouts/'.$h['id'].'/inspect')->assertOk()->assertJsonPath('checkout.status', 'needs_review')->assertJsonPath('checkout.can_retry_preparation', true);
        $this->actingAs($f['foreign'])->postJson('/api/commercial/hosted-checkouts/'.$h['id'].'/retry-preparation')->assertNotFound();
        $this->actingAs($f['customer']);
        $this->fakeProvider(function ($request) use (&$requests, $h) {
            $requests[] = ['key' => $request->header('Idempotency-Key'), 'body' => $request->data()];

            return Http::response($this->providerSession($h));
        });
        $this->postJson('/api/commercial/hosted-checkouts/'.$h['id'].'/retry-preparation')->assertOk()->assertJsonPath('checkout.id', $h['id'])->assertJsonPath('checkout.status', 'open')->assertJsonPath('checkout.can_retry_preparation', false);
        $this->assertSame($requests[0], $requests[1]);
        $this->assertDatabaseCount('hosted_payment_checkouts', 1);
        $this->assertDatabaseCount($scope === 'charging' ? 'gateway_checkouts' : 'subscription_gateway_checkouts', 1);
        $this->assertDatabaseCount('finance_payments', 0);
        $this->assertDatabaseCount('commercial_subscription_receipts', 0);
        $this->postJson('/api/commercial/hosted-checkouts/'.$h['id'].'/retry-preparation')->assertConflict();
        Http::assertSentCount(1);
    }

    public function test_unknown_charging_session_creation_retries_original_body_and_provider_key_after_negative_lookup(): void
    {
        $this->assertOriginalPreparationRetry('charging');
    }

    public function test_unknown_subscription_session_creation_retries_original_body_and_provider_key_after_negative_lookup(): void
    {
        $this->assertOriginalPreparationRetry('subscription');
    }

    public function test_hosted_provider_url_must_belong_to_the_exact_verified_session(): void
    {
        $f = $this->fixture();
        $h = $this->stripeCreate($f);
        $response = $this->providerSession($h);
        $response['url'] = 'https://checkout.stripe.com/c/pay/cs_test_Different#opaque';
        $this->fakeProvider(fn () => Http::response($response));
        $this->postJson('/api/commercial/hosted-checkouts/'.$h['id'].'/inspect')->assertConflict();
        $this->assertDatabaseCount('finance_payments', 0);
        $this->getJson('/api/commercial/hosted-checkouts/'.$h['id'])->assertJsonPath('checkout.hosted_url', 'https://checkout.stripe.com/c/pay/cs_test_Hosted#opaque-provider-fragment');
    }

    public function test_hosted_identity_and_audit_cannot_be_deleted_or_rewritten(): void
    {
        $f = $this->fixture();
        $h = $this->create($f);
        $this->expectException(QueryException::class);
        DB::table('hosted_payment_checkouts')->where('id', $h['id'])->update(['amount_minor' => 1]);
    }
}
