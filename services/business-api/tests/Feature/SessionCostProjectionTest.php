<?php

namespace Tests\Feature;

use App\Models\ChargerCommand;
use App\Models\Charging\SessionLink;
use App\Models\ChargingSession;
use App\Models\Finance\SessionQuote;
use App\Models\Finance\TariffVersion;
use App\Models\Reservation;
use App\Models\Station;
use App\Models\User;
use App\Services\Finance\FinanceService;
use App\Services\Finance\SessionCostProjection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SessionCostProjectionTest extends TestCase
{
    use RefreshDatabase;

    private function scenario(bool $quoted = true): array
    {
        $this->freezeTime();
        $vendor = User::factory()->create(['role' => 'vendor']);
        $customer = User::factory()->create();
        $station = Station::create(['vendor_id' => $vendor->id, 'name' => 'Meter cost fixture', 'country' => 'AE', 'currency' => 'KWD', 'timezone' => 'Asia/Dubai', 'latitude' => 25, 'longitude' => 55, 'source' => 'simulator', 'connector_type' => 'CCS2', 'power_kw' => 50]);
        $tariff = TariffVersion::create(['station_id' => $station->id, 'created_by' => $vendor->id, 'rate_minor_per_kwh' => 251, 'tax_bps' => 500, 'commission_bps' => 1000, 'currency' => 'KWD', 'minor_units' => 3, 'effective_from' => now()->subMinute()]);
        $reservation = Reservation::create(['user_id' => $customer->id, 'station_id' => $station->id, 'starts_at' => now(), 'idempotency_key' => 'cost-fixture']);
        $session = ChargingSession::create(['reservation_id' => $reservation->id, 'status' => 'charging', 'energy_wh' => 1500, 'rate_minor_per_kwh' => 251, 'currency' => 'KWD']);
        $quote = $quoted ? app(FinanceService::class)->snapshotSession($session, $tariff) : null;

        return [$vendor, $customer, $station, $reservation, $session, $quote];
    }

    public function test_customer_vendor_admin_read_the_same_scoped_exact_estimate_without_ledger_mutation(): void
    {
        [$vendor, $customer, , , , $quote] = $this->scenario();
        $projection = null;
        foreach ([$customer, $vendor, User::factory()->create(['role' => 'admin'])] as $actor) {
            $reply = $this->actingAs($actor)->getJson('/api/simulations')->assertOk()->assertHeader('Cache-Control', 'no-store, private')
                ->assertJsonPath('data.0.cost_projection.state', 'estimated')->assertJsonPath('data.0.cost_projection.total_minor', '396')
                ->assertJsonPath('data.0.cost_projection.tax_minor', '19')->assertJsonPath('data.0.cost_projection.minor_units', 3)
                ->assertJsonPath('data.0.cost_projection.meter_freshness_at_read', 'APPLICATION_SIMULATOR')
                ->assertJsonPath('data.0.cost_projection.real_payment_processed', false);
            $value = $reply->json('data.0.cost_projection');
            $projection === null ? $projection = $value : $this->assertSame($projection, $value);
            foreach (['commission_bps', 'price_fingerprint', 'auth_epoch', 'binding_id', 'remote_token_digest'] as $secret) {
                $this->assertArrayNotHasKey($secret, $value);
            }
        }
        $this->actingAs(User::factory()->create())->getJson('/api/simulations')->assertOk()->assertJsonCount(0, 'data');
        $this->assertDatabaseCount('finance_invoices', 0);
        $this->assertDatabaseCount('finance_journals', 0);
        $this->assertDatabaseCount('finance_payments', 0);
        $this->assertDatabaseCount('charger_commands', 0);
        $this->assertSame(251, $quote->fresh()->rate_minor_per_kwh);
    }

    public function test_meter_progress_uses_the_captured_tariff_then_matches_its_real_canonical_invoice(): void
    {
        [$vendor, $customer, $station, , $session] = $this->scenario();
        TariffVersion::create(['station_id' => $station->id, 'created_by' => $vendor->id, 'rate_minor_per_kwh' => 999, 'tax_bps' => 9999, 'commission_bps' => 1000, 'currency' => 'KWD', 'minor_units' => 3, 'effective_from' => now()]);
        $this->actingAs($vendor)->putJson('/api/simulations/'.$session->id.'/meter', ['energy_wh' => 2500])->assertOk();
        $this->actingAs($customer)->getJson('/api/simulations')->assertJsonPath('data.0.cost_projection.total_minor', '659')
            ->assertJsonPath('data.0.cost_projection.rate_minor_per_kwh', '251')->assertJsonPath('data.0.cost_projection.state', 'estimated');
        $this->postJson('/api/simulations/'.$session->id.'/stop')->assertOk();
        $this->getJson('/api/simulations')->assertJsonPath('data.0.cost_projection.state', 'estimated');
        $invoice = app(FinanceService::class)->issue($session->fresh(), $customer->id);
        $this->getJson('/api/simulations')->assertJsonPath('data.0.cost_projection.state', 'invoiced')
            ->assertJsonPath('data.0.cost_projection.invoice_id', $invoice->id)->assertJsonPath('data.0.cost_projection.total_minor', (string) $invoice->total_minor);
        $this->assertDatabaseCount('finance_journals', 1);
        $session->update(['energy_wh' => 2600]);
        $this->getJson('/api/simulations')->assertJsonPath('data.0.cost_projection.state', 'unavailable')->assertJsonMissingPath('data.0.cost_projection.total_minor');
        $this->assertSame(659, $invoice->fresh()->total_minor);
        $this->assertDatabaseCount('finance_journals', 1);
    }

    public function test_source_meter_counters_and_source_time_are_not_replaced_by_application_timestamps(): void
    {
        [, $customer, $station, , $session] = $this->scenario();
        $binding = DB::table('csms_bindings')->insertGetId(['station_id' => $station->id, 'identifier' => 'ev-test-cost', 'protocol' => '1.6', 'created_at' => now(), 'updated_at' => now()]);
        $command = ChargerCommand::create(['actor_id' => $customer->id, 'station_id' => $station->id, 'action' => 'start', 'payload' => ['session_id' => $session->id], 'idempotency_key' => 'cost-source', 'payload_hash' => str_repeat('a', 64)]);
        $link = SessionLink::create(['charging_session_id' => $session->id, 'binding_id' => $binding, 'start_command_id' => $command->id, 'protocol' => '1.6', 'identifier' => 'ev-test-cost']);
        $this->actingAs($customer)->getJson('/api/simulations')->assertJsonPath('data.0.cost_projection.state', 'awaiting_meter')->assertJsonMissingPath('data.0.cost_projection.total_minor');
        $link->update(['started_at' => now()->subMinutes(10), 'meter_start_wh' => 1000, 'last_meter_wh' => 2500, 'last_event_at' => now()->subSeconds(300)]);
        $this->getJson('/api/simulations')->assertJsonPath('data.0.cost_projection.state', 'estimated')->assertJsonPath('data.0.cost_projection.total_minor', '396')
            ->assertJsonPath('data.0.cost_projection.meter_freshness_at_read', 'STALE')->assertJsonPath('data.0.cost_projection.physical_charging_verified', false);
        $link->update(['last_event_at' => now()->subSeconds(299)]);
        $this->getJson('/api/simulations')->assertJsonPath('data.0.cost_projection.meter_freshness_at_read', 'RECENT');
        $link->update(['last_event_at' => now()->addSecond()]);
        $this->getJson('/api/simulations')->assertJsonPath('data.0.cost_projection.meter_freshness_at_read', 'UNKNOWN');
        $link->update(['last_meter_wh' => 2501]);
        $this->getJson('/api/simulations')->assertJsonPath('data.0.cost_projection.state', 'awaiting_meter')->assertJsonMissingPath('data.0.cost_projection.total_minor');
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    public function test_missing_foreign_or_unsupported_quotes_and_overflows_never_invent_a_price(): void
    {
        [, $customer, , , $session, $quote] = $this->scenario(false);
        $this->actingAs($customer)->getJson('/api/simulations')->assertJsonPath('data.0.cost_projection.state', 'unavailable');
        $q = new SessionQuote(['user_id' => $customer->id, 'currency' => 'KWD', 'minor_units' => 3, 'rate_minor_per_kwh' => 251, 'tax_bps' => 500, 'evidence' => 'SIMULATED']);
        $service = app(SessionCostProjection::class);
        foreach (['user_id' => $customer->id + 100, 'currency' => 'USD', 'minor_units' => 4, 'rate_minor_per_kwh' => -1, 'tax_bps' => 10001, 'evidence' => 'LIVE'] as $key => $value) {
            $other = clone $q;
            $other->setAttribute($key, $value);
            $this->assertSame('unavailable', $service->present($session, $other, null, null, $customer->id)['state']);
        }
        $session->energy_wh = PHP_INT_MAX;
        $this->assertSame('unavailable', $service->present($session, $q, null, null, $customer->id)['state']);
        $this->assertNull($quote);
        $this->assertDatabaseCount('finance_session_quotes', 0);
    }

    public function test_projection_reads_are_batched_for_a_full_page(): void
    {
        [, $customer, , $reservation, $session, $quote] = $this->scenario();
        foreach (range(1, 24) as $i) {
            $r = $reservation->replicate();
            $r->idempotency_key = 'cost-page-'.$i;
            $r->starts_at = now()->addDays($i);
            $r->save();
            $s = $session->replicate();
            $s->reservation_id = $r->id;
            $s->save();
            $q = $quote->replicate();
            $q->charging_session_id = $s->id;
            $q->save();
        }
        $rows = ChargingSession::all();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $result = app(SessionCostProjection::class)->forSessions($rows, collect());
        $this->assertCount(25, $result);
        $this->assertCount(3, DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertSame('396', $result->get($session->id)['total_minor']);
        $this->assertDatabaseCount('finance_invoices', 0);
    }
}
