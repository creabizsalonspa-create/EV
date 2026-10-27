<?php

namespace Tests\Feature;

use App\Models\ChargingSession;
use App\Models\Finance\Invoice;
use App\Models\Finance\TariffVersion;
use App\Models\Reservation;
use App\Models\Station;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CsmsSessionBridgeTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'fixture-local-event-signing-key-at-least-32';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfSecond());
        config(['csms.session_bridge_enabled' => true, 'csms.event_secret' => self::KEY]);
    }

    private function fixture(): array
    {
        $v = User::factory()->create(['role' => 'vendor']);
        $u = User::factory()->create();
        $s = Station::create(['vendor_id' => $v->id, 'name' => 'Bound simulator', 'country' => 'AE', 'currency' => 'AED', 'timezone' => 'Asia/Dubai', 'latitude' => 25, 'longitude' => 55, 'source' => 'simulator', 'connector_type' => 'CCS2', 'power_kw' => 50]);
        DB::table('csms_bindings')->insert(['station_id' => $s->id, 'identifier' => 'ev-test-bridge', 'protocol' => '1.6', 'created_at' => now(), 'updated_at' => now()]);
        $r = Reservation::create(['user_id' => $u->id, 'station_id' => $s->id, 'starts_at' => now()->subMinutes(5), 'idempotency_key' => 'fixture']);

        return [$v, $u, $s, $r];
    }

    private function event(int $id, string $type, int $seq, int $meter, array $extra = []): array
    {
        return array_replace(['event_id' => 'evt-'.$id.'-'.$seq, 'session_id' => $id, 'identifier' => 'ev-test-bridge', 'protocol' => '1.6', 'transaction_id' => '123', 'type' => $type, 'sequence' => $seq, 'meter_wh' => $meter, 'occurred_at' => now()->addSeconds($seq)->toIso8601String()], $extra);
    }

    private function send(array $event, ?string $timestamp = null, ?string $key = null)
    {
        $raw = json_encode($event, JSON_THROW_ON_ERROR);
        $ts = $timestamp ?? (string) time();

        return $this->call('POST', '/api/internal/csms/events', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_EV_TIMESTAMP' => $ts, 'HTTP_X_EV_SIGNATURE' => hash_hmac('sha256', $ts.'.'.$raw, $key ?? self::KEY)], $raw);
    }

    public function test_correlated_source_meters_finalize_one_invoice_and_replay_cannot_double_bill(): void
    {
        [$v,$u,$station,$r] = $this->fixture();
        TariffVersion::create(['station_id' => $station->id, 'created_by' => $v->id, 'rate_minor_per_kwh' => 125, 'tax_bps' => 500, 'commission_bps' => 0, 'currency' => 'AED', 'minor_units' => 2, 'effective_from' => now()->subMinute()]);
        $this->actingAs($u)->postJson('/api/reservations/'.$r->id.'/csms-start')->assertAccepted()->assertJsonPath('session.status', 'pending_start');
        $s = ChargingSession::first();
        $this->postJson('/api/reservations/'.$r->id.'/csms-start')->assertAccepted();
        $this->assertDatabaseCount('charger_commands', 1);
        $this->send($this->event($s->id, 'started', 0, 1000))->assertOk()->assertJsonPath('status', 'charging');
        $this->send($this->event($s->id, 'meter', 1, 2500))->assertOk()->assertJsonPath('energy_wh', 1500);
        $this->send($this->event($s->id, 'meter', 1, 2500))->assertOk()->assertJsonPath('outcome', 'duplicate');
        $this->postJson('/api/charging-sessions/'.$s->id.'/csms-stop')->assertAccepted()->assertJsonPath('session.status', 'stopping');
        $this->postJson('/api/charging-sessions/'.$s->id.'/csms-stop')->assertAccepted();
        $this->assertDatabaseCount('charger_commands', 2);
        $this->assertDatabaseCount('finance_invoices', 0);
        $end = $this->event($s->id, 'ended', 2, 3500);
        $this->send($end)->assertOk()->assertJsonPath('energy_wh', 2500);
        $this->send($end)->assertOk()->assertJsonPath('outcome', 'duplicate');
        $this->assertDatabaseCount('finance_invoices', 1);
        $invoice = Invoice::first();
        $this->assertSame(313, $invoice->subtotal_minor);
        $this->assertSame(16, $invoice->tax_minor);
        $this->assertSame(329, $invoice->total_minor);
        $this->assertSame('stopped', $s->fresh()->status);
        $this->assertSame(313, $s->fresh()->total_minor);
        $this->send($this->event($s->id, 'meter', 3, 4000))->assertConflict();
        $this->assertSame(2500, $s->fresh()->energy_wh);
    }

    public function test_signature_replay_window_binding_and_transaction_conflicts_are_rejected(): void
    {
        [$v,$u,$s,$r] = $this->fixture();
        $this->actingAs($u)->postJson('/api/reservations/'.$r->id.'/csms-start')->assertAccepted();
        $id = ChargingSession::first()->id;
        $started = $this->event($id, 'started', 0, 1000);
        $this->send($started, key: 'wrong-key')->assertUnauthorized();
        $this->send($started, timestamp: (string) (time() - 301))->assertUnauthorized();
        $this->send($started + [], key: self::KEY)->assertOk();
        $this->send($this->event($id, 'meter', 1, 2000, ['identifier' => 'ev-test-different']))->assertConflict();
        $this->send($this->event($id, 'meter', 1, 2000, ['transaction_id' => '456']))->assertConflict();
        $this->send($this->event($id, 'meter', 1, 500))->assertConflict();
        $this->send($this->event($id, 'meter', 1, 2000))->assertOk();
        $this->send($this->event($id, 'meter', 1, 2500))->assertConflict();
        $this->assertDatabaseCount('csms_inbox_events', 2);
    }

    public function test_only_owner_can_start_and_legacy_mutation_cannot_override_csms_energy(): void
    {
        [$v,$u,$s,$r] = $this->fixture();
        $other = User::factory()->create();
        $this->actingAs($other)->postJson('/api/reservations/'.$r->id.'/csms-start')->assertNotFound();
        $this->actingAs($u)->postJson('/api/reservations/'.$r->id.'/csms-start')->assertAccepted();
        $id = ChargingSession::first()->id;
        $this->send($this->event($id, 'started', 0, 1000))->assertOk();
        $this->actingAs($v)->putJson('/api/simulations/'.$id.'/meter', ['energy_wh' => 999])->assertConflict();
        $this->actingAs($u)->postJson('/api/simulations/'.$id.'/stop')->assertConflict();
        $this->actingAs($other)->postJson('/api/charging-sessions/'.$id.'/csms-stop')->assertNotFound();
        $this->postJson('/api/simulations/'.$id.'/stop')->assertNotFound();
        $this->putJson('/api/simulations/'.$id.'/meter', ['energy_wh' => 999])->assertNotFound();
        $this->assertSame(0, ChargingSession::first()->energy_wh);
    }

    public function test_missing_start_future_meter_stale_order_and_unknown_start_are_not_invoiced(): void
    {
        [$v,$u,$station,$r] = $this->fixture();
        $this->actingAs($u)->postJson('/api/reservations/'.$r->id.'/csms-start')->assertAccepted();
        $id = ChargingSession::first()->id;
        $this->postJson('/api/charging-sessions/'.$id.'/csms-stop')->assertConflict();
        $this->send($this->event($id, 'ended', 2, 3000))->assertConflict();
        $this->send($this->event($id, 'started', 0, 1000, ['occurred_at' => now()->addMinutes(10)->toIso8601String()]))->assertUnprocessable();
        $this->send($this->event($id, 'started', 0, 1000))->assertOk();
        $this->send($this->event($id, 'meter', 3, 3000))->assertOk();
        $this->send($this->event($id, 'meter', 1, 1500))->assertOk()->assertJsonPath('outcome', 'stale');
        $this->assertSame(2000, ChargingSession::first()->energy_wh);
        $this->assertDatabaseCount('finance_invoices', 0);
        $r->update(['starts_at' => now()->subHours(2)]);
        $new = Reservation::create(['station_id' => $station->id, 'user_id' => $u->id, 'starts_at' => now()->subMinutes(5), 'idempotency_key' => 'second']);
        $this->postJson('/api/reservations/'.$new->id.'/csms-start')->assertConflict();
    }

    public function test_disabled_or_production_bridge_never_accepts_events(): void
    {
        config(['csms.session_bridge_enabled' => false]);
        $this->send(['event_id' => 'none'])->assertStatus(503);
    }
}
