<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Commercial\CorporateBilling;
use App\Services\Commercial\Payments\CorporateHostedCollection;
use App\Services\Commercial\SettlementService;
use App\Services\Finance\FinanceService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class CorporateHostedCollectionTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/commercial/corporate/hosted-checkouts';

    protected function setUp(): void
    {
        parent::setUp();
        config(['payments.provider' => 'mock']);
        Http::preventStrayRequests();
    }

    private function f(): array
    {
        return (require base_path('tests/Fixtures/corporate-hosted.php'))();
    }

    private function create(array $f, string $key = 'corporate'): array
    {
        return $this->actingAs($f['owner'])->postJson(self::URL, ['statement_id' => $f['statement']->id, 'consent' => true, 'idempotency_key' => $key])->assertCreated()->json('checkout');
    }

    private function fake(callable $fn): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake($fn);
    }

    private function stripe(): void
    {
        config(['payments.provider' => 'stripe_test', 'payments.corporate_hosted_return_url' => 'https://platform.example.test/payments/corporate-hosted-return', 'payments.stripe_test_secret' => 'sk_test_CorporateMerchant', 'payments.stripe_test_webhook_secret' => 'whsec_'.str_repeat('corporate', 8)]);
    }

    private function providerSession(array $h, ?array $pi = null, string $status = 'open'): array
    {
        return ['id' => 'cs_test_Corporate', 'livemode' => false, 'mode' => 'payment', 'client_reference_id' => $h['reference'], 'metadata' => ['creabiz_hosted' => $h['reference']], 'amount_total' => 2100, 'currency' => 'aed', 'status' => $status, 'url' => 'https://checkout.stripe.com/c/pay/cs_test_Corporate#opaque', 'payment_intent' => $pi];
    }

    private function pi(array $h, string $status = 'requires_capture'): array
    {
        return ['id' => 'pi_Corporate', 'livemode' => false, 'capture_method' => 'manual', 'metadata' => ['creabiz_hosted' => $h['reference'], 'creabiz_checkout' => 'corporate_'.$h['reference']], 'amount' => 2100, 'currency' => 'aed', 'status' => $status, 'amount_received' => $status === 'succeeded' ? 2100 : 0, 'amount_capturable' => $status === 'requires_capture' ? 2100 : 0];
    }

    private function stripeCreate(array $f): array
    {
        $this->stripe();
        $this->fake(fn ($r) => Http::response($this->providerSession(['reference' => $r['client_reference_id']])));

        return $this->create($f);
    }

    private function signed(array $h, string $type, array $object, string $event = 'evt_Corporate')
    {
        $raw = json_encode(['id' => $event, 'livemode' => false, 'type' => $type, 'data' => ['object' => $object]], JSON_THROW_ON_ERROR);
        $time = time();
        $signature = 't='.$time.',v1='.hash_hmac('sha256', $time.'.'.$raw, config('payments.stripe_test_webhook_secret'));

        return $this->call('POST', '/api/corporate-payments/stripe-test/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => $signature], $raw);
    }

    public function test_mock_collects_all_source_invoices_atomically_without_changing_owners_or_creating_gateway_checkouts(): void
    {
        $f = $this->f();
        $h = $this->create($f);
        $this->assertSame(2100, $h['amount_minor']);
        $this->assertSame('corporate', $h['scope']);
        $this->assertSame($h['id'], $this->create($f)['id']);
        $source = app(CorporateBilling::class)->statement($f['statement']->id, $f['owner']->id);
        $this->assertTrue($source['can_collect_hosted']);
        $this->assertSame($source['line_fingerprint'], $h['line_fingerprint']);
        $this->postJson(self::URL.'/'.$h['id'].'/capture')->assertConflict();
        $this->postJson(self::URL.'/'.$h['id'].'/simulate', ['consent' => true, 'outcome' => 'authorized'])->assertOk()->assertJsonPath('checkout.status', 'authorized');
        foreach ([1, 2] as $n) {
            $this->postJson(self::URL.'/'.$h['id'].'/capture')->assertOk()->assertJsonPath('checkout.status', 'captured');
        }
        $this->assertDatabaseCount('finance_payments', 2);
        $this->assertDatabaseCount('corporate_receipts', 1);
        $this->assertDatabaseCount('corporate_receipt_payments', 2);
        $this->assertDatabaseCount('gateway_checkouts', 0);
        foreach ($f['invoices'] as $i) {
            $this->assertSame($f['driver']->id, $i->fresh()->user_id);
            $s = app(FinanceService::class)->summary($i);
            $this->assertTrue($s['ledger_balanced']);
            $this->assertSame(0, $s['amount_due_minor']);
        }
        $this->assertFalse(app(CorporateBilling::class)->statement($f['statement']->id, $f['owner']->id)['can_collect_hosted']);
    }

    public function test_only_original_payer_who_is_current_fleet_owner_may_mutate_and_other_parties_cannot_read(): void
    {
        $f = $this->f();
        $h = $this->create($f);
        foreach ([$f['driver'], $f['vendor'], User::factory()->create()] as $u) {
            $this->actingAs($u)->getJson(self::URL.'/'.$h['id'])->assertNotFound();
            $this->postJson(self::URL, ['statement_id' => $f['statement']->id, 'consent' => true, 'idempotency_key' => 'forbidden'])->assertNotFound();
        }
        $this->actingAs($f['admin'])->getJson(self::URL.'/'.$h['id'])->assertOk()->assertJsonPath('checkout.owner', false)->assertJsonPath('checkout.simulation_url', null);
        $this->postJson(self::URL.'/'.$h['id'].'/capture')->assertNotFound();
        $new = User::factory()->create();
        $f['fleet']->update(['owner_id' => $new->id]);
        foreach ([$f['owner'], $new] as $u) {
            $this->actingAs($u)->postJson(self::URL.'/'.$h['id'].'/simulate', ['consent' => true, 'outcome' => 'authorized'])->assertNotFound();
            $this->getJson(self::URL.'/by-reference/'.$h['reference'])->assertNotFound();
        }
        $this->assertFalse(app(CorporateBilling::class)->statement($f['statement']->id, $new->id)['can_collect_hosted']);
    }

    public function test_source_terms_are_server_authoritative_and_distinct_keys_cannot_replace_active_statement(): void
    {
        $f = $this->f();
        $this->actingAs($f['owner'])->postJson(self::URL, ['statement_id' => $f['statement']->id, 'consent' => true, 'idempotency_key' => 'terms', 'amount_minor' => 1])->assertUnprocessable();
        $this->postJson(self::URL, ['statement_id' => $f['statement']->id, 'idempotency_key' => 'terms'])->assertUnprocessable();
        $h = $this->create($f);
        $this->assertSame(2100, $h['amount_minor']);
        $this->createConflict($f, 'different');
    }

    private function createConflict(array $f, string $key): void
    {
        $this->postJson(self::URL, ['statement_id' => $f['statement']->id, 'consent' => true, 'idempotency_key' => $key])->assertConflict();
    }

    public function test_active_hold_blocks_driver_gateway_direct_payment_corporate_simulation_and_local_refunds(): void
    {
        $f = $this->f();
        $h = $this->create($f);
        $i = $f['invoices'][0];
        $this->actingAs($f['driver'])->postJson('/api/commercial/checkouts', ['invoice_id' => $i->id, 'amount_minor' => 1050, 'idempotency_key' => 'driver'])->assertConflict();
        try {
            app(FinanceService::class)->pay($i, $f['driver']->id, 1050, 'direct');
            $this->fail('Hold bypassed');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        try {
            app(CorporateBilling::class)->simulatePayment($f['statement']->id, $f['owner']->id, 'sim');
            $this->fail('Hold bypassed');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        $this->actingAs($f['owner'])->postJson(self::URL.'/'.$h['id'].'/simulate', ['consent' => true, 'outcome' => 'authorized'])->assertOk();
        $this->postJson(self::URL.'/'.$h['id'].'/capture')->assertOk();
        try {
            app(FinanceService::class)->refund($i, $f['owner']->id, 100, 'refund', 'Not external return');
            $this->fail('Refund bypassed');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        $this->assertDatabaseCount('finance_refunds', 0);
    }

    public function test_authoritative_terminal_mock_cancel_allows_only_explicit_new_identity(): void
    {
        $f = $this->f();
        $h = $this->create($f);
        $this->postJson(self::URL.'/'.$h['id'].'/cancel')->assertOk()->assertJsonPath('checkout.can_retry', true);
        $this->assertSame($h['id'], $this->create($f)['id']);
        $next = $this->create($f, 'explicit-new');
        $this->assertNotSame($h['id'], $next['id']);
        $this->assertDatabaseCount('finance_payments', 0);
    }

    public function test_unknown_creation_keeps_original_body_and_provider_key_even_after_negative_lookup(): void
    {
        $f = $this->f();
        $this->stripe();
        $sent = [];
        $this->fake(function ($r) use (&$sent) {
            $sent[] = [$r->data(), $r->header('Idempotency-Key')];
            throw new ConnectionException('Lost creation');
        });
        $this->actingAs($f['owner'])->postJson(self::URL, ['statement_id' => $f['statement']->id, 'consent' => true, 'idempotency_key' => 'unknown'])->assertStatus(503);
        $row = DB::table('corporate_hosted_checkouts')->first();
        $this->assertSame('uncertain', $row->status);
        $this->createConflict($f, 'replacement');
        $this->fake(fn () => Http::response(['data' => [], 'has_more' => false]));
        $this->postJson(self::URL.'/'.$row->id.'/inspect')->assertOk()->assertJsonPath('checkout.status', 'needs_review')->assertJsonPath('checkout.can_retry', false);
        $this->fake(function ($r) use (&$sent) {
            $sent[] = [$r->data(), $r->header('Idempotency-Key')];

            return Http::response($this->providerSession(['reference' => $r['client_reference_id']]));
        });
        $this->postJson(self::URL.'/'.$row->id.'/retry-preparation')->assertOk()->assertJsonPath('checkout.status', 'open');
        $this->assertSame($sent[0], $sent[1]);
        $this->assertDatabaseCount('corporate_hosted_checkouts', 1);
    }

    public function test_stripe_contract_authorizes_captures_and_allocates_one_original_statement(): void
    {
        $f = $this->f();
        $h = $this->stripeCreate($f);
        $this->assertStringContainsString('#opaque', $h['hosted_url']);
        $this->fake(fn () => Http::response($this->providerSession($h, $this->pi($h), 'complete')));
        $this->postJson(self::URL.'/'.$h['id'].'/inspect')->assertOk()->assertJsonPath('checkout.status', 'authorized');
        $this->fake(fn ($r) => Http::response(str_ends_with($r->url(), '/capture') ? $this->pi($h, 'succeeded') : $this->providerSession($h, $this->pi($h, 'succeeded'), 'complete')));
        $this->postJson(self::URL.'/'.$h['id'].'/capture')->assertOk()->assertJsonPath('checkout.status', 'captured');
        $this->assertDatabaseCount('finance_payments', 2);
        $this->assertSame('STRIPE_TEST_CONTRACT', DB::table('corporate_receipts')->first()->evidence);
        $this->assertSame('test_provider_collected', app(CorporateBilling::class)->statement($f['statement']->id, $f['owner']->id)['state']);
    }

    public function test_lost_capture_keeps_hold_and_read_only_provider_reconciliation_posts_exactly_once(): void
    {
        $f = $this->f();
        $h = $this->stripeCreate($f);
        $this->fake(fn () => Http::response($this->providerSession($h, $this->pi($h), 'complete')));
        $this->postJson(self::URL.'/'.$h['id'].'/inspect')->assertOk();
        $this->fake(fn () => throw new ConnectionException('Lost capture'));
        $this->postJson(self::URL.'/'.$h['id'].'/capture')->assertStatus(503);
        $this->getJson(self::URL.'/'.$h['id'])->assertJsonPath('checkout.status', 'uncertain')->assertJsonPath('checkout.can_cancel', false);
        $this->createConflict($f, 'no-replacement');
        $this->fake(fn () => Http::response($this->providerSession($h, $this->pi($h, 'succeeded'), 'complete')));
        foreach ([1, 2] as $n) {
            $this->postJson(self::URL.'/'.$h['id'].'/inspect')->assertOk()->assertJsonPath('checkout.status', 'captured');
        }Http::assertSent(fn ($r) => $r->method() === 'GET');
        $this->assertDatabaseCount('finance_payments', 2);
    }

    public function test_signed_capture_before_authorization_duplicate_and_late_decline_never_rewind_or_double_post(): void
    {
        $f = $this->f();
        $h = $this->stripeCreate($f);
        foreach ([1, 2] as $n) {
            $this->signed($h, 'payment_intent.succeeded', $this->pi($h, 'succeeded'))->assertOk();
        }
        $this->signed($h, 'payment_intent.amount_capturable_updated', $this->pi($h), 'evt_LateAuth')->assertOk();
        $this->signed($h, 'payment_intent.payment_failed', $this->pi($h, 'requires_payment_method'), 'evt_LateFailure')->assertOk();
        $this->getJson(self::URL.'/'.$h['id'])->assertJsonPath('checkout.status', 'captured');
        $this->assertDatabaseCount('finance_payments', 2);
        $this->assertDatabaseCount('corporate_receipts', 1);
    }

    public function test_invalid_signature_changed_merchant_mismatched_source_or_untrusted_url_never_post(): void
    {
        $f = $this->f();
        $h = $this->stripeCreate($f);
        $this->postJson('/api/corporate-payments/stripe-test/webhook', [])->assertUnauthorized();
        $bad = $this->pi($h, 'succeeded');
        $bad['amount'] = 1;
        $this->signed($h, 'payment_intent.succeeded', $bad)->assertConflict();
        $bad = $this->providerSession($h);
        $bad['url'] = 'https://checkout.stripe.com.evil.example/c/pay/cs_test_Corporate';
        $this->fake(fn () => Http::response($bad));
        $this->postJson(self::URL.'/'.$h['id'].'/inspect')->assertStatus(502);
        config(['payments.stripe_test_secret' => 'sk_test_OtherMerchant']);
        $this->signed($h, 'payment_intent.succeeded', $this->pi($h, 'succeeded'))->assertStatus(503);
        $this->assertDatabaseCount('finance_payments', 0);
    }

    public function test_signed_provider_refund_is_held_for_statement_review_without_inventing_credit(): void
    {
        $f = $this->f();
        $h = $this->stripeCreate($f);
        $this->signed($h, 'payment_intent.succeeded', $this->pi($h, 'succeeded'))->assertOk();
        $refund = ['id' => 're_Corporate', 'payment_intent' => 'pi_Corporate', 'livemode' => false, 'amount' => 100, 'currency' => 'aed', 'status' => 'succeeded'];
        $this->signed($h, 'refund.updated', $refund, 'evt_RefundReview')->assertOk()->assertJsonPath('review_required', true);
        $this->assertDatabaseCount('finance_refunds', 0);
        $this->assertSame(1, DB::table('corporate_hosted_events')->where('kind', 'provider_case_requires_review')->count());
    }

    public function test_immutable_provider_terms_lines_and_history_reject_rewrite_or_deletion(): void
    {
        $f = $this->f();
        $h = $this->create($f);
        foreach ([fn () => DB::table('corporate_hosted_checkouts')->where('id', $h['id'])->update(['payer_id' => $f['driver']->id]), fn () => DB::table('corporate_hosted_lines')->delete(), fn () => DB::table('corporate_hosted_events')->delete()] as $change) {
            try {
                DB::transaction($change);
                $this->fail('Immutable history changed');
            } catch (QueryException $e) {
                $this->assertNotEmpty($e->getMessage());
            }
        }
    }

    public function test_missing_or_live_merchant_keys_fail_before_persisting_a_corporate_identity(): void
    {
        $f = $this->f();
        foreach ([null, '', 'sk_live_disallowed', 'malformed'] as $secret) {
            config(['payments.provider' => 'stripe_test', 'payments.stripe_test_secret' => $secret]);
            $this->actingAs($f['owner'])->postJson(self::URL, ['statement_id' => $f['statement']->id, 'consent' => true, 'idempotency_key' => 'invalid-key'])->assertStatus(503);
            $this->assertFalse(app(CorporateBilling::class)->statement($f['statement']->id, $f['owner']->id)['can_collect_hosted']);
        }
        $this->assertDatabaseCount('corporate_hosted_checkouts', 0);
        Http::assertNothingSent();
    }

    public function test_expired_unknown_preparation_remains_held_without_new_request_or_identity(): void
    {
        $f = $this->f();
        $this->stripe();
        $this->fake(fn () => throw new ConnectionException('Creation unknown'));
        $this->actingAs($f['owner'])->postJson(self::URL, ['statement_id' => $f['statement']->id, 'consent' => true, 'idempotency_key' => 'expired-unknown'])->assertStatus(503);
        $h = DB::table('corporate_hosted_checkouts')->first();
        $this->travel(61)->minutes();
        $this->getJson(self::URL.'/'.$h->id)->assertOk()->assertJsonPath('checkout.can_retry_preparation', false)->assertJsonPath('checkout.can_retry', false);
        $this->postJson(self::URL.'/'.$h->id.'/retry-preparation')->assertConflict();
        $this->createConflict($f, 'replacement-after-expiry');
        $this->assertDatabaseCount('corporate_hosted_checkouts', 1);
        $this->assertDatabaseCount('finance_payments', 0);
    }

    public function test_worker_exit_after_capture_dispatch_retries_the_original_provider_body_and_key(): void
    {
        $f = $this->f();
        $h = $this->stripeCreate($f);
        $this->fake(fn () => Http::response($this->providerSession($h, $this->pi($h), 'complete')));
        $this->postJson(self::URL.'/'.$h['id'].'/inspect')->assertOk();
        DB::table('corporate_hosted_operations')->insert(['checkout_id' => $h['id'], 'kind' => 'capture', 'status' => 'executing', 'lease' => 'dead-worker', 'lease_until' => now()->subMinute(), 'retry_until' => now()->addHours(23), 'created_at' => now()->subMinutes(4), 'updated_at' => now()->subMinutes(4)]);
        $this->getJson(self::URL.'/'.$h['id'])->assertOk()->assertJsonPath('checkout.status', 'uncertain')->assertJsonPath('checkout.can_capture', true)->assertJsonPath('checkout.can_cancel', false);
        $this->fake(fn ($r) => Http::response(str_ends_with($r->url(), '/capture') ? $this->pi($h, 'succeeded') : $this->providerSession($h, $this->pi($h, 'succeeded'), 'complete')));
        $this->postJson(self::URL.'/'.$h['id'].'/capture')->assertOk()->assertJsonPath('checkout.status', 'captured');
        Http::assertSent(fn ($r) => $r->method() === 'POST' && $r['amount_to_capture'] === 2100 && $r->header('Idempotency-Key') === [hash('sha256', 'creabiz-test:capture:corporate:'.$h['reference'])]);
        $this->assertDatabaseCount('corporate_hosted_operations', 1);
        $this->assertDatabaseCount('finance_payments', 2);
    }

    public function test_unknown_session_expiry_is_reconciled_before_any_explicit_replacement(): void
    {
        $f = $this->f();
        $h = $this->stripeCreate($f);
        $this->fake(function ($r) use ($h) {
            if ($r->method() === 'POST') {
                throw new ConnectionException('Expiry unknown');
            }

            return Http::response($this->providerSession($h));
        });
        $this->postJson(self::URL.'/'.$h['id'].'/cancel')->assertStatus(503);
        $this->getJson(self::URL.'/'.$h['id'])->assertOk()->assertJsonPath('checkout.status', 'uncertain')->assertJsonPath('checkout.recovery_operation', 'expire')->assertJsonPath('checkout.can_retry', false);
        $this->createConflict($f, 'unknown-expiry-replacement');
        $this->fake(fn () => Http::response($this->providerSession($h, null, 'expired')));
        $this->postJson(self::URL.'/'.$h['id'].'/inspect')->assertOk()->assertJsonPath('checkout.status', 'expired')->assertJsonPath('checkout.can_retry', true);
        $this->assertDatabaseCount('finance_payments', 0);
    }

    public function test_external_corporate_collection_holds_settlement_until_provider_return_and_dispute_software_exists(): void
    {
        $f = $this->f();
        $h = $this->stripeCreate($f);
        $this->signed($h, 'payment_intent.succeeded', $this->pi($h, 'succeeded'))->assertOk();
        $this->assertSame(0, app(SettlementService::class)->balances($f['vendor']->id, 'AED')['available_minor']);
        foreach ($f['invoices'] as $invoice) {
            $this->assertTrue(CorporateHostedCollection::settlementHeld($invoice->id));
        }
        $this->assertSame(2100, app(CorporateBilling::class)->statement($f['statement']->id, $f['owner']->id)['net_collected_minor']);
    }

    public function test_untrusted_or_non_https_test_return_configuration_fails_before_durable_creation(): void
    {
        $f = $this->f();
        $this->stripe();
        foreach (['http://platform.example.test/payments/corporate-hosted-return', 'https://user@platform.example.test/payments/corporate-hosted-return', 'https://platform.example.test/payments/corporate-hosted-return?next=evil', 'https://platform.example.test/wrong-path'] as $return) {
            config(['payments.corporate_hosted_return_url' => $return]);
            $this->actingAs($f['owner'])->postJson(self::URL, ['statement_id' => $f['statement']->id, 'consent' => true, 'idempotency_key' => 'bad-return'])->assertStatus(503);
        }
        $this->assertDatabaseCount('corporate_hosted_checkouts', 0);
        Http::assertNothingSent();
    }

    public function test_test_adapter_currency_contract_rejects_wrong_precision_unsupported_units_and_overflow(): void
    {
        $this->stripe();
        $method = new \ReflectionMethod(CorporateHostedCollection::class, 'providerTerms');
        foreach ([['AED', 0, 100], ['JPY', 2, 100], ['KWD', 3, 1001], ['ABC', 2, 100], ['AED', 2, 2000000001], ['AED', 2, 0]] as [$currency,$minor,$amount]) {
            try {
                $method->invoke(app(CorporateHostedCollection::class), (object) ['currency' => $currency, 'minor_units' => $minor, 'total_minor' => $amount]);
                $this->fail('Unsafe currency contract accepted');
            } catch (HttpException $e) {
                $this->assertSame(409, $e->getStatusCode());
            }
        }
        foreach ([['AED', 2, 2100], ['JPY', 0, 100], ['KWD', 3, 1000]] as [$currency,$minor,$amount]) {
            $this->assertNull($method->invoke(app(CorporateHostedCollection::class), (object) ['currency' => $currency, 'minor_units' => $minor, 'total_minor' => $amount]));
        }
    }

    public function test_bounded_worker_requires_provider_opt_in_and_only_reads_authoritative_original_outcomes(): void
    {
        $f = $this->f();
        $h = $this->stripeCreate($f);
        $this->artisan('commercial:reconcile-corporate-hosted')->assertFailed();
        $this->artisan('commercial:reconcile-corporate-hosted', ['--provider' => true, '--limit' => 101])->assertFailed();
        $this->fake(fn () => Http::response($this->providerSession($h, $this->pi($h, 'succeeded'), 'complete')));
        $this->artisan('commercial:reconcile-corporate-hosted', ['--provider' => true, '--limit' => 1])->assertSuccessful();
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => $r->method() === 'GET');
        $this->assertDatabaseCount('finance_payments', 2);
        $this->assertDatabaseCount('corporate_receipts', 1);
    }

    public function test_worker_keeps_unknown_capture_hold_and_never_mutates_under_changed_merchant(): void
    {
        $f = $this->f();
        $h = $this->stripeCreate($f);
        $this->fake(fn () => Http::response($this->providerSession($h, $this->pi($h), 'complete')));
        $this->postJson(self::URL.'/'.$h['id'].'/inspect')->assertOk();
        $this->fake(fn () => throw new ConnectionException('Unknown capture'));
        $this->postJson(self::URL.'/'.$h['id'].'/capture')->assertStatus(503);
        $this->fake(fn () => Http::response($this->providerSession($h, $this->pi($h), 'complete')));
        $this->assertSame(['checked' => 1, 'reconciled' => 0, 'unresolved' => 1], app(CorporateHostedCollection::class)->scan(1));
        $this->getJson(self::URL.'/'.$h['id'])->assertJsonPath('checkout.status', 'uncertain')->assertJsonPath('checkout.can_retry', false);
        config(['payments.stripe_test_secret' => 'sk_test_DifferentMerchant']);
        $before = DB::table('corporate_hosted_checkouts')->find($h['id']);
        $this->fake(fn () => throw new \RuntimeException('Different merchant must never be contacted'));
        $this->assertSame(['checked' => 1, 'reconciled' => 0, 'unresolved' => 1], app(CorporateHostedCollection::class)->scan(1));
        Http::assertNothingSent();
        $this->assertEquals($before, DB::table('corporate_hosted_checkouts')->find($h['id']));
        $this->assertDatabaseCount('finance_payments', 0);
    }
}
