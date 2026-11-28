<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Operations\EmailHandoffHealth;
use App\Services\Operations\OperationalHealth;
use App\Services\Operations\OperationalIncidents;
use App\Services\Operations\OperationalWatchdog;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class OperationalWatchdogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['operational_health.enabled' => true, 'queue.default' => 'database',
            'queue.connections.database.connection' => null, 'queue.connections.database.queue' => 'default']);
        $this->travelTo(CarbonImmutable::parse('2026-11-15T12:00:00Z'));
    }

    private function probe(): array
    {
        return app(OperationalWatchdog::class)->check(
            app(OperationalHealth::class), app(OperationalIncidents::class));
    }

    public function test_fresh_scoped_heartbeats_and_empty_queues_are_observed_normal_not_production_accepted(): void
    {
        $health = app(OperationalHealth::class);
        $this->assertTrue($health->record('queue_worker'));
        $this->assertTrue($health->record('scheduler'));
        app(OperationalIncidents::class)->scan($health);
        $result = $this->probe();
        $this->assertSame('observed_normal', $result['status']);
        $this->assertSame([], $result['signals']);
        foreach (['read_only', 'automatic_retry', 'external_alert_delivery',
            'all_workers_verified', 'charger_connectivity_verified', 'production_readiness_verified'] as $key) {
            $this->assertSame($key === 'read_only', $result[$key]);
        }
        $this->assertSame(0, Artisan::call('operations:watchdog-check'));
        $this->assertDatabaseCount('operational_incident_events', 0);
        $this->assertDatabaseCount('charger_commands', 0);
        $this->assertDatabaseCount('finance_payments', 0);
    }

    public function test_scanner_requires_committed_fresh_cycles_even_when_process_heartbeats_are_fresh(): void
    {
        $health = app(OperationalHealth::class);
        $this->assertTrue($health->record('queue_worker'));
        $this->assertTrue($health->record('scheduler'));
        $this->assertContains('incident_scanner_unknown', $this->probe()['signals']);
        $this->assertNull(DB::table('operational_incident_scan_locks')->where('id', 1)->value('last_completed_at'));
        $this->assertSame(1, Artisan::call('operations:watchdog-check'));
        $this->assertNull(DB::table('operational_incident_scan_locks')->where('id', 1)->value('last_completed_at'));

        $scanner = app(OperationalIncidents::class);
        $this->assertFalse($scanner->scan($health)['skipped']);
        $this->assertSame('recent', $scanner->summary()['scanner']['state']);
        $this->assertSame('observed_normal', $this->probe()['status']);
        $this->travel(180)->seconds();
        $this->assertTrue($health->record('queue_worker'));
        $this->assertTrue($health->record('scheduler'));
        $signals = $this->probe()['signals'];
        $this->assertContains('incident_scanner_stale', $signals);
        $this->assertNotContains('queue_worker_stale', $signals);
        $this->assertNotContains('scheduler_stale', $signals);
        $this->assertSame('stale', $scanner->summary()['scanner']['state']);
        $this->assertSame(1, Artisan::call('operations:watchdog-check'));
        $this->assertSame(0, $scanner->scan($health)['opened']);
        $this->assertSame('observed_normal', $this->probe()['status']);
        $this->assertDatabaseCount('operational_incident_events', 0);
        $this->assertDatabaseCount('charger_commands', 0);
        $this->assertDatabaseCount('finance_payments', 0);
    }

    public function test_scan_liveness_keeps_utc_in_a_non_utc_database_session(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SET LOCAL TIME ZONE 'Asia/Dubai'");
        }
        $incidents = app(OperationalIncidents::class);
        $incidents->scan(app(OperationalHealth::class));

        $this->assertSame('recent', $incidents->summary()['scanner']['state']);
        $this->assertSame(now('UTC')->toISOString(), $incidents->summary()['scanner']['last_completed_at']);
    }

    public function test_future_or_invalid_scanner_evidence_fails_closed(): void
    {
        $health = app(OperationalHealth::class);
        $health->record('queue_worker');
        $health->record('scheduler');
        app(OperationalIncidents::class)->scan($health);
        DB::table('operational_incident_scan_locks')->where('id', 1)
            ->update(['last_completed_at' => now()->addMinutes(10)->toISOString()]);
        $this->assertSame('unknown', app(OperationalIncidents::class)->summary()['scanner']['state']);
        $this->assertContains('incident_scanner_unknown', $this->probe()['signals']);
        $this->assertDatabaseCount('operational_incident_events', 0);
    }

    public function test_missing_or_stale_observations_fail_without_manufacturing_a_heartbeat(): void
    {
        $this->assertSame('needs_attention', $this->probe()['status']);
        $this->assertContains('queue_worker_unknown', $this->probe()['signals']);
        $this->assertContains('scheduler_unknown', $this->probe()['signals']);
        $this->assertSame(1, Artisan::call('operations:watchdog-check'));
        $this->assertDatabaseCount('operational_heartbeats', 0);
        $health = app(OperationalHealth::class);
        $this->assertTrue($health->record('queue_worker'));
        $this->assertTrue($health->record('scheduler'));
        $this->travel(180)->seconds();
        $this->assertContains('queue_worker_stale', $this->probe()['signals']);
        $this->assertContains('scheduler_stale', $this->probe()['signals']);
        $this->assertSame(1, Artisan::call('operations:watchdog-check'));
        // Probe does not touch the stored observations, so repeat checks stay stale.
        $this->assertSame('stale', $health->report()['components'][1]['state']);
        $this->assertDatabaseCount('operational_incident_events', 0);
    }

    public function test_failed_or_aged_backlog_jobs_trigger_attention_without_queue_mutation(): void
    {
        $health = app(OperationalHealth::class);
        $health->record('queue_worker');
        $health->record('scheduler');
        for ($i = 0; $i < 25; $i++) {
            DB::table('jobs')->insert(['queue' => 'default', 'payload' => 'SECRET-DO-NOT-PRINT',
                'attempts' => 0, 'reserved_at' => null, 'available_at' => now()->timestamp - 400,
                'created_at' => now()->timestamp - 400]);
        }
        DB::table('failed_jobs')->insert(['uuid' => (string) Str::uuid(),
            'connection' => 'database', 'queue' => 'default', 'payload' => 'SECRET-FAILED',
            'exception' => 'PRIVATE-STACK', 'failed_at' => now()]);
        $result = $this->probe();
        $this->assertContains('queue_backlog', $result['signals']);
        $this->assertContains('queue_failed_jobs', $result['signals']);
        $this->assertStringNotContainsString('SECRET', json_encode($result));
        $this->assertStringNotContainsString('PRIVATE', json_encode($result));
        $this->assertDatabaseCount('jobs', 25);
        $this->assertDatabaseCount('failed_jobs', 1);
        $this->assertDatabaseCount('charger_commands', 0);
    }

    public function test_stale_queued_email_claims_fail_closed_without_retrying_delivery(): void
    {
        $health = app(OperationalHealth::class);
        $health->record('queue_worker');
        $health->record('scheduler');
        app(OperationalIncidents::class)->scan($health);
        $user = User::factory()->create();
        $id = (string) Str::uuid();
        DB::table('notification_email_outbox')->insert([
            'id' => $id, 'user_id' => $user->id, 'after_notification_id' => 0,
            'through_notification_id' => 1, 'consent_revision' => 1,
            'recipient_hash' => hash('sha256', $user->email),
            'configuration_hash' => str_repeat('d', 64),
            'state' => 'queued', 'evidence' => 'SIMULATED_EMAIL_HANDOFF',
            'created_at' => now()->subMinutes(16), 'updated_at' => now()->subMinutes(16),
        ]);
        $result = $this->probe();
        $this->assertSame('needs_attention', $result['status']);
        $this->assertContains('email_queue_overdue', $result['signals']);
        $this->assertSame(1, Artisan::call('operations:watchdog-check'));
        $this->assertStringNotContainsString($id, json_encode($result));
        $this->assertStringNotContainsString($user->email, json_encode($result));
        $this->assertDatabaseHas('notification_email_outbox', ['id' => $id, 'state' => 'queued']);
        $this->assertDatabaseCount('charger_commands', 0);
        $this->assertDatabaseCount('finance_payments', 0);
    }

    public function test_email_health_counts_claims_and_outcomes_in_one_database_snapshot(): void
    {
        $cutoff = now()->subMinutes(15);
        $fresh = $cutoff->copy()->addSecond();
        $cases = [
            ['outcome_unknown', $fresh, null],
            ['queued', $cutoff, null],
            ['queued', $fresh, null],
            ['claimed', $cutoff, $cutoff],
            ['claimed', $cutoff, null],
            ['claimed', $cutoff, $fresh],
            ['handed_off', $cutoff, $cutoff],
        ];
        foreach ($cases as [$state, $createdAt, $claimedAt]) {
            $user = User::factory()->create();
            DB::table('notification_email_outbox')->insert([
                'id' => (string) Str::uuid(), 'user_id' => $user->id, 'after_notification_id' => 0,
                'through_notification_id' => 1, 'consent_revision' => 1,
                'recipient_hash' => hash('sha256', $user->email),
                'configuration_hash' => str_repeat('d', 64),
                'state' => $state, 'evidence' => 'SIMULATED_EMAIL_HANDOFF',
                'claimed_at' => $claimedAt, 'created_at' => $createdAt, 'updated_at' => $createdAt,
            ]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $snapshot = app(EmailHandoffHealth::class)->snapshot();
            $reads = collect(DB::getQueryLog())->filter(
                fn (array $entry) => str_contains($entry['query'], 'notification_email_outbox'),
            );
            $this->assertCount(1, $reads);
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }

        $this->assertSame('available', $snapshot['state']);
        $this->assertSame(1, $snapshot['outcome_unknown']);
        $this->assertSame(1, $snapshot['overdue_queued']);
        $this->assertSame(2, $snapshot['stale_claimed']);
    }

    public function test_unknown_email_and_incident_store_are_not_treated_as_normal(): void
    {
        $health = app(OperationalHealth::class);
        $health->record('queue_worker');
        $health->record('scheduler');
        $user = User::factory()->create();
        DB::table('notification_email_outbox')->insert([
            'id' => (string) Str::uuid(), 'user_id' => $user->id, 'after_notification_id' => 0,
            'through_notification_id' => 1, 'consent_revision' => 1,
            'recipient_hash' => hash('sha256', $user->email), 'configuration_hash' => str_repeat('c', 64),
            'state' => 'outcome_unknown', 'evidence' => 'SIMULATED_EMAIL_HANDOFF',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertContains('email_handoff_needs_review', $this->probe()['signals']);
        app(OperationalIncidents::class)->scan($health);
        $this->assertContains('recorded_incidents_open', $this->probe()['signals']);
        Schema::drop('notification_email_outbox');
        $this->assertContains('email_handoff_metrics_unknown', $this->probe()['signals']);
        $this->assertContains('recorded_incidents_open', $this->probe()['signals']);
        Schema::drop('operational_incident_states');
        $this->assertContains('incident_store_unavailable', $this->probe()['signals']);
        $this->assertSame(1, Artisan::call('operations:watchdog-check'));
    }

    public function test_disabled_monitoring_and_unsupported_queue_fail_closed(): void
    {
        config(['operational_health.enabled' => false, 'queue.default' => 'sync']);
        $result = $this->probe();
        $this->assertSame('needs_attention', $result['status']);
        $this->assertContains('monitoring_disabled', $result['signals']);
        $this->assertContains('queue_metrics_unknown', $result['signals']);
        $this->assertSame(1, Artisan::call('operations:watchdog-check'));
    }
}
