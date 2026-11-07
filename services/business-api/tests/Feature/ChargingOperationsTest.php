<?php

namespace Tests\Feature;

use App\Models\ChargerCommand;
use App\Models\ChargingSession;
use App\Models\Reservation;
use App\Models\Station;
use App\Models\User;
use App\Services\Charging\SessionBridge;
use App\Services\Charging\SourceConsumer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ChargingOperationsTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        Http::preventStrayRequests();
        config(['csms.session_bridge_enabled' => true, 'csms.simulator_enabled' => true, 'csms.base_url' => 'http://csms.test']);
        $this->travelTo(now()->startOfSecond());
        $vendor = User::factory()->create(['role' => 'vendor']);
        $customer = User::factory()->create();
        $station = Station::create(['vendor_id' => $vendor->id, 'name' => 'Recovery simulator', 'country' => 'AE', 'currency' => 'AED', 'timezone' => 'Asia/Dubai', 'latitude' => 25, 'longitude' => 55, 'source' => 'simulator', 'connector_type' => 'CCS2', 'power_kw' => 50]);
        DB::table('csms_bindings')->insert(['station_id' => $station->id, 'identifier' => 'ev-test-recovery', 'protocol' => '1.6', 'created_at' => now(), 'updated_at' => now()]);
        $r = Reservation::create(['user_id' => $customer->id, 'station_id' => $station->id, 'starts_at' => now()->subMinutes(5), 'idempotency_key' => 'recovery']);
        $this->actingAs($customer)->postJson('/api/reservations/'.$r->id.'/csms-start')->assertAccepted();

        return [$vendor, $customer, ChargingSession::firstOrFail(), ChargerCommand::firstOrFail()];
    }

    private function event(ChargingSession $s, string $type = 'started'): array
    {
        $end = $type === 'ended';

        return ['event_id' => 'recovery-'.$s->id.'-'.$type, 'session_id' => $s->id, 'identifier' => 'ev-test-recovery', 'protocol' => '1.6', 'transaction_id' => '123', 'type' => $type, 'sequence' => $end ? 1 : 0, 'meter_wh' => $end ? 3500 : 1000, 'occurred_at' => now()->addSeconds($end ? 1 : 0)->toISOString()];
    }

    public function test_uncertain_case_cannot_release_capacity_or_repeat_commands_without_source(): void
    {
        [$vendor,$customer,$session,$command] = $this->fixture();
        $command->update(['status' => 'outcome_unknown']);
        $url = '/api/charging-operations/'.$command->id;
        $this->postJson($url.'/case', ['note' => 'Investigate source absence'])->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'vendor']))->postJson($url.'/case', ['note' => 'Investigate source absence'])->assertNotFound();
        $this->actingAs($vendor)->postJson($url.'/case', ['note' => 'Investigate source absence'])->assertCreated()->assertJsonPath('state', 'open');
        $this->postJson($url.'/case', ['note' => 'Repeated request unchanged'])->assertOk();
        $this->patchJson($url.'/case', ['state' => 'resolved', 'note' => 'No hardware evidence exists'])->assertConflict();
        $this->patchJson($url.'/case', ['state' => 'investigating', 'note' => 'Checking authorized source logs'])->assertOk()->assertJsonPath('capacity_release_forced', false);
        $mock = \Mockery::mock(SourceConsumer::class);
        $mock->shouldReceive('consume')->once()->andReturn(0);
        $this->app->instance(SourceConsumer::class, $mock);
        $this->postJson($url.'/inspect-source')->assertOk()->assertJsonPath('events_received', 0)->assertJsonPath('source_observed', false)->assertJsonPath('command_resent', false);
        $this->getJson('/api/charging-operations')->assertOk()->assertJsonPath('data.0.case.state', 'awaiting_source');
        $this->assertSame('outcome_unknown', $command->fresh()->status);
        $this->assertSame('pending_start', $session->fresh()->status);
        $this->assertDatabaseCount('charger_commands', 1);
        $this->assertDatabaseCount('finance_invoices', 0);
        $this->assertStringNotContainsString('source absence', DB::table('charging_operational_cases')->value('operator_note'));
        Http::assertNothingSent();
    }

    public function test_proof_is_required_for_case_resolution_and_tenant_scope_precedes_source_reads(): void
    {
        [$vendor,$customer,$session,$command] = $this->fixture();
        $command->update(['status' => 'outcome_unknown']);
        $url = '/api/charging-operations/'.$command->id;
        $this->actingAs($vendor)->postJson($url.'/case', ['note' => 'Investigating uncertain dispatch'])->assertCreated();
        $mock = \Mockery::mock(SourceConsumer::class);
        $mock->shouldNotReceive('consume');
        $this->app->instance(SourceConsumer::class, $mock);
        $this->actingAs(User::factory()->create(['role' => 'vendor']))->postJson($url.'/inspect-source')->assertNotFound();
        app(SessionBridge::class)->ingest($this->event($session));
        $this->actingAs($vendor)->patchJson($url.'/case', ['state' => 'resolved', 'note' => 'Authenticated source start observed'])->assertOk()->assertJsonPath('capacity_release_forced', false);
        $this->assertSame('charging', $session->fresh()->status);
        $this->assertSame('source_observed', $command->fresh()->status);
        $this->assertDatabaseCount('command_source_observations', 1);
        $this->assertDatabaseCount('charger_commands', 1);
        Http::assertNothingSent();
    }
}
