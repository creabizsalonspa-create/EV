<?php

namespace Tests\Feature;

use App\Models\ChargerCommand;
use App\Models\ChargingSession;
use App\Models\Reservation;
use App\Models\Station;
use App\Models\User;
use App\Services\Charging\ReservationLifecycle;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ReservationLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function booking(): Reservation
    {
        Http::preventStrayRequests();
        $owner = User::factory()->create(['role' => 'vendor']);
        $customer = User::factory()->create();
        $station = Station::create(['vendor_id' => $owner->id, 'name' => 'Lifecycle simulator', 'country' => 'AE', 'currency' => 'AED', 'timezone' => 'Asia/Dubai', 'latitude' => 25, 'longitude' => 55, 'source' => 'simulator', 'connector_type' => 'CCS2', 'power_kw' => 50]);
        $this->actingAs($customer);

        return Reservation::create(['user_id' => $customer->id, 'station_id' => $station->id, 'starts_at' => now()->startOfHour()->addHour()->format('Y-m-d H:i:s'), 'idempotency_key' => 'lifecycle']);
    }

    public function test_check_in_is_scoped_explicit_self_reported_server_timed_and_idempotent(): void
    {
        $r = $this->booking();
        $start = CarbonImmutable::parse($r->starts_at, 'UTC');
        $url = '/api/reservations/'.$r->id.'/check-in';
        $this->travelTo($start->subMinutes(16));
        $this->postJson($url, ['self_reported_arrival' => true])->assertConflict();
        $this->travelTo($start->subMinutes(15));
        $this->postJson($url, ['self_reported_arrival' => false])->assertUnprocessable();
        $this->postJson($url, ['self_reported_arrival' => true, 'checked_in_at' => now()->subYear()->toISOString()])->assertUnprocessable();
        $reply = $this->postJson($url, ['self_reported_arrival' => true])->assertCreated()->assertJsonPath('physical_presence_verified', false)->assertJsonPath('evidence', 'SELF_REPORTED')->json();
        $this->travelTo($start->addMinutes(2));
        $this->postJson($url, ['self_reported_arrival' => true])->assertOk()->assertJsonPath('reservation.checked_in_at', $reply['reservation']['checked_in_at'])->assertJsonPath('replayed', true);
        $this->getJson('/api/reservations')->assertJsonPath('data.0.can_check_in', false);
        $this->deleteJson('/api/reservations/'.$r->id)->assertOk()->assertJsonPath('status', 'cancelled');
        $this->postJson($url, ['self_reported_arrival' => true])->assertOk();
        $this->assertDatabaseCount('reservation_lifecycle_events', 1);
        $this->assertDatabaseCount('finance_invoices', 0);
        $this->actingAs(User::factory()->create())->postJson($url, ['self_reported_arrival' => true])->assertNotFound();
        Http::assertNothingSent();
    }

    public static function arrivals(): array
    {
        return [[false], [true]];
    }

    #[DataProvider('arrivals')]
    public function test_unused_window_expiry_is_exact_retains_arrival_and_never_creates_session_or_money(bool $arrived): void
    {
        $r = $this->booking();
        $end = CarbonImmutable::parse($r->starts_at, 'UTC')->addMinutes(30);
        if ($arrived) {
            $this->travelTo($end->subMinute());
            $this->postJson('/api/reservations/'.$r->id.'/check-in', ['self_reported_arrival' => true])->assertCreated();
        }
        $service = app(ReservationLifecycle::class);
        $this->travelTo($end->subSecond());
        $this->assertSame(0, $service->expire()['expired']);
        $this->travelTo($end);
        $this->assertSame(1, $service->expire()['expired']);
        $this->assertSame(0, $service->expire()['expired']);
        $this->assertSame('expired_unused', $r->fresh()->status);
        $this->assertSame($arrived, $r->fresh()->checked_in_at !== null);
        $event = DB::table('reservation_lifecycle_events')->where('event_type', 'expired_unused')->sole();
        $this->assertNull($event->actor_id);
        $e = json_decode($event->evidence, true);
        $this->assertSame('none', $e['financial_action']);
        $this->assertSame($arrived, $e['self_reported_arrival']);
        $this->assertDatabaseCount('charging_sessions', 0);
        $this->assertDatabaseCount('finance_invoices', 0);
        $this->deleteJson('/api/reservations/'.$r->id)->assertConflict();
        Http::assertNothingSent();
    }

    public function test_existing_session_any_state_and_uncorrelated_commands_require_separate_reconciliation(): void
    {
        $r = $this->booking();
        $this->travelTo(CarbonImmutable::parse($r->starts_at)->addHour());
        $command = ChargerCommand::create(['actor_id' => $r->user_id, 'station_id' => $r->station_id, 'action' => 'start', 'payload' => [], 'payload_hash' => str_repeat('a', 64), 'idempotency_key' => 'unknown', 'status' => 'outcome_unknown']);
        $service = app(ReservationLifecycle::class);
        $this->assertSame(1, $service->expire()['skipped']);
        $this->assertSame('confirmed', $r->fresh()->status);
        $command->update(['status' => 'cancelled']);
        ChargingSession::create(['reservation_id' => $r->id, 'status' => 'pending_start', 'currency' => 'AED', 'rate_minor_per_kwh' => 100]);
        $this->assertSame(0, $service->expire()['examined']);
        $this->assertSame('confirmed', $r->fresh()->status);
        $this->assertDatabaseCount('reservation_lifecycle_events', 0);
    }

    public function test_scheduled_scan_is_bounded_and_resumes_past_reconciliation_records(): void
    {
        $r = $this->booking();
        $this->travelTo(CarbonImmutable::parse($r->starts_at)->addHour());
        ChargerCommand::create(['actor_id' => $r->user_id, 'station_id' => $r->station_id, 'action' => 'start', 'payload' => [], 'payload_hash' => str_repeat('b', 64), 'idempotency_key' => 'hold']);
        $this->artisan('reservations:expire-unused', ['--limit' => '0'])->assertExitCode(2);
        $this->artisan('reservations:expire-unused', ['--limit' => '1', '--scheduled' => true])->expectsOutputToContain('"skipped":1')->assertSuccessful();
        $this->assertSame($r->id, Cache::get('reservations:unused-expiry-cursor'));
        $this->artisan('reservations:expire-unused', ['--limit' => '1', '--scheduled' => true])->expectsOutputToContain('"examined":0')->assertSuccessful();
        $this->assertSame(0, Cache::get('reservations:unused-expiry-cursor'));
        $this->assertNotNull(collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command ?? '', 'reservations:expire-unused')));
    }
}
