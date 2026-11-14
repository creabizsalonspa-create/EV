<?php

namespace Tests\Feature;

use App\Models\ChargingSession;
use App\Models\Reservation;
use App\Models\Station;
use App\Models\User;
use App\Services\Charging\ReservationLifecycle;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReservationPolicyTest extends TestCase
{
    use RefreshDatabase;

    private function scenario(): array
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-17T08:00:00Z'));
        $vendor = User::factory()->create(['role' => 'vendor']);
        $customer = User::factory()->create();
        $station = Station::create(['vendor_id' => $vendor->id, 'name' => 'Policy fixture', 'country' => 'AE', 'currency' => 'AED', 'timezone' => 'Asia/Dubai', 'latitude' => 25, 'longitude' => 55, 'source' => 'simulator', 'connector_type' => 'CCS2', 'power_kw' => 50]);
        $this->actingAs($customer);

        return [$vendor, $customer, $station];
    }

    private function booking(Station $station, string $key = 'policy', string $time = '2026-11-17T08:30:00Z'): array
    {
        return ['station_id' => $station->id, 'starts_at' => $time, 'idempotency_key' => $key, 'expected_reservation_terms_version' => 1];
    }

    public function test_terms_are_source_qualified_server_owned_and_historical_replay_does_not_rewrite_them(): void
    {
        [, , $station] = $this->scenario();
        $this->getJson('/api/stations')->assertJsonPath('data.0.reservation_terms.version', 1)
            ->assertJsonPath('data.0.reservation_terms.no_show_fee_minor', 0)->assertJsonPath('data.0.reservation_terms.physical_occupancy_verified', false);
        $body = $this->booking($station);
        $this->postJson('/api/reservations', $body + ['reservation_terms_snapshot' => ['no_show_fee_minor' => 900]])->assertUnprocessable();
        $receipt = $this->postJson('/api/reservations', $body)->assertCreated()->assertJsonPath('reservation_terms_snapshot.client_version_confirmed', true)
            ->assertJsonPath('reservation_terms_snapshot.slot_ends_at', '2026-11-17T09:00:00+00:00')
            ->assertJsonPath('reservation_terms_snapshot.self_reported_arrival_from', '2026-11-17T08:15:00+00:00')->json();
        $station->update(['timezone' => 'Europe/London']);
        $this->travel(3)->minutes();
        $this->postJson('/api/reservations', $body)->assertOk()->assertJsonPath('reservation_terms_snapshot', $receipt['reservation_terms_snapshot']);
        $this->postJson('/api/reservations', array_replace($body, ['expected_reservation_terms_version' => 2]))->assertConflict();
        $this->postJson('/api/reservations', array_replace($body, ['idempotency_key' => 'changed', 'expected_reservation_terms_version' => 2]))->assertConflict();
        try {
            DB::transaction(fn () => DB::table('reservations')->where('id', $receipt['id'])->update(['reservation_terms_snapshot' => null]));
            $this->fail('Terms were rewritten.');
        } catch (QueryException) {
            $this->assertSame($receipt['reservation_terms_snapshot'], Reservation::findOrFail($receipt['id'])->reservation_terms_snapshot);
        }
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    public function test_legacy_records_are_not_falsely_backfilled_with_confirmed_terms(): void
    {
        [, $customer, $station] = $this->scenario();
        Reservation::create(['user_id' => $customer->id, 'station_id' => $station->id, 'starts_at' => '2026-11-17 08:30:00', 'idempotency_key' => 'legacy']);
        $body = $this->booking($station, 'legacy');
        $this->postJson('/api/reservations', $body)->assertConflict();
        unset($body['expected_reservation_terms_version']);
        $this->postJson('/api/reservations', $body)->assertOk()->assertJsonPath('reservation_terms_snapshot', null);
        $body['idempotency_key'] = 'older-client-new-booking';
        $body['starts_at'] = '2026-11-17T09:00:00Z';
        $this->postJson('/api/reservations', $body)->assertCreated()->assertJsonPath('reservation_terms_snapshot.client_version_confirmed', false);
    }

    public function test_legacy_unresolved_charging_cannot_overlap_a_later_slot_but_planned_reservations_and_stops_remain_available(): void
    {
        [, , $station] = $this->scenario();
        $first = $this->postJson('/api/reservations', $this->booking($station))->assertCreated()->json('id');
        $second = $this->postJson('/api/reservations', $this->booking($station, 'next', '2026-11-17T09:00:00Z'))->assertCreated()->json('id');
        $this->travelTo(CarbonImmutable::parse('2026-11-17T08:30:00Z'));
        $sid = $this->postJson('/api/reservations/'.$first.'/simulate-start')->assertCreated()->json('id');
        $this->travelTo(CarbonImmutable::parse('2026-11-17T09:00:00Z'));
        $this->postJson('/api/reservations/'.$second.'/simulate-start')->assertConflict();
        $this->postJson('/api/reservations/'.$first.'/simulate-start')->assertOk()->assertJsonPath('id', $sid);
        $this->deleteJson('/api/reservations/'.$first)->assertConflict();
        $this->postJson('/api/simulations/'.$sid.'/stop')->assertOk();
        $this->postJson('/api/reservations/'.$second.'/simulate-start')->assertCreated();
        $this->assertSame(1, ChargingSession::where('status', 'charging')->count());
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    public function test_unused_expiry_after_self_reported_arrival_never_claims_physical_absence_or_creates_fees(): void
    {
        [, $customer, $station] = $this->scenario();
        $id = $this->postJson('/api/reservations', $this->booking($station))->assertCreated()->json('id');
        $this->travelTo(CarbonImmutable::parse('2026-11-17T08:15:00Z'));
        $this->postJson('/api/reservations/'.$id.'/check-in', ['self_reported_arrival' => true])->assertCreated();
        $this->travelTo(CarbonImmutable::parse('2026-11-17T09:00:00Z'));
        $result = app(ReservationLifecycle::class)->expire(id: $id);
        $this->assertSame(1, $result['expired']);
        $this->assertSame(0, app(ReservationLifecycle::class)->expire(id: $id)['expired']);
        $this->assertDatabaseHas('reservations', ['id' => $id, 'status' => 'expired_unused']);
        $event = json_decode(DB::table('reservation_lifecycle_events')->where('reservation_id', $id)->where('event_type', 'expired_unused')->value('evidence'), true);
        $this->assertTrue($event['self_reported_arrival']);
        $this->assertSame('none', $event['financial_action']);
        $this->assertDatabaseCount('finance_invoices', 0);
        $this->assertSame($customer->id, Reservation::findOrFail($id)->user_id);
    }

    public function test_native_bookings_use_the_same_versioned_terms_and_reject_foreign_customers(): void
    {
        [, $customer, $station] = $this->scenario();
        $this->app['auth']->forgetGuards();
        $token = $customer->createToken('native')->plainTextToken;
        $id = $this->withToken($token)->postJson('/api/v1/reservations', $this->booking($station))->assertCreated()
            ->assertJsonPath('reservation_terms_snapshot.version', 1)->json('id');
        $this->app['auth']->forgetGuards();
        $this->withToken(User::factory()->create()->createToken('other')->plainTextToken)->deleteJson('/api/v1/reservations/'.$id)->assertNotFound();
    }
}
