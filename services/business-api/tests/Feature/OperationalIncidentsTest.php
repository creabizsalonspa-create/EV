<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Operations\OperationalHealth;
use App\Services\Operations\OperationalIncidents;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class OperationalIncidentsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['operational_health.enabled' => true, 'queue.default' => 'database',
            'queue.connections.database.connection' => null, 'queue.connections.database.queue' => 'default']);
        $this->travelTo(CarbonImmutable::parse('2026-11-15T12:00:00Z'));
    }

    public function test_operator_review_is_occurrence_bound_idempotent_and_never_resolves_incidents(): void
    {
        $health = app(OperationalHealth::class);
        $scanner = app(OperationalIncidents::class);
        $health->record('scheduler');
        $this->travel(180)->seconds();
        $scanner->scan($health);
        $url = '/api/admin/operational-health/acknowledge';
        $body = ['condition_key' => 'scheduler_stale', 'transition_revision' => 1];
        $this->postJson($url, $body)->assertUnauthorized();
        foreach (['customer', 'vendor'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))->postJson($url, $body)->assertForbidden();
        }
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->postJson($url, $body + ['external_actions' => true])
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('incident_state', 'open')->assertJsonPath('external_actions', false);
        $this->postJson($url, $body)->assertOk()->assertJsonPath('transition_revision', 1);
        $this->assertDatabaseCount('operational_incident_acknowledgements', 1);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'operations.incident_acknowledged')->count());
        $this->assertDatabaseCount('operational_incident_events', 1);
        $this->getJson('/api/admin/operational-health')->assertOk()
            ->assertJsonPath('incidents.open.0.transition_revision', 1)
            ->assertJsonPath('incidents.open.0.state', 'open')
            ->assertJsonStructure(['incidents' => ['open' => [['acknowledged_at']]]]);
        $this->postJson($url, array_replace($body, ['condition_key' => 'unknown']))->assertUnprocessable();
        $this->postJson($url, array_replace($body, ['transition_revision' => 0]))->assertUnprocessable();
        $health->record('scheduler');
        $scanner->scan($health);
        $this->postJson($url, $body)->assertStatus(409);
        $this->travel(180)->seconds();
        $scanner->scan($health);
        $this->postJson($url, $body)->assertStatus(409);
        $this->getJson('/api/admin/operational-health')->assertOk()
            ->assertJsonPath('incidents.open.0.transition_revision', 3)
            ->assertJsonPath('incidents.open.0.acknowledged_at', null);
        DB::table('users')->where('id', $admin->id)->increment('auth_epoch');
        $this->postJson($url, array_replace($body, ['transition_revision' => 3]))->assertStatus(409);
        $this->assertDatabaseCount('operational_incident_acknowledgements', 1);
        $this->assertDatabaseCount('operational_incident_events', 3);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('charger_commands', 0);
        $this->assertDatabaseCount('finance_payments', 0);
    }

    public function test_unknown_startup_does_not_fabricate_alerts_or_recovery(): void
    {
        $scanner = app(OperationalIncidents::class);
        $this->assertSame(['skipped' => false, 'opened' => 0, 'resolved' => 0, 'uncertain' => 2, 'external_actions' => false],
            $scanner->scan(app(OperationalHealth::class)));
        $this->assertDatabaseCount('operational_incident_events', 0);
        $this->assertSame([], $scanner->summary()['open']);
        config(['operational_health.enabled' => false]);
        $this->assertTrue($scanner->scan(app(OperationalHealth::class))['skipped']);
        $this->assertDatabaseCount('operational_incident_events', 0);
    }

    public function test_committed_scan_liveness_does_not_advance_when_monitoring_is_disabled(): void
    {
        $scanner = app(OperationalIncidents::class);
        $this->assertSame('unknown', $scanner->summary()['scanner']['state']);
        $scanner->scan(app(OperationalHealth::class));
        $this->assertSame('recent', $scanner->summary()['scanner']['state']);
        $completed = DB::table('operational_incident_scan_locks')->where('id', 1)->value('last_completed_at');
        $this->assertNotNull($completed);
        $this->travel(180)->seconds();
        config(['operational_health.enabled' => false]);
        $this->assertTrue($scanner->scan(app(OperationalHealth::class))['skipped']);
        $this->assertSame($completed, DB::table('operational_incident_scan_locks')->where('id', 1)->value('last_completed_at'));
        $this->assertSame('stale', $scanner->summary()['scanner']['state']);
        $this->assertDatabaseCount('operational_incident_events', 0);
    }

    public function test_expired_observations_open_once_and_fresh_observations_resolve_once(): void
    {
        $health = app(OperationalHealth::class);
        $scanner = app(OperationalIncidents::class);
        $this->assertTrue($health->record('queue_worker'));
        $this->assertTrue($health->record('scheduler'));
        $this->travel(180)->seconds();
        $first = $scanner->scan($health);
        $this->assertSame(2, $first['opened']);
        $this->assertSame(0, $scanner->scan($health)['opened']);
        $this->assertDatabaseCount('operational_incident_events', 2);
        $this->assertCount(2, $scanner->summary()['open']);
        $this->assertTrue($health->record('queue_worker'));
        $this->assertTrue($health->record('scheduler'));
        $this->assertSame(2, $scanner->scan($health)['resolved']);
        $this->assertSame(0, $scanner->scan($health)['resolved']);
        $this->assertSame([], $scanner->summary()['open']);
        $resolved = $scanner->summary()['resolved'];
        $this->assertSame(['queue_worker_stale', 'scheduler_stale'], array_column($resolved, 'condition_key'));
        $this->assertNotEmpty($resolved[0]->resolved_at);
        $this->assertDatabaseCount('operational_incident_events', 4);
        $this->travel(180)->seconds();
        $this->assertSame(2, $scanner->scan($health)['opened']);
        $this->assertDatabaseHas('operational_incident_states', ['condition_key' => 'scheduler_stale', 'state' => 'open', 'transitions' => 3]);
        $this->assertSame([], $scanner->summary()['resolved']);
        $this->assertDatabaseCount('operational_incident_events', 6);
    }

    public function test_private_payloads_are_never_duplicated_into_incidents_and_no_work_is_retried(): void
    {
        $health = app(OperationalHealth::class);
        $scanner = app(OperationalIncidents::class);
        for ($i = 0; $i < 25; $i++) {
            DB::table('jobs')->insert(['queue' => 'default', 'payload' => 'SECRET-QUEUE-JOB', 'attempts' => 0,
                'reserved_at' => null, 'available_at' => now()->timestamp - 500, 'created_at' => now()->timestamp - 500]);
        }
        DB::table('failed_jobs')->insert(['uuid' => (string) Str::uuid(), 'connection' => 'database',
            'queue' => 'default', 'payload' => 'PRIVATE-FAILED-PAYLOAD', 'exception' => 'SECRET-SMTP-KEY',
            'failed_at' => now()]);
        $this->assertSame(2, $scanner->scan($health)['opened']);
        $this->assertSame(0, $scanner->scan($health)['opened']);
        $admin = User::factory()->create(['role' => 'admin']);
        $response = $this->actingAs($admin)->getJson('/api/admin/operational-health')
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('incidents.automatic_retry', false)
            ->assertJsonPath('incidents.production_recovery_verified', false);
        $this->assertStringNotContainsString('SECRET', $response->getContent());
        $this->assertStringNotContainsString('PRIVATE-FAILED', $response->getContent());
        $this->assertCount(2, $response->json('incidents.open'));
        $this->assertDatabaseCount('jobs', 25);
        $this->assertDatabaseCount('failed_jobs', 1);
        DB::table('jobs')->where('queue', 'default')->delete();
        DB::table('failed_jobs')->where('queue', 'default')->delete();
        $this->assertSame(2, $scanner->scan($health)['resolved']);
        $this->assertDatabaseCount('operational_incident_events', 4);
    }

    public function test_unknown_metrics_and_disabled_monitoring_never_close_open_incidents(): void
    {
        $scanner = app(OperationalIncidents::class);
        $health = app(OperationalHealth::class);
        $health->record('scheduler');
        $this->travel(180)->seconds();
        $scanner->scan($health);
        $this->assertCount(1, $scanner->summary()['open']);
        config(['operational_health.enabled' => false]);
        $scanner->scan($health);
        $this->assertCount(1, $scanner->summary()['open']);
        config(['operational_health.enabled' => true]);
        Schema::drop('operational_heartbeats');
        $this->assertSame(0, $scanner->scan($health)['resolved']);
        $this->assertCount(1, $scanner->summary()['open']);
    }

    public function test_stale_or_unknown_email_handoff_opens_review_without_resending(): void
    {
        $user = User::factory()->create();
        $scanner = app(OperationalIncidents::class);
        $health = app(OperationalHealth::class);
        $receipt = (string) Str::uuid();
        $row = [
            'id' => $receipt, 'user_id' => $user->id, 'after_notification_id' => 0,
            'through_notification_id' => 1, 'consent_revision' => 1,
            'recipient_hash' => hash('sha256', $user->email), 'configuration_hash' => str_repeat('a', 64),
            'state' => 'claimed', 'evidence' => 'SIMULATED_EMAIL_HANDOFF', 'claimed_at' => now()->subMinutes(20),
            'created_at' => now()->subMinutes(21), 'updated_at' => now()->subMinutes(20),
        ];
        DB::table('notification_email_outbox')->insert($row);
        $metrics = $health->report()['email_handoff'];
        $this->assertSame(1, $metrics['stale_claimed']);
        $this->assertSame(0, $metrics['outcome_unknown']);
        $this->assertFalse($metrics['delivery_confirmed']);
        $this->assertFalse($metrics['automatic_retry']);
        $this->assertSame(1, $scanner->scan($health)['opened']);
        $this->assertSame(0, $scanner->scan($health)['opened']);
        $this->assertDatabaseHas('notification_email_outbox', ['id' => $receipt, 'state' => 'claimed']);
        $response = $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->getJson('/api/admin/operational-health')->assertOk()
            ->assertJsonPath('email_handoff.stale_claimed', 1)
            ->assertJsonPath('incidents.open.0.condition_key', 'email_handoff_uncertain');
        $this->assertStringNotContainsString($user->email, $response->getContent());
        $this->assertStringNotContainsString($receipt, $response->getContent());
        DB::table('notification_email_outbox')->where('id', $receipt)
            ->update(['state' => 'outcome_unknown', 'completed_at' => now(), 'updated_at' => now()]);
        $this->assertSame(1, $health->report()['email_handoff']['outcome_unknown']);
        $this->assertSame(0, $scanner->scan($health)['resolved']);
        // Explicit synthetic operator evidence correction, not a transport retry.
        DB::table('notification_email_outbox')->where('id', $receipt)->update(['state' => 'cancelled', 'updated_at' => now()]);
        $this->assertSame(1, $scanner->scan($health)['resolved']);
        $this->assertSame([], $scanner->summary()['open']);
        $this->assertDatabaseCount('operational_incident_events', 2);
        $this->assertDatabaseCount('charger_commands', 0);
        $this->assertDatabaseCount('finance_payments', 0);
    }

    public function test_email_digest_queued_too_long_opens_review_without_sending_or_replaying(): void
    {
        $health = app(OperationalHealth::class);
        $scanner = app(OperationalIncidents::class);
        $user = User::factory()->create();
        $receipt = (string) Str::uuid();
        DB::table('notification_email_outbox')->insert([
            'id' => $receipt, 'user_id' => $user->id, 'after_notification_id' => 0,
            'through_notification_id' => 1, 'consent_revision' => 1,
            'recipient_hash' => hash('sha256', $user->email),
            'configuration_hash' => str_repeat('e', 64),
            'state' => 'queued', 'evidence' => 'SIMULATED_EMAIL_HANDOFF',
            'created_at' => now()->subMinutes(17), 'updated_at' => now()->subMinutes(17),
        ]);
        $metrics = $health->report()['email_handoff'];
        $this->assertSame(1, $metrics['overdue_queued']);
        $this->assertSame(0, $metrics['outcome_unknown']);
        $this->assertSame(0, $metrics['stale_claimed']);
        $this->assertSame(1, $scanner->scan($health)['opened']);
        $this->assertSame('email_queue_backlog', $scanner->summary()['open'][0]->condition_key);
        $this->assertSame(0, $scanner->scan($health)['opened']);
        $this->assertDatabaseHas('notification_email_outbox', ['id' => $receipt, 'state' => 'queued']);
        $this->assertDatabaseCount('operational_incident_events', 1);
        $this->assertDatabaseCount('charger_commands', 0);
        $this->assertDatabaseCount('finance_payments', 0);
        $response = $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->getJson('/api/admin/operational-health')->assertOk()
            ->assertJsonPath('email_handoff.overdue_queued', 1)
            ->assertJsonPath('incidents.open.0.condition_key', 'email_queue_backlog');
        $this->assertStringNotContainsString($receipt, $response->getContent());
        $this->assertStringNotContainsString($user->email, $response->getContent());
        // Synthetic explicit operator resolution, never an automated resend.
        DB::table('notification_email_outbox')->where('id', $receipt)
            ->update(['state' => 'cancelled', 'updated_at' => now()]);
        $this->assertSame(1, $scanner->scan($health)['resolved']);
        $this->assertSame([], $scanner->summary()['open']);
        $this->assertDatabaseCount('operational_incident_events', 2);
    }

    public function test_missing_email_receipt_store_does_not_falsely_resolve_existing_alert(): void
    {
        $scanner = app(OperationalIncidents::class);
        $health = app(OperationalHealth::class);
        $user = User::factory()->create();
        DB::table('notification_email_outbox')->insert([
            'id' => (string) Str::uuid(), 'user_id' => $user->id, 'after_notification_id' => 0,
            'through_notification_id' => 1, 'consent_revision' => 1,
            'recipient_hash' => hash('sha256', $user->email), 'configuration_hash' => str_repeat('b', 64),
            'state' => 'outcome_unknown', 'evidence' => 'SIMULATED_EMAIL_HANDOFF',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertSame(1, $scanner->scan($health)['opened']);
        Schema::drop('notification_email_outbox');
        $this->assertSame('unavailable', $health->report()['email_handoff']['state']);
        $this->assertNull($health->report()['email_handoff']['outcome_unknown']);
        $this->assertSame(0, $scanner->scan($health)['resolved']);
        $this->assertDatabaseHas('operational_incident_states', [
            'condition_key' => 'email_handoff_uncertain', 'state' => 'open',
        ]);
    }

    public function test_missing_incident_store_is_unknown_without_breaking_admin_transaction(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Schema::drop('operational_incident_states');
        $response = $this->actingAs($admin)->getJson('/api/admin/operational-health')->assertOk()
            ->assertJsonPath('incidents.store_readable', false)
            ->assertJsonPath('incidents.scanner.state', 'unknown')
            ->assertJsonPath('incidents.automatic_retry', false)
            ->assertJsonPath('incidents.open', []);
        $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
        // The missing incident table cannot poison the caller's PostgreSQL
        // transaction or trigger a queue/payment/charger action.
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertDatabaseCount('charger_commands', 0);
        $this->assertDatabaseCount('finance_payments', 0);
    }

    public function test_only_authenticated_current_admin_can_read_timeline(): void
    {
        $url = '/api/admin/operational-health';
        $this->getJson($url)->assertUnauthorized();
        foreach (['customer', 'vendor'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))->getJson($url)->assertForbidden();
        }
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->getJson($url)->assertOk()->assertJsonPath('incidents.store_readable', true)
            ->assertJsonPath('incidents.external_alert_delivery', false);
        DB::table('users')->where('id', $admin->id)->update(['auth_epoch' => $admin->auth_epoch + 1]);
        $this->getJson($url)->assertStatus(409);
    }
}
