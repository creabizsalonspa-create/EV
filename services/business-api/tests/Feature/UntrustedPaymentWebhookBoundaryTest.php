<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Never call a provider or transfer money in this suite. Exercise every
 * externally reachable payment/dispute web hook with forged success evidence.
 */
class UntrustedPaymentWebhookBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_payment_webhook_families_reject_unsigned_or_forged_success(): void
    {
        Http::preventStrayRequests();
        config([
            'payments.provider' => 'mock',
            'payments.webhook_secret' => str_repeat('a', 48),
        ]);

        $paths = [
            '/api/payments/mock/webhook',
            '/api/payments/stripe-test/webhook',
            '/api/subscription-payments/stripe-test/webhook',
            '/api/subscription-credit-payments/stripe-test/webhook',
            '/api/payment-enrollments/stripe-test/webhook',
            '/api/corporate-payments/stripe-test/webhook',
            '/api/disputes/stripe-test/webhook',
            '/api/webhooks/payouts/stripe-test',
        ];
        $raw = json_encode([
            'id' => 'evt_agent9_forged_capture',
            'event_id' => 'evt_agent9_forged_capture',
            'type' => 'payment_intent.succeeded',
            'created' => time(),
            'data' => ['object' => [
                'id' => 'pi_agent9_no_real_money',
                'status' => 'succeeded',
                'amount' => 999999999,
                'currency' => 'aed',
            ]],
            'invoice_id' => 1,
            'amount_minor' => 999999999,
            'status' => 'captured',
        ], JSON_THROW_ON_ERROR);

        foreach ($paths as $path) {
            foreach ([[], [
                'HTTP_STRIPE_SIGNATURE' => 't='.time().',v1='.str_repeat('0', 64),
                'HTTP_X_PAYMENT_TIMESTAMP' => (string) time(),
                'HTTP_X_PAYMENT_SIGNATURE' => str_repeat('0', 64),
            ]] as $headers) {
                $response = $this->call('POST', $path, [], [], [], [
                    'CONTENT_TYPE' => 'application/json',
                    'HTTP_ACCEPT' => 'application/json',
                ] + $headers, $raw);

                $this->assertContains($response->status(), [400, 401, 403, 404, 409, 422, 429, 503],
                    'A forged webhook must not authorize funds, redirect or crash: '.$path);
                $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'), $path);
                $this->assertSame('DENY', $response->headers->get('X-Frame-Options'), $path);
            }
        }

        $this->assertDatabaseCount('gateway_webhook_events', 0);
        $this->assertDatabaseCount('finance_payments', 0);
        $this->assertDatabaseCount('finance_refunds', 0);
        Http::assertNothingSent();
    }

    public function test_configured_stripe_test_rejects_forged_stale_tampered_or_live_mode_webhook(): void
    {
        Http::preventStrayRequests();
        $secret = 'whsec_'.str_repeat('s', 48);
        config([
            'payments.provider' => 'stripe_test',
            'payments.stripe_test_webhook_secret' => $secret,
            'payments.stripe_test_secret' => 'sk_test_agent9_no_payment_calls',
        ]);

        $path = '/api/payments/stripe-test/webhook';
        $time = time();
        $event = json_encode([
            'id' => 'evt_agent9_synthetic_provider_proof',
            'livemode' => false,
            'type' => 'payment_intent.succeeded',
            'data' => ['object' => ['id' => 'pi_agent9_untrusted', 'amount' => 5000]],
        ], JSON_THROW_ON_ERROR);
        $hmac = fn (string $raw, int $timestamp): string => hash_hmac('sha256', $timestamp.'.'.$raw, $secret);
        $send = fn (string $raw, string $signature) => $this->call('POST', $path, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => $signature,
        ], $raw);

        $send($event, '')->assertUnauthorized();
        $send($event, 't='.$time.',v1='.str_repeat('0', 64))->assertUnauthorized();
        $send($event.' ', 't='.$time.',v1='.$hmac($event, $time))->assertUnauthorized();

        $stale = $time - 301;
        $send($event, 't='.$stale.',v1='.$hmac($event, $stale))->assertUnauthorized();

        // Even a correctly signed message cannot turn a Stripe live-mode
        // notification into test-only charging money movement.
        $live = json_encode([
            'id' => 'evt_agent9_marked_live_not_real',
            'livemode' => true,
            'type' => 'payment_intent.succeeded',
            'data' => ['object' => ['id' => 'pi_agent9_untrusted', 'amount' => 5000]],
        ], JSON_THROW_ON_ERROR);
        $send($live, 't='.$time.',v1='.$hmac($live, $time))->assertUnprocessable();

        $this->assertDatabaseCount('gateway_webhook_events', 0);
        $this->assertDatabaseCount('finance_payments', 0);
        $this->assertDatabaseCount('finance_refunds', 0);
        Http::assertNothingSent();
    }

    public function test_valid_stripe_test_signature_never_processes_in_production(): void
    {
        Http::preventStrayRequests();
        $secret = 'whsec_'.str_repeat('s', 48);
        config([
            'payments.provider' => 'stripe_test',
            'payments.stripe_test_webhook_secret' => $secret,
            'payments.stripe_test_secret' => 'sk_test_agent9_no_money',
        ]);
        $raw = json_encode([
            'id' => 'evt_agent9_inert_production_gate',
            'livemode' => false,
            'type' => 'payment_intent.succeeded',
            'data' => ['object' => [
                'id' => 'pi_agent9_no_real_money',
                'amount' => 1200,
                'currency' => 'aed',
            ]],
        ], JSON_THROW_ON_ERROR);
        $timestamp = time();
        $signature = 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$raw, $secret);

        // The test adapter is categorically disabled in production, even if
        // an attacker knows its development-only signing key.
        app()->detectEnvironment(fn () => 'production');
        try {
            $this->call('POST', '/api/payments/stripe-test/webhook', [], [], [], [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_STRIPE_SIGNATURE' => $signature,
            ], $raw)->assertStatus(503);

            $this->assertDatabaseCount('gateway_webhook_events', 0);
            $this->assertDatabaseCount('finance_payments', 0);
            $this->assertDatabaseCount('finance_refunds', 0);
            Http::assertNothingSent();
        } finally {
            app()->detectEnvironment(fn () => 'testing');
        }
    }
}
