<?php

namespace Tests\Feature;

use App\Models\ChargingSession;
use App\Models\Reservation;
use App\Models\Station;
use App\Models\User;
use App\Services\Commercial\Payments\GatewayService;
use App\Services\Commercial\Payments\PaymentRecovery;
use App\Services\Commercial\Payments\StripeSandboxProvider;
use App\Services\Finance\FinanceService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class PaymentRecoveryTest extends TestCase
{
    private bool $disposableDatabasePrepared = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freshDisposableDatabase();
        $this->disposableDatabasePrepared = true;
        config(['payments.provider' => 'stripe_test', 'payments.stripe_test_secret' => 'sk_test_recoverycontract', 'payments.stripe_test_webhook_secret' => 'whsec_'.str_repeat('r', 40)]);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        try {
            if ($this->disposableDatabasePrepared) {
                // Forward rebuild avoids unrelated rollback-only migration defects
                // and leaves shared PostgreSQL clean for later transaction suites.
                $this->freshDisposableDatabase();
            }
        } finally {
            parent::tearDown();
        }
    }

    private function freshDisposableDatabase(): void
    {
        $driver = DB::getDriverName();
        $database = DB::connection()->getDatabaseName();
        $this->assertTrue(app()->environment('testing') && (($driver === 'sqlite' && $database === ':memory:') || ($driver === 'pgsql' && preg_match('/_(review|test|testing)$/D', $database))), 'Recovery tests require an isolated in-memory SQLite or explicitly disposable PostgreSQL testing database.');
        $this->assertSame(0, DB::transactionLevel(), 'Durability tests must not run inside ambient test transactions.');
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
    }

    private function fixture(bool $captured = false): array
    {
        $vendor = User::factory()->create(['role' => 'vendor']);
        $user = User::factory()->create();
        $station = Station::create(['vendor_id' => $vendor->id, 'name' => 'Recovery test simulator', 'country' => 'AE', 'currency' => 'AED', 'timezone' => 'Asia/Dubai', 'latitude' => 25, 'longitude' => 55, 'source' => 'simulator', 'connector_type' => 'CCS2', 'power_kw' => 50]);
        $reservation = Reservation::create(['user_id' => $user->id, 'station_id' => $station->id, 'starts_at' => now(), 'status' => 'confirmed', 'idempotency_key' => 'recovery-fixture']);
        $session = ChargingSession::create(['reservation_id' => $reservation->id, 'status' => 'stopped', 'energy_wh' => 1000, 'rate_minor_per_kwh' => 100, 'currency' => 'AED', 'total_minor' => 100, 'stopped_at' => now()]);
        $invoice = app(FinanceService::class)->issue($session, $user->id);
        $checkout = app(GatewayService::class)->checkout($invoice, $user->id, 100, 'recovery-fixture');
        DB::table('gateway_checkouts')->where('id', $checkout->id)->update(['provider_reference' => 'pi_recovery', 'status' => $captured ? 'captured' : 'authorized', 'authorization_expires_at' => now()->addDays(3)]);
        if ($captured) {
            app(FinanceService::class)->pay($invoice, $user->id, 100, 'explicit-recovery-test-fixture');
        }

        return [$user, $invoice, DB::table('gateway_checkouts')->find($checkout->id)];
    }

    private function failure(callable $action, int $status, string $message = ''): void
    {
        try {
            $action();
            $this->fail('Expected payment operation rejection.');
        } catch (HttpException $e) {
            $this->assertSame($status, $e->getStatusCode());
            if ($message !== '') {
                $this->assertStringContainsString($message, $e->getMessage());
            }
        }
    }

    public function test_durable_intent_survives_provider_success_followed_by_business_transaction_rollback(): void
    {
        [$user, , $checkout] = $this->fixture();
        Http::fake(['*' => Http::response(['id' => 'pi_recovery', 'livemode' => false, 'status' => 'succeeded', 'amount_received' => 100, 'client_secret' => 'must_not_persist', 'payment_method' => 'pm_must_not_persist'])]);
        try {
            app(PaymentRecovery::class)->run('charging', $checkout, $user->id, 'capture', 'before-rollback', ['capture', 100, null, ''], fn () => DB::transaction(function () use ($checkout) {
                (new StripeSandboxProvider)->capture('pi_recovery', 100, 'caller-key');
                DB::table('gateway_checkouts')->where('id', $checkout->id)->update(['status' => 'captured']);
                throw new \RuntimeException('Deliberate business rollback after provider response.');
            }));
            $this->fail('Business rollback was swallowed.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Deliberate business rollback after provider response.', $e->getMessage());
        }
        $intent = DB::table('payment_recovery_intents')->sole();
        $this->assertSame('uncertain', $intent->status);
        $this->assertSame('authorized', DB::table('gateway_checkouts')->find($checkout->id)->status);
        $this->assertDatabaseCount('finance_payments', 0);
        $this->assertSame('pi_recovery', json_decode($intent->observation, true)['id']);
        $this->assertStringNotContainsString('must_not_persist', $intent->observation);
        $this->assertDatabaseHas('payment_recovery_events', ['intent_id' => $intent->id, 'status' => 'uncertain']);
    }

    public function test_timeout_retry_with_changed_client_key_preserves_provider_key_and_posts_one_capture(): void
    {
        [$user, $invoice, $checkout] = $this->fixture();
        $keys = [];
        Http::fake(function ($request) use (&$keys) {
            $keys[] = $request->header('Idempotency-Key')[0];
            if (count($keys) === 1) {
                throw new ConnectionException('Controlled timeout, no external request.');
            }

            return Http::response(['id' => 'pi_recovery', 'livemode' => false, 'status' => 'succeeded', 'amount_received' => 100]);
        });
        $gateway = app(GatewayService::class);
        $this->failure(fn () => $gateway->operate($checkout->id, $user->id, 'capture', 'original-client-key'), 503);
        $this->assertSame('uncertain', DB::table('payment_recovery_intents')->sole()->status);
        $gateway->operate($checkout->id, $user->id, 'capture', 'new-client-key');
        $gateway->operate($checkout->id, $user->id, 'capture', 'another-client-key');
        $this->assertCount(2, $keys);
        $this->assertSame($keys[0], $keys[1]);
        $intent = DB::table('payment_recovery_intents')->sole();
        $this->assertSame(hash('sha256', 'creabiz-test:recovery:'.$intent->id), $keys[0]);
        $this->assertSame('completed', $intent->status);
        $this->assertSame(2, $intent->attempts);
        $this->assertDatabaseCount('finance_payments', 1);
        $this->assertTrue(app(FinanceService::class)->summary($invoice)['ledger_balanced']);
    }

    public function test_uncertain_refund_rejects_changed_payload_or_replacement_key_without_another_provider_request(): void
    {
        [$user, , $checkout] = $this->fixture(true);
        Http::fake(['*' => Http::failedConnection()]);
        $gateway = app(GatewayService::class);
        $this->failure(fn () => $gateway->operate($checkout->id, $user->id, 'refund', 'refund-original', 50, null, 'Original'), 503);
        $this->failure(fn () => $gateway->operate($checkout->id, $user->id, 'refund', 'refund-original', 51, null, 'Original'), 409, 'payload');
        $this->failure(fn () => $gateway->operate($checkout->id, $user->id, 'refund', 'refund-replacement', 50, null, 'Original'), 409, 'unresolved');
        $this->assertDatabaseCount('payment_recovery_intents', 1);
        $this->assertDatabaseCount('finance_refunds', 0);
        Http::assertSentCount(1);
    }

    public function test_unexpired_crash_lease_blocks_retry_and_expired_lease_reuses_same_recovery_identity(): void
    {
        [$user, , $checkout] = $this->fixture();
        $calls = 0;
        Http::fake(function () use (&$calls) {
            if (++$calls === 1) {
                throw new ConnectionException('Controlled timeout.');
            }

            return Http::response(['id' => 'pi_recovery', 'livemode' => false, 'status' => 'succeeded', 'amount_received' => 100]);
        });
        $gateway = app(GatewayService::class);
        $this->failure(fn () => $gateway->operate($checkout->id, $user->id, 'capture', 'first'), 503);
        $intent = DB::table('payment_recovery_intents')->sole();
        DB::table('payment_recovery_intents')->where('id', $intent->id)->update(['status' => 'executing', 'lease' => 'synthetic-crashed-worker-lease', 'lease_until' => now()->addMinutes(2)]);
        $this->failure(fn () => $gateway->operate($checkout->id, $user->id, 'capture', 'second'), 409, 'executing');
        $this->assertSame(1, $calls);
        DB::table('payment_recovery_intents')->where('id', $intent->id)->update(['lease_until' => now()->subSecond()]);
        $gateway->operate($checkout->id, $user->id, 'capture', 'third');
        $this->assertSame(2, $calls);
        $this->assertSame($intent->id, DB::table('payment_recovery_intents')->sole()->id);
        $this->assertDatabaseCount('finance_payments', 1);
    }

    public function test_expired_provider_retry_window_and_scan_never_send_another_mutation(): void
    {
        [$user, , $checkout] = $this->fixture();
        Http::fake(['*' => Http::failedConnection()]);
        $gateway = app(GatewayService::class);
        $this->failure(fn () => $gateway->operate($checkout->id, $user->id, 'capture', 'first'), 503);
        $this->travel(24)->hours();
        $this->failure(fn () => $gateway->operate($checkout->id, $user->id, 'capture', 'changed-client-key'), 409, 'retry window');
        $this->assertSame(1, app(PaymentRecovery::class)->scan());
        $this->assertSame('needs_review', DB::table('payment_recovery_intents')->sole()->status);
        $this->failure(fn () => $gateway->operate($checkout->id, $user->id, 'capture', 'another-key'), 409, 'retry window');
        $this->assertDatabaseCount('finance_payments', 0);
        Http::assertSentCount(1);
    }

    public function test_authenticated_capture_webhook_completes_uncertain_recovery_only_with_committed_ledger(): void
    {
        [$user, $invoice, $checkout] = $this->fixture();
        Http::fake(['*' => Http::failedConnection()]);
        $gateway = app(GatewayService::class);
        $this->failure(fn () => $gateway->operate($checkout->id, $user->id, 'capture', 'lost-capture'), 503);
        $this->assertDatabaseCount('finance_payments', 0);
        $event = ['id' => 'evt_recovery_final', 'type' => 'payment_intent.succeeded', 'livemode' => false, 'data' => ['object' => ['id' => 'pi_recovery', 'status' => 'succeeded', 'livemode' => false, 'amount' => 100, 'amount_received' => 100, 'currency' => 'aed']]];
        $raw = json_encode($event, JSON_THROW_ON_ERROR);
        $timestamp = time();
        $signature = 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$raw, 'whsec_'.str_repeat('r', 40));
        try {
            DB::transaction(function () use ($gateway, $raw, $signature) {
                $gateway->stripeWebhook($raw, $signature);
                throw new \RuntimeException('Rollback webhook ledger and recovery completion together.');
            });
            $this->fail('Expected controlled webhook rollback.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Rollback webhook ledger and recovery completion together.', $e->getMessage());
        }
        $this->assertSame('uncertain', DB::table('payment_recovery_intents')->sole()->status);
        $this->assertDatabaseCount('finance_payments', 0);
        $this->assertDatabaseCount('gateway_webhook_events', 0);
        $gateway->stripeWebhook($raw, $signature);
        $gateway->stripeWebhook($raw, $signature);
        $this->assertSame('completed', DB::table('payment_recovery_intents')->sole()->status);
        $this->assertDatabaseCount('finance_payments', 1);
        $this->assertDatabaseCount('gateway_webhook_events', 1);
        $this->assertTrue(app(FinanceService::class)->summary($invoice)['ledger_balanced']);
        $gateway->operate($checkout->id, $user->id, 'capture', 'client-retry-after-webhook');
        Http::assertSentCount(1);
    }

    public function test_recovery_http_is_tenant_scoped_hides_private_evidence_and_reconciles_by_get_only(): void
    {
        [$user, $invoice, $checkout] = $this->fixture();
        $methods = [];
        Http::fake(function ($request) use (&$methods) {
            $methods[] = $request->method();
            if ($request->method() === 'POST') {
                throw new ConnectionException('Controlled capture timeout.');
            }

            return Http::response(['id' => 'pi_recovery', 'livemode' => false, 'status' => 'succeeded', 'amount' => 100, 'amount_received' => 100, 'currency' => 'aed']);
        });
        $this->failure(fn () => app(GatewayService::class)->operate($checkout->id, $user->id, 'capture', 'private-recovery'), 503);
        $intent = DB::table('payment_recovery_intents')->sole();
        DB::table('payment_recovery_intents')->where('id', $intent->id)->update(['observation' => json_encode(['private_sentinel' => 'must_not_expose'])]);
        $base = '/api/commercial/payment-recovery';
        $this->actingAs($user)->getJson($base)->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonMissingPath('data.0.identity')->assertJsonMissingPath('data.0.fingerprint')
            ->assertJsonMissingPath('data.0.lease')->assertJsonMissingPath('data.0.observation');
        $this->getJson($base.'/'.$intent->id.'/events')->assertOk();
        foreach (['customer', 'vendor'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]));
            $this->getJson($base)->assertOk()->assertJsonCount(0, 'data');
            $this->getJson($base.'/'.$intent->id.'/events')->assertNotFound();
            $this->postJson($base.'/'.$intent->id.'/reconcile')->assertNotFound();
        }
        $this->actingAs(User::findOrFail($invoice->vendor_id))->getJson($base)->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs(User::factory()->create(['role' => 'admin']))->getJson($base)->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($user)->postJson($base.'/'.$intent->id.'/reconcile')->assertOk()
            ->assertJsonPath('status', 'completed')->assertJsonMissingPath('observation')->assertJsonMissingPath('lease');
        $this->assertDatabaseCount('finance_payments', 1);
        $this->assertSame(['POST', 'GET'], $methods, 'Reconciliation must add only one GET after the original timed-out mutation.');
        // Laravel records completed fakes, not the callback that threw the timeout.
        Http::assertSentCount(1);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
        Http::assertSent(fn ($request) => $request->method() === 'GET' && $request->url() === 'https://api.stripe.com/v1/payment_intents/pi_recovery');
    }

    public function test_read_only_reconciliation_rejects_another_payment_identity_even_when_amount_and_currency_match(): void
    {
        [$user, , $checkout] = $this->fixture();
        Http::fake(function ($request) {
            if ($request->method() === 'POST') {
                throw new ConnectionException('Controlled capture timeout.');
            }

            return Http::response(['id' => 'pi_anothercheckout', 'livemode' => false, 'status' => 'succeeded', 'amount' => 100, 'amount_received' => 100, 'currency' => 'aed']);
        });
        $this->failure(fn () => app(GatewayService::class)->operate($checkout->id, $user->id, 'capture', 'mismatched-provider-identity'), 503);
        $intent = DB::table('payment_recovery_intents')->sole();
        $this->failure(fn () => app(PaymentRecovery::class)->reconcileProvider($intent->id), 409);
        $this->assertSame('uncertain', DB::table('payment_recovery_intents')->sole()->status);
        $this->assertSame('pi_recovery', DB::table('gateway_checkouts')->find($checkout->id)->provider_reference);
        $this->assertDatabaseCount('finance_payments', 0);
        $this->assertDatabaseCount('gateway_webhook_events', 0);
    }

    public function test_uncertain_capture_holds_other_authorized_checkouts_and_replacement_checkout_on_same_invoice(): void
    {
        [$user, $invoice, $first] = $this->fixture();
        $gateway = app(GatewayService::class);
        $second = $gateway->checkout($invoice, $user->id, 100, 'second-existing-checkout');
        DB::table('gateway_checkouts')->where('id', $second->id)->update(['provider_reference' => 'pi_secondary', 'status' => 'authorized', 'authorization_expires_at' => now()->addHour()]);
        Http::fake(['*' => Http::failedConnection()]);
        $this->failure(fn () => $gateway->operate($first->id, $user->id, 'capture', 'uncertain-first-capture'), 503);
        $this->failure(fn () => $gateway->operate($second->id, $user->id, 'capture', 'second-capture-key'), 409, 'holds this invoice');
        $this->failure(fn () => $gateway->checkout($invoice, $user->id, 100, 'replacement-after-timeout'), 409, 'prior provider outcome');
        $this->assertDatabaseCount('gateway_checkouts', 2);
        $this->assertDatabaseCount('payment_recovery_intents', 1);
        $this->assertSame('uncertain', DB::table('payment_recovery_intents')->sole()->status);
        $this->assertDatabaseCount('finance_payments', 0);
        Http::assertSentCount(1);
    }

    public function test_malformed_capture_response_cannot_redirect_reconciliation_to_another_payment(): void
    {
        [$user, , $checkout] = $this->fixture();
        $requests = [];
        Http::fake(function ($request) use (&$requests) {
            $requests[] = [$request->method(), $request->url()];
            if ($request->method() === 'POST') {
                return Http::response(['id' => 'pi_other', 'livemode' => false, 'status' => 'succeeded', 'amount' => 100, 'amount_received' => 100, 'currency' => 'aed']);
            }
            if ($request->url() === 'https://api.stripe.com/v1/payment_intents/pi_recovery') {
                return Http::response(['id' => 'pi_recovery', 'livemode' => false, 'status' => 'requires_capture', 'amount' => 100, 'amount_received' => 0, 'amount_capturable' => 100, 'currency' => 'aed']);
            }

            return Http::response(['id' => 'pi_other', 'livemode' => false, 'status' => 'succeeded', 'amount' => 100, 'amount_received' => 100, 'currency' => 'aed']);
        });
        $this->failure(fn () => app(GatewayService::class)->operate($checkout->id, $user->id, 'capture', 'wrong-response-identity'), 409);
        $intent = DB::table('payment_recovery_intents')->sole();
        $this->assertSame('uncertain', $intent->status);
        $this->failure(fn () => app(PaymentRecovery::class)->reconcileProvider($intent->id), 409);
        $this->assertSame([
            ['POST', 'https://api.stripe.com/v1/payment_intents/pi_recovery/capture'],
            ['GET', 'https://api.stripe.com/v1/payment_intents/pi_recovery'],
        ], $requests, 'Malformed observations must not override the authoritative checkout identity.');
        Http::assertNotSent(fn ($request) => $request->url() === 'https://api.stripe.com/v1/payment_intents/pi_other');
        $this->assertSame('uncertain', DB::table('payment_recovery_intents')->sole()->status);
        $this->assertSame('pi_recovery', DB::table('gateway_checkouts')->find($checkout->id)->provider_reference);
        $this->assertDatabaseCount('finance_payments', 0);
        $this->assertDatabaseCount('gateway_webhook_events', 0);
    }

    public function test_refund_lookup_requires_its_own_recovery_metadata_even_on_same_capture(): void
    {
        [$owner,$invoice,$checkout] = $this->fixture(true);
        $intent = (object) ['id' => 999, 'kind' => 'refund', 'observation' => json_encode(['id' => 're_wrong']), 'amount_minor' => 25, 'currency' => 'AED'];
        Http::fake(['api.stripe.com/v1/refunds/re_wrong' => Http::response(['id' => 're_wrong', 'livemode' => false, 'status' => 'succeeded', 'amount' => 25, 'currency' => 'aed', 'payment_intent' => 'pi_recovery', 'metadata' => ['creabiz_recovery' => '998']])]);
        try {
            (new StripeSandboxProvider)->recoveryEvidence($intent, $checkout);
            $this->fail('Another refund request was accepted.');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        Http::assertSentCount(1);
        $this->assertDatabaseCount('finance_refunds', 0);
    }
}
