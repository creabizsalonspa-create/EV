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
        $this->assertDatabaseCount('operational_incident_events', 4);
        $this->travel(180)->seconds();
        $this->assertSame(2, $scanner->scan($health)['opened']);
        $this->assertDatabaseHas('operational_incident_states', ['condition_key' => 'scheduler_stale', 'state' => 'open', 'transitions' => 3]);
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
