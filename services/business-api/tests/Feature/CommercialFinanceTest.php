<?php

namespace Tests\Feature;

use App\Models\ChargingSession;
use App\Models\Reservation;
use App\Models\Station;
use App\Models\User;
use App\Services\Finance\FinanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $this->assertDatabaseCount('finance_refunds',0);
    }
}
