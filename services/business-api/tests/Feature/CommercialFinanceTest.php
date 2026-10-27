<?php

namespace Tests\Feature;

use App\Models\ChargingSession;
use App\Models\Reservation;
use App\Models\Station;
use App\Models\User;
use App\Services\Finance\FinanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CommercialFinanceTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $v = User::factory()->create(['role' => 'vendor']);
        $u = User::factory()->create();
        $s = Station::create(['vendor_id' => $v->id, 'name' => 'Refund fixture', 'country' => 'AE', 'currency' => 'AED', 'timezone' => 'Asia/Dubai', 'latitude' => 25, 'longitude' => 55, 'source' => 'simulator', 'connector_type' => 'CCS2', 'power_kw' => 50]);
        $r = Reservation::create(['user_id' => $u->id, 'station_id' => $s->id, 'starts_at' => now(), 'idempotency_key' => 'refund-fixture']);
        $session = ChargingSession::create(['reservation_id' => $r->id, 'status' => 'stopped', 'energy_wh' => 1000, 'rate_minor_per_kwh' => 100, 'currency' => 'AED', 'total_minor' => 100, 'stopped_at' => now()]);
        $f = app(FinanceService::class);
        $i = $f->issue($session, $u->id);
        $f->pay($i, $u->id, 100, 'paid');

        return [$u, $v, $i];
    }

    public function test_customer_request_vendor_review_credit_note_and_private_report(): void
    {
        [$u,$v,$i] = $this->fixture();
        $body = ['amount_minor' => 60, 'reason' => 'Service issue', 'idempotency_key' => 'refund-one'];
        $a = $this->actingAs($u)->postJson("/api/finance/invoices/$i->id/refund-requests", $body)->assertCreated()->json();
        $this->postJson("/api/finance/invoices/$i->id/refund-requests", $body)->assertOk()->assertJsonPath('id', $a['id']);
        $this->postJson("/api/finance/invoices/$i->id/refund-requests", array_replace($body, ['amount_minor' => 61]))->assertConflict();
        $this->postJson("/api/finance/invoices/$i->id/refund-requests", array_replace($body, ['idempotency_key' => 'another', 'amount_minor' => 41]))->assertConflict();
        $this->postJson('/api/finance/refund-requests/'.$a['id'].'/review', ['decision' => 'approve', 'note' => 'accepted'])->assertNotFound();
        $this->actingAs(User::factory()->create())->getJson('/api/finance/refund-requests')->assertJsonCount(0, 'data');
        $this->actingAs($v)->postJson('/api/finance/refund-requests/'.$a['id'].'/review', ['decision' => 'approve', 'note' => 'accepted'])->assertOk()->assertJsonPath('status', 'approved');
        $this->postJson('/api/finance/refund-requests/'.$a['id'].'/review', ['decision' => 'approve', 'note' => 'accepted'])->assertOk();
        $this->assertDatabaseCount('finance_refunds', 1);
        $this->getJson('/api/finance/report')->assertOk()->assertJsonPath('groups.0.refunded_minor', 60)->assertJsonPath('groups.0.net_collected_minor', 40);
        $this->actingAs($u)->getJson('/api/finance/report')->assertJsonMissingPath('groups.0.commission_minor')->assertJsonPath('groups.0.invoice_count', 1);
        $this->actingAs(User::factory()->create())->getJson('/api/finance/report')->assertJsonCount(0, 'groups');
    }

    public function test_rejection_releases_reserved_amount_and_validation(): void
    {
        [$u,$v,$i] = $this->fixture();
        $body = ['amount_minor' => 100, 'reason' => 'Issue', 'idempotency_key' => 'a'];
        $id = $this->actingAs($u)->postJson("/api/finance/invoices/$i->id/refund-requests", $body)->json('id');
        $this->actingAs($v)->postJson("/api/finance/refund-requests/$id/review", ['decision' => 'reject', 'note' => 'Not eligible'])->assertOk();
        $this->actingAs($u)->postJson("/api/finance/invoices/$i->id/refund-requests", array_replace($body, ['idempotency_key' => 'b']))->assertCreated();
        $this->getJson('/api/finance/report?currency=invalid')->assertUnprocessable();
        $this->assertDatabaseCount('finance_refunds', 0);
    }

    public function test_external_provider_invoice_cannot_use_ledger_only_simulation_after_config_switch(): void
    {
        [$u, $v, $i] = $this->fixture();
        DB::table('gateway_checkouts')->insert(['invoice_id' => $i->id, 'user_id' => $u->id, 'provider' => 'stripe_test', 'provider_reference' => 'pi_guard', 'request_key' => 'guard', 'fingerprint' => str_repeat('0', 64), 'currency' => 'AED', 'amount_minor' => 100, 'status' => 'captured', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($u)->postJson("/api/finance/invoices/$i->id/simulate-payment", ['amount_minor' => 1, 'idempotency_key' => 'guard'])->assertStatus(503);
        $id = $this->postJson("/api/finance/invoices/$i->id/refund-requests", ['amount_minor' => 10, 'reason' => 'Refund issue', 'idempotency_key' => 'guard'])->assertCreated()->json('id');
        $this->actingAs($v)->postJson("/api/finance/invoices/$i->id/simulate-refund", ['amount_minor' => 10, 'reason' => 'Issue', 'idempotency_key' => 'guard'])->assertStatus(503);
        $this->postJson("/api/finance/refund-requests/$id/review", ['decision' => 'approve', 'note' => 'Approved'])->assertStatus(503);
        $this->assertDatabaseCount('finance_refunds', 0);
    }

    public function test_native_and_cookie_clients_share_financial_records_and_report_filters(): void
    {
        [$customer,$vendor,$invoice] = $this->fixture();
        $token = $customer->createToken('finance-contract', ['*'])->plainTextToken;
        $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/v1/finance/report?to='.now()->toDateString())->assertOk()->assertJsonPath('groups.0.net_collected_minor', 100);
        $request = $this->postJson('/api/v1/finance/invoices/'.$invoice->id.'/refund-requests', ['amount_minor' => 10, 'reason' => 'Native contract', 'idempotency_key' => 'native'])->assertCreated()->json();
        $this->withHeader('Authorization', '')->actingAs($vendor)->getJson('/api/finance/refund-requests')->assertOk()->assertJsonPath('data.0.id', $request['id']);
        $this->getJson('/api/finance/report?from=2026-02-02&to=2026-01-01')->assertUnprocessable();
    }
}
