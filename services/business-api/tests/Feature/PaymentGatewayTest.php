<?php

namespace Tests\Feature;

use App\Http\Controllers\PaymentGatewayController;
use App\Models\ChargingSession;
use App\Models\Reservation;
use App\Models\Station;
use App\Models\User;
use App\Services\Finance\FinanceService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class PaymentGatewayTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Route::middleware(['web', 'auth'])->prefix('gateway-test')->group(base_path('routes/payments.php'));
        Route::post('/gateway-webhook-test', [PaymentGatewayController::class, 'webhook']);
        config(['payments.provider' => 'mock', 'payments.webhook_secret' => str_repeat('a', 48)]);
    }

    private function fixture(): array
    {
        $vendor = User::factory()->create(['role' => 'vendor']);
        $user = User::factory()->create();
        $admin = User::factory()->create(['role' => 'admin']);
        $station = Station::create(['vendor_id' => $vendor->id, 'name' => 'Payment simulator', 'country' => 'AE', 'currency' => 'AED', 'timezone' => 'Asia/Dubai', 'latitude' => 25, 'longitude' => 55, 'source' => 'simulator', 'connector_type' => 'CCS2', 'power_kw' => 50]);
        $reservation = Reservation::create(['user_id' => $user->id, 'station_id' => $station->id, 'starts_at' => now(), 'status' => 'confirmed', 'idempotency_key' => 'payment-fixture']);
        $session = ChargingSession::create(['reservation_id' => $reservation->id, 'status' => 'stopped', 'energy_wh' => 10000, 'rate_minor_per_kwh' => 100, 'currency' => 'AED', 'total_minor' => 1000, 'stopped_at' => now()]);
        $invoice = app(FinanceService::class)->issue($session, $user->id);

        return [$user, $vendor, $admin, $invoice];
    }

    private function checkout($invoice, int $amount = 1000, string $key = 'checkout'): int
    {
        return $this->postJson('/gateway-test/commercial/checkouts', ['invoice_id' => $invoice->id, 'amount_minor' => $amount, 'idempotency_key' => $key])->assertCreated()->assertJsonPath('evidence', 'MOCK_PROVIDER')->assertJsonMissingPath('fingerprint')->json('id');
    }

    private function op(int $id, string $kind, array $body = [])
    {
        return $this->postJson('/gateway-test/commercial/checkouts/'.$id.'/'.$kind, $body + ['idempotency_key' => $kind]);
    }

    public function test_checkout_authorize_capture_replay_refund_reconcile_and_exact_ledger(): void
    {
        [$user, $vendor, $admin, $invoice] = $this->fixture();
        $this->actingAs($user);
        $id = $this->checkout($invoice);
        $this->assertSame($id, $this->checkout($invoice));
        $this->op($id, 'capture')->assertConflict();
        $this->op($id, 'authorize', ['token' => 'mock_success'])->assertOk()->assertJsonPath('status', 'authorized');
        $this->op($id, 'authorize', ['token' => 'mock_success'])->assertOk();
        $this->op($id, 'authorize', ['token' => 'mock_decline'])->assertConflict();
        $this->op($id, 'capture')->assertOk()->assertJsonPath('status', 'captured');
        $this->op($id, 'capture')->assertOk();
        $this->assertDatabaseCount('finance_payments', 1);
        $this->op($id, 'refund', ['amount_minor' => 400, 'reason' => 'Partial mock refund'])->assertNotFound();
        $this->actingAs($vendor);
        $this->op($id, 'refund', ['amount_minor' => 400, 'reason' => 'Partial mock refund'])->assertOk();
        $this->op($id, 'refund', ['amount_minor' => 400, 'reason' => 'Partial mock refund'])->assertOk();
        $this->op($id, 'refund', ['amount_minor' => 601, 'reason' => 'Too much', 'idempotency_key' => 'over'])->assertConflict();
        $this->getJson('/gateway-test/commercial/reconciliation/'.$invoice->id)->assertOk()->assertJsonPath('matched', true)->assertJsonPath('ledger.net_collected_minor', 600)->assertJsonPath('ledger.ledger_balanced', true)->assertJsonPath('external_provider_verified', false);
        $this->assertDatabaseCount('finance_refunds', 1);
        $this->assertDatabaseCount('gateway_operations', 3);
    }

    public function test_failed_authorizations_expiry_cancel_and_competing_checkout_balance(): void
    {
        [$user, , , $invoice] = $this->fixture();
        $this->actingAs($user);
        $declined = $this->checkout($invoice, 1000, 'declined');
        $this->op($declined, 'authorize', ['token' => 'mock_decline'])->assertOk()->assertJsonPath('status', 'failed')->assertJsonPath('failure_code', 'mock_declined');
        $this->op($declined, 'capture')->assertConflict();
        $expired = $this->checkout($invoice, 1000, 'expired');
        $this->op($expired, 'authorize', ['token' => 'mock_success'])->assertOk();
        $this->travel(31)->minutes();
        $this->op($expired, 'capture')->assertConflict();
        $this->op($expired, 'cancel')->assertOk()->assertJsonPath('status', 'cancelled');
        $this->travelBack();
        $one = $this->checkout($invoice, 1000, 'one');
        $two = $this->checkout($invoice, 1000, 'two');
        foreach ([$one, $two] as $id) {
            $this->op($id, 'authorize', ['token' => 'mock_success'])->assertOk();
        }
        $this->op($one, 'capture')->assertOk();
        $this->op($two, 'capture')->assertConflict();
        $this->assertDatabaseCount('finance_payments', 1);
    }

    private function webhook(array $event, bool $valid = true, ?int $time = null)
    {
        $raw = json_encode($event, JSON_THROW_ON_ERROR);
        $timestamp = (string) ($time ?? time());
        $signature = hash_hmac('sha256', $timestamp.'.'.$raw, $valid ? str_repeat('a', 48) : 'invalid');

        return $this->call('POST', '/gateway-webhook-test', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_PAYMENT_TIMESTAMP' => $timestamp, 'HTTP_X_PAYMENT_SIGNATURE' => $signature], $raw);
    }

    public function test_signed_webhooks_exact_replay_conflicts_and_invalid_signatures(): void
    {
        [$user, , , $invoice] = $this->fixture();
        $this->actingAs($user);
        $id = $this->checkout($invoice);
        $this->op($id, 'authorize', ['token' => 'mock_success'])->assertOk();
        $event = ['event_id' => 'provider-1', 'checkout_id' => $id, 'type' => 'captured', 'amount_minor' => 1000, 'currency' => 'AED'];
        $this->webhook($event, false)->assertUnauthorized();
        $this->webhook($event, true, time() - 301)->assertUnauthorized();
        $this->webhook(array_replace($event, ['currency' => 'USD']))->assertConflict();
        $this->webhook($event)->assertOk()->assertJsonPath('duplicate', false);
        $this->webhook($event)->assertOk()->assertJsonPath('duplicate', true);
        $this->webhook(array_replace($event, ['amount_minor' => 999]))->assertConflict();
        $this->webhook(array_replace($event, ['event_id' => 'provider-2', 'type' => 'refunded', 'amount_minor' => 1000]))->assertOk();
        $this->assertDatabaseCount('finance_payments', 1);
        $this->assertDatabaseCount('finance_refunds', 1);
        $this->assertDatabaseCount('gateway_webhook_events', 2);
    }

    public function test_customer_cases_admin_approval_credit_notes_replays_and_tenant_isolation(): void
    {
        [$user, $vendor, $admin, $invoice] = $this->fixture();
        $this->actingAs($user);
        $id = $this->checkout($invoice);
        $this->op($id, 'authorize', ['token' => 'mock_success'])->assertOk();
        $this->op($id, 'capture')->assertOk();
        $body = ['invoice_id' => $invoice->id, 'kind' => 'dispute', 'amount_minor' => 500, 'reason' => 'Mock issue', 'idempotency_key' => 'case'];
        $case = $this->postJson('/gateway-test/commercial/cases', $body)->assertCreated()->json('id');
        $this->postJson('/gateway-test/commercial/cases', $body)->assertCreated()->assertJsonPath('id', $case);
        $this->actingAs($vendor)->postJson('/gateway-test/commercial/cases/'.$case.'/resolve', ['outcome' => 'approved', 'resolution' => 'Reviewed mock refund'])->assertForbidden();
        $this->actingAs(User::factory()->create());
        $this->getJson('/gateway-test/commercial/checkouts')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/gateway-test/commercial/cases')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson('/gateway-test/commercial/cases', $body)->assertNotFound();
        $this->op($id, 'capture')->assertNotFound();
        $this->actingAs($admin);
        $resolution = ['outcome' => 'approved', 'resolution' => 'Reviewed mock refund'];
        $this->postJson('/gateway-test/commercial/cases/'.$case.'/resolve', $resolution)->assertOk()->assertJsonPath('status', 'approved');
        $this->postJson('/gateway-test/commercial/cases/'.$case.'/resolve', $resolution)->assertOk();
        $this->postJson('/gateway-test/commercial/cases/'.$case.'/resolve', ['outcome' => 'rejected', 'resolution' => 'Changed'])->assertConflict();
        $this->assertDatabaseCount('finance_refunds', 1);
        $this->assertDatabaseHas('finance_refunds', ['amount_minor' => 500]);
    }

    public function test_production_and_unconfigured_provider_fail_closed_and_raw_card_fields_are_not_accepted(): void
    {
        [$user, , , $invoice] = $this->fixture();
        $this->actingAs($user);
        $id = $this->checkout($invoice);
        $this->op($id, 'authorize', ['token' => '4111111111111111'])->assertUnprocessable();
        $this->op($id, 'authorize', ['token' => 'mock_success', 'card_number' => '4111111111111111'])->assertUnprocessable();
        config(['payments.provider' => 'unknown']);
        $this->postJson('/gateway-test/commercial/checkouts', ['invoice_id' => $invoice->id, 'amount_minor' => 100, 'idempotency_key' => 'bad'])->assertStatus(503);
        config(['payments.provider' => 'mock']);
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->app->detectEnvironment(fn () => 'production');
        $this->op($id, 'authorize', ['token' => 'mock_success'])->assertStatus(503);
        $this->assertDatabaseCount('finance_payments', 0);
    }
}
