<?php

namespace Tests\Feature;

use App\Models\Station;
use App\Models\User;
use App\Services\Charging\FaultMonitor;
use App\Services\Charging\SourceStatusDurations;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SourceStatusDurationsTest extends TestCase
{
    use RefreshDatabase;

    private function binding(): object
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-28T12:00:00Z'));
        $vendor = User::factory()->create(['role' => 'vendor']);
        $station = Station::create(['vendor_id' => $vendor->id, 'name' => 'Duration fixture', 'country' => 'AE', 'currency' => 'AED', 'timezone' => 'Asia/Dubai', 'latitude' => 25, 'longitude' => 55, 'source' => 'simulator', 'connector_type' => 'CCS2', 'power_kw' => 50]);
        $id = DB::table('csms_bindings')->insertGetId(['station_id' => $station->id, 'identifier' => 'ev-test-duration', 'protocol' => '1.6', 'created_at' => now(), 'updated_at' => now()]);

        return DB::table('csms_bindings')->find($id);
    }

    private function observe(object $binding, int $secondsAgo, string $status, int $connector = 1, ?string $error = null): void
    {
        $id = DB::table('charging_status_observations')->count() + 1;
        DB::table('charging_status_observations')->insert(['binding_id' => $binding->id, 'source_generation' => FaultMonitor::generation($binding), 'source_event_id' => $id, 'fingerprint' => hash('sha256', (string) $id), 'evse_number' => 1, 'connector_number' => $connector, 'status' => $status, 'error_code' => $error, 'occurred_at' => now()->subSeconds($secondsAgo), 'created_at' => now()]);
    }

    public function test_silence_stays_unknown_and_components_are_independent(): void
    {
        $binding = $this->binding();
        $this->observe($binding, 600, 'Available');
        $this->observe($binding, 480, 'Charging');
        $this->observe($binding, 180, 'Faulted');
        $this->observe($binding, 100, 'Available', 2);
        $report = app(SourceStatusDurations::class)->report($binding);
        $this->assertSame(['Available' => 120, 'Charging' => 300, 'Faulted' => 180], (array) $report['components'][0]['observed_status_seconds']);
        $this->assertSame(85800, $report['components'][0]['unknown_seconds']);
        $this->assertSame(86300, $report['components'][1]['unknown_seconds']);
        $this->assertFalse($report['physical_uptime_verified']);
        $this->assertFalse($report['commands_sent']);
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    public function test_conflicting_same_second_events_are_unknown_and_errors_remain_faults(): void
    {
        $binding = $this->binding();
        $this->observe($binding, 300, 'Available');
        $this->observe($binding, 300, 'Unavailable');
        $this->observe($binding, 100, 'Available', 1, 'GroundFailure');
        $report = app(SourceStatusDurations::class)->report($binding);
        $this->assertSame(['Faulted' => 100], (array) $report['components'][0]['observed_status_seconds']);
        $this->assertSame(86300, $report['components'][0]['unknown_seconds']);
    }

    public function test_mapping_changes_future_events_and_unseen_history_never_prove_uptime(): void
    {
        $binding = $this->binding();
        $this->observe($binding, -100, 'Available');
        $this->observe($binding, 90000, 'Available');
        $this->assertSame('not_observed', app(SourceStatusDurations::class)->report($binding)['state']);
        $this->observe($binding, 100, 'Available');
        $binding->identifier = 'ev-test-rebound';
        $report = app(SourceStatusDurations::class)->report($binding);
        $this->assertSame('not_observed', $report['state']);
        $this->assertSame([], $report['components']);
    }

    public function test_prior_boundary_observation_is_clipped_and_never_extended_to_a_day(): void
    {
        $binding = $this->binding();
        $this->observe($binding, 86500, 'Available');
        $report = app(SourceStatusDurations::class)->report($binding);
        $this->assertSame(['Available' => 200], (array) $report['components'][0]['observed_status_seconds']);
        $this->assertSame(86200, $report['components'][0]['unknown_seconds']);
    }

    public function test_observation_budget_fails_closed_without_partial_duration_claims(): void
    {
        $binding = $this->binding();
        $rows = [];
        for ($id = 1; $id <= SourceStatusDurations::MAX_EVENTS + 1; $id++) {
            $rows[] = ['binding_id' => $binding->id, 'source_generation' => FaultMonitor::generation($binding), 'source_event_id' => $id, 'fingerprint' => hash('sha256', (string) $id), 'evse_number' => 1, 'connector_number' => 1, 'status' => 'Available', 'error_code' => null, 'occurred_at' => now()->subSeconds(100), 'created_at' => now()];
            if (count($rows) === 500) {
                DB::table('charging_status_observations')->insert($rows);
                $rows = [];
            }
        }
        if ($rows) {
            DB::table('charging_status_observations')->insert($rows);
        }
        $report = app(SourceStatusDurations::class)->report($binding);
        $this->assertSame('requires_review', $report['state']);
        $this->assertSame('observation_budget_exceeded', $report['reason']);
        $this->assertSame([], $report['components']);
    }

    public function test_current_fractional_second_is_observed_without_counting_future_or_unelapsed_time(): void
    {
        $binding = $this->binding();
        $this->travelTo(CarbonImmutable::parse('2026-11-28T12:00:00.500000Z'));
        app(FaultMonitor::class)->ingest($binding, ['source_generation' => FaultMonitor::generation($binding), 'events' => [
            ['id' => 1, 'evseId' => 1, 'connectorId' => 1, 'connectorStatus' => 'Available', 'errorCode' => 'NoError', 'timestamp' => '2026-11-28T12:00:00.250000Z'],
            ['id' => 2, 'evseId' => 1, 'connectorId' => 2, 'connectorStatus' => 'Faulted', 'errorCode' => 'GroundFailure', 'timestamp' => '2026-11-28T12:00:00.750000Z'],
        ]]);
        $report = app(SourceStatusDurations::class)->report($binding);
        $this->assertSame('observed', $report['state']);
        $this->assertCount(1, $report['components']);
        $this->assertSame(1, $report['components'][0]['connector_number']);
        $this->assertSame([], (array) $report['components'][0]['observed_status_seconds']);
        $this->assertSame(86400, $report['components'][0]['unknown_seconds']);
        $this->assertFalse($report['physical_uptime_verified']);
    }
}
