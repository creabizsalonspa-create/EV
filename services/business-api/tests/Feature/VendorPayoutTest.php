<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Commercial\Payouts\PayoutService;
use App\Services\Commercial\SettlementService;
use App\Services\Finance\FinanceService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class VendorPayoutTest extends TestCase
{
    use RefreshDatabase;

    private array $state = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['payouts.enabled' => true, 'payouts.mock_outcome' => 'paid', 'payments.stripe_test_secret' => 'sk_test_contractonly', 'payouts.stripe_webhook_secret' => 'whsec_'.str_repeat('payoutcontract', 4)]);
        Http::preventStrayRequests();
        Route::middleware(['web', 'auth'])->prefix('payout-test')->group(base_path('routes/payouts.php'));
        Route::middleware(['web', 'auth'])->prefix('payout-test')->group(base_path('routes/settlements.php'));
        Route::middleware(['web', 'auth'])->prefix('payout-test')->group(base_path('routes/commercial_finance.php'));
        Route::prefix('payout-test')->group(base_path('routes/payout_webhooks.php'));
    }

    private function fixture(string $provider = 'mock'): array
    {
        return (require base_path('tests/Fixtures/vendor-payout.php'))($provider);
    }

    private function path(int $id, string $action = ''): string
    {
        return '/payout-test/commercial/payouts/'.$id.($action ? '/'.$action : '');
    }

    private function prepared(array $f, string $key = 'prepare'): int
    {
        $s = app(PayoutService::class);
        $s->verify($f['account']->id, $f['maker']->id);

        return $this->actingAs($f['maker'])->postJson('/payout-test/commercial/settlements/'.$f['settlement']->id.'/payouts', ['account_id' => $f['account']->id, 'idempotency_key' => $key])->assertCreated()->json('payout.id');
    }

    private function approved(array $f): int
    {
        $id = $this->prepared($f);
        $this->actingAs($f['checker'])->postJson($this->path($id, 'approve'))->assertOk();

        return $id;
    }

    private function stripeFake(?callable $post = null, int $funds = 900, bool $wrongVendor = false): void
    {
        Http::fake(function ($r) use ($post, $funds, $wrongVendor) {
            $path = parse_url($r->url(), PHP_URL_PATH);
            if ($path === '/v1/accounts/acct_Payout') {
                return Http::response(['id' => 'acct_Payout', 'object' => 'account', 'type' => 'custom', 'payouts_enabled' => true, 'details_submitted' => true, 'metadata' => ['creabiz_vendor_id' => $wrongVendor ? '999999' : (string) $this->state['vendor']]]);
            }
            if ($path === '/v1/accounts/acct_Payout/external_accounts/ba_Payout') {
                return Http::response(['id' => 'ba_Payout', 'object' => 'bank_account', 'currency' => 'aed', 'status' => 'verified']);
            }
            $this->assertSame(['acct_Payout'], $r->header('Stripe-Account'));
            if ($path === '/v1/balance') {
                return Http::response(['object' => 'balance', 'livemode' => false, 'available' => [['currency' => 'aed', 'amount' => $funds]]]);
            }
            if ($path === '/v1/payouts' && $r->method() === 'POST') {
                $this->state['posts'] = ($this->state['posts'] ?? 0) + 1;
                $this->state['keys'][] = $r->header('Idempotency-Key')[0];
                $this->state['body'] = ['id' => 'po_Contract', 'object' => 'payout', 'livemode' => false, 'amount' => (int) $r['amount'], 'currency' => $r['currency'], 'destination' => $r['destination'], 'status' => 'paid', 'metadata' => $r['metadata']];
                if ($post) {
                    return $post($r, $this->state['posts']);
                }

                return Http::response($this->state['body']);
            }
            if ($path === '/v1/payouts/po_Contract') {
                return Http::response($this->state['body']);
            }
            if ($path === '/v1/payouts' && $r->method() === 'GET') {
                return Http::response(['object' => 'list', 'data' => isset($this->state['body']) ? [$this->state['body']] : [], 'has_more' => false]);
            }
            throw new \LogicException('Unexpected payout test URL');
        });
    }

    public function test_mock_payout_has_maker_checker_source_state_and_no_invoice_cash_mutation(): void
    {
        $f = $this->fixture();
        $id = $this->prepared($f);
        $this->postJson($this->path($id, 'approve'))->assertForbidden();
        $this->postJson($this->path($id, 'execute'))->assertConflict();
        $this->actingAs($f['checker'])->postJson($this->path($id, 'approve'))->assertOk();
        $this->postJson($this->path($id, 'execute'))->assertOk()->assertJsonPath('payout.status', 'paid')->assertJsonPath('payout.evidence', 'MOCK_PAYOUT')->assertJsonPath('payout.real_money_moved', false);
        $this->postJson($this->path($id, 'execute'))->assertOk();
        $this->assertDatabaseCount('commercial_payout_attempts', 1);
        $this->assertDatabaseCount('finance_journals', 2);
        $this->assertDatabaseCount('finance_payments', 1);
        $this->assertDatabaseHas('commercial_settlements', ['id' => $f['settlement']->id, 'state' => 'provider_paid']);
        $this->actingAs($f['vendor'])->getJson($this->path($id))->assertOk()->assertJsonMissingPath('account.account_reference')->assertJsonMissingPath('account.bank_reference')->assertJsonMissingPath('attempts.0.lease');
        $this->getJson('/payout-test/commercial/vendors/'.$f['vendor']->id.'/statement?currency=AED')->assertOk()->assertJsonPath('available_minor', 0);
    }

    public function test_account_configuration_is_encrypted_and_requires_vendor_consent_and_provider_ownership(): void
    {
        $f = $this->fixture('stripe_test');
        $this->state['vendor'] = $f['vendor']->id;
        $this->stripeFake(null, 900, true);
        $this->assertNotSame('acct_Payout', DB::table('commercial_payout_accounts')->value('account_reference'));
        $this->assertSame('acct_Payout', Crypt::decryptString(DB::table('commercial_payout_accounts')->value('account_reference')));
        $this->actingAs($f['maker'])->postJson('/payout-test/commercial/payout-accounts/'.$f['account']->id.'/verify')->assertConflict();
        $this->actingAs($f['vendor'])->postJson('/payout-test/commercial/payout-accounts/'.$f['account']->id.'/verify')->assertForbidden();
        $this->actingAs($f['maker'])->postJson('/payout-test/commercial/payout-accounts/'.$f['account']->id.'/consent', ['consent' => true, 'consent_version' => 'vendor-payout-v1'])->assertForbidden();
        $this->actingAs($f['vendor'])->postJson('/payout-test/commercial/payout-accounts/'.$f['account']->id.'/revoke')->assertOk()->assertJsonPath('account.status', 'revoked');
        $this->actingAs($f['maker'])->postJson('/payout-test/commercial/payout-accounts/'.$f['account']->id.'/verify')->assertConflict();
    }

    public function test_stripe_test_connected_bank_payout_is_funding_checked_and_posts_once(): void
    {
        $f = $this->fixture('stripe_test');
        $this->state['vendor'] = $f['vendor']->id;
        $this->stripeFake();
        $id = $this->approved($f);
        $this->postJson($this->path($id, 'execute'))->assertOk()->assertJsonPath('payout.status', 'paid')->assertJsonPath('payout.amount_minor', 900);
        $this->postJson($this->path($id, 'execute'))->assertOk();
        $this->assertSame(1, $this->state['posts']);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/transfers'));
        $this->assertDatabaseHas('commercial_payout_attempts', ['provider_reference' => 'po_Contract', 'status' => 'paid']);
    }

    public function test_insufficient_connected_funding_sends_no_bank_payout_and_needs_fresh_approval_on_retry(): void
    {
        $f = $this->fixture('stripe_test');
        $this->state['vendor'] = $f['vendor']->id;
        $this->stripeFake(null, 899);
        $id = $this->approved($f);
        $this->postJson($this->path($id, 'execute'))->assertStatus(503);
        $this->assertSame(0, $this->state['posts'] ?? 0);
        $this->assertDatabaseHas('commercial_payouts', ['id' => $id, 'status' => 'failed']);
        $this->postJson($this->path($id, 'retry'))->assertOk()->assertJsonPath('payout.status', 'prepared');
        $this->postJson($this->path($id, 'approve'))->assertForbidden();
        $this->actingAs($f['maker'])->postJson($this->path($id, 'approve'))->assertOk();
    }

    public function test_timeout_is_durable_blocks_legacy_bypass_and_recovers_by_metadata_get_without_repost(): void
    {
        $f = $this->fixture('stripe_test');
        $this->state['vendor'] = $f['vendor']->id;
        $this->stripeFake(fn () => throw new ConnectionException('Synthetic network loss'));
        $id = $this->approved($f);
        $this->postJson($this->path($id, 'execute'))->assertStatus(503);
        $this->assertDatabaseHas('commercial_payouts', ['id' => $id, 'status' => 'uncertain']);
        $this->postJson($this->path($id, 'retry'))->assertConflict();
        $this->postJson($this->path($id, 'cancel'))->assertConflict();
        $this->postJson('/payout-test/commercial/settlements/'.$f['settlement']->id.'/simulate-payout')->assertConflict();
        $this->postJson('/payout-test/commercial/settlements/'.$f['settlement']->id.'/reject')->assertConflict();
        $this->postJson($this->path($id, 'reconcile'))->assertOk()->assertJsonPath('payout.status', 'paid');
        $this->assertSame(1, $this->state['posts']);
    }

    public function test_unknown_same_attempt_replay_retains_provider_key_and_never_gets_a_replacement_identity(): void
    {
        $f = $this->fixture('stripe_test');
        $this->state['vendor'] = $f['vendor']->id;
        $this->stripeFake(function ($r, $count) {
            if ($count === 1) {
                throw new ConnectionException('Synthetic lost response');
            }

            return Http::response($this->state['body']);
        });
        $id = $this->approved($f);
        $this->postJson($this->path($id, 'execute'))->assertStatus(503);
        $this->postJson($this->path($id, 'execute'))->assertOk()->assertJsonPath('payout.status', 'paid');
        $this->assertCount(1, array_unique($this->state['keys']));
        $this->assertDatabaseCount('commercial_payout_attempts', 1);
    }

    public function test_provider_paid_fact_survives_refund_after_dispatch_and_creates_vendor_debt(): void
    {
        $f = $this->fixture('stripe_test');
        $this->state['vendor'] = $f['vendor']->id;
        $this->stripeFake(function () use ($f) {
            app(FinanceService::class)->refund($f['invoice'], $f['maker']->id, 525, 'after-dispatch', 'Refund concurrent with payout');

            return Http::response($this->state['body']);
        });
        $id = $this->approved($f);
        $this->postJson($this->path($id, 'execute'))->assertOk()->assertJsonPath('payout.status', 'paid');
        $this->assertSame(-450, app(SettlementService::class)->balances($f['vendor']->id, 'AED')['available_minor']);
        $this->assertTrue(app(FinanceService::class)->summary($f['invoice'])['ledger_balanced']);
    }

    public function test_pending_provider_status_never_releases_reservation_and_final_failure_allows_retry(): void
    {
        $f = $this->fixture('stripe_test');
        $this->state['vendor'] = $f['vendor']->id;
        $this->stripeFake(function () {
            $this->state['body']['status'] = 'pending';

            return Http::response($this->state['body']);
        });
        $id = $this->approved($f);
        $this->postJson($this->path($id, 'execute'))->assertOk()->assertJsonPath('payout.status', 'pending');
        $this->postJson($this->path($id, 'retry'))->assertConflict();
        $this->state['body']['status'] = 'failed';
        $this->state['body']['failure_code'] = 'account_closed';
        $this->postJson($this->path($id, 'reconcile'))->assertOk()->assertJsonPath('payout.status', 'failed');
        $this->postJson($this->path($id, 'retry'))->assertOk()->assertJsonPath('payout.status', 'prepared');
    }

    private function webhook(array $body, string $signature = '')
    {
        $raw = json_encode($body, JSON_THROW_ON_ERROR);
        $signature = $signature ?: 't='.time().',v1='.hash_hmac('sha256', time().'.'.$raw, config('payouts.stripe_webhook_secret'));

        return $this->call('POST', '/payout-test/webhooks/payouts/stripe-test', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => $signature], $raw);
    }

    public function test_signed_webhook_retrieval_reversal_deduplication_and_scope_checks(): void
    {
        $f = $this->fixture('stripe_test');
        $this->state['vendor'] = $f['vendor']->id;
        $this->stripeFake();
        $id = $this->approved($f);
        $this->postJson($this->path($id, 'execute'))->assertOk();
        $body = ['id' => 'evt_Payout', 'livemode' => false, 'type' => 'payout.failed', 'account' => 'acct_Payout', 'data' => ['object' => array_replace($this->state['body'], ['status' => 'failed'])]];
        $this->webhook($body, 't='.time().',v1=bad')->assertUnauthorized();
        $this->state['body']['status'] = 'failed';
        $this->state['body']['failure_code'] = 'account_closed';
        $this->webhook($body)->assertOk()->assertJsonPath('accepted', true);
        $this->webhook($body)->assertOk()->assertJsonPath('duplicate', true);
        $this->assertDatabaseHas('commercial_payouts', ['id' => $id, 'status' => 'reversed']);
        $this->assertDatabaseHas('commercial_settlements', ['id' => $f['settlement']->id, 'state' => 'approved']);
        $body['data']['object']['amount'] = 901;
        $this->webhook($body)->assertConflict();
        $this->assertDatabaseCount('commercial_payout_webhooks', 1);
        $body['id'] = 'evt_StalePaid';
        $body['type'] = 'payout.paid';
        $body['data']['object']['amount'] = 900;
        $body['data']['object']['status'] = 'paid';
        $this->webhook($body)->assertOk();
        $this->assertDatabaseHas('commercial_payouts', ['id' => $id, 'status' => 'reversed']);
        $this->postJson($this->path($id, 'retry'))->assertOk();
    }

    public function test_tenant_privacy_role_authorization_and_immutable_economic_terms(): void
    {
        $f = $this->fixture();
        $id = $this->prepared($f);
        $this->actingAs(User::factory()->create(['role' => 'vendor']))->getJson($this->path($id))->assertNotFound();
        $this->getJson('/payout-test/commercial/payouts')->assertJsonCount(0, 'data');
        $this->getJson('/payout-test/commercial/payout-accounts')->assertJsonCount(0, 'data');
        $this->postJson($this->path($id, 'execute'))->assertForbidden();
        $this->actingAs($f['customer'])->getJson('/payout-test/commercial/payouts')->assertForbidden();
        $this->expectException(QueryException::class);
        DB::table('commercial_payouts')->where('id', $id)->update(['amount_minor' => 1]);
    }

    public function test_refund_request_after_payout_approval_holds_dispatch_without_creating_an_attempt(): void
    {
        $f = $this->fixture();
        $id = $this->approved($f);
        $this->actingAs($f['customer'])->postJson('/payout-test/finance/invoices/'.$f['invoice']->id.'/refund-requests', ['amount_minor' => 100, 'reason' => 'Owner requested credit', 'idempotency_key' => 'hold'])->assertCreated();
        $this->actingAs($f['checker'])->postJson($this->path($id, 'execute'))->assertConflict();
        $this->assertDatabaseCount('commercial_payout_attempts', 0);
        $this->assertDatabaseHas('commercial_payouts', ['id' => $id, 'status' => 'approved']);
    }

    public function test_unknown_attempt_outside_retry_window_requires_read_only_reconciliation(): void
    {
        $f = $this->fixture('stripe_test');
        $this->state['vendor'] = $f['vendor']->id;
        $this->stripeFake(fn () => throw new ConnectionException('Synthetic unknown outcome'));
        $id = $this->approved($f);
        $this->postJson($this->path($id, 'execute'))->assertStatus(503);
        $this->travel(24)->hours();
        $this->postJson($this->path($id, 'execute'))->assertConflict();
        $this->postJson($this->path($id, 'retry'))->assertConflict();
        $this->postJson($this->path($id, 'reconcile'))->assertOk()->assertJsonPath('payout.status', 'paid');
        $this->assertSame(1, $this->state['posts']);
        $this->assertDatabaseCount('commercial_payout_attempts', 1);
    }

    public function test_mismatched_provider_amount_is_uncertain_and_never_records_a_paid_settlement(): void
    {
        $f = $this->fixture('stripe_test');
        $this->state['vendor'] = $f['vendor']->id;
        $this->stripeFake(function () {
            $this->state['body']['amount'] = 901;

            return Http::response($this->state['body']);
        });
        $id = $this->approved($f);
        $this->postJson($this->path($id, 'execute'))->assertStatus(503);
        $this->assertDatabaseHas('commercial_payouts', ['id' => $id, 'status' => 'uncertain']);
        $this->assertDatabaseHas('commercial_settlements', ['id' => $f['settlement']->id, 'state' => 'approved']);
        $this->postJson($this->path($id, 'reconcile'))->assertConflict();
        $this->assertDatabaseCount('finance_journals', 2);
    }

    public function test_bounded_reconciliation_worker_only_reads_provider_and_preserves_exact_invoice_journals(): void
    {
        $f = $this->fixture('stripe_test');
        $this->state['vendor'] = $f['vendor']->id;
        $this->stripeFake(function () {
            $this->state['body']['status'] = 'pending';

            return Http::response($this->state['body']);
        });
        $id = $this->approved($f);
        $this->postJson($this->path($id, 'execute'))->assertOk();
        $this->state['body']['status'] = 'paid';
        $this->artisan('finance:payout-reconcile', ['--limit' => 1])->assertSuccessful();
        $this->assertDatabaseHas('commercial_payouts', ['id' => $id, 'status' => 'paid']);
        $this->assertSame(1, $this->state['posts']);
        $this->assertDatabaseCount('finance_journals', 2);
    }

    public function test_production_and_live_credentials_are_rejected_before_any_provider_request(): void
    {
        $f = $this->fixture('stripe_test');
        config(['payments.stripe_test_secret' => 'sk_live_prohibited']);
        $this->actingAs($f['maker'])->postJson('/payout-test/commercial/payout-accounts/'.$f['account']->id.'/verify')->assertStatus(503);
        Http::assertNothingSent();
        $this->app->detectEnvironment(fn () => 'production');
        try {
            app(PayoutService::class)->verify($f['account']->id, $f['maker']->id);
            $this->fail('Production payout accepted');
        } catch (HttpException $e) {
            $this->assertSame(503, $e->getStatusCode());
        }
    }

    public function test_revocation_refund_hold_and_production_or_live_keys_prevent_execution(): void
    {
        $f = $this->fixture('stripe_test');
        $this->state['vendor'] = $f['vendor']->id;
        $this->stripeFake();
        $id = $this->approved($f);
        app(PayoutService::class)->revoke($f['account']->id, $f['vendor']->id);
        $this->postJson($this->path($id, 'execute'))->assertStatus(503);
        $this->assertSame(0, $this->state['posts'] ?? 0);
        config(['payments.stripe_test_secret' => 'sk_live_prohibited']);
        $this->postJson($this->path($id, 'reconcile'))->assertStatus(503);
        config(['payouts.enabled' => false]);
        $this->postJson($this->path($id, 'retry'))->assertStatus(503);
    }

    public function test_hosted_recipient_onboarding_is_owner_only_provider_bound_and_never_verifies_payment(): void
    {
        $f = $this->fixture('stripe_test');
        config(['app.url' => 'https://ev.example.test']);
        Http::fake(function ($r) use ($f) {
            $this->assertSame(['2024-06-20'], $r->header('Stripe-Version'));
            $this->assertSame([], $r->header('Stripe-Account'));
            if ($r->method() === 'GET') {
                return Http::response(['id' => 'acct_Payout', 'object' => 'account', 'type' => 'express', 'payouts_enabled' => false, 'details_submitted' => false, 'metadata' => ['creabiz_vendor_id' => (string) $f['vendor']->id]]);
            }
            $this->assertSame('https://api.stripe.com/v1/account_links', $r->url());
            $this->assertSame('acct_Payout', $r['account']);
            $this->assertSame('account_onboarding', $r['type']);
            $this->assertSame('https://ev.example.test/?payout_onboarding=return', $r['return_url']);
            $this->assertSame('https://ev.example.test/?payout_onboarding=refresh', $r['refresh_url']);
            $this->assertNotEmpty($r->header('Idempotency-Key'));

            return Http::response(['object' => 'account_link', 'url' => 'https://connect.stripe.com/setup/s/Contract', 'expires_at' => now()->addMinutes(5)->timestamp]);
        });
        $path = '/payout-test/commercial/payout-accounts/'.$f['account']->id.'/onboarding';
        $payload = ['idempotency_key' => 'hosted'];
        $this->actingAs($f['maker'])->postJson($path, $payload)->assertNotFound();
        $this->actingAs(User::factory()->create(['role' => 'vendor']))->postJson($path, $payload)->assertNotFound();
        Http::assertNothingSent();
        $this->actingAs($f['vendor'])->postJson($path, $payload)->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('url', 'https://connect.stripe.com/setup/s/Contract')->assertJsonPath('real_money_moved', false)->assertJsonMissingPath('account_reference');
        $this->assertDatabaseHas('commercial_payout_accounts', ['id' => $f['account']->id, 'status' => 'pending_verification', 'verified_at' => null]);
        $this->assertDatabaseCount('commercial_payouts', 0);
        $this->assertDatabaseCount('finance_journals', 2);
        $this->assertDatabaseHas('commercial_payout_events', ['account_id' => $f['account']->id, 'action' => 'account.onboarding_link_requested']);
    }

    public function test_hosted_onboarding_rejects_untrusted_redirect_and_revoked_or_mock_recipient(): void
    {
        $f = $this->fixture('stripe_test');
        config(['app.url' => 'https://ev.example.test']);
        Http::fake(fn ($r) => Http::response($r->method() === 'GET'
            ? ['id' => 'acct_Payout', 'object' => 'account', 'type' => 'custom', 'metadata' => ['creabiz_vendor_id' => (string) $f['vendor']->id]]
            : ['object' => 'account_link', 'url' => 'https://attacker.example/setup', 'expires_at' => now()->addMinutes(5)->timestamp]));
        $path = '/payout-test/commercial/payout-accounts/'.$f['account']->id;
        $this->actingAs($f['vendor'])->postJson($path.'/onboarding', ['idempotency_key' => 'unsafe'])->assertStatus(502);
        $this->assertDatabaseMissing('commercial_payout_events', ['account_id' => $f['account']->id, 'action' => 'account.onboarding_link_requested']);
        $this->postJson($path.'/revoke')->assertOk();
        Http::fake();
        $this->postJson($path.'/onboarding', ['idempotency_key' => 'revoked'])->assertConflict();
        Http::assertNothingSent();
        $m = $this->fixture();
        $this->actingAs($m['vendor'])->postJson('/payout-test/commercial/payout-accounts/'.$m['account']->id.'/onboarding', ['idempotency_key' => 'mock'])->assertConflict();
        Http::assertNothingSent();
    }
}
