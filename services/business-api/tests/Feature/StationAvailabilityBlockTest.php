<?php

namespace Tests\Feature;

use App\Models\Station;
use App\Models\User;
use App\Services\Charging\OperatingCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\Support\AcceptsPrechargePricing;
use Tests\TestCase;

class StationAvailabilityBlockTest extends TestCase
{
    use AcceptsPrechargePricing;
    use RefreshDatabase;

    private function station(User $vendor): Station
    {
        return Station::create(['vendor_id' => $vendor->id, 'name' => 'Operator policy', 'country' => 'AE', 'currency' => 'AED', 'timezone' => 'Asia/Dubai', 'latitude' => 25.2, 'longitude' => 55.3, 'source' => 'simulator', 'connector_type' => 'CCS2', 'power_kw' => 50]);
    }

    private function details(): array
    {
        return ['idempotency_key' => (string) Str::uuid(), 'starts_at' => now('UTC')->addHour()->format('Y-m-d\TH:i:s\Z'), 'ends_at' => now('UTC')->addHours(2)->format('Y-m-d\TH:i:s\Z'), 'reason_en' => 'Scheduled maintenance', 'reason_ar' => 'صيانة مجدولة'];
    }

    public function test_owner_only_public_projection_and_natural_replay_never_reactivate_cancellation(): void
    {
        $this->freezeTime();
        $vendor = User::factory()->create(['role' => 'vendor']);
        $station = $this->station($vendor);
        $path = '/api/stations/'.$station->id.'/availability-blocks';
        $body = $this->details();
        $this->actingAs(User::factory()->create(['role' => 'vendor']))->postJson($path, $body)->assertNotFound();
        $this->actingAs(User::factory()->create())->postJson($path, $body)->assertNotFound();
        $id = $this->actingAs($vendor)->postJson($path, $body)->assertCreated()->json('id');
        $this->postJson($path, $body)->assertOk()->assertJsonPath('id', $id);
        $this->postJson($path, array_replace($body, ['reason_en' => 'Different']))->assertConflict();
        $this->getJson($path)->assertOk()->assertJsonPath('data.0.scope', 'STATION_WIDE_OPERATOR_POLICY')
            ->assertJsonPath('data.0.physical_availability_verified', false)->assertJsonMissingPath('data.0.created_by')->assertJsonMissingPath('data.0.idempotency_key');
        $this->getJson('/api/stations/'.$station->id.'/information')->assertJsonPath('availability_blocks.0.id', $id);
        $this->getJson('/api/stations')->assertJsonPath('data.0.information.availability_blocks.0.id', $id)->assertJsonPath('data.0.availability', 'unknown');
        $this->postJson($path.'/'.$id.'/cancel', ['revision' => 2])->assertConflict();
        $this->postJson($path.'/'.$id.'/cancel', ['revision' => 1])->assertOk()->assertJsonPath('revision', 2);
        $this->postJson($path.'/'.$id.'/cancel', ['revision' => 1])->assertOk();
        $this->postJson($path, $body)->assertOk()->assertJsonPath('cancelled', true);
        $this->getJson($path)->assertJsonCount(0, 'data');
        $this->assertSame(1, DB::table('audit_events')->where('action', 'station.availability_block.created')->count());
        $this->assertSame(1, DB::table('audit_events')->where('action', 'station.availability_block.cancelled')->count());
    }

    public function test_half_open_blocks_apply_even_without_published_hours_and_cancel_reopens_admission(): void
    {
        $this->freezeTime();
        $vendor = User::factory()->create(['role' => 'vendor']);
        $station = $this->station($vendor);
        $path = '/api/stations/'.$station->id.'/availability-blocks';
        $body = $this->details();
        $id = $this->actingAs($vendor)->postJson($path, $body)->assertCreated()->json('id');
        $start = CarbonImmutable::parse($body['starts_at']);
        $end = CarbonImmutable::parse($body['ends_at']);
        $calendar = app(OperatingCalendar::class);
        $this->assertNull($calendar->requireWindow($station, $start->subMinutes(30), $start));
        $this->assertNull($calendar->requireWindow($station, $end, $end->addMinutes(30)));
        foreach ([[$start->subMinute(), $start->addMinute()], [$start, $end], [$end->subSecond(), $end->addSecond()]] as [$a, $b]) {
            try {
                $calendar->requireWindow($station, $a, $b);
                $this->fail('Overlapping block permitted.');
            } catch (HttpExceptionInterface $e) {
                $this->assertSame(409, $e->getStatusCode());
            }
        }
        $this->postJson($path.'/'.$id.'/cancel', ['revision' => 1])->assertOk();
        $this->assertNull($calendar->requireWindow($station, $start, $end));
    }

