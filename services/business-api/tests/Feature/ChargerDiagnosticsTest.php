<?php

namespace Tests\Feature;

use App\Models\Station;
use App\Models\User;
use App\Models\VendorOps\VendorCharger;
use App\Models\VendorOps\VendorCompany;
use App\Models\VendorOps\VendorCompanyStaff;
use App\Models\VendorOps\VendorSite;
use App\Services\Charging\FaultMonitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ChargerDiagnosticsTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(string $protocol = '1.6'): array
    {
        Http::preventStrayRequests();
        config(['csms.session_bridge_enabled' => true, 'csms.graphql_secret' => str_repeat('s', 32), 'csms.graphql_url' => 'https://source.test/graphql']);
        $owner = User::factory()->create(['role' => 'vendor']);
        $station = Station::create(['vendor_id' => $owner->id, 'name' => 'Diagnostics fixture', 'country' => 'AE', 'currency' => 'AED', 'timezone' => 'Asia/Dubai', 'latitude' => 25, 'longitude' => 55, 'source' => 'simulator', 'connector_type' => 'CCS2', 'power_kw' => 50]);
        $company = VendorCompany::create(['owner_id' => $owner->id, 'legal_name' => 'Fixture', 'country' => 'AE', 'trade_license_number' => 'SYNTHETIC', 'license_expires_on' => now()->addYear()->toDateString(), 'status' => 'approved']);
        $site = VendorSite::create(['company_id' => $company->id, 'station_id' => $station->id, 'name' => 'Fixture', 'country' => 'AE', 'timezone' => 'Asia/Dubai', 'address' => 'Fixture', 'latitude' => 25, 'longitude' => 55]);
        $charger = VendorCharger::create(['company_id' => $company->id, 'site_id' => $site->id, 'asset_reference' => 'DIAGNOSTICS', 'manufacturer' => 'Synthetic', 'model' => 'Fixture', 'source' => 'simulator', 'ocpp_protocol' => $protocol]);
        DB::table('csms_bindings')->insert(['station_id' => $station->id, 'vendor_charger_id' => $charger->id, 'identifier' => 'ev-test-diagnostics-'.$charger->id, 'protocol' => $protocol, 'created_at' => now(), 'updated_at' => now()]);

        return [$owner, $company, $charger, '/api/vendor-ops/companies/'.$company->id.'/chargers/'.$charger->id.'/source-diagnostics'];
    }

    public static function protocols(): array
    {
        return [['1.6'], ['2.0.1']];
    }

    #[DataProvider('protocols')]
    public function test_both_protocols_source_fault_history_pagination_and_no_commands(string $protocol): void
    {
        [$owner, , , $url] = $this->fixture($protocol);
        $events = [];
        for ($id = 30; $id > 4; $id--) {
            $events[] = ['id' => $id, 'connectorId' => 1, 'evseId' => 1, 'connectorStatus' => 'Faulted', 'errorCode' => 'GroundFailure', 'timestamp' => now()->toISOString(), 'info' => 'PRIVATE-SOURCE-INFO'];
        }
        Http::fakeSequence()->push(['data' => ['ChargingStations' => [['id' => 42, 'protocol' => 'ocpp'.$protocol, 'isOnline' => true, 'firmwareVersion' => '<source-firmware>', 'latestOcppMessageTimestamp' => now()->subMinutes(6)->toISOString()]]]])->push(['data' => ['StatusNotifications' => $events]]);
        $reply = $this->actingAs($owner)->getJson($url)->assertOk()->assertJsonPath('source_online', true)->assertJsonPath('recent_message_observed', false)->assertJsonPath('evidence', 'SIMULATED_SOURCE')->assertJsonPath('next_before', 6)->assertJsonCount(25, 'events')->assertJsonPath('commands_sent', false)->assertJsonPath('physical_charging_verified', false);
        $this->assertStringNotContainsString('PRIVATE-SOURCE-INFO', $reply->getContent());
        $this->assertStringContainsString('no-store', $reply->headers->get('Cache-Control'));
        $this->assertDatabaseCount('charger_commands', 0);
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    public function test_scope_precedes_network_and_absence_stays_unknown(): void
    {
        [$owner, , , $url] = $this->fixture();
        $this->getJson($url)->assertUnauthorized();
        $this->actingAs(User::factory()->create())->getJson($url)->assertNotFound();
        Http::assertNothingSent();
        Http::fakeSequence()->push(['data' => ['ChargingStations' => []]]);
        $this->actingAs($owner)->getJson($url)->assertOk()->assertJsonPath('source_registered', false)->assertJsonPath('source_online', null)->assertJsonPath('recent_message_observed', false)->assertJsonPath('events', []);
    }

    public function test_mapping_changes_during_source_reads_prevent_disclosure(): void
    {
        [$owner, , $charger, $url] = $this->fixture();
        Http::fake(function () use ($charger) {
            DB::table('csms_bindings')->where('vendor_charger_id', $charger->id)->update(['identifier' => 'ev-test-replaced']);

            return Http::response(['data' => ['ChargingStations' => []]]);
        });
        $this->actingAs($owner)->getJson($url)->assertConflict();
    }

    public function test_source_failure_is_sanitized_and_never_reported_as_offline(): void
    {
        [$owner, , , $url] = $this->fixture();
        Http::fakeSequence()->push(['errors' => [['message' => 'PRIVATE-CREDENTIAL-DETAIL']]], 200);
        $reply = $this->actingAs($owner)->getJson($url)->assertServiceUnavailable();
        $reply->assertJsonMissingPath('source_online');
        $this->assertStringNotContainsString('PRIVATE-CREDENTIAL-DETAIL', $reply->getContent());
    }

    public function test_revoked_inventory_permission_during_read_prevents_disclosure(): void
    {
        [, $company, , $url] = $this->fixture();
        $staff = User::factory()->create();
        $membership = VendorCompanyStaff::create(['company_id' => $company->id, 'user_id' => $staff->id, 'email' => $staff->email, 'status' => 'active', 'permissions' => ['company.read', 'inventory.read']]);
        Http::fake(function () use ($membership) {
            $membership->update(['status' => 'revoked']);

            return Http::response(['data' => ['ChargingStations' => []]]);
        });
        $this->actingAs($staff)->getJson($url)->assertNotFound()->assertJsonMissingPath('source_registered');
    }

    public function test_native_admin_access_uses_same_scope_and_production_source_is_disabled(): void
    {
        [$owner, , , $url] = $this->fixture();
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        Http::fakeSequence()->push(['data' => ['ChargingStations' => []]]);
        $native = str_replace('/api/', '/api/v1/', $url);
        $this->getJson($native)->assertOk()->assertJsonPath('source_registered', false);
        $this->app->instance('env', 'production');

        // Mandatory Admin MFA is checked before the source adapter. A native
        // Admin bearer must not reveal whether source diagnostics are enabled.
        $this->getJson($native)->assertForbidden()
            ->assertJsonPath('admin_second_factor_required', true)
            ->assertJsonMissingPath('source_online');

        // Independently prove production still refuses source reads for an
        // otherwise authorized vendor, instead of weakening the MFA gate.
        Sanctum::actingAs($owner);
        $this->getJson($native)->assertServiceUnavailable()->assertJsonMissingPath('source_online');
        Http::assertSentCount(1);
    }

    private function sourceEvents(array $events): void
    {
        Http::fakeSequence()->push(['data' => ['ChargingStations' => [['id' => 42, 'protocol' => 'ocpp1.6', 'isOnline' => true, 'latestOcppMessageTimestamp' => now()->toISOString()]]]])->push(['data' => ['StatusNotifications' => $events]]);
    }

    private function statusEvent(int $id, string $status, int $minutes, int $connector = 1): array
    {
        return ['id' => $id, 'evseId' => 1, 'connectorId' => $connector, 'connectorStatus' => $status, 'errorCode' => $status === 'Faulted' ? 'GroundFailure' : 'NoError', 'timestamp' => now()->subMinutes($minutes)->toISOString()];
    }

    public function test_fault_alerts_replay_recovery_and_acknowledgment_never_control_capacity(): void
    {
        [$owner, , , $url] = $this->fixture();
        $this->travelTo(now()->startOfSecond());
        $base = str_replace('/source-diagnostics', '/fault-alerts', $url);
        $this->sourceEvents([$this->statusEvent(20, 'Available', 1), $this->statusEvent(10, 'Faulted', 2)]);
        $this->actingAs($owner)->postJson($base.'/inspect')->assertOk()->assertJsonPath('alerts_created', 1)->assertJsonPath('capacity_changed', false)->assertJsonPath('commands_sent', false);
        $row = $this->getJson($base)->assertOk()->assertJsonCount(1, 'data')->json('data.0');
        $this->assertNotNull($row['recovery_observed_at']);
        $this->patchJson($base.'/'.$row['id'].'/acknowledge', ['note' => 'tiny'])->assertUnprocessable();
        $this->patchJson($base.'/'.$row['id'].'/acknowledge', ['note' => 'Investigating synthetic ground fault'])->assertOk()->assertJsonPath('fault_resolution_verified', false)->assertJsonPath('capacity_changed', false);
        $this->patchJson($base.'/'.$row['id'].'/acknowledge', ['note' => 'Must preserve the original review'])->assertOk();
        $stored = DB::table('charging_fault_alerts')->first();
        $this->assertSame('Investigating synthetic ground fault', Crypt::decryptString($stored->operator_note));
        $this->assertDatabaseCount('charger_commands', 0);
        $this->assertDatabaseCount('finance_invoices', 0);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'vendor_ops.charging_fault.acknowledged')->count());
    }

    public function test_backward_source_pages_recover_old_faults_and_changed_replay_is_rejected(): void
    {
        [$owner, , $charger] = $this->fixture();
        $binding = DB::table('csms_bindings')->where('vendor_charger_id', $charger->id)->first();
        $monitor = app(FaultMonitor::class);
        $generation = FaultMonitor::generation($binding);
        $this->travelTo(now()->startOfSecond());
        DB::transaction(function () use ($monitor, $binding, $generation) {
            $monitor->ingest($binding, ['source_generation' => $generation, 'events' => [$this->statusEvent(20, 'Available', 1)]]);
            $monitor->ingest($binding, ['source_generation' => $generation, 'events' => [$this->statusEvent(10, 'Faulted', 2)]]);
            $this->assertSame(0, $monitor->ingest($binding, ['source_generation' => $generation, 'events' => [$this->statusEvent(10, 'Faulted', 2)]]));
        });
        $this->assertNotNull(DB::table('charging_fault_alerts')->value('recovery_observation_id'));
        $url = '/api/vendor-ops/companies/'.$charger->company_id.'/chargers/'.$charger->id.'/fault-alerts/inspect';
        $changed = $this->statusEvent(10, 'Faulted', 2);
        $changed['errorCode'] = 'OverCurrentFailure';
        $this->sourceEvents([$changed]);
        $this->actingAs($owner)->postJson($url)->assertConflict();
        $this->assertDatabaseCount('charging_status_observations', 2);
        $this->assertDatabaseCount('charging_fault_alerts', 1);
    }

    public function test_read_only_staff_can_read_alerts_but_cannot_inspect_or_acknowledge(): void
    {
        [, $company, , $url] = $this->fixture();
        $staff = User::factory()->create();
        VendorCompanyStaff::create(['company_id' => $company->id, 'user_id' => $staff->id, 'email' => $staff->email, 'status' => 'active', 'permissions' => ['company.read', 'inventory.read']]);
        $base = str_replace('/source-diagnostics', '/fault-alerts', $url);
        $this->actingAs($staff)->getJson($base)->assertOk()->assertJsonPath('can_manage_faults', false)->assertJsonPath('total', 0);
        $this->postJson($base.'/inspect')->assertNotFound();
        $this->patchJson($base.'/1/acknowledge', ['note' => 'Unauthorized review attempt'])->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_monitor_command_is_bounded_and_does_not_send_device_commands(): void
    {
        [, , $charger] = $this->fixture();
        $id = DB::table('csms_bindings')->where('vendor_charger_id', $charger->id)->value('id');
        Http::fakeSequence()->push(['data' => ['ChargingStations' => []]]);
        $this->artisan('csms:monitor-faults', ['--binding' => (string) $id, '--limit' => '1'])->expectsOutputToContain('"commands_sent":false')->assertSuccessful();
        $this->artisan('csms:monitor-faults', ['--limit' => '0'])->assertFailed();
        $this->assertDatabaseCount('charger_commands', 0);
        Http::assertSentCount(1);
    }
}
