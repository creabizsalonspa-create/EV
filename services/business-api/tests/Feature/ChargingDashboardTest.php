<?php

namespace Tests\Feature;

use App\Models\ChargerCommand;
use App\Models\Charging\OperationalCase;
use App\Models\ChargingSession;
use App\Models\Reservation;
use App\Models\Station;
use App\Models\User;
use App\Models\VendorOps\VendorCharger;
use App\Models\VendorOps\VendorCompany;
use App\Models\VendorOps\VendorSite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ChargingDashboardTest extends TestCase
{
    use RefreshDatabase;

    private function link(User $vendor, ?string $event, array $extra = []): int
    {
        $customer = User::factory()->create();
        $station = Station::create(['vendor_id' => $vendor->id, 'name' => 'Private simulator', 'country' => 'AE', 'currency' => 'AED', 'timezone' => 'Asia/Dubai', 'latitude' => 25, 'longitude' => 55, 'source' => 'simulator', 'connector_type' => 'CCS2', 'power_kw' => 50]);
        $binding = DB::table('csms_bindings')->insertGetId(['station_id' => $station->id, 'identifier' => 'ev-test-dashboard-'.$station->id, 'protocol' => '1.6', 'created_at' => now(), 'updated_at' => now()]);
        $reservation = Reservation::create(['user_id' => $customer->id, 'station_id' => $station->id, 'starts_at' => now(), 'idempotency_key' => 'dashboard']);
        $session = ChargingSession::create(['reservation_id' => $reservation->id, 'rate_minor_per_kwh' => 100, 'currency' => 'AED']);
        $command = ChargerCommand::create(['consent_auth_epoch' => 0, 'actor_id' => $customer->id, 'station_id' => $station->id, 'action' => 'start', 'idempotency_key' => 'dashboard', 'payload_hash' => str_repeat('a', 64), 'payload' => ['token' => 'PRIVATE-TOKEN'], 'status' => 'outcome_unknown']);
        DB::table('csms_session_links')->insert($extra + ['charging_session_id' => $session->id, 'binding_id' => $binding, 'start_command_id' => $command->id, 'identifier' => 'ev-test-dashboard-'.$station->id, 'protocol' => '1.6', 'last_event_at' => $event, 'created_at' => now(), 'updated_at' => now()]);

        return $session->id;
    }

    public function test_scope_staleness_and_read_only_privacy(): void
    {
        Http::preventStrayRequests();
        $this->travelTo(now()->startOfSecond());
        $vendor = User::factory()->create(['role' => 'vendor']);
        $missing = $this->link($vendor, null);
        $stale = $this->link($vendor, now()->subSeconds(301)->toDateTimeString());
        $this->link($vendor, now()->subSeconds(300)->toDateTimeString());
        $this->link($vendor, null, ['ended_at' => now()]);
        $this->link($vendor, null, ['closed_without_dispatch_at' => now()]);
        $this->link(User::factory()->create(['role' => 'vendor']), null);
        $command = ChargerCommand::where('station_id', Station::where('vendor_id', $vendor->id)->value('id'))->firstOrFail();
        OperationalCase::create(['command_id' => $command->id, 'state' => 'awaiting_source', 'operator_note' => 'PRIVATE-RECOVERY-NOTE', 'opened_by' => $vendor->id]);
        $response = $this->actingAs($vendor)->getJson('/api/charging-operations/dashboard')->assertOk()
            ->assertJsonPath('station_count', 5)->assertJsonPath('active_session_count', 3)
            ->assertJsonPath('missing_recent_evidence_count', 2)->assertJsonPath('attention.total', 2)
            ->assertJsonPath('unresolved_case_count', 1)
            ->assertJsonPath('attention.data.0.session_id', $missing)->assertJsonPath('attention.data.0.evidence_state', 'not_observed')
            ->assertJsonPath('attention.data.1.session_id', $stale)->assertJsonPath('attention.data.1.evidence_state', 'stale')
            ->assertJsonPath('command_counts.outcome_unknown', 5)->assertJsonPath('physical_charging_verified', false);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('PRIVATE-TOKEN', $response->getContent());
        $this->assertStringNotContainsString('PRIVATE-RECOVERY-NOTE', $response->getContent());
        $this->assertDatabaseCount('charger_commands', 6);
        $this->assertDatabaseCount('finance_invoices', 0);
        Http::assertNothingSent();
        DB::table('charging_operational_cases')->where('command_id', $command->id)->update(['state' => 'resolved']);
        $this->getJson('/api/charging-operations/dashboard')->assertOk()->assertJsonPath('unresolved_case_count', 0);
        $this->actingAs(User::factory()->create(['role' => 'admin']))->getJson('/api/charging-operations/dashboard')->assertOk()->assertJsonPath('station_count', 6);
    }

    public function test_recorded_fault_and_monitor_attention_counts_are_vendor_scoped_not_live_health(): void
    {
        $owner = User::factory()->create(['role' => 'vendor']);
        $stranger = User::factory()->create(['role' => 'vendor']);
        $firstStationId = null;
        foreach ([$owner, $stranger] as $offset => $vendor) {
            $station = Station::create([
                'vendor_id' => $vendor->id,
                'name' => 'Fixture charging monitor '.$offset,
                'country' => 'AE',
                'currency' => 'AED',
                'timezone' => 'Asia/Dubai',
                'latitude' => 25,
                'longitude' => 55,
                'source' => 'simulator',
                'connector_type' => 'CCS2',
                'power_kw' => 50,
            ]);
            if ($offset === 0) {
                $firstStationId = $station->id;
            }
            $company = VendorCompany::create([
                'owner_id' => $vendor->id,
                'legal_name' => 'Synthetic vendor '.$offset,
                'country' => 'AE',
                'trade_license_number' => 'TEST-'.$offset,
                'license_expires_on' => now()->addYear()->toDateString(),
                'status' => 'approved',
            ]);
            $site = VendorSite::create([
                'company_id' => $company->id,
                'station_id' => $station->id,
                'name' => 'Simulator site '.$offset,
                'country' => 'AE',
                'timezone' => 'Asia/Dubai',
                'address' => 'Synthetic only',
                'latitude' => 25,
                'longitude' => 55,
            ]);
            $charger = VendorCharger::create([
                'company_id' => $company->id,
                'site_id' => $site->id,
                'asset_reference' => 'SIM-'.$offset,
                'manufacturer' => 'Synthetic',
                'model' => 'Fixture',
                'source' => 'simulator',
                'ocpp_protocol' => '1.6',
            ]);
            $binding = DB::table('csms_bindings')->insertGetId([
                'station_id' => $station->id,
                'vendor_charger_id' => $charger->id,
                'identifier' => 'ev-test-observer-'.$station->id,
                'protocol' => '1.6',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $generation = hash('sha256', 'test-namespace-'.$binding);
            DB::table('charging_fault_cursors')->insert([
                'binding_id' => $binding,
                'source_generation' => $generation,
                'source_station_id' => 42 + $offset,
                'last_event_id' => 1,
                'state' => 'requires_review',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $observation = DB::table('charging_status_observations')->insertGetId([
                'binding_id' => $binding,
                'source_generation' => $generation,
                'source_event_id' => 1,
                'fingerprint' => hash('sha256', 'fault-'.$binding),
                'evse_number' => 1,
                'connector_number' => 1,
                'status' => 'Faulted',
                'error_code' => 'GroundFailure',
                'occurred_at' => now(),
                'created_at' => now(),
            ]);
            DB::table('charging_fault_alerts')->insert([
                'observation_id' => $observation,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $response = $this->actingAs($owner)
            ->getJson('/api/v1/charging-operations/dashboard')
            ->assertOk()
            ->assertJsonPath('recorded_monitor_review_count', 1)
            ->assertJsonPath('unacknowledged_fault_alert_count', 1)
            ->assertJsonPath('source_recovered_unacknowledged_fault_count', 0)
            ->assertJsonPath('alert_metrics_are_historical', true)
            ->assertJsonPath('physical_charging_verified', false);
        $this->assertStringNotContainsString('GroundFailure', $response->getContent());
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->getJson('/api/charging-operations/dashboard')
            ->assertOk()->assertJsonPath('recorded_monitor_review_count', 2)
            ->assertJsonPath('unacknowledged_fault_alert_count', 2);
        $this->actingAs($stranger)
            ->getJson('/api/v1/charging-operations/dashboard')
            ->assertOk()->assertJsonPath('recorded_monitor_review_count', 1)
            ->assertJsonPath('unacknowledged_fault_alert_count', 1);
        // An ownership transfer invalidates the original company/site/charger
        // mapping. Neither the original vendor nor the new station owner may
        // read the previous company's archived fault workload.
        Station::whereKey($firstStationId)->update(['vendor_id' => $stranger->id]);
        $this->actingAs($owner)->getJson('/api/charging-operations/dashboard')
            ->assertOk()->assertJsonPath('recorded_monitor_review_count', 0)
            ->assertJsonPath('unacknowledged_fault_alert_count', 0);
        $this->actingAs($stranger)->getJson('/api/charging-operations/dashboard')
            ->assertOk()->assertJsonPath('recorded_monitor_review_count', 1)
            ->assertJsonPath('unacknowledged_fault_alert_count', 1);
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->getJson('/api/charging-operations/dashboard')
            ->assertOk()->assertJsonPath('recorded_monitor_review_count', 1)
            ->assertJsonPath('unacknowledged_fault_alert_count', 1);
    }

    public function test_guests_customers_and_invalid_pagination_are_rejected(): void
    {
        $this->getJson('/api/charging-operations/dashboard')->assertUnauthorized();
        $this->actingAs(User::factory()->create())->getJson('/api/charging-operations/dashboard')->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'vendor']))->getJson('/api/charging-operations/dashboard?page=0')->assertUnprocessable();
        $this->getJson('/api/charging-operations/dashboard')->assertOk()->assertJsonPath('station_count', 0)->assertJsonPath('attention.total', 0);
    }

    public function test_native_bearer_scope_and_attention_pagination(): void
    {
        $vendor = User::factory()->create(['role' => 'vendor']);
        for ($i = 0; $i < 26; $i++) {
            $this->link($vendor, null);
        }
        Sanctum::actingAs($vendor);
        $this->getJson('/api/v1/charging-operations/dashboard?page=2')->assertOk()
            ->assertJsonPath('attention.current_page', 2)->assertJsonPath('attention.last_page', 2)
            ->assertJsonPath('attention.total', 26)->assertJsonCount(1, 'attention.data')
            ->assertJsonPath('missing_recent_evidence_count', 26);
        Sanctum::actingAs(User::factory()->create(['role' => 'vendor']));
        $this->getJson('/api/v1/charging-operations/dashboard')->assertOk()->assertJsonPath('attention.total', 0);
    }
}
