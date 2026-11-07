<?php

namespace Tests\Feature;

use App\Http\Controllers\RecurringPaymentController;
use App\Models\User;
use App\Services\Commercial\PaymentMandates;
use App\Services\Commercial\SubscriptionCollections;
use App\Services\Commercial\SubscriptionGateway;
use App\Services\Commercial\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class SubscriptionRecurringTest extends TestCase
{
    use RefreshDatabase;

    private array $session = [];

    private string $outcome = 'requires_capture';

    private bool $wrongCustomer = false;

    private bool $lostEnrollment = false;

    private array $sent = [];

    private int $paymentSequence = 0;

    private string $currentPayment = 'pi_Contract';

    protected function setUp(): void
    {
        parent::setUp();
        config(['payments.provider' => 'stripe_test', 'payments.stripe_test_secret' => 'sk_test_contractonly', 'payments.stripe_test_webhook_secret' => 'whsec_'.str_repeat('contractonly', 4), 'payments.hosted_return_url' => 'https://example.test/payments', 'payments.collection_enabled' => true]);
        Route::middleware(['web', 'auth'])->prefix('recurring-test')->group(base_path('routes/recurring_payments.php'));
        Route::post('/recurring-test/callback', [RecurringPaymentController::class, 'webhook']);
        Http::preventStrayRequests();
        Http::fake(function ($r) {
            $this->sent[] = [$r->method(), parse_url($r->url(), PHP_URL_PATH), $r->data(), $r->header('Idempotency-Key')];
            $path = parse_url($r->url(), PHP_URL_PATH);
            if ($path === '/v1/customers') {
                return Http::response(['id' => 'cus_Contract', 'livemode' => false, 'metadata' => $r['metadata']]);
            }
            if ($path === '/v1/checkout/sessions' && $r->method() === 'POST') {
                $this->session = ['id' => 'cs_test_Contract', 'livemode' => false, 'mode' => 'setup', 'customer' => $r['customer'], 'client_reference_id' => $r['client_reference_id'], 'metadata' => $r['metadata'], 'status' => 'open', 'url' => 'https://checkout.stripe.com/c/pay/test'];

                if ($this->lostEnrollment) {
                    throw new ConnectionException('Synthetic hosted-session lost acknowledgment');
                }

                return Http::response($this->session);
            }
            if ($path === '/v1/checkout/sessions') {
                return Http::response(['data' => [$this->session], 'has_more' => false]);
            }
            if (str_starts_with($path, '/v1/checkout/sessions/')) {
                return Http::response($this->session);
            }
            if ($path === '/v1/payment_methods/pm_Contract/detach') {
                return Http::response(['id' => 'pm_Contract', 'livemode' => false, 'customer' => null]);
            }
            if ($path === '/v1/payment_methods/pm_Contract') {
                return Http::response(['id' => 'pm_Contract', 'livemode' => false, 'customer' => $this->wrongCustomer ? 'cus_Other' : 'cus_Contract', 'type' => 'card', 'card' => ['brand' => 'visa', 'last4' => '4242', 'exp_month' => 12, 'exp_year' => now()->year + 3]]);
            }
            if ($path === '/v1/payment_intents') {
                $this->currentPayment = 'pi_Contract'.(++$this->paymentSequence);
            }
            $pi = ['id' => $this->currentPayment, 'livemode' => false, 'customer' => 'cus_Contract', 'amount' => 1050, 'currency' => 'aed', 'status' => $this->outcome, 'amount_capturable' => 1050, 'metadata' => []];
            if ($path === '/v1/payment_intents') {
                if ($this->outcome === 'timeout') {
                    throw new ConnectionException('Synthetic lost acknowledgment');
                }
                if ($this->outcome === 'insufficient_funds') {
                    return Http::response(['error' => ['decline_code' => 'insufficient_funds']], 402);
                }

                return Http::response($pi + ['next_action' => ['type' => 'redirect_to_url', 'redirect_to_url' => ['url' => 'https://hooks.stripe.com/3d_secure/test']]]);
            }
            if (preg_match('#^/v1/payment_intents/pi_Contract[0-9]*/capture$#D', $path)) {
                return Http::response(array_replace($pi, ['status' => 'succeeded', 'amount_received' => 1050]));
            }
            if (str_starts_with($path, '/v1/payment_intents/pi_Contract')) {
                return Http::response($pi + ['next_action' => ['type' => 'redirect_to_url', 'redirect_to_url' => ['url' => 'https://hooks.stripe.com/3d_secure/test']]]);
            }
            if ($path === '/v1/payment_intents/search') {
                return Http::response(['data' => [], 'has_more' => false]);
            }
            throw new \RuntimeException('Unexpected provider contract path: '.$path);
        });
    }

    private function enroll(User $u): int
    {
        $this->actingAs($u)->postJson('/recurring-test/commercial/payment-enrollments', ['consent' => true, 'consent_version' => 'recurring-v1', 'idempotency_key' => 'enroll'])->assertCreated()->assertJsonPath('status', 'pending')->assertJsonMissingPath('provider_session');
        $this->assertDatabaseCount('payment_mandates', 0);
        $id = DB::table('payment_enrollments')->value('id');
        $this->session['status'] = 'complete';
        $this->session['setup_intent'] = ['id' => 'seti_Contract', 'livemode' => false, 'customer' => 'cus_Contract', 'status' => 'succeeded', 'usage' => 'off_session', 'metadata' => $this->session['metadata'], 'payment_method' => 'pm_Contract'];
        $this->postJson('/recurring-test/commercial/payment-enrollments/'.$id.'/inspect')->assertOk()->assertJsonPath('status', 'completed');

        return DB::table('payment_mandates')->value('id');
    }

    private function subscription(User $u): array
    {
        $s = app(SubscriptionService::class);
        $plan = $s->publish(User::factory()->create(['role' => 'admin']), ['code' => 'recurring-pro', 'name' => 'Recurring Pro', 'audience' => 'customer', 'currency' => 'AED', 'minor_units' => 2, 'price_minor' => 1000, 'tax_bps' => 500, 'interval' => 'month', 'entitlements' => json_encode(['premium_reports' => true])]);
        $sub = $s->subscribe($u, $plan->id, 'subscription');

        return [$sub['subscription']->id, $sub['invoices']->first()->id];
    }

    public function test_hosted_consent_is_authoritative_private_tenant_scoped_and_versioned(): void
    {
        $u = User::factory()->create();
        $id = $this->enroll($u);
        $list = $this->getJson('/recurring-test/commercial/payment-methods')->assertOk()->assertJsonPath('methods.data.0.last_four', '4242')->assertJsonMissingPath('methods.data.0.provider_method')->json();
        $this->assertStringNotContainsString('pm_Contract', json_encode($list));
        $this->assertStringNotContainsString('pm_Contract', DB::table('payment_mandates')->value('provider_method'));
        $this->postJson('/recurring-test/commercial/payment-methods/'.$id.'/default', ['expected_version' => 0])->assertConflict();
        $this->postJson('/recurring-test/commercial/payment-methods/'.$id.'/default', ['expected_version' => $list['version']])->assertOk();
        $before = count($this->sent);
        $this->actingAs(User::factory()->create())->postJson('/recurring-test/commercial/payment-enrollments/'.DB::table('payment_enrollments')->value('id').'/inspect')->assertNotFound();
        $this->deleteJson('/recurring-test/commercial/payment-methods/'.$id, ['expected_version' => 1])->assertNotFound();
        $this->assertCount($before, $this->sent);
        $this->actingAs($u)->postJson('/recurring-test/commercial/payment-enrollments', ['consent' => true, 'consent_version' => 'recurring-v1', 'idempotency_key' => 'bad', 'payment_method' => 'pm_Any', 'card_number' => 'synthetic'])->assertUnprocessable();
    }

    public function test_old_callback_cannot_restore_revoked_consent_or_disabled_policies(): void
    {
        $u = User::factory()->create();
        $id = $this->enroll($u);
        [$s] = $this->subscription($u);
        app(SubscriptionCollections::class)->policy($u, $s, true, $id, 0);
        $this->deleteJson('/recurring-test/commercial/payment-methods/'.$id, ['expected_version' => 1])->assertOk();
        $this->assertDatabaseHas('subscription_collection_policies', ['subscription_id' => $s, 'enabled' => false, 'version' => 2]);
        $this->postJson('/recurring-test/commercial/payment-enrollments/'.DB::table('payment_enrollments')->value('id').'/inspect')->assertOk();
        $this->assertDatabaseHas('payment_mandates', ['id' => $id, 'status' => 'revoked', 'version' => 2]);
        $this->postJson('/recurring-test/commercial/payment-methods/'.$id.'/detach')->assertOk();
        $this->assertDatabaseHas('payment_mandates', ['id' => $id, 'detach_status' => 'detached']);
    }

    public function test_collection_uses_server_mandate_and_posts_canonical_receipt_once_across_worker_replays(): void
    {
        $u = User::factory()->create();
        $id = $this->enroll($u);
        [$s,$i] = $this->subscription($u);
        $c = app(SubscriptionCollections::class);
        $c->policy($u, $s, true, $id, 0);
        $c->scan(25);
        $c->scan(25);
        $c->collect($i);
        $this->assertDatabaseCount('commercial_subscription_receipts', 1);
        $this->assertDatabaseHas('subscription_collections', ['invoice_id' => $i, 'state' => 'collected', 'attempts' => 1]);
        $this->assertTrue(app(SubscriptionService::class)->summary($s)['entitlements_active']);
        $sent = collect($this->sent)->filter(fn ($r) => $r[0] === 'POST' && $r[1] === '/v1/payment_intents');
        $this->assertCount(1, $sent);
        $this->assertSame('pm_Contract', $sent->first()[2]['payment_method']);
        $this->assertSame('true', $sent->first()[2]['off_session']);
        $checkout = DB::table('subscription_gateway_checkouts')->first();
        app(SubscriptionGateway::class)->operate($u, $checkout->id, 'capture', 'changed-key', null);
        $this->assertDatabaseCount('commercial_subscription_receipts', 1);
    }

    public function test_timeout_holds_invoice_and_never_automatically_replaces_uncertain_authorization(): void
    {
        $u = User::factory()->create();
        $id = $this->enroll($u);
        [$s,$i] = $this->subscription($u);
        $c = app(SubscriptionCollections::class);
        $c->policy($u, $s, true, $id, 0);
        $this->outcome = 'timeout';
        $c->scan(25);
        $c->scan(25);
        $this->assertDatabaseHas('subscription_collections', ['invoice_id' => $i, 'state' => 'recovery_required', 'attempts' => 1]);
        $this->assertDatabaseHas('payment_recovery_intents', ['scope' => 'subscription', 'status' => 'uncertain']);
        $this->assertDatabaseCount('subscription_gateway_checkouts', 1);
        $this->assertDatabaseCount('commercial_subscription_receipts', 0);
        $this->assertCount(1, collect($this->sent)->filter(fn ($r) => $r[0] === 'POST' && $r[1] === '/v1/payment_intents'));
    }

    public function test_recoverable_decline_obeys_due_time_and_revocation_stops_retry(): void
    {
        $u = User::factory()->create();
        $id = $this->enroll($u);
        [$s,$i] = $this->subscription($u);
        $c = app(SubscriptionCollections::class);
        $c->policy($u, $s, true, $id, 0);
        $this->outcome = 'insufficient_funds';
        $c->scan(25);
        $c->scan(25);
        $this->assertDatabaseHas('subscription_collections', ['invoice_id' => $i, 'state' => 'retry_scheduled', 'attempts' => 1]);
        $this->travel(1)->days();
        app(PaymentMandates::class)->revoke($u, $id, 1);
        $c->scan(25);
        $this->assertDatabaseCount('commercial_subscription_receipts', 0);
        $this->assertDatabaseCount('subscription_gateway_checkouts', 1);
    }

    public function test_sca_uses_existing_intent_and_authenticated_inspection_before_capture(): void
    {
        $u = User::factory()->create();
        $id = $this->enroll($u);
        [$s,$i] = $this->subscription($u);
        $c = app(SubscriptionCollections::class);
        $c->policy($u, $s, true, $id, 0);
        $this->outcome = 'requires_action';
        $c->scan(25);
        $checkout = DB::table('subscription_gateway_checkouts')->first();
        $this->assertDatabaseHas('subscription_collections', ['invoice_id' => $i, 'state' => 'action_required']);
        $this->postJson('/recurring-test/commercial/subscription-checkouts/'.$checkout->id.'/authentication')->assertOk()->assertJsonPath('hosted_action_supported', true)->assertJsonMissingPath('client_secret');
        $this->assertDatabaseCount('commercial_subscription_receipts', 0);
        $this->outcome = 'requires_capture';
        $this->postJson('/recurring-test/commercial/subscription-checkouts/'.$checkout->id.'/authentication')->assertOk()->assertJsonPath('status', 'authorized');
        $c->scan(25);
        $this->assertDatabaseCount('commercial_subscription_receipts', 1);
        $this->assertCount(1, collect($this->sent)->filter(fn ($r) => $r[0] === 'POST' && $r[1] === '/v1/payment_intents'));
    }

    public function test_wrong_provider_owner_and_expired_or_changed_consent_never_enable_collection(): void
    {
        $u = User::factory()->create();
        $this->actingAs($u);
        $this->postJson('/recurring-test/commercial/payment-enrollments', ['consent' => true, 'consent_version' => 'outdated', 'idempotency_key' => 'enroll'])->assertConflict();
        $this->postJson('/recurring-test/commercial/payment-enrollments', ['consent' => true, 'consent_version' => 'recurring-v1', 'idempotency_key' => 'enroll'])->assertCreated();
        $this->session['status'] = 'complete';
        $this->session['setup_intent'] = ['id' => 'seti_Contract', 'livemode' => false, 'customer' => 'cus_Contract', 'status' => 'succeeded', 'usage' => 'off_session', 'metadata' => $this->session['metadata'], 'payment_method' => 'pm_Contract'];
        $id = DB::table('payment_enrollments')->value('id');
        $this->wrongCustomer = true;
        $this->postJson('/recurring-test/commercial/payment-enrollments/'.$id.'/inspect')->assertConflict();
        $this->assertDatabaseCount('payment_mandates', 0);
        $this->wrongCustomer = false;
        $this->postJson('/recurring-test/commercial/payment-enrollments/'.$id.'/inspect')->assertOk();
        [$s] = $this->subscription($u);
        $this->travel(366)->days();
        $this->putJson('/recurring-test/commercial/subscriptions/'.$s.'/collection-policy', ['enabled' => true, 'mandate_id' => DB::table('payment_mandates')->value('id'), 'consent' => true, 'expected_version' => 0])->assertConflict();
        $this->assertDatabaseCount('commercial_subscription_receipts', 0);
    }

    public function test_lost_hosted_ack_is_recovered_read_only_and_blocks_changed_enrollment_key(): void
    {
        $u = User::factory()->create();
        $this->actingAs($u);
        $this->lostEnrollment = true;
        $body = ['consent' => true, 'consent_version' => 'recurring-v1', 'idempotency_key' => 'enroll'];
        $this->postJson('/recurring-test/commercial/payment-enrollments', $body)->assertStatus(503);
        $this->assertDatabaseHas('payment_enrollments', ['status' => 'uncertain']);
        $this->postJson('/recurring-test/commercial/payment-enrollments', array_replace($body, ['idempotency_key' => 'replacement']))->assertConflict();
        $this->session['status'] = 'complete';
        $this->session['setup_intent'] = ['id' => 'seti_Contract', 'livemode' => false, 'customer' => 'cus_Contract', 'status' => 'succeeded', 'usage' => 'off_session', 'metadata' => $this->session['metadata'], 'payment_method' => 'pm_Contract'];
        $this->postJson('/recurring-test/commercial/payment-enrollments/'.DB::table('payment_enrollments')->value('id').'/inspect')->assertOk()->assertJsonPath('status', 'completed');
        $this->assertCount(1, collect($this->sent)->filter(fn ($r) => $r[0] === 'POST' && $r[1] === '/v1/checkout/sessions'));
    }

    public function test_signed_enrollment_callback_duplicate_replay_and_session_binding(): void
    {
        $u = User::factory()->create();
        $this->enroll($u);
        $event = ['id' => 'evt_Recurring', 'livemode' => false, 'type' => 'checkout.session.completed', 'data' => ['object' => $this->session]];
        $send = function (array $e) {
            $raw = json_encode($e, JSON_THROW_ON_ERROR);
            $time = time();

            return $this->call('POST', '/recurring-test/callback', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => 't='.$time.',v1='.hash_hmac('sha256', $time.'.'.$raw, config('payments.stripe_test_webhook_secret'))], $raw);
        };
        $send($event)->assertOk()->assertJsonPath('duplicate', false);
        $send($event)->assertOk()->assertJsonPath('duplicate', true);
        $event['data']['object']['status'] = 'changed';
        $send($event)->assertConflict();
        $event['id'] = 'evt_Other';
        $event['data']['object']['id'] = 'cs_test_Other';
        $send($event)->assertConflict();
        $this->assertDatabaseCount('payment_mandates', 1);
        $this->assertSame(1, DB::table('recurring_payment_events')->where('kind', 'provider_callback_verified')->count());
    }

    public function test_cancelled_subscription_cannot_be_collected(): void
    {
        $u = User::factory()->create();
        $id = $this->enroll($u);
        [$s,$i] = $this->subscription($u);
        $c = app(SubscriptionCollections::class);
        $c->policy($u, $s, true, $id, 0);
        app(SubscriptionService::class)->change($u, $s, 'cancel_now', null, 'cancel');
        $c->scan(25);
        $this->assertDatabaseCount('subscription_gateway_checkouts', 0);
        $this->assertDatabaseCount('commercial_subscription_receipts', 0);
    }

    public function test_controlled_retry_only_runs_after_due_time_and_preserves_one_invoice(): void
    {
        $u = User::factory()->create();
        $id = $this->enroll($u);
        [$s,$i] = $this->subscription($u);
        $c = app(SubscriptionCollections::class);
        $c->policy($u, $s, true, $id, 0);
        $this->outcome = 'insufficient_funds';
        $c->scan(25);
        $c->scan(25);
        $this->assertDatabaseCount('subscription_gateway_checkouts', 1);
        $this->outcome = 'requires_capture';
        $this->travel(1)->days();
        $c->scan(25);
        $this->assertDatabaseHas('subscription_collections', ['invoice_id' => $i, 'state' => 'collected', 'attempts' => 2]);
        $this->assertDatabaseCount('commercial_subscription_invoices', 1);
        $this->assertDatabaseCount('commercial_subscription_receipts', 1);
        $keys = collect($this->sent)->filter(fn ($r) => $r[0] === 'POST' && $r[1] === '/v1/payment_intents')->pluck(3)->all();
        $this->assertCount(2, $keys);
        $this->assertNotSame($keys[0], $keys[1]);
    }

    public function test_failed_sca_and_permanent_declines_do_not_grant_entitlements_or_loop(): void
    {
        $u = User::factory()->create();
        $id = $this->enroll($u);
        [$s,$i] = $this->subscription($u);
        $c = app(SubscriptionCollections::class);
        $c->policy($u, $s, true, $id, 0);
        $this->outcome = 'requires_action';
        $c->scan(25);
        $checkout = DB::table('subscription_gateway_checkouts')->first();
        $this->outcome = 'requires_payment_method';
        $this->postJson('/recurring-test/commercial/subscription-checkouts/'.$checkout->id.'/authentication')->assertOk()->assertJsonPath('status', 'failed');
        $c->scan(25);
        $this->travel(20)->days();
        $c->scan(25);
        $this->assertDatabaseHas('subscription_collections', ['invoice_id' => $i, 'state' => 'permanent_failure', 'attempts' => 1]);
        $this->assertDatabaseCount('commercial_subscription_receipts', 0);
        $this->assertFalse(app(SubscriptionService::class)->summary($s)['entitlements_active']);
        $this->assertCount(1, collect($this->sent)->filter(fn ($r) => $r[0] === 'POST' && $r[1] === '/v1/payment_intents'));
    }

    public function test_due_renewal_issues_next_frozen_invoice_and_collects_once_through_shared_gateway(): void
    {
        $u = User::factory()->create();
        $id = $this->enroll($u);
        [$s] = $this->subscription($u);
        $c = app(SubscriptionCollections::class);
        $c->policy($u, $s, true, $id, 0);
        $c->scan(25);
        $this->travel(32)->days();
        $c->scan(25);
        $c->scan(25);
        $this->assertDatabaseCount('commercial_subscription_invoices', 2);
        $this->assertDatabaseCount('commercial_subscription_receipts', 2);
        $this->assertDatabaseHas('commercial_subscriptions', ['id' => $s, 'status' => 'active', 'cycle_number' => 2]);
        $this->assertDatabaseCount('subscription_gateway_checkouts', 2);
        $this->assertSame(2100, (int) DB::table('commercial_subscription_receipts')->sum('amount_minor'));
        $this->assertTrue(app(SubscriptionService::class)->summary($s)['ledger_balanced']);
    }
}
