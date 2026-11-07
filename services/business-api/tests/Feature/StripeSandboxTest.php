<?php

namespace Tests\Feature;

use App\Http\Controllers\PaymentGatewayController;
use App\Models\ChargingSession;
use App\Models\Reservation;
use App\Models\Station;
use App\Models\User;
use App\Services\Commercial\Payments\StripeSandboxProvider;
use App\Services\Finance\FinanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/** HTTP contract tests only; no Stripe account or network connection is used. */
class StripeSandboxTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['payments.provider' => 'stripe_test', 'payments.stripe_test_secret' => 'sk_test_contractonly', 'payments.stripe_test_webhook_secret' => 'whsec_'.str_repeat('x', 40)]);
        Http::preventStrayRequests();
        Route::middleware(['web', 'auth'])->prefix('stripe-test')->group(base_path('routes/payments.php'));
        Route::post('/stripe-webhook-test', [PaymentGatewayController::class, 'stripeWebhook']);
    }

    private function fixture(): array
    {
        $vendor = User::factory()->create(['role' => 'vendor']);
        $user = User::factory()->create();
        $station = Station::create(['vendor_id' => $vendor->id, 'name' => 'Stripe contract simulator', 'country' => 'AE', 'currency' => 'AED', 'timezone' => 'Asia/Dubai', 'latitude' => 25, 'longitude' => 55, 'source' => 'simulator', 'connector_type' => 'CCS2', 'power_kw' => 50]);
        $reservation = Reservation::create(['user_id' => $user->id, 'station_id' => $station->id, 'starts_at' => now(), 'status' => 'confirmed', 'idempotency_key' => 'stripe-fixture']);
        $session = ChargingSession::create(['reservation_id' => $reservation->id, 'status' => 'stopped', 'energy_wh' => 10000, 'rate_minor_per_kwh' => 100, 'currency' => 'AED', 'total_minor' => 1000, 'stopped_at' => now()]);

        return [$user, $vendor, app(FinanceService::class)->issue($session, $user->id)];
    }

    public function test_stripe_manual_authorization_capture_refund_and_signed_callback_use_immutable_ledger(): void
    {
        Http::fake([
            'api.stripe.com/v1/payment_intents' => Http::response(['id' => 'pi_contract', 'livemode' => false, 'status' => 'requires_capture', 'amount' => 1000, 'currency' => 'aed']),
            'api.stripe.com/v1/payment_intents/pi_contract/capture' => Http::response(['id' => 'pi_contract', 'livemode' => false, 'status' => 'succeeded', 'amount_received' => 1000]),
            'api.stripe.com/v1/refunds' => Http::response(['id' => 're_contract', 'payment_intent' => 'pi_contract', 'livemode' => false, 'status' => 'succeeded', 'amount' => 400]),
            'api.stripe.com/v1/payment_intents/pi_contract?*' => Http::response(['id' => 'pi_contract', 'livemode' => false, 'status' => 'succeeded', 'currency' => 'aed', 'amount_received' => 1000, 'latest_charge' => ['amount_refunded' => 400]]),
        ]);
        [$user, $vendor, $invoice] = $this->fixture();
        $this->actingAs($user);
        $id = $this->postJson('/stripe-test/commercial/checkouts', ['invoice_id' => $invoice->id, 'amount_minor' => 1000, 'idempotency_key' => 'stripe-checkout'])->assertCreated()->assertJsonPath('provider', 'stripe_test')->assertJsonPath('evidence', 'STRIPE_TEST_CONTRACT')->json('id');
        $base = '/stripe-test/commercial/checkouts/'.$id;
        $this->postJson($base.'/authorize', ['token' => 'pm_card_visa', 'idempotency_key' => 'authorize'])->assertOk()->assertJsonPath('provider_reference', 'pi_contract');
        $this->postJson($base.'/capture', ['idempotency_key' => 'capture'])->assertOk()->assertJsonPath('status', 'captured');
        $this->postJson($base.'/capture', ['idempotency_key' => 'capture'])->assertOk();
        $event = ['id' => 'evt_capture', 'type' => 'payment_intent.succeeded', 'livemode' => false, 'data' => ['object' => ['id' => 'pi_contract', 'livemode' => false, 'status' => 'succeeded', 'amount' => 1000, 'amount_received' => 1000, 'currency' => 'aed']]];
        $this->event($event)->assertOk()->assertJsonPath('duplicate', false);
        $this->event($event)->assertOk()->assertJsonPath('duplicate', true);
        $this->actingAs($vendor)->postJson($base.'/refund', ['amount_minor' => 400, 'reason' => 'Contract refund', 'idempotency_key' => 'refund'])->assertOk();
        $refundEvent = ['id' => 'evt_refund', 'type' => 'refund.updated', 'livemode' => false, 'data' => ['object' => ['id' => 're_contract', 'payment_intent' => 'pi_contract', 'livemode' => false, 'status' => 'succeeded', 'amount' => 400, 'currency' => 'aed']]];
        $this->event($refundEvent)->assertOk();
        $this->assertDatabaseCount('finance_payments', 1);
        $this->assertDatabaseCount('finance_refunds', 1);
        $this->assertTrue(app(FinanceService::class)->summary($invoice)['ledger_balanced']);
        $this->getJson('/stripe-test/commercial/reconciliation/'.$invoice->id)->assertOk()->assertJsonPath('matched', true)->assertJsonPath('provider_observations.0.captured_minor', 1000)->assertJsonPath('provider_observations.0.refunded_minor', 400);
        Http::assertSentCount(4);
        Http::assertSent(fn ($r) => $r->url() === 'https://api.stripe.com/v1/payment_intents' && $r['capture_method'] === 'manual' && $r['amount'] === 1000 && $r['currency'] === 'aed' && $r->hasHeader('Idempotency-Key') && $r->hasHeader('Stripe-Version', '2024-06-20'));
    }

    private function event(array $event, bool $valid = true)
    {
        $raw = json_encode($event, JSON_THROW_ON_ERROR);
        $time = time();
        $signature = hash_hmac('sha256', $time.'.'.$raw, $valid ? 'whsec_'.str_repeat('x', 40) : 'invalid');

        return $this->call('POST', '/stripe-webhook-test', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => 't='.$time.',v1='.$signature], $raw);
    }

    public function test_signed_provider_capture_can_recover_a_lost_response_once_without_another_network_call(): void
    {
        Http::fake(['api.stripe.com/v1/payment_intents' => Http::response(['id' => 'pi_recovery', 'livemode' => false, 'status' => 'requires_capture', 'amount' => 1000, 'currency' => 'aed'])]);
        [$user, , $invoice] = $this->fixture();
        $this->actingAs($user);
        $id = $this->postJson('/stripe-test/commercial/checkouts', ['invoice_id' => $invoice->id, 'amount_minor' => 1000, 'idempotency_key' => 'recovery'])->assertCreated()->json('id');
        $this->postJson('/stripe-test/commercial/checkouts/'.$id.'/authorize', ['token' => 'pm_card_visa', 'idempotency_key' => 'auth'])->assertOk();
        $event = ['id' => 'evt_recovery', 'type' => 'payment_intent.succeeded', 'livemode' => false, 'data' => ['object' => ['id' => 'pi_recovery', 'livemode' => false, 'status' => 'succeeded', 'amount' => 1000, 'amount_received' => 1000, 'currency' => 'aed']]];
        $this->event($event, false)->assertUnauthorized();
        $this->event($event)->assertOk();
        $this->event($event)->assertOk();
        $event['data']['object']['amount'] = 999;
        $this->event($event)->assertConflict();
        $this->assertDatabaseCount('finance_payments', 1);
        Http::assertSentCount(1);
    }

    public function test_live_secret_and_live_webhook_are_rejected_without_network_calls(): void
    {
        config(['payments.stripe_test_secret' => 'sk_live_prohibited']);
        try {
            (new StripeSandboxProvider)->authorize('local', 100, 'AED', 'pm_card_visa');
            $this->fail('Live secret was accepted.');
        } catch (HttpException $e) {
            $this->assertSame(503, $e->getStatusCode());
        }
        $this->event(['id' => 'evt_live', 'livemode' => true])->assertUnprocessable();
        Http::assertNothingSent();
    }

    public function test_provider_declines_action_required_unconfirmed_refunds_and_unexpected_live_response(): void
    {
        $provider = new StripeSandboxProvider;
        Http::fake(['*' => Http::sequence()->push(['error' => ['code' => 'card_declined']], 402)
            ->push(['id' => 'pi_sca', 'livemode' => false, 'status' => 'requires_action', 'amount' => 100, 'currency' => 'aed', 'client_secret' => 'never_persist_or_return'])
            ->push(['id' => 're_pending', 'payment_intent' => 'pi_sca', 'livemode' => false, 'status' => 'pending', 'amount' => 100])
            ->push(['id' => 'pi_live', 'livemode' => true, 'status' => 'requires_capture', 'amount' => 100, 'currency' => 'aed'])]);
        $this->assertSame('failed', $provider->authorize('decline', 100, 'AED', 'pm_card_chargeDeclined')['status']);
        $result = $provider->authorize('sca', 100, 'AED', 'pm_card_authenticationRequired');
        $this->assertSame('action_required', $result['status']);
        $this->assertArrayNotHasKey('client_secret', $result);
        try {
            $provider->refund('pi_sca', 100, 'pending');
            $this->fail('Pending refund was treated as final.');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        try {
            $provider->authorize('unexpected', 100, 'AED', 'pm_card_visa');
            $this->fail('Live response accepted.');
        } catch (HttpException $e) {
            $this->assertSame(502, $e->getStatusCode());
        }
    }

    public function test_cancellation_and_transport_uncertainty_do_not_invent_success(): void
    {
        Http::fake(['*' => Http::sequence()
            ->push(['id' => 'pi_cancel', 'livemode' => false, 'status' => 'canceled'])
            ->pushResponse(Http::failedConnection())]);
        $provider = new StripeSandboxProvider;
        $this->assertSame('cancelled', $provider->cancel('pi_cancel', 'cancel')['status']);
        try {
            $provider->capture('pi_timeout', 123, 'stable-capture');
            $this->fail('Transport uncertainty was treated as captured.');
        } catch (HttpException $e) {
            $this->assertSame(503, $e->getStatusCode());
            $this->assertStringContainsString('same operation key', $e->getMessage());
        }
    }
}