    public function test_validation_bounds_utc_intervals_reasons_and_bearer_owner_routes(): void
    {
        $this->freezeTime();
        $vendor = User::factory()->create(['role' => 'vendor']);
        $station = $this->station($vendor);
        $body = $this->details();
        $token = $vendor->createToken('mobile')->plainTextToken;
        $path = '/api/v1/stations/'.$station->id.'/availability-blocks';
        $this->withToken($token)->postJson($path, array_replace($body, ['starts_at' => '2026-02-30T12:00:00Z']))->assertUnprocessable();
        $this->postJson($path, array_replace($body, ['ends_at' => now()->addDays(32)->format('Y-m-d\TH:i:s\Z')]))->assertUnprocessable();
        $this->postJson($path, array_replace($body, ['reason_ar' => '   ']))->assertUnprocessable();
        $this->postJson($path, array_replace($body, ['starts_at' => now()->subHour()->format('Y-m-d\TH:i:s\Z')]))->assertUnprocessable();
        $id = $this->postJson($path, $body)->assertCreated()->json('id');
        $this->postJson($path.'/'.$id.'/cancel', ['revision' => 1])->assertOk();
    }

    public function test_block_does_not_interrupt_active_charging_or_change_finance(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-17T08:00:00Z'));
        $vendor = User::factory()->create(['role' => 'vendor']);
        $customer = User::factory()->create();
        $station = $this->station($vendor);
        $id = $this->actingAs($customer)->postJson('/api/reservations', ['station_id' => $station->id, 'starts_at' => '2026-11-17T08:30:00Z', 'idempotency_key' => 'active-before-block'])->assertCreated()->json('id');
        $this->travelTo(CarbonImmutable::parse('2026-11-17T08:30:00Z'));
        $this->acceptPrechargePrice($id);
        $session = $this->postJson('/api/reservations/'.$id.'/simulate-start')->assertCreated()->json('id');
        $body = $this->details();
        $body['starts_at'] = '2026-11-17T08:30:00Z';
        $this->actingAs($vendor)->postJson('/api/stations/'.$station->id.'/availability-blocks', $body)->assertCreated();
        $this->actingAs($customer)->postJson('/api/reservations/'.$id.'/simulate-start')->assertOk()->assertJsonPath('id', $session);
        $this->postJson('/api/simulations/'.$session.'/stop')->assertOk();
        $this->assertDatabaseHas('charging_sessions', ['id' => $session, 'status' => 'stopped']);
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    public function test_new_block_prevents_new_booking_but_accepted_booking_replay_and_cancellation_remain_available(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-17T08:00:00Z'));
        $vendor = User::factory()->create(['role' => 'vendor']);
        $customer = User::factory()->create();
        $station = $this->station($vendor);
        $booking = ['station_id' => $station->id, 'starts_at' => '2026-11-17T09:00:00Z', 'idempotency_key' => 'accepted-before-block'];
        $reservation = $this->actingAs($customer)->postJson('/api/reservations', $booking)->assertCreated()->json('id');
        $path = '/api/stations/'.$station->id.'/availability-blocks';
        $this->actingAs($vendor)->postJson($path, $this->details())->assertCreated();
        $this->actingAs($customer)->postJson('/api/reservations', $booking)->assertOk()->assertJsonPath('id', $reservation);
        $this->postJson('/api/reservations', array_replace($booking, ['starts_at' => '2026-11-17T09:30:00Z', 'idempotency_key' => 'new-blocked']))->assertConflict();
        $this->travelTo(CarbonImmutable::parse('2026-11-17T09:00:00Z'));
        $this->postJson('/api/reservations/'.$reservation.'/simulate-start')->assertConflict();
        $this->deleteJson('/api/reservations/'.$reservation)->assertOk();
    }
}
