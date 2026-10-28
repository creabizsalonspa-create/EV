<?php

namespace Tests\Feature;

use App\Http\Controllers\ExternalDisputesController;
use App\Models\ChargingSession;
use App\Models\Reservation;
use App\Models\Station;
use App\Models\User;
use App\Services\Commercial\Disputes\ExternalDisputes;
use App\Services\Commercial\Payments\GatewayService;
use App\Services\Finance\FinanceService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ExternalDisputesTest extends TestCase
{
    use RefreshDatabase;

    private array $body;

    private User $user;

    private User $vendor;

    private User $admin;

    private object $invoice;

    protected function setUp(): void
    {
        parent::setUp();
        config(['payments.provider' => 'stripe_test', 'payments.stripe_test_secret' => 'sk_test_contractonly', 'payments.stripe_test_webhook_secret' => 'whsec_'.str_repeat('x', 40)]);
        Http::preventStrayRequests();
        Route::middleware(['web', 'auth'])->prefix('dispute-test')->group(base_path('routes/external_disputes.php'));
        Route::post('/dispute-callback-test', [ExternalDisputesController::class, 'webhook']);
        $this->user = User::factory()->create();
        $this->vendor = User::factory()->create(['role' => 'vendor']);
        $this->admin = User::factory()->create(['role' => 'admin']);
        $station = Station::create(['vendor_id' => $this->vendor->id, 'name' => 'Dispute test simulator', 'country' => 'AE', 'currency' => 'AED', 'timezone' => 'Asia/Dubai', 'latitude' => 25, 'longitude' => 55, 'source' => 'simulator', 'connector_type' => 'CCS2', 'power_kw' => 50]);
        $reservation = Reservation::create(['user_id' => $this->user->id, 'station_id' => $station->id, 'starts_at' => now(), 'status' => 'confirmed', 'idempotency_key' => 'dispute-fixture']);
        $session = ChargingSession::create(['reservation_id' => $reservation->id, 'status' => 'stopped', 'energy_wh' => 10000, 'rate_minor_per_kwh' => 100, 'currency' => 'AED', 'total_minor' => 1000, 'stopped_at' => now()]);
        $finance = app(FinanceService::class);
        $this->invoice = $finance->issue($session, $this->user->id);
        $finance->pay($this->invoice, $this->user->id, 1000, 'dispute-fixture');
        DB::table('gateway_checkouts')->insert(['invoice_id' => $this->invoice->id, 'user_id' => $this->user->id, 'provider' => 'stripe_test', 'provider_reference' => 'pi_Dispute', 'request_key' => hash('sha256', 'fixture'), 'fingerprint' => hash('sha256', 'fixture'), 'currency' => 'AED', 'amount_minor' => 1000, 'status' => 'captured', 'created_at' => now(), 'updated_at' => now()]);
        $this->body = ['id' => 'dp_Dispute', 'livemode' => false, 'charge' => 'ch_Dispute', 'payment_intent' => 'pi_Dispute', 'amount' => 700, 'currency' => 'aed', 'status' => 'needs_response', 'reason' => 'fraudulent', 'evidence_details' => ['due_by' => now()->addDays(3)->timestamp], 'evidence' => []];
        $this->fakeProvider(function ($request) {
            if (str_contains($request->url(), '/charges/')) {
                return Http::response(['id' => 'ch_Dispute', 'livemode' => false, 'payment_intent' => 'pi_Dispute', 'paid' => true, 'captured' => true, 'amount_captured' => 1000, 'currency' => 'aed']);
            }
            $body = $this->body;
            if ($request->method() === 'POST') {
                $body['evidence'] = $request['evidence'];
                if ($request['submit'] === 'true') {
                    $body['status'] = 'under_review';
                    $body['evidence_details']['submission_count'] = 1;
                }
            }

            return Http::response($body);
        });
    }

    private function fakeProvider($callback = null): void
    {
        // Laravel merges repeated stubs; reset the factory so uncertainty/error
        // scenarios cannot be accidentally answered by an earlier happy stub.
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake($callback);
    }

    private function providerEvent(string $id = 'evt_Dispute', bool $signed = true)
    {
        $raw = json_encode(['id' => $id, 'type' => 'charge.dispute.created', 'livemode' => false, 'data' => ['object' => $this->body]], JSON_THROW_ON_ERROR);
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$raw, $signed ? config('payments.stripe_test_webhook_secret') : 'wrong');

        return $this->call('POST', '/dispute-callback-test', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => 't='.$timestamp.',v1='.$signature], $raw);
    }

    public function test_signed_callback_creates_one_canonical_hold_and_private_history(): void
    {
        $this->providerEvent('evt_Invalid', false)->assertUnauthorized();
        Http::assertNothingSent();
        $id = $this->providerEvent()->assertOk()->assertJsonPath('duplicate', false)->json('dispute_id');
        $this->providerEvent()->assertOk()->assertJsonPath('duplicate', true);
        $this->assertDatabaseCount('external_disputes', 1);
        $this->assertDatabaseCount('gateway_cases', 1);
        $this->assertDatabaseHas('gateway_cases', ['status' => 'open', 'invoice_id' => $this->invoice->id]);
        $this->actingAs($this->user)->getJson('/dispute-test/commercial/external-disputes/'.$id)->assertOk()->assertJsonPath('dispute.amount_minor', 700)->assertJsonMissingPath('dispute.provider_reference')->assertJsonCount(0, 'evidence_submissions');
        $this->actingAs(User::factory()->create())->getJson('/dispute-test/commercial/external-disputes/'.$id)->assertNotFound();
        $this->actingAs($this->vendor)->postJson('/dispute-test/commercial/external-disputes/'.$id.'/reconcile')->assertForbidden();
        $this->assertDatabaseCount('finance_payments', 1);
        $this->assertDatabaseCount('finance_refunds', 0);
    }

    public function test_changed_event_and_wrong_charge_currency_fail_closed(): void
    {
        $this->providerEvent()->assertOk();
        $this->body['currency'] = 'usd';
        $this->providerEvent()->assertConflict();
        $this->providerEvent('evt_Other')->assertConflict();
        $this->assertDatabaseCount('external_dispute_events', 1);
    }

    public function test_provider_reconciliation_releases_only_won_hold_and_preserves_lost_hold(): void
    {
        $id = $this->providerEvent()->assertOk()->json('dispute_id');
        $this->actingAs($this->admin);
        $this->body['status'] = 'lost';
        $this->postJson('/dispute-test/commercial/external-disputes/'.$id.'/reconcile')->assertOk()->assertJsonPath('dispute.settlement_hold', true);
        $this->assertDatabaseHas('gateway_cases', ['status' => 'open']);
        $this->body['status'] = 'won';
        $this->postJson('/dispute-test/commercial/external-disputes/'.$id.'/reconcile')->assertOk()->assertJsonPath('dispute.settlement_hold', false);
        $this->assertDatabaseHas('gateway_cases', ['status' => 'rejected']);
        $this->assertDatabaseCount('finance_refunds', 0);
        $this->assertTrue(app(FinanceService::class)->summary($this->invoice)['ledger_balanced']);
    }

    public function test_provider_case_cannot_be_manually_rejected_to_release_hold(): void
    {
        $id = $this->providerEvent()->assertOk()->json('dispute_id');
        $case = DB::table('external_disputes')->find($id)->case_id;
        try {
            app(GatewayService::class)->resolveCase($case, $this->admin->id, 'rejected', 'Bypass');
            $this->fail('Expected provider hold guard');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        $this->assertDatabaseHas('gateway_cases', ['id' => $case, 'status' => 'open']);
    }

    public function test_evidence_is_admin_only_encrypted_idempotent_and_bounded(): void
    {
        $id = $this->providerEvent()->assertOk()->json('dispute_id');
        $path = '/dispute-test/commercial/external-disputes/'.$id.'/evidence';
        $payload = ['idempotency_key' => 'evidence', 'submit' => false, 'evidence' => ['product_description' => 'Private charging record']];
        $this->actingAs($this->vendor)->postJson($path, $payload)->assertForbidden();
        $this->actingAs($this->admin)->postJson($path, $payload)->assertOk()->assertJsonPath('evidence_submissions.0.status', 'confirmed');
        $this->postJson($path, $payload)->assertOk();
        $this->assertDatabaseCount('external_dispute_evidence', 1);
        $row = DB::table('external_dispute_evidence')->first();
        $this->assertStringNotContainsString('Private charging record', $row->encrypted_payload);
        $payload['evidence']['product_description'] = 'changed';
        $this->postJson($path, $payload)->assertConflict();
        $payload['idempotency_key'] = 'raw-card';
        $payload['evidence'] = ['card_number' => '4111111111111111'];
        $this->postJson($path, $payload)->assertUnprocessable();
        Http::assertSent(fn ($r) => $r->method() === 'POST' && $r->hasHeader('Idempotency-Key') && $r['submit'] === 'false');
    }

    public function test_evidence_deadline_blocks_new_submission(): void
    {
        $id = $this->providerEvent()->assertOk()->json('dispute_id');
        $this->travel(4)->days();
        $this->actingAs($this->admin)->postJson('/dispute-test/commercial/external-disputes/'.$id.'/evidence', ['idempotency_key' => 'expired', 'submit' => true, 'evidence' => ['product_description' => 'Expired']])->assertConflict();
        $this->assertDatabaseCount('external_dispute_evidence', 0);
    }

    public function test_lost_evidence_response_is_recovered_by_read_only_exact_evidence(): void
    {
        $id = $this->providerEvent()->assertOk()->json('dispute_id');
        $path = '/dispute-test/commercial/external-disputes/'.$id.'/evidence';
        $this->fakeProvider(fn ($r) => $r->method() === 'POST' ? Http::failedConnection() : (str_contains($r->url(), '/charges/') ? Http::response(['id' => 'ch_Dispute', 'livemode' => false, 'payment_intent' => 'pi_Dispute', 'paid' => true, 'captured' => true, 'amount_captured' => 1000, 'currency' => 'aed']) : Http::response($this->body)));
        $payload = ['idempotency_key' => 'uncertain', 'submit' => true, 'evidence' => ['product_description' => 'Private approved evidence']];
        $this->actingAs($this->admin)->postJson($path, $payload)->assertStatus(503);
        $this->assertDatabaseHas('external_dispute_evidence', ['status' => 'uncertain']);
        $payload['idempotency_key'] = 'replacement';
        $this->postJson($path, $payload)->assertConflict();
        $this->body['status'] = 'under_review';
        $this->body['evidence'] = ['product_description' => 'Private approved evidence'];
        $this->body['evidence_details']['submission_count'] = 1;
        $this->fakeProvider(fn ($r) => str_contains($r->url(), '/charges/') ? Http::response(['id' => 'ch_Dispute', 'livemode' => false, 'payment_intent' => 'pi_Dispute', 'paid' => true, 'captured' => true, 'amount_captured' => 1000, 'currency' => 'aed']) : Http::response($this->body));
        $this->postJson('/dispute-test/commercial/external-disputes/'.$id.'/reconcile')->assertOk()->assertJsonPath('evidence_submissions.0.status', 'confirmed');
        Http::assertNotSent(fn ($r) => $r->method() === 'POST');
    }

    public function test_live_provider_evidence_and_keys_are_never_accepted(): void
    {
        $this->body['livemode'] = true;
        $this->providerEvent()->assertStatus(502);
        $this->assertDatabaseCount('external_disputes', 0);
        $this->body['livemode'] = false;
        $this->fakeProvider();
        config(['payments.stripe_test_secret' => 'sk_live_forbidden']);
        $this->providerEvent()->assertStatus(503);
        Http::assertNothingSent();
    }

    public function test_dispute_events_and_submitted_evidence_terms_are_database_immutable(): void
    {
        $id = $this->providerEvent()->assertOk()->json('dispute_id');
        $this->actingAs($this->admin)->postJson('/dispute-test/commercial/external-disputes/'.$id.'/evidence', ['idempotency_key' => 'immutable', 'submit' => false, 'evidence' => ['product_description' => 'Evidence']])->assertOk();
        foreach ([fn () => DB::table('external_dispute_events')->delete(), fn () => DB::table('external_dispute_evidence')->update(['encrypted_payload' => 'tampered']), fn () => DB::table('external_dispute_evidence')->delete()] as $write) {
            try {
                DB::transaction($write);
                $this->fail('Financial evidence mutation was allowed.');
            } catch (QueryException $error) {
                $this->assertStringContainsString('immutable', strtolower($error->getMessage()));
            }
        }
    }

    public function test_unresolved_provider_disputes_block_refund_before_provider_mutation(): void
    {
        $id = $this->providerEvent()->assertOk()->json('dispute_id');
        $checkout = DB::table('external_disputes')->find($id)->checkout_id;
        foreach (['needs_response', 'lost'] as $status) {
            $this->body['status'] = $status;
            app(ExternalDisputes::class)->reconcile($id);
            try {
                app(GatewayService::class)->operate($checkout, $this->admin->id, 'refund', 'blocked-'.$status, 100, null, 'Must not double-return funds');
                $this->fail('Expected unresolved dispute refund guard');
            } catch (HttpException $error) {
                $this->assertSame(409, $error->getStatusCode());
            }
        }
        Http::assertNotSent(fn ($r) => $r->method() === 'POST');
        $this->assertDatabaseCount('finance_refunds', 0);
    }

    public function test_provider_scan_requires_opt_in_and_reports_attention_without_mutations(): void
    {
        $this->providerEvent()->assertOk();
        $this->fakeProvider();
        $this->artisan('commercial:reconcile-disputes')->assertFailed();
        $this->artisan('commercial:reconcile-disputes', ['--limit' => 0, '--provider' => true])->assertFailed();
        Http::assertNothingSent();
        $this->fakeProvider(fn ($r) => str_contains($r->url(), '/charges/') ? Http::response(['id' => 'ch_Dispute', 'livemode' => false, 'payment_intent' => 'pi_Dispute', 'paid' => true, 'captured' => true, 'amount_captured' => 1000, 'currency' => 'aed']) : Http::response($this->body));
        $this->body['status'] = 'lost';
        $this->artisan('commercial:reconcile-disputes', ['--provider' => true])->assertFailed();
        Http::assertNotSent(fn ($r) => $r->method() === 'POST');
        $this->assertDatabaseCount('finance_refunds', 0);
    }

    public function test_failed_scan_never_claims_new_authoritative_provider_observation(): void
    {
        $id = $this->providerEvent()->assertOk()->json('dispute_id');
        $before = DB::table('external_disputes')->find($id)->provider_observed_at;
        $this->travel(2)->hours();
        $this->fakeProvider(fn () => Http::response([], 503));
        $counts = app(ExternalDisputes::class)->scan(1);
        $this->assertSame(1, $counts['failed']);
        $this->assertSame($before, DB::table('external_disputes')->find($id)->provider_observed_at);
        $this->assertDatabaseHas('gateway_cases', ['status' => 'open']);
    }
}
