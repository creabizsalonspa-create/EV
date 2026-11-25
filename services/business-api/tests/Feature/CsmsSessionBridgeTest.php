<?php

namespace Tests\Feature;

use App\Models\ChargingSession;
use App\Models\Finance\Invoice;
use App\Models\Finance\TariffVersion;
use App\Models\Reservation;
use App\Models\Station;
use App\Models\User;
use App\Models\VendorOps\VendorCharger;
use App\Models\VendorOps\VendorCompany;
use App\Models\VendorOps\VendorConnector;
use App\Models\VendorOps\VendorSite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class CsmsSessionBridgeTest extends TestCase
{
    use RefreshDatabase;

    private string $protocol = '1.6';

    private const KEY = 'fixture-local-event-signing-key-at-least-32';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfSecond());
        config(['csms.session_bridge_enabled' => true, 'csms.event_secret' => self::KEY]);
    }

    private function fixture(string $protocol = '1.6'): array
    {
        $this->protocol = $protocol;
        $v = User::factory()->create(['role' => 'vendor']);
        $u = User::factory()->create();
        $s = Station::create(['vendor_id' => $v->id, 'name' => 'Bound simulator', 'country' => 'AE', 'currency' => 'AED', 'timezone' => 'Asia/Dubai', 'latitude' => 25, 'longitude' => 55, 'source' => 'simulator', 'connector_type' => 'CCS2', 'power_kw' => 50]);
        DB::table('csms_bindings')->insert(['station_id' => $s->id, 'identifier' => 'ev-test-bridge', 'protocol' => $this->protocol, 'created_at' => now(), 'updated_at' => now()]);
        $r = Reservation::create(['user_id' => $u->id, 'station_id' => $s->id, 'starts_at' => now()->subMinutes(5), 'idempotency_key' => 'fixture']);

        return [$v, $u, $s, $r];
    }

    private function event(int $id, string $type, int $seq, int $meter, array $extra = []): array
    {
        return array_replace(['event_id' => 'evt-'.$id.'-'.$seq, 'session_id' => $id, 'identifier' => 'ev-test-bridge', 'protocol' => $this->protocol, 'transaction_id' => '123', 'type' => $type, 'sequence' => $seq, 'meter_wh' => $meter, 'occurred_at' => now()->addSeconds($seq)->toIso8601String()], $extra);
    }

    private function send(array $event, ?string $timestamp = null, ?string $key = null)
    {
        $raw = json_encode($event, JSON_THROW_ON_ERROR);
        $ts = $timestamp ?? (string) now()->timestamp;

        return $this->call('POST', '/api/internal/csms/events', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_EV_TIMESTAMP' => $ts, 'HTTP_X_EV_SIGNATURE' => hash_hmac('sha256', $ts.'.'.$raw, $key ?? self::KEY)], $raw);
    }

    public function test_source_event_requires_explicit_absolute_time_and_preserves_existing_clock_tolerance(): void
    {
        [$vendor, $customer, $station, $reservation] = $this->fixture();
        $this->actingAs($customer)->postJson('/api/reservations/'.$reservation->id.'/csms-start')->assertAccepted();
        $session = ChargingSession::firstOrFail();
        $event = $this->event($session->id, 'started', 0, 1000);
        foreach ([now()->format('Y-m-d H:i:s'), now()->format('Y-m-d\TH:i:s'), now()->addMinutes(6)->toIso8601String()] as $invalid) {
            $this->send(array_replace($event, ['occurred_at' => $invalid]))->assertStatus(422);
            $this->assertDatabaseCount('csms_inbox_events', 0);
            $this->assertSame('pending_start', $session->fresh()->status);
            $this->assertDatabaseCount('finance_invoices', 0);
        }
        $this->send(array_replace($event, ['occurred_at' => now()->addMinute()->toIso8601String()]))->assertOk();
        $this->assertSame('charging', $session->fresh()->status);
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    #[TestWith(['1.6'])]
    #[TestWith(['2.0.1'])]
    public function test_correlated_source_meters_finalize_one_invoice_and_replay_cannot_double_bill(string $protocol): void
    {
        [$v,$u,$station,$r] = $this->fixture($protocol);
        TariffVersion::create(['station_id' => $station->id, 'created_by' => $v->id, 'rate_minor_per_kwh' => 125, 'tax_bps' => 500, 'commission_bps' => 0, 'currency' => 'AED', 'minor_units' => 2, 'effective_from' => now()->subMinute()]);
        $this->actingAs($u)->postJson('/api/reservations/'.$r->id.'/csms-start')->assertAccepted()->assertJsonPath('session.status', 'pending_start');
        $s = ChargingSession::first();
        $this->getJson('/api/reservations')->assertOk()->assertJsonPath('data.0.csms_bound', true);
        $this->getJson('/api/simulations')->assertOk()->assertJsonPath('data.0.csms_linked', true)->assertJsonPath('data.0.evidence', 'SIMULATED_CSMS')->assertJsonPath('data.0.start_command_status', 'pending');
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
        $proofPath = '/api/finance/invoices/'.$invoice->id.'/source-evidence';
        $this->getJson($proofPath)->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('kind', 'OCPP_SIMULATOR')->assertJsonPath('source_verified', true)->assertJsonPath('protocol', 'OCPP '.$protocol)->assertJsonPath('energy_wh', 2500)->assertJsonPath('physical_charging_verified', false)->assertJsonCount(2, 'proofs');
        $this->actingAs($v)->getJson($proofPath)->assertOk();
        $this->actingAs(User::factory()->create(['role' => 'admin']))->getJson($proofPath)->assertOk();
        $this->actingAs(User::factory()->create())->getJson($proofPath)->assertNotFound();
        $this->actingAs(User::factory()->create(['role' => 'vendor']))->getJson($proofPath)->assertNotFound();
        $endReceipt = DB::table('csms_inbox_events')->where('type', 'ended')->first();
        DB::table('csms_inbox_events')->where('id', $endReceipt->id)->update(['fingerprint' => 'malformed-receipt']);
        $this->actingAs($u)->getJson($proofPath)->assertOk()->assertJsonPath('source_verified', false)->assertJsonPath('status', 'requires_review')->assertJsonCount(0, 'proofs');
        DB::table('csms_inbox_events')->where('id', $endReceipt->id)->update(['fingerprint' => $endReceipt->fingerprint]);
        DB::table('csms_inbox_events')->where('type', 'ended')->update(['outcome' => 'stale']);
        $this->actingAs($u)->getJson($proofPath)->assertOk()->assertJsonPath('source_verified', false)->assertJsonPath('status', 'requires_review')->assertJsonCount(0, 'proofs');
        $this->assertDatabaseCount('finance_invoices', 1);
        $this->assertDatabaseCount('finance_payments', 0);

    }

    public function test_signed_ocpp16_events_reject_transaction_identity_aliases_before_state_or_billing(): void
    {
        [, $customer, , $reservation] = $this->fixture('1.6');
        $this->actingAs($customer)->postJson('/api/reservations/'.$reservation->id.'/csms-start')
            ->assertAccepted();
        $session = ChargingSession::firstOrFail();
        $base = $this->event($session->id, 'started', 0, 1000);
        foreach (['00123', '+123', '2147483648', '-1', '1e2'] as $alias) {
            $this->send(array_replace($base, ['transaction_id' => $alias]))
                ->assertUnprocessable();
            $this->assertSame('pending_start', $session->fresh()->status);
            $this->assertDatabaseCount('csms_inbox_events', 0);
            $this->assertDatabaseCount('finance_invoices', 0);
        }
        $this->send($base)->assertOk()->assertJsonPath('status', 'charging');
        $this->assertDatabaseHas('csms_session_links', [
            'charging_session_id' => $session->id, 'transaction_id' => '123',
        ]);
        $this->assertDatabaseCount('csms_inbox_events', 1);
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    public function test_signature_replay_window_binding_and_transaction_conflicts_are_rejected(): void
    {
        [$v,$u,$s,$r] = $this->fixture();
        $this->actingAs($u)->postJson('/api/reservations/'.$r->id.'/csms-start')->assertAccepted();
        $id = ChargingSession::first()->id;
        $started = $this->event($id, 'started', 0, 1000);
        $this->send($started, key: 'wrong-key')->assertUnauthorized();
        $this->send($started, timestamp: (string) (now()->timestamp - 301))->assertUnauthorized();
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

    #[TestWith(['1.6'])]
    #[TestWith(['2.0.1'])]
    public function test_signed_inbox_refuses_rebound_station_identity_and_source_before_meter_reconciliation(string $protocol): void
    {
        [, $customer, $station, $reservation] = $this->fixture($protocol);
        $this->actingAs($customer)->postJson('/api/reservations/'.$reservation->id.'/csms-start')->assertAccepted();
        $session = ChargingSession::firstOrFail();
        $started = $this->event($session->id, 'started', 0, 1000);
        $original = DB::table('csms_bindings')->where('station_id', $station->id)->first();

        DB::table('csms_bindings')->where('id', $original->id)
            ->update(['identifier' => 'ev-test-repointed-identity']);
        $this->send($started)->assertConflict();
        $this->assertDatabaseCount('csms_inbox_events', 0);
        $this->assertSame('pending_start', $session->fresh()->status);
        $this->assertDatabaseCount('finance_invoices', 0);

        DB::table('csms_bindings')->where('id', $original->id)
            ->update(['identifier' => $original->identifier]);
        $this->send($started)->assertOk()->assertJsonPath('outcome', 'applied');
        $this->assertSame('charging', $session->fresh()->status);

        $meter = $this->event($session->id, 'meter', 1, 2000);
        $station->update(['source' => 'partner']);
        $this->send($meter)->assertConflict();
        $this->assertDatabaseCount('csms_inbox_events', 1);
        $this->assertSame(0, (int) $session->fresh()->energy_wh);
        $this->assertDatabaseCount('finance_invoices', 0);

        $station->update(['source' => 'simulator']);
        $this->send($meter)->assertOk()->assertJsonPath('outcome', 'applied');
        $this->assertSame(1000, (int) $session->fresh()->energy_wh);
        $this->assertDatabaseCount('charger_commands', 1);
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    #[TestWith(['1.6'])]
    #[TestWith(['2.0.1'])]
    public function test_signed_meter_receipt_rejects_canonical_charger_owner_transfer_and_recovers_with_original_owner(string $protocol): void
    {
        [$owner, $customer, $station, $reservation] = $this->fixture($protocol);
        $company = VendorCompany::create([
            'owner_id' => $owner->id, 'legal_name' => 'CSMS source event owner',
            'country' => 'AE', 'trade_license_number' => 'SIMULATOR-ONLY',
            'license_expires_on' => now()->addYear()->toDateString(), 'status' => 'approved',
        ]);
        $site = VendorSite::create([
            'company_id' => $company->id, 'station_id' => $station->id,
            'name' => 'CSMS event site', 'country' => 'AE',
            'timezone' => 'Asia/Dubai', 'address' => 'Synthetic',
            'latitude' => 25, 'longitude' => 55,
        ]);
        $charger = VendorCharger::create([
            'company_id' => $company->id, 'site_id' => $site->id,
            'asset_reference' => 'EVENT-OWNER', 'manufacturer' => 'Synthetic',
            'model' => 'CSMS receipt fixture', 'source' => 'simulator',
            'ocpp_protocol' => $protocol,
        ]);
        // The legacy simulator start gate requires exactly one operated
        // physical inventory connector on the newly claimed site.
        VendorConnector::create([
            'charger_id' => $charger->id, 'connector_number' => 1,
            'connector_type' => 'CCS2', 'max_power_kw' => 50,
        ]);
        DB::table('csms_bindings')->where('station_id', $station->id)
            ->update(['vendor_charger_id' => $charger->id]);
        $this->actingAs($customer)->postJson('/api/reservations/'.$reservation->id.'/csms-start')->assertAccepted();
        $session = ChargingSession::firstOrFail();
        $this->send($this->event($session->id, 'started', 0, 1000))->assertOk();
        $event = $this->event($session->id, 'meter', 1, 2000);
        $newOwner = User::factory()->create(['role' => 'vendor']);
        $company->update(['owner_id' => $newOwner->id]);
        $this->send($event)->assertConflict();
        $this->assertDatabaseCount('csms_inbox_events', 1);
        $this->assertSame(0, (int) $session->fresh()->energy_wh);
        $this->assertDatabaseCount('finance_invoices', 0);

        $company->update(['owner_id' => $owner->id]);
        $this->send($event)->assertOk()->assertJsonPath('outcome', 'applied');
        $this->assertSame(1000, (int) $session->fresh()->energy_wh);
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    #[TestWith(['1.6', 'expire'])]
    #[TestWith(['2.0.1', 'expire'])]
    #[TestWith(['1.6', 'rotate'])]
    #[TestWith(['2.0.1', 'rotate'])]
    public function test_signed_terminal_receipt_rechecks_authority_after_session_lock(string $protocol, string $change): void
    {
        [, $customer, , $reservation] = $this->fixture($protocol);
        $this->actingAs($customer)->postJson('/api/reservations/'.$reservation->id.'/csms-start')->assertAccepted();
        $session = ChargingSession::firstOrFail();
        $this->send($this->event($session->id, 'started', 0, 1000))->assertOk();
        $end = $this->event($session->id, 'ended', 1, 3500);
        $mutated = false;
        $sessionReads = 0;
        DB::listen(function ($query) use (&$mutated, &$sessionReads, $change) {
            // The third charging_sessions SELECT is the final session lock:
            // hint, link-correlated read, then the write-boundary lock.
            if (! $mutated && str_contains(strtolower($query->sql), 'select')
                && str_contains($query->sql, 'charging_sessions')
                && ++$sessionReads === 3) {
                $mutated = true;
                if ($change === 'expire') {
                    $this->travel(301)->seconds();
                } else {
                    config(['csms.event_secret' => str_repeat('r', 64)]);
                }
            }
        });
        try {
            $this->send($end)->assertUnauthorized();
            $this->assertTrue($mutated, 'The simulated lock-boundary change must occur.');
            $this->assertDatabaseCount('csms_inbox_events', 1);
            $this->assertDatabaseCount('finance_invoices', 0);
            $this->assertSame('charging', $session->fresh()->status);
            $this->assertSame(0, (int) $session->fresh()->energy_wh);

            // The unchanged receipt can be signed afresh by current authority.
            $key = $change === 'rotate' ? str_repeat('r', 64) : self::KEY;
            $this->send($end, key: $key)->assertOk()->assertJsonPath('outcome', 'applied');
            $this->send($end, key: $key)->assertOk()->assertJsonPath('outcome', 'duplicate');
            $this->assertDatabaseCount('finance_invoices', 1);
            $this->assertDatabaseCount('csms_inbox_events', 2);
            $this->assertSame(2500, (int) $session->fresh()->energy_wh);
            $this->assertSame('stopped', $session->fresh()->status);
        } finally {
            config(['csms.event_secret' => self::KEY]);
            $this->travelBack();
        }
    }

    public function test_disabled_or_production_bridge_never_accepts_events(): void
    {
        config(['csms.session_bridge_enabled' => false]);
        $this->send(['event_id' => 'none'])->assertStatus(503);
    }
}
