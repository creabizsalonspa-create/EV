<?php

namespace Tests\Feature;

use App\Models\InboxNotification;
use App\Models\Station;
use App\Models\User;
use App\Models\VendorOps\VendorCharger;
use App\Models\VendorOps\VendorCompany;
use App\Models\VendorOps\VendorSite;
use App\Services\Charging\FaultMonitor;
use App\Services\Charging\FaultReminderEscalator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FaultReminderEscalatorTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        config([
            'csms.fault_worker_enabled' => true,
            'csms.session_bridge_enabled' => true,
        ]);
        $owner = User::factory()->create(['role' => 'vendor']);
        $station = Station::create([
            'vendor_id' => $owner->id, 'name' => 'Reminder isolated fixture',
            'country' => 'AE', 'currency' => 'AED', 'timezone' => 'Asia/Dubai',
            'latitude' => 25, 'longitude' => 55, 'source' => 'simulator',
            'connector_type' => 'CCS2', 'power_kw' => 50,
        ]);
        $company = VendorCompany::create([
            'owner_id' => $owner->id, 'legal_name' => 'Synthetic reminder vendor',
            'country' => 'AE', 'trade_license_number' => 'SYNTHETIC-ONLY',
            'license_expires_on' => now()->addYear()->toDateString(),
            'status' => 'approved',
        ]);
        $site = VendorSite::create([
            'company_id' => $company->id, 'station_id' => $station->id,
            'name' => 'Reminder source site', 'country' => 'AE',
            'timezone' => 'Asia/Dubai', 'address' => 'Synthetic',
            'latitude' => 25, 'longitude' => 55,
        ]);
        $charger = VendorCharger::create([
            'company_id' => $company->id, 'site_id' => $site->id,
            'asset_reference' => 'TEST-REMINDER', 'manufacturer' => 'Synthetic',
            'model' => 'Simulated', 'source' => 'simulator', 'ocpp_protocol' => '1.6',
        ]);
        $bindingId = DB::table('csms_bindings')->insertGetId([
            'station_id' => $station->id, 'vendor_charger_id' => $charger->id,
            'identifier' => 'ev-test-reminder-'.$charger->id, 'protocol' => '1.6',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $binding = DB::table('csms_bindings')->find($bindingId);
        $observedAt = now()->subMinutes(21);
        $observation = DB::table('charging_status_observations')->insertGetId([
            'binding_id' => $bindingId,
            'source_generation' => FaultMonitor::generation($binding),
            'source_event_id' => 1,
            'fingerprint' => str_repeat('a', 64),
            'evse_number' => 1, 'connector_number' => 1,
            'status' => 'Faulted', 'error_code' => 'GroundFailure',
            'occurred_at' => $observedAt, 'created_at' => $observedAt,
        ]);
        $alert = DB::table('charging_fault_alerts')->insertGetId([
            'observation_id' => $observation,
            'created_at' => $observedAt,
            'updated_at' => $observedAt,
        ]);

        return compact('owner', 'company', 'station', 'charger', 'bindingId', 'observation', 'alert');
    }

    public function test_simulator_fault_reminds_owner_once_without_hardware_or_payment_side_effects(): void
    {
        $f = $this->fixture();
        $other = User::factory()->create(['role' => 'vendor']);
        $first = app(FaultReminderEscalator::class)->scan();
        $this->assertSame(1, $first['reminded']);
        $this->assertSame(0, app(FaultReminderEscalator::class)->scan()['reminded']);
        $this->assertSame(1, DB::table('charging_fault_reminders')->count());
        $note = InboxNotification::where('kind', 'charging.fault.reminder.simulated')
            ->firstOrFail();
        $this->assertSame($f['owner']->id, $note->user_id);
        $this->assertSame($f['alert'], $note->metadata['alert_id']);
        $this->assertSame('SIMULATED_SOURCE_OBSERVED', $note->metadata['evidence']);
        $this->assertFalse($note->metadata['physical_verification']);
        $this->actingAs($f['owner'])->getJson('/api/notifications?category=charging')
            ->assertOk()->assertJsonPath('total', 1);
        $this->actingAs($other)->getJson('/api/notifications?category=charging')
            ->assertOk()->assertJsonPath('total', 0);
        $this->assertDatabaseCount('charger_commands', 0);
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    public function test_rotating_cursor_never_starves_an_enabled_vendor_behind_opted_out_faults(): void
    {
        $first = $this->fixture();
        $this->actingAs($first['owner'])->putJson('/api/notifications/preferences', [
            'default_category' => 'charging',
            'default_unread_only' => false,
            'remind_unresolved_simulator_faults' => false,
        ])->assertOk();
        $second = $this->fixture();
        $service = app(FaultReminderEscalator::class);

        $firstPass = $service->scan(1);
        $this->assertSame(1, $firstPass['examined']);
        $this->assertSame(0, $firstPass['reminded']);

        $secondPass = $service->scan(1);
        $this->assertSame(1, $secondPass['examined']);
        $this->assertSame(1, $secondPass['reminded']);
        $this->assertSame($second['owner']->id,
            InboxNotification::where('kind', 'charging.fault.reminder.simulated')
                ->firstOrFail()->user_id);
        $this->assertSame($second['alert'], (int) DB::table(
            'charging_fault_reminder_scan_progress')->where('id', 1)->value('after_alert_id'));

        // Wrapping must consume an eligible bounded page immediately,
        // not spend a whole scheduler interval resetting the cursor.
        $wrapped = $service->scan(1);
        $this->assertSame(1, $wrapped['examined']);
        $this->assertSame(0, $wrapped['reminded']);
        $this->assertSame($first['alert'], (int) DB::table('charging_fault_reminder_scan_progress')
            ->where('id', 1)->value('after_alert_id'));
        $this->assertSame(0, $service->scan(1)['reminded']);
        $this->assertDatabaseCount('charging_fault_reminders', 1);
        $this->assertDatabaseCount('inbox_notifications', 1);
    }

    public function test_bad_historical_timestamp_fails_closed_without_starving_next_vendor(): void
    {
        $invalid = $this->fixture();
        DB::table('charging_status_observations')->where('id', $invalid['observation'])
            ->update(['occurred_at' => 'invalid-not-a-date']);
        $valid = $this->fixture();

        $result = app(FaultReminderEscalator::class)->scan(2);
        $this->assertSame(2, $result['examined']);
        $this->assertSame(1, $result['reminded']);
        $this->assertDatabaseCount('charging_fault_reminders', 1);
        $this->assertSame($valid['owner']->id,
            InboxNotification::where('kind', 'charging.fault.reminder.simulated')
                ->firstOrFail()->user_id);
        $this->assertSame(0, app(FaultReminderEscalator::class)->scan(2)['reminded']);
    }

    public function test_relative_and_overflowed_fault_times_never_trigger_reminders(): void
    {
        $fixture = $this->fixture();
        $worker = app(FaultReminderEscalator::class);
        $invalidTimes = [
            'yesterday',
            'now',
            '2020-02-30 10:00:00',
            '2020-01-01 24:00:00',
        ];
        foreach ($invalidTimes as $untrusted) {
            DB::table('charging_status_observations')
                ->where('id', $fixture['observation'])
                ->update(['occurred_at' => $untrusted]);
            $this->assertSame(0, $worker->scan(1)['reminded']);
            $this->assertDatabaseCount('charging_fault_reminders', 0);
        }
        DB::table('charging_status_observations')
            ->where('id', $fixture['observation'])
            ->update(['occurred_at' => now()->subMinutes(31)]);
        $this->assertSame(1, $worker->scan(1)['reminded']);
        $this->assertDatabaseCount('charging_fault_reminders', 1);
    }

    public function test_each_owner_delay_is_respected_without_starving_other_vendors(): void
    {
        $slow = $this->fixture();
        $this->actingAs($slow['owner'])->putJson('/api/notifications/preferences', [
            'default_category' => 'charging',
            'default_unread_only' => false,
            'remind_unresolved_simulator_faults' => true,
            'fault_reminder_delay_minutes' => 30,
        ])->assertOk();
        $fast = $this->fixture();
        $this->actingAs($fast['owner'])->putJson('/api/v1/notifications/preferences', [
            'default_category' => 'charging',
            'default_unread_only' => false,
            'remind_unresolved_simulator_faults' => true,
            'fault_reminder_delay_minutes' => 15,
        ])->assertOk();

        $worker = app(FaultReminderEscalator::class);
        $scan = $worker->scan(2);
        $this->assertSame(2, $scan['examined']);
        $this->assertSame(1, $scan['reminded']);
        $this->assertSame($fast['owner']->id, InboxNotification::where(
            'kind', 'charging.fault.reminder.simulated')->firstOrFail()->user_id);
        $this->assertDatabaseCount('charging_fault_reminders', 1);

        DB::table('charging_status_observations')->where('id', $slow['observation'])
            ->update(['occurred_at' => now()->subMinutes(31)]);
        $this->assertSame(1, $worker->scan(2)['reminded']);
        $this->assertDatabaseCount('charging_fault_reminders', 2);
        $this->assertSame(1, InboxNotification::where('user_id', $slow['owner']->id)
            ->where('kind', 'charging.fault.reminder.simulated')->count());
        $this->assertSame(0, $worker->scan(2)['reminded']);
        $this->assertDatabaseCount('charger_commands', 0);
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    public function test_explicit_admin_escalation_is_private_durable_and_independent_of_owner_opt_out(): void
    {
        $f = $this->fixture();
        $admin = User::factory()->create(['role' => 'admin']);
        $otherAdmin = User::factory()->create(['role' => 'admin']);
        config(['csms.fault_escalation_admin_user_id' => $admin->id]);
        DB::table('charging_status_observations')->where('id', $f['observation'])
            ->update(['occurred_at' => now()->subMinutes(65)]);
        $this->actingAs($f['owner'])->putJson('/api/notifications/preferences', [
            'default_category' => 'charging',
            'default_unread_only' => false,
            'remind_unresolved_simulator_faults' => false,
        ])->assertOk();
        $service = app(FaultReminderEscalator::class);
        $first = $service->scan();
        $this->assertSame(0, $first['reminded']);
        $this->assertSame(1, $first['escalated']);
        $this->assertSame(0, $service->scan()['escalated']);
        $this->assertDatabaseCount('charging_fault_admin_escalations', 1);
        $this->assertDatabaseCount('inbox_notifications', 1);
        $this->assertDatabaseHas('charging_fault_admin_escalations', [
            'alert_id' => $f['alert'], 'admin_user_id' => $admin->id,
        ]);
        $note = InboxNotification::firstOrFail();
        $this->assertSame($admin->id, $note->user_id);
        $this->assertSame('charging.fault.escalated.simulated', $note->kind);
        $this->assertFalse($note->metadata['physical_verification']);
        $this->assertTrue($note->metadata['admin_escalation_only']);
        $this->actingAs($admin)->getJson('/api/notifications?category=charging')
            ->assertOk()->assertJsonPath('total', 1);
        $this->actingAs($otherAdmin)->getJson('/api/notifications?category=charging')
            ->assertOk()->assertJsonPath('total', 0);
        $this->actingAs($f['owner'])->getJson('/api/notifications?category=charging')
            ->assertOk()->assertJsonPath('total', 0);
        $this->assertDatabaseCount('charger_commands', 0);
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    public function test_escalation_closes_once_on_operator_acknowledgment_without_claiming_repair(): void
    {
        $f = $this->fixture();
        $admin = User::factory()->create(['role' => 'admin']);
        config(['csms.fault_escalation_admin_user_id' => $admin->id]);
        DB::table('charging_status_observations')->where('id', $f['observation'])
            ->update(['occurred_at' => now()->subMinutes(65)]);
        $service = app(FaultReminderEscalator::class);
        $this->assertSame(1, $service->scan()['escalated']);
        DB::table('charging_fault_alerts')->where('id', $f['alert'])
            ->update(['acknowledged_at' => now(), 'acknowledged_by' => $f['owner']->id]);
        $resolution = $service->scan();
        $this->assertSame(1, $resolution['closed']);
        $this->assertSame(0, $resolution['escalated']);
        $this->assertSame(0, $service->scan()['closed']);
        $this->assertDatabaseHas('charging_fault_admin_escalations', [
            'alert_id' => $f['alert'], 'resolution_source' => 'operator_acknowledged',
        ]);
        $closure = InboxNotification::where('kind',
            'charging.fault.escalation_closed.simulated')->firstOrFail();
        $this->assertSame($admin->id, $closure->user_id);
        $this->assertSame('SIMULATED_OPERATOR_ACKNOWLEDGED',
            $closure->metadata['evidence']);
        $this->assertFalse($closure->metadata['physical_verification']);
        $this->actingAs($admin)->getJson('/api/notifications?category=charging')
            ->assertOk()->assertJsonPath('total', 2);
        $this->assertDatabaseCount('charger_commands', 0);
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    public function test_verified_source_recovery_closes_escalation_and_bad_recovery_does_not(): void
    {
        $f = $this->fixture();
        $admin = User::factory()->create(['role' => 'admin']);
        config(['csms.fault_escalation_admin_user_id' => $admin->id]);
        DB::table('charging_status_observations')->where('id', $f['observation'])
            ->update(['occurred_at' => now()->subMinutes(65)]);
        $worker = app(FaultReminderEscalator::class);
        $this->assertSame(1, $worker->scan()['escalated']);
        $binding = DB::table('csms_bindings')->find($f['bindingId']);
        $bad = DB::table('charging_status_observations')->insertGetId([
            'binding_id' => $binding->id,
            'source_generation' => str_repeat('f', 64),
            'source_event_id' => 2, 'fingerprint' => str_repeat('f', 64),
            'evse_number' => 1, 'connector_number' => 1,
            'status' => 'Available', 'error_code' => 'NoError',
            'occurred_at' => now(), 'created_at' => now(),
        ]);
        DB::table('charging_fault_alerts')->where('id', $f['alert'])
            ->update(['recovery_observation_id' => $bad]);
        $this->assertSame(0, $worker->scan()['closed']);
        $this->assertDatabaseHas('charging_fault_admin_escalations', [
            'alert_id' => $f['alert'], 'resolution_source' => null,
        ]);
        DB::table('charging_status_observations')->where('id', $bad)
            ->update([
                'source_generation' => FaultMonitor::generation($binding),
                // A newer source event ID with an older timestamp is NOT
                // evidence of recovery after this fault occurred.
                'occurred_at' => now()->subMinutes(70),
            ]);
        $this->assertSame(0, $worker->scan()['closed']);
        DB::table('charging_status_observations')->where('id', $bad)
            ->update(['occurred_at' => now()->addMinutes(5)]);
        $this->assertSame(0, $worker->scan()['closed']);
        // Carbon::parse('') resolves to now. A blank retained source time
        // must not be misinterpreted as fresh physical or source recovery.
        $invalidTimes = [
            '', '   ', 'now', 'today',
            '2020-02-30 10:00:00', '2020-01-01 24:00:00',
        ];
        foreach ($invalidTimes as $invalidTime) {
            DB::table('charging_status_observations')->where('id', $bad)
                ->update(['occurred_at' => $invalidTime]);
            $this->assertSame(0, $worker->scan()['closed']);
            $this->assertDatabaseHas('charging_fault_admin_escalations', [
                'alert_id' => $f['alert'], 'resolution_source' => null,
            ]);
        }
        DB::table('charging_status_observations')->where('id', $bad)
            ->update(['occurred_at' => now()]);
        $this->assertSame(1, $worker->scan()['closed']);
        $this->assertSame(0, $worker->scan()['closed']);
        $this->assertDatabaseHas('charging_fault_admin_escalations', [
            'alert_id' => $f['alert'], 'resolution_source' => 'source_recovered',
        ]);
        $closure = InboxNotification::where('kind',
            'charging.fault.escalation_closed.simulated')->firstOrFail();
        $this->assertSame('source_recovered', $closure->metadata['resolution_source']);
        $this->assertSame('SIMULATED_SOURCE_OBSERVED', $closure->metadata['evidence']);
        $this->assertDatabaseCount('charger_commands', 0);
    }

    public function test_escalation_closure_is_retained_during_admin_demotion_but_stays_private(): void
    {
        $f = $this->fixture();
        $admin = User::factory()->create(['role' => 'admin']);
        config(['csms.fault_escalation_admin_user_id' => $admin->id]);
        DB::table('charging_status_observations')->where('id', $f['observation'])
            ->update(['occurred_at' => now()->subMinutes(65)]);
        $worker = app(FaultReminderEscalator::class);
        $this->assertSame(1, $worker->scan()['escalated']);
        $initial = InboxNotification::where('kind',
            'charging.fault.escalated.simulated')->firstOrFail()->id;

        DB::table('users')->where('id', $admin->id)->update(['role' => 'vendor']);
        DB::table('charging_fault_alerts')->where('id', $f['alert'])
            ->update(['acknowledged_at' => now(), 'acknowledged_by' => $f['owner']->id]);
        $this->assertSame(1, $worker->scan()['closed']);
        $this->assertSame(0, $worker->scan()['closed']);
        $closure = InboxNotification::where('kind',
            'charging.fault.escalation_closed.simulated')->firstOrFail();
        $this->assertSame($admin->id, $closure->user_id);
        $this->assertSame('operator_acknowledged', $closure->metadata['resolution_source']);
        // The original vendor reminder is independent of the two private
        // Admin escalation lifecycle notices.
        $this->assertDatabaseCount('inbox_notifications', 3);
        $this->assertSame(1, InboxNotification::where('kind',
            'charging.fault.reminder.simulated')->count());
        foreach (['/api/notifications', '/api/v1/notifications'] as $prefix) {
            $this->actingAs($admin)->getJson($prefix.'?category=charging')
                ->assertOk()->assertJsonPath('total', 0);
            $this->getJson($prefix.'/summary')->assertOk()
                ->assertJsonPath('unread_count', 0);
            $this->postJson($prefix.'/'.$closure->id.'/read')->assertNotFound();
            $this->postJson($prefix.'/'.$initial.'/read')->assertNotFound();
        }
        $this->assertNull($closure->fresh()->read_at);
        DB::table('users')->where('id', $admin->id)->update(['role' => 'admin']);
        $this->actingAs($admin)->getJson('/api/notifications?category=charging')
            ->assertOk()->assertJsonPath('total', 2);
        $this->postJson('/api/notifications/'.$closure->id.'/read')->assertOk();
        $this->assertNotNull($closure->fresh()->read_at);
        $this->assertDatabaseCount('charger_commands', 0);
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    public function test_demoted_admin_cannot_read_closure_escalation_or_mutate_its_receipt(): void
    {
        $f = $this->fixture();
        $admin = User::factory()->create(['role' => 'admin']);
        config(['csms.fault_escalation_admin_user_id' => $admin->id]);
        DB::table('charging_status_observations')->where('id', $f['observation'])
            ->update(['occurred_at' => now()->subMinutes(65)]);
        $worker = app(FaultReminderEscalator::class);
        $this->assertSame(1, $worker->scan()['escalated']);
        DB::table('charging_fault_alerts')->where('id', $f['alert'])
            ->update(['acknowledged_at' => now(), 'acknowledged_by' => $f['owner']->id]);
        $this->assertSame(1, $worker->scan()['closed']);
        $openId = InboxNotification::where('kind', 'charging.fault.escalated.simulated')
            ->firstOrFail()->id;
        $closedId = InboxNotification::where('kind', 'charging.fault.escalation_closed.simulated')
            ->firstOrFail()->id;

        $this->actingAs($admin)->getJson('/api/notifications?category=charging')
            ->assertOk()->assertJsonPath('total', 2);
        // The authenticated actor is still the old cached Admin session;
        // account role revocation is only reflected in the authoritative DB.
        $preparedRead = InboxNotification::visibleTo($admin)->whereKey($closedId);
        $preparedWrite = InboxNotification::visibleTo($admin)->whereKey($closedId);
        DB::table('users')->where('id', $admin->id)->update(['role' => 'vendor']);
        // Building an authorized query BEFORE revocation is not authority
        // to execute it afterward, including a previously prepared mutation.
        $this->assertFalse($preparedRead->exists());
        $this->assertSame(0, $preparedWrite->update(['read_at' => now()]));
        foreach (['/api/notifications', '/api/v1/notifications'] as $prefix) {
            $this->getJson($prefix.'?category=charging')
                ->assertOk()->assertJsonPath('total', 0);
            $this->getJson($prefix.'/summary')
                ->assertOk()->assertJsonPath('unread_count', 0);
            foreach ([$openId, $closedId] as $id) {
                $this->postJson($prefix.'/'.$id.'/read')->assertNotFound();
                $this->postJson($prefix.'/'.$id.'/unread')->assertNotFound();
            }
            $this->postJson($prefix.'/read-through', [
                'through_id' => $closedId,
            ])->assertOk()->assertJsonPath('marked_read', 0)
                ->assertJsonPath('remaining_unread', 0);
        }
        $this->assertNull(InboxNotification::findOrFail($openId)->read_at);
        $this->assertNull(InboxNotification::findOrFail($closedId)->read_at);
        DB::table('users')->where('id', $admin->id)->update(['role' => 'admin']);
        $this->assertTrue($preparedRead->exists());
        $this->getJson('/api/notifications?category=charging')
            ->assertOk()->assertJsonPath('total', 2);
        $this->postJson('/api/notifications/'.$closedId.'/read')->assertOk();
        $this->assertNotNull(InboxNotification::findOrFail($closedId)->read_at);
        $this->assertDatabaseCount('charger_commands', 0);
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    public function test_former_admin_cannot_view_or_mutate_historical_escalation(): void
    {
        $fixture = $this->fixture();
        $admin = User::factory()->create(['role' => 'admin']);
        config(['csms.fault_escalation_admin_user_id' => $admin->id]);
        DB::table('charging_status_observations')
            ->where('id', $fixture['observation'])
            ->update(['occurred_at' => now()->subMinutes(65)]);
        $this->assertSame(1, app(FaultReminderEscalator::class)->scan()['escalated']);
        $id = InboxNotification::where('kind', 'charging.fault.escalated.simulated')
            ->firstOrFail()->id;
        $this->actingAs($admin);
        $this->getJson('/api/notifications?category=charging')
            ->assertOk()->assertJsonPath('total', 1);

        $escalation = InboxNotification::findOrFail($id);
        $originalEscalation = $escalation->metadata;
        foreach ([true, 0, 'false', null] as $bogusPhysicalProof) {
            $escalation->update(['metadata' => array_replace(
                $originalEscalation, ['physical_verification' => $bogusPhysicalProof]
            )]);
            $this->getJson('/api/notifications?category=charging')
                ->assertOk()->assertJsonPath('total', 0);
            $this->postJson('/api/notifications/'.$id.'/read')->assertNotFound();
        }
        $escalation->update(['metadata' => array_diff_key(
            $originalEscalation, ['physical_verification' => true]
        )]);
        $this->getJson('/api/notifications?category=charging')
            ->assertOk()->assertJsonPath('total', 0);
        $escalation->update(['metadata' => $originalEscalation]);
        $this->getJson('/api/notifications?category=charging')
            ->assertOk()->assertJsonPath('total', 1);
        $rawEscalation = json_encode($originalEscalation, JSON_THROW_ON_ERROR);
        DB::table('inbox_notifications')->where('id', $id)->update([
            'metadata' => substr($rawEscalation, 0, -1)
                .',"physical_verification":true}',
        ]);
        $this->getJson('/api/notifications?category=charging')
            ->assertOk()->assertJsonPath('total', 0);
        DB::table('inbox_notifications')->where('id', $id)
            ->update(['metadata' => $rawEscalation]);
        foreach (['live', 'SIMULATOR', null] as $source) {
            $escalation->update(['metadata' => array_replace(
                $originalEscalation, ['source' => $source]
            )]);
            $this->getJson('/api/notifications?category=charging')
                ->assertOk()->assertJsonPath('total', 0);
        }
        $escalation->update(['metadata' => array_diff_key(
            $originalEscalation, ['source' => true]
        )]);
        $this->getJson('/api/notifications?category=charging')
            ->assertOk()->assertJsonPath('total', 0);
        $escalation->update(['metadata' => $originalEscalation]);

        // Role is deliberately guarded on User: change the database
        // directly to model a remote operator revocation with a cached auth
        // session that still contains the old Admin attributes.
        DB::table('users')->where('id', $admin->id)
            ->update(['role' => 'vendor']);
        foreach (['/api/notifications', '/api/v1/notifications'] as $base) {
            $this->getJson($base.'?category=charging')
                ->assertOk()->assertJsonPath('total', 0);
            $this->getJson($base.'/summary')->assertOk()
                ->assertJsonPath('unread_count', 0);
            $this->postJson($base.'/'.$id.'/read')->assertNotFound();
            $this->postJson($base.'/'.$id.'/unread')->assertNotFound();
            $this->postJson($base.'/read-through', [
                'through_id' => $id,
            ])->assertOk()->assertJsonPath('marked_read', 0)
                ->assertJsonPath('remaining_unread', 0);
        }
        $this->assertNull(InboxNotification::findOrFail($id)->read_at);
        DB::table('users')->where('id', $admin->id)
            ->update(['role' => 'admin']);
        $this->getJson('/api/notifications?category=charging')
            ->assertOk()->assertJsonPath('total', 1);
        $this->postJson('/api/notifications/'.$id.'/read')->assertOk();
        $this->assertNotNull(InboxNotification::findOrFail($id)->read_at);
        $this->assertDatabaseCount('charger_commands', 0);
    }

    public function test_escalation_requires_valid_admin_and_unresolved_fault_over_sixty_minutes(): void
    {
        $f = $this->fixture();
        $admin = User::factory()->create(['role' => 'admin']);
        $service = app(FaultReminderEscalator::class);
        // A configured vendor ID is never an escalation destination.
        config(['csms.fault_escalation_admin_user_id' => $f['owner']->id]);
        $this->assertSame(0, $service->scan()['escalated']);
        // A valid Admin is too early at 21 minutes.
        config(['csms.fault_escalation_admin_user_id' => $admin->id]);
        $this->assertSame(0, $service->scan()['escalated']);
        DB::table('charging_status_observations')->where('id', $f['observation'])
            ->update(['occurred_at' => now()->subMinutes(61)]);
        $this->assertSame(1, $service->scan()['escalated']);
        $this->assertSame(0, $service->scan()['escalated']);
        $this->assertDatabaseCount('charging_fault_admin_escalations', 1);
        $this->assertDatabaseCount('charger_commands', 0);
    }

    public function test_owner_can_suppress_repeat_reminder_but_original_fault_remains_available(): void
    {
        $f = $this->fixture();
        $this->actingAs($f['owner'])->putJson('/api/notifications/preferences', [
            'default_category' => 'charging',
            'default_unread_only' => true,
            'remind_unresolved_simulator_faults' => false,
        ])->assertOk();
        $this->assertSame(0, app(FaultReminderEscalator::class)->scan()['reminded']);
        $this->assertDatabaseCount('charging_fault_reminders', 0);
        // Opt-out never deletes or hides a real source-fault alert.
        $this->assertDatabaseCount('charging_fault_alerts', 1);

        $this->putJson('/api/notifications/preferences', [
            'default_category' => 'charging',
            'default_unread_only' => false,
            'remind_unresolved_simulator_faults' => true,
        ])->assertOk();
        $this->assertSame(1, app(FaultReminderEscalator::class)->scan()['reminded']);
    }

    public function test_acknowledged_recovered_or_new_faults_never_receive_premature_reminders(): void
    {
        $f = $this->fixture();
        DB::table('charging_fault_alerts')->where('id', $f['alert'])
            ->update(['acknowledged_at' => now(), 'acknowledged_by' => $f['owner']->id]);
        $this->assertSame(0, app(FaultReminderEscalator::class)->scan()['reminded']);
        DB::table('charging_fault_alerts')->where('id', $f['alert'])
            ->update(['acknowledged_at' => null, 'acknowledged_by' => null,
                'recovery_observation_id' => $f['observation']]);
        $this->assertSame(0, app(FaultReminderEscalator::class)->scan()['reminded']);
        DB::table('charging_fault_alerts')->where('id', $f['alert'])
            ->update(['recovery_observation_id' => null]);
        DB::table('charging_status_observations')->where('id', $f['observation'])
            ->update(['occurred_at' => now()->subMinutes(2)]);
        $this->assertSame(0, app(FaultReminderEscalator::class)->scan()['reminded']);
    }

    public function test_source_mapping_or_vendor_ownership_change_fails_closed(): void
    {
        $f = $this->fixture();
        $different = User::factory()->create(['role' => 'vendor']);
        Station::whereKey($f['station']->id)
            ->update(['vendor_id' => $different->id]);
        $this->assertSame(0, app(FaultReminderEscalator::class)->scan()['reminded']);
        $this->assertDatabaseCount('charging_fault_reminders', 0);
        $this->assertDatabaseCount('inbox_notifications', 0);
        Station::whereKey($f['station']->id)
            ->update(['vendor_id' => $f['owner']->id]);
        DB::table('csms_bindings')->where('id', $f['bindingId'])
            ->update(['identifier' => 'ev-test-reassigned']);
        $this->assertSame(0, app(FaultReminderEscalator::class)->scan()['reminded']);
        $this->assertDatabaseCount('inbox_notifications', 0);
    }

    public function test_worker_is_disabled_in_production_and_rejects_unbounded_scan_arguments(): void
    {
        $this->fixture();
        config(['csms.fault_worker_enabled' => false]);
        $this->artisan('notifications:remind-faults --limit=10')->assertFailed();
        config(['csms.fault_worker_enabled' => true]);
        $this->app->instance('env', 'production');
        $this->artisan('notifications:remind-faults --limit=10')->assertFailed();
        $this->app->instance('env', 'testing');
        $this->artisan('notifications:remind-faults --limit=1000')->assertFailed();
    }
}
