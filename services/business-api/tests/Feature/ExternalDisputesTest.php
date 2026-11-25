<?php

namespace Tests\Feature;

use App\Http\Controllers\ExternalDisputesController;
use App\Models\ChargingSession;
use App\Models\InboxNotification;
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
use Illuminate\Support\Facades\Hash;
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

    public function test_revoked_customer_vendor_and_admin_cannot_read_private_provider_dispute_or_triage(): void
    {
        $dispute = $this->providerEvent('evt_CurrentDisputeSecurity')
            ->assertOk()->json('dispute_id');
        $base = '/dispute-test/commercial/external-disputes';
        $before = DB::table('external_dispute_events')->count();
        foreach ([$this->user, $this->vendor, $this->admin] as $reader) {
            $record = DB::table('users')->where('id', $reader->id)
                ->first(['role', 'account_status', 'auth_epoch', 'password']);
            $restore = [
                'role' => $record->role,
                'account_status' => $record->account_status,
                'auth_epoch' => $record->auth_epoch,
                'password' => $record->password,
            ];
            $this->actingAs($reader);
            $this->getJson($base)->assertOk()->assertJsonPath('total', 1);
            $this->getJson($base.'/'.$dispute)->assertOk();

            foreach ([
                ['role' => $record->role === 'vendor' ? 'customer' : 'vendor'],
                ['account_status' => 'suspended'],
                ['auth_epoch' => (int) $record->auth_epoch + 1],
                ['password' => Hash::make('revoked-private-dispute-reader')],
            ] as $mutation) {
                DB::table('users')->where('id', $reader->id)->update($mutation);
                $this->getJson($base)->assertConflict();
                $this->getJson($base.'/'.$dispute)->assertConflict();
                if ($record->role === 'admin') {
                    $this->getJson($base.'/deadline-queue')->assertConflict();
                }
                if ($record->role === 'admin') {
                    $this->postJson($base.'/'.$dispute.'/reconcile')->assertConflict();
                    $this->postJson($base.'/'.$dispute.'/evidence', [
                        'submit' => false,
                        'idempotency_key' => 'must-not-submit-revoked',
                        'evidence' => ['uncategorized_text' => 'Private dispute review'],
                    ])->assertConflict();
                }
                DB::table('users')->where('id', $reader->id)->update($restore);
            }
        }
        $this->assertSame($before, DB::table('external_dispute_events')->count());
        $this->assertDatabaseCount('external_disputes', 1);
    }

    public function test_changed_event_and_wrong_charge_currency_fail_closed(): void
    {
        $this->providerEvent()->assertOk();
        $this->body['currency'] = 'usd';
        $this->providerEvent()->assertConflict();
        $this->providerEvent('evt_Other')->assertConflict();
        $this->assertDatabaseCount('external_dispute_events', 1);
    }

    public function test_receipts_remain_scoped_and_reconciliation_stays_admin_web_only(): void
    {
        $id = $this->providerEvent()->assertOk()->json('dispute_id');
        $path = '/dispute-test/commercial/external-disputes/'.$id;
        foreach ([$this->user, $this->vendor] as $actor) {
            $this->actingAs($actor)->getJson($path)->assertOk()->assertJsonPath('dispute.id', $id);
            $this->postJson($path.'/reconcile')->assertForbidden();
        }
        $this->actingAs(User::factory()->create())->getJson($path)->assertNotFound();
        $this->actingAs($this->admin)->getJson($path)->assertOk()->assertJsonPath('dispute.id', $id);
        $this->assertDatabaseCount('finance_refunds', 0);
    }

    public function test_provider_notices_are_deduplicated_and_current_invoice_party_scoped(): void
    {
        $id = $this->providerEvent()->assertOk()->json('dispute_id');
        $this->assertDatabaseCount('inbox_notifications', 2);
        $this->providerEvent()->assertOk();
        $this->assertDatabaseCount('inbox_notifications', 2);
        $this->actingAs($this->admin)->postJson('/dispute-test/commercial/external-disputes/'.$id.'/reconcile')->assertOk();
        $this->assertDatabaseCount('inbox_notifications', 2);
        $query = InboxNotification::visibleTo($this->vendor);
        $this->assertSame(1, (clone $query)->count());
        DB::table('users')->where('id', $this->vendor->id)->update(['role' => 'customer']);
        $this->assertSame(0, (clone $query)->count());
        DB::table('users')->where('id', $this->vendor->id)->update(['role' => 'vendor', 'account_status' => 'suspended']);
        $this->assertSame(0, (clone $query)->count());
        $this->assertSame(1, InboxNotification::visibleTo($this->user)->count());
        $this->assertDatabaseCount('finance_refunds', 0);
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

    public function test_admin_revoked_during_provider_dispute_get_cannot_release_a_settlement_hold(): void
    {
        $id = $this->providerEvent('evt_InspectAdminEpoch')->assertOk()->json('dispute_id');
        $this->body['status'] = 'won';
        $eventCount = DB::table('external_dispute_events')->count();
        $notificationCount = DB::table('inbox_notifications')->count();
        $revoked = false;

        $this->fakeProvider(function ($request) use (&$revoked) {
            if (str_contains($request->url(), '/charges/')) {
                return Http::response([
                    'id' => 'ch_Dispute', 'livemode' => false,
                    'payment_intent' => 'pi_Dispute', 'paid' => true,
                    'captured' => true, 'amount_captured' => 1000,
                    'currency' => 'aed',
                ]);
            }
            if (! $revoked && str_contains($request->url(), '/disputes/')) {
                $revoked = true;
                DB::table('users')->where('id', $this->admin->id)->update([
                    'auth_epoch' => (int) $this->admin->auth_epoch + 1,
                ]);
            }

            return Http::response($this->body);
        });

        $this->actingAs($this->admin)
            ->postJson('/dispute-test/commercial/external-disputes/'.$id.'/reconcile')
            ->assertConflict();

        $this->assertTrue($revoked);
        $this->assertDatabaseHas('external_disputes', [
            'id' => $id, 'status' => 'needs_response', 'settlement_hold' => 1,
        ]);
        $this->assertDatabaseHas('gateway_cases', ['status' => 'open']);
        $this->assertSame($eventCount, DB::table('external_dispute_events')->count());
        $this->assertSame($notificationCount, DB::table('inbox_notifications')->count());
        $this->assertDatabaseCount('finance_refunds', 0);
    }

    public function test_admin_role_revoked_during_provider_charge_get_cannot_mutate_dispute_case(): void
    {
        $id = $this->providerEvent('evt_InspectAdminRole')->assertOk()->json('dispute_id');
        $this->body['status'] = 'won';
        $eventCount = DB::table('external_dispute_events')->count();
        $revoked = false;

        $this->fakeProvider(function ($request) use (&$revoked) {
            if (str_contains($request->url(), '/charges/')) {
                if (! $revoked) {
                    $revoked = true;
                    DB::table('users')->where('id', $this->admin->id)
                        ->update(['role' => 'customer']);
                }

                return Http::response([
                    'id' => 'ch_Dispute', 'livemode' => false,
                    'payment_intent' => 'pi_Dispute', 'paid' => true,
                    'captured' => true, 'amount_captured' => 1000,
                    'currency' => 'aed',
                ]);
            }

            return Http::response($this->body);
        });

        $this->actingAs($this->admin)
            ->postJson('/dispute-test/commercial/external-disputes/'.$id.'/reconcile')
            ->assertConflict();
        $this->assertTrue($revoked);
        $this->assertDatabaseHas('external_disputes', [
            'id' => $id, 'status' => 'needs_response', 'settlement_hold' => 1,
        ]);
        $this->assertDatabaseHas('gateway_cases', ['status' => 'open']);
        $this->assertSame($eventCount, DB::table('external_dispute_events')->count());
        $this->assertDatabaseCount('finance_refunds', 0);
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

    public function test_provider_evidence_service_cannot_be_invoked_without_request_bound_admin_authority(): void
    {
        $id = $this->providerEvent('evt_UnboundServiceCall')->assertOk()->json('dispute_id');
        $this->fakeProvider();
        try {
            app(ExternalDisputes::class)->evidence(
                $id, $this->admin->id, ['product_description' => 'Must remain private'],
                false, 'without-admin-credential-snapshot'
            );
            $this->fail('Evidence dispatch accepted without an Admin credential snapshot.');
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
        Http::assertNothingSent();
        $this->assertDatabaseCount('external_dispute_evidence', 0);
    }

    public function test_evidence_does_not_dispatch_after_admin_epoch_revocation_during_provider_inspection(): void
    {
        $id = $this->providerEvent('evt_EvidenceRevokedDuringGet')->assertOk()->json('dispute_id');
        $revoked = false;
        $this->fakeProvider(function ($request) use (&$revoked) {
            if (str_contains($request->url(), '/charges/')) {
                return Http::response(['id' => 'ch_Dispute', 'livemode' => false, 'payment_intent' => 'pi_Dispute', 'paid' => true, 'captured' => true, 'amount_captured' => 1000, 'currency' => 'aed']);
            }
            if ($request->method() === 'GET' && ! $revoked) {
                // Simulate an independent Admin session revoking credentials
                // while the request waits on its authoritative provider GET.
                DB::table('users')->where('id', $this->admin->id)->update([
                    'auth_epoch' => (int) $this->admin->auth_epoch + 1,
                ]);
                $revoked = true;
            }

            return Http::response($this->body);
        });

        $this->actingAs($this->admin)->postJson(
            '/dispute-test/commercial/external-disputes/'.$id.'/evidence',
            ['idempotency_key' => 'no-revoked-post', 'submit' => false,
                'evidence' => ['product_description' => 'Sensitive provider evidence']]
        )->assertConflict();
        $this->assertTrue($revoked);
        $this->assertDatabaseCount('external_dispute_evidence', 0);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
        $this->assertDatabaseCount('finance_refunds', 0);
    }

    public function test_uncertain_evidence_retry_does_not_dispatch_after_admin_role_revocation_during_inspection(): void
    {
        [$id, $operation] = $this->uncertainStoredEvidence();
        $revoked = false;
        $this->fakeProvider(function ($request) use (&$revoked) {
            if (str_contains($request->url(), '/charges/')) {
                return Http::response(['id' => 'ch_Dispute', 'livemode' => false, 'payment_intent' => 'pi_Dispute', 'paid' => true, 'captured' => true, 'amount_captured' => 1000, 'currency' => 'aed']);
            }
            if ($request->method() === 'GET' && ! $revoked) {
                DB::table('users')->where('id', $this->admin->id)->update(['role' => 'vendor']);
                $revoked = true;
            }

            return Http::response($this->body);
        });

        $this->actingAs($this->admin)->postJson(
            '/dispute-test/commercial/external-disputes/'.$id.'/evidence/'.$operation->id.'/retry'
        )->assertConflict();
        $this->assertTrue($revoked);
        $this->assertDatabaseHas('external_dispute_evidence', [
            'id' => $operation->id, 'status' => 'uncertain',
        ]);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
        $this->assertDatabaseCount('finance_refunds', 0);
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

    private function uncertainStoredEvidence(): array
    {
        $id = $this->providerEvent()->assertOk()->json('dispute_id');
        $this->fakeProvider(fn ($r) => $r->method() === 'POST' ? Http::failedConnection() : (str_contains($r->url(), '/charges/') ? Http::response(['id' => 'ch_Dispute', 'livemode' => false, 'payment_intent' => 'pi_Dispute', 'paid' => true, 'captured' => true, 'amount_captured' => 1000, 'currency' => 'aed']) : Http::response($this->body)));
        $this->actingAs($this->admin)->postJson('/dispute-test/commercial/external-disputes/'.$id.'/evidence', ['idempotency_key' => 'stored-original', 'submit' => false, 'evidence' => ['product_description' => 'Original encrypted private evidence']])->assertStatus(503);

        return [$id, DB::table('external_dispute_evidence')->first()];
    }

    public function test_reload_safe_retry_uses_only_stored_payload_and_original_provider_key(): void
    {
        [$id, $operation] = $this->uncertainStoredEvidence();
        $this->fakeProvider(function ($r) {
            if (str_contains($r->url(), '/charges/')) {
                return Http::response(['id' => 'ch_Dispute', 'livemode' => false, 'payment_intent' => 'pi_Dispute', 'paid' => true, 'captured' => true, 'amount_captured' => 1000, 'currency' => 'aed']);
            }
            $body = $this->body;
            if ($r->method() === 'POST') {
                $body['evidence'] = $r['evidence'];
            }

            return Http::response($body);
        });
        $path = '/dispute-test/commercial/external-disputes/'.$id.'/evidence/'.$operation->id.'/retry';
        $this->postJson($path, ['idempotency_key' => 'replacement'])->assertUnprocessable();
        $this->postJson($path, ['evidence' => ['product_description' => 'changed']])->assertUnprocessable();
        $this->postJson($path)->assertOk()->assertJsonPath('evidence_submissions.0.status', 'confirmed');
        Http::assertSent(fn ($r) => $r->method() === 'POST' && $r['evidence'] === ['product_description' => 'Original encrypted private evidence'] && $r->hasHeader('Idempotency-Key', 'creabiz-dispute-'.$operation->request_key));
        $this->postJson($path)->assertOk();
        $this->assertCount(1, Http::recorded(fn ($r) => $r->method() === 'POST'));
        $this->assertDatabaseCount('external_dispute_evidence', 1);
        $this->assertDatabaseCount('finance_refunds', 0);
    }

    public function test_stored_retry_rejects_foreign_scope_role_and_active_worker_lease(): void
    {
        [$id, $operation] = $this->uncertainStoredEvidence();
        $this->fakeProvider(fn ($r) => str_contains($r->url(), '/charges/') ? Http::response(['id' => 'ch_Dispute', 'livemode' => false, 'payment_intent' => 'pi_Dispute', 'paid' => true, 'captured' => true, 'amount_captured' => 1000, 'currency' => 'aed']) : Http::response($this->body));
        $path = '/dispute-test/commercial/external-disputes/'.$id.'/evidence/'.$operation->id.'/retry';
        $this->actingAs($this->vendor)->postJson($path)->assertForbidden();
        $this->actingAs($this->admin)->postJson('/dispute-test/commercial/external-disputes/999999/evidence/'.$operation->id.'/retry')->assertNotFound();
        DB::table('external_dispute_evidence')->where('id', $operation->id)->update(['status' => 'sending', 'lease_until' => now()->addMinutes(3)]);
        $this->postJson($path)->assertConflict();
        Http::assertNotSent(fn ($r) => $r->method() === 'POST');
        $this->assertDatabaseHas('external_dispute_evidence', ['id' => $operation->id, 'status' => 'sending']);
    }

    public function test_stored_retry_cannot_outlive_provider_retention_or_acceptance_state(): void
    {
        [$id, $operation] = $this->uncertainStoredEvidence();
        $this->fakeProvider(fn ($r) => str_contains($r->url(), '/charges/') ? Http::response(['id' => 'ch_Dispute', 'livemode' => false, 'payment_intent' => 'pi_Dispute', 'paid' => true, 'captured' => true, 'amount_captured' => 1000, 'currency' => 'aed']) : Http::response($this->body));
        $path = '/dispute-test/commercial/external-disputes/'.$id.'/evidence/'.$operation->id.'/retry';
        $this->travel(24)->hours();
        $this->postJson($path)->assertConflict();
        $this->travelBack();
        $this->body['status'] = 'lost';
        $this->postJson($path)->assertConflict();
        $this->assertDatabaseHas('external_dispute_evidence', ['id' => $operation->id, 'status' => 'uncertain']);
        $this->assertDatabaseCount('finance_refunds', 0);
        Http::assertNotSent(fn ($r) => $r->method() === 'POST');
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

    public function test_admin_deadline_queue_is_scoped_time_bounded_and_read_only(): void
    {
        $id = $this->providerEvent()->assertOk()->json('dispute_id');
        $path = '/dispute-test/commercial/external-disputes/deadline-queue';

        $this->actingAs($this->vendor)->getJson($path)->assertForbidden();
        $this->actingAs($this->user)->getJson($path)->assertForbidden();
        $this->actingAs($this->admin)->getJson($path.'?window_hours=48')
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('total', 0);
        $this->getJson($path.'?window_hours=72')->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $id)
            ->assertJsonPath('data.0.urgency', 'evidence_due_soon')
            ->assertJsonPath('data.0.amount_minor', 700)
            ->assertJsonPath('data.0.real_money_moved', false)
            ->assertJsonPath('provider_actions_performed', false)
            ->assertJsonMissingPath('data.0.provider_reference')
            ->assertJsonMissingPath('data.0.charge_reference');
        $this->getJson($path.'?window_hours=169')->assertUnprocessable();
        $this->getJson($path.'?window_hours=-1')->assertUnprocessable();
        $this->getJson($path.'?window_hours=72&per_page=26')->assertUnprocessable();
        foreach (['page=0', 'page=-1', 'page=10001', 'page=not-a-number'] as $filter) {
            $this->getJson($path.'?window_hours=72&'.$filter)
                ->assertUnprocessable();
        }
        $this->getJson($path.'?window_hours=72&per_page=1&page=10000')
            ->assertOk()->assertJsonPath('total', 1)
            ->assertJsonCount(0, 'data');
        $this->assertDatabaseCount('finance_refunds', 0);
        $this->assertDatabaseCount('external_dispute_events', 1);
        $this->assertDatabaseCount('external_dispute_evidence', 0);

        $this->travel(4)->days();
        $this->getJson($path.'?window_hours=0')->assertOk()
            ->assertJsonPath('data.0.urgency', 'deadline_passed')
            ->assertJsonPath('data.0.deadline_passed', true)
            ->assertJsonPath('data.0.provider_observation_stale', true);
        $this->assertDatabaseCount('finance_refunds', 0);
        $this->assertDatabaseCount('external_dispute_events', 1);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    public function test_deadline_queue_prioritizes_actionable_dates_before_null_deadlines_on_all_drivers(): void
    {
        $due = $this->providerEvent('evt_DueFirst')->assertOk()->json('dispute_id');
        $this->body['id'] = 'dp_LostWithoutDeadline';
        $this->body['status'] = 'lost';
        $this->body['evidence_details']['due_by'] = null;
        $lost = $this->providerEvent('evt_LostSecond')->assertOk()->json('dispute_id');

        $page = $this->actingAs($this->admin)->getJson(
            '/dispute-test/commercial/external-disputes/deadline-queue?window_hours=72&per_page=1'
        )->assertOk()->assertJsonPath('total', 2)
            ->assertJsonPath('data.0.id', $due)
            ->assertJsonPath('data.0.urgency', 'evidence_due_soon')
            ->assertJsonPath('current_page', 1)->json();
        $second = $this->getJson(
            '/dispute-test/commercial/external-disputes/deadline-queue?window_hours=72&per_page=1&page=2'
        )->assertOk()->assertJsonPath('data.0.id', $lost)
            ->assertJsonPath('data.0.urgency', 'financial_resolution_required')
            ->assertJsonPath('current_page', 2)->json();
        $this->assertSame(2, $second['total']);
        $this->assertSame(2, $page['total']);
        $this->assertDatabaseCount('finance_refunds', 0);
        $this->assertDatabaseCount('finance_payments', 1);
    }

    public function test_lost_provider_dispute_remains_held_in_admin_deadline_queue_without_refund(): void
    {
        $id = $this->providerEvent()->assertOk()->json('dispute_id');
        $this->body['status'] = 'lost';
        $this->actingAs($this->admin)->postJson(
            '/dispute-test/commercial/external-disputes/'.$id.'/reconcile'
        )->assertOk()->assertJsonPath('dispute.requires_financial_resolution', true);

        $this->getJson('/dispute-test/commercial/external-disputes/deadline-queue?window_hours=0')
            ->assertOk()
            ->assertJsonPath('data.0.id', $id)
            ->assertJsonPath('data.0.urgency', 'financial_resolution_required')
            ->assertJsonPath('data.0.settlement_hold', true)
            ->assertJsonPath('data.0.requires_financial_resolution', true)
            ->assertJsonPath('provider_actions_performed', false);
        $this->assertDatabaseCount('finance_refunds', 0);
        $this->assertDatabaseCount('finance_payments', 1);
    }

    public function test_native_provider_receipts_reject_admin_personal_access_tokens(): void
    {
        $this->providerEvent()->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($this->vendor->createToken('vendor-dispute-reader')->plainTextToken)->getJson('/api/v1/commercial/external-disputes')->assertOk()->assertJsonPath('total', 1);
        $this->app['auth']->forgetGuards();
        $this->withToken($this->admin->createToken('forbidden-admin-dispute-native')->plainTextToken)->getJson('/api/v1/commercial/external-disputes')->assertForbidden();
    }
}
