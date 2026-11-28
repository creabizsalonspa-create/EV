<?php

namespace Tests\Feature;

use App\Models\InboxNotification;
use App\Models\User;
use App\Services\Notifications\EmailNotices;
use App\Services\Operations\IncidentAlertRouting;
use App\Services\Operations\OperationalHealth;
use App\Services\Operations\OperationalIncidents;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class IncidentAlertRoutingTest extends TestCase
{
    use RefreshDatabase;

    public function test_current_admin_opt_in_routes_each_unreviewed_occurrence_once_without_external_action(): void
    {
        config(['operational_health.enabled' => true]);
        Mail::fake();
        $url = '/api/admin/operational-health/alert-preference';
        $admin = User::factory()->create(['role' => 'admin']);
        $other = User::factory()->create(['role' => 'admin']);
        $vendor = User::factory()->create(['role' => 'vendor']);
        $this->putJson($url, ['enabled' => true])->assertUnauthorized();
        $this->actingAs($vendor)->putJson($url, ['enabled' => true])->assertForbidden();
        $this->actingAs($admin)->putJson($url, ['enabled' => true, 'user_id' => $other->id])->assertUnprocessable();
        $this->putJson($url, ['enabled' => true])->assertOk()->assertJsonPath('consent_current', true);
        $this->putJson($url, ['enabled' => true])->assertOk();
        $this->assertSame(1, DB::table('audit_events')->where('action', 'operations.alerts_opt_in')->count());
        $health = app(OperationalHealth::class);
        $scanner = app(OperationalIncidents::class);
        $routing = app(IncidentAlertRouting::class);
        $health->record('scheduler');
        $this->travel(180)->seconds();
        $scanner->scan($health);
        $this->assertSame(['notices_created' => 1, 'external_actions' => false], $routing->run(1));
        $this->assertSame(0, $routing->run(1)['notices_created']);
        $this->assertSame(1, InboxNotification::visibleTo($admin)->where('kind', 'operations.incident.review')->count());
        $this->assertSame(0, InboxNotification::visibleTo($other)->where('kind', 'operations.incident.review')->count());
        $this->putJson($url, ['enabled' => false])->assertOk()->assertJsonPath('consent_current', false);
        $health->record('scheduler');
        $scanner->scan($health);
        $this->travel(180)->seconds();
        $scanner->scan($health);
        $this->assertSame(0, $routing->run()['notices_created']);
        $this->putJson($url, ['enabled' => true])->assertOk();
        $this->assertSame(1, $routing->run()['notices_created']);
        $this->assertSame(0, $routing->run()['notices_created']);
        DB::table('users')->where('id', $admin->id)->increment('auth_epoch');
        $this->assertSame(0, InboxNotification::visibleTo($admin)->where('kind', 'operations.incident.review')->count());
        $this->assertSame(0, $routing->run()['notices_created']);
        $this->assertDatabaseCount('operational_incident_alert_receipts', 2);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('finance_payments', 0);
        $this->assertDatabaseCount('charger_commands', 0);
        Mail::assertNothingSent();
    }

    public function test_local_alerts_do_not_trigger_email_even_with_independent_email_consent_and_stale_scans_do_not_route(): void
    {
        config(['operational_health.enabled' => true, 'notification_email.enabled' => true,
            'app.url' => 'https://platform.example', 'mail.default' => 'array', 'mail.from.address' => 'notices@example.test']);
        Mail::fake();
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->putJson('/api/notifications/email', [
            'enabled' => true, 'confirmed' => true, 'expected_revision' => '0',
        ])->assertOk();
        $this->putJson('/api/admin/operational-health/alert-preference', ['enabled' => true])->assertOk();
        $health = app(OperationalHealth::class);
        $scanner = app(OperationalIncidents::class);
        $routing = app(IncidentAlertRouting::class);
        $health->record('scheduler');
        $this->travel(180)->seconds();
        $scanner->scan($health);
        $this->travel(151)->seconds();
        $this->assertSame(0, $routing->run()['notices_created']);
        $scanner->scan($health);
        $this->assertSame(1, $routing->run()['notices_created']);
        $email = app(EmailNotices::class);
        $this->assertSame(0, $email->prepare());
        $this->assertDatabaseCount('notification_email_outbox', 0);
        Mail::assertNothingSent();
        InboxNotification::create(['user_id' => $admin->id, 'kind' => 'other.account_event']);
        $this->assertSame(1, $email->prepare());
    }

    public function test_acknowledged_incident_and_disabled_monitoring_do_not_route(): void
    {
        config(['operational_health.enabled' => true]);
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->putJson('/api/admin/operational-health/alert-preference', ['enabled' => true])->assertOk();
        $health = app(OperationalHealth::class);
        $health->record('scheduler');
        $this->travel(180)->seconds();
        app(OperationalIncidents::class)->scan($health);
        $this->postJson('/api/admin/operational-health/acknowledge', [
            'condition_key' => 'scheduler_stale', 'transition_revision' => 1,
        ])->assertOk();
        $this->assertSame(0, app(IncidentAlertRouting::class)->run()['notices_created']);
        config(['operational_health.enabled' => false]);
        $this->assertSame(0, app(IncidentAlertRouting::class)->run()['notices_created']);
        $this->assertDatabaseCount('operational_incident_alert_receipts', 0);
    }
}
