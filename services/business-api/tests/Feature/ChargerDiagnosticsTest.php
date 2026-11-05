<?php

namespace Tests\Feature;

use App\Models\Station;
use App\Models\User;
use App\Models\VendorOps\VendorCharger;
use App\Models\VendorOps\VendorCompany;
use App\Models\VendorOps\VendorCompanyStaff;
use App\Models\VendorOps\VendorSite;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        [, , , $url] = $this->fixture();
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        Http::fakeSequence()->push(['data' => ['ChargingStations' => []]]);
        $native = str_replace('/api/', '/api/v1/', $url);
        $this->getJson($native)->assertOk()->assertJsonPath('source_registered', false);
        $this->app->instance('env', 'production');
        $this->getJson($native)->assertServiceUnavailable()->assertJsonMissingPath('source_online');
        Http::assertSentCount(1);
    }
}
