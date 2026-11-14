<?php

namespace Tests\Feature;

use App\Models\ChargingSession;
use App\Models\Reservation;
use App\Models\Station;
use App\Models\User;
use App\Models\VendorOps\VendorCharger;
use App\Models\VendorOps\VendorCompany;
use App\Models\VendorOps\VendorCompanyStaff;
use App\Models\VendorOps\VendorConnector;
use App\Models\VendorOps\VendorSite;
use App\Services\Charging\CustomerReviews;
use App\Services\Charging\FaultMonitor;
use App\Services\Finance\FinanceService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OperationalAnalyticsTest extends TestCase
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

    private function setupReport(): array
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-06T12:00:00Z'));
        [$owner,$company,$charger] = $this->fixture();
        $binding = DB::table('csms_bindings')->where('vendor_charger_id', $charger->id)->first();
        $connector = VendorConnector::create(['charger_id' => $charger->id, 'connector_number' => 1, 'connector_type' => 'CCS2', 'max_power_kw' => 50]);
        $evse = DB::table('charging_evses')->insertGetId(['charger_id' => $charger->id, 'number' => 1, 'mode' => 'exclusive', 'binding_id' => $binding->id, 'source_evse_number' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $map = DB::table('charging_connector_mappings')->insertGetId(['station_id' => $binding->station_id, 'evse_id' => $evse, 'connector_id' => $connector->id, 'source_connector_number' => 1, 'source_target_key' => hash('sha256', 'test-'.$binding->id), 'created_at' => now(), 'updated_at' => now()]);

        return [$owner, $company, $charger, $binding, $map, $evse, '/api/vendor-ops/companies/'.$company->id.'/operational-analytics'];
    }

    private function sourceSession(object $b, int $map, int $evse, string $start, string $end): object
    {
        $customer = User::factory()->create();
        $r = Reservation::create(['user_id' => $customer->id, 'station_id' => $b->station_id, 'starts_at' => $start, 'status' => 'completed', 'idempotency_key' => bin2hex(random_bytes(10)), 'connector_mapping_id' => $map, 'charging_evse_id' => $evse]);
        $s = ChargingSession::create(['reservation_id' => $r->id, 'status' => 'stopped', 'energy_wh' => 1000, 'rate_minor_per_kwh' => 100, 'currency' => 'AED', 'total_minor' => 100, 'stopped_at' => $end, 'charging_evse_id' => $evse]);
        $id = DB::table('csms_session_links')->insertGetId(['charging_session_id' => $s->id, 'binding_id' => $b->id, 'start_command_id' => null, 'protocol' => $b->protocol, 'identifier' => $b->identifier, 'connector_mapping_id' => $map, 'transaction_id' => 'test-'.$s->id, 'last_sequence' => 1, 'meter_start_wh' => 100, 'last_meter_wh' => 1100, 'started_at' => $start, 'ended_at' => $end, 'last_event_at' => $end, 'created_at' => now()->subDay(), 'updated_at' => now()]);
        foreach ([[$start, 'started', 0, 100], [$end, 'ended', 1, 1100]] as [$at,$type,$seq,$meter]) {
            DB::table('csms_inbox_events')->insert(['link_id' => $id, 'event_id' => 'report-'.$id.'-'.$seq, 'fingerprint' => hash('sha256', $id.':'.$seq), 'type' => $type, 'sequence' => $seq, 'meter_wh' => $meter, 'occurred_at' => $at, 'outcome' => 'applied', 'created_at' => now()]);
        }
        app(FinanceService::class)->issue($s, $customer->id);

        return $s;
    }

    public function test_completed_energy_canonical_invoices_union_occupancy_and_unknown_status_are_separate(): void
    {
        [$owner,$company,,$b,$map,$evse,$url] = $this->setupReport();
        $this->sourceSession($b, $map, $evse, '2026-11-06 10:00:00', '2026-11-06 11:00:00');
        $this->sourceSession($b, $map, $evse, '2026-11-06 10:30:00', '2026-11-06 11:30:00');
        app(FaultMonitor::class)->ingest($b, ['source_generation' => FaultMonitor::generation($b), 'events' => [
            ['id' => 1, 'evseId' => 0, 'connectorId' => 1, 'connectorStatus' => 'Faulted', 'errorCode' => 'GroundFailure', 'timestamp' => '2026-11-06T10:00:00Z'],
            ['id' => 2, 'evseId' => 0, 'connectorId' => 1, 'connectorStatus' => 'Available', 'errorCode' => 'NoError', 'timestamp' => '2026-11-06T10:02:00Z']]]);
        $before = DB::table('finance_journals')->count();
        $reply = $this->actingAs($owner)->getJson($url.'?from=2026-11-06&to=2026-11-06')->assertOk()->assertJsonPath('sites.0.completed_energy_wh', 2000)->assertJsonPath('sites.0.observed_busy_seconds', 5400)->assertJsonPath('sites.0.source_completed', 2)->assertJsonPath('sites.0.maximum_session_interval_average_w', 1000)->assertJsonPath('sites.0.status_known_seconds', 420)->assertJsonPath('sites.0.reported_fault_seconds', 120)->assertJsonPath('sites.0.status_unknown_seconds', 42780)->assertJsonPath('invoice_facts.0.invoiced_minor', 200)->assertJsonPath('physical_uptime_verified', false);
        $this->assertSame($before, DB::table('finance_journals')->count());
        $this->assertStringContainsString('no-store', $reply->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('user_id', $reply->getContent());
        Http::assertNothingSent();
    }

    public function test_large_valid_meter_delta_does_not_overflow_during_power_scaling(): void
    {
        [$owner, $company, , $b, $map, $evse, $url] = $this->setupReport();
        $session = $this->sourceSession($b, $map, $evse, '2026-11-06 10:00:00', '2026-11-06 11:00:00');
        $meter = 9007199254740991;
        $delta = $meter - 100;
        DB::table('charging_sessions')->where('id', $session->id)->update(['energy_wh' => $delta]);
        $link = DB::table('csms_session_links')->where('charging_session_id', $session->id)->first();
        DB::table('csms_session_links')->where('id', $link->id)->update(['last_meter_wh' => $meter]);
        DB::table('csms_inbox_events')->where('link_id', $link->id)->where('type', 'ended')->update(['meter_wh' => $meter]);
        $before = DB::table('finance_journals')->count();
        $this->actingAs($owner)->getJson($url.'?from=2026-11-06&to=2026-11-06')->assertOk()
            ->assertJsonPath('sites.0.maximum_session_interval_average_w', $delta);
        $this->assertSame($before, DB::table('finance_journals')->count());

        // A one-second interval really exceeds the public exact-number bound.
        DB::table('csms_session_links')->where('id', $link->id)->update(['ended_at' => '2026-11-06 10:00:01']);
        DB::table('csms_inbox_events')->where('link_id', $link->id)->where('type', 'ended')->update(['occurred_at' => '2026-11-06 10:00:01']);
        $this->getJson($url.'?from=2026-11-06&to=2026-11-06')->assertStatus(409);
        $this->assertSame($before, DB::table('finance_journals')->count());
    }

    public function test_inventory_staff_can_read_operations_but_not_owner_financial_facts_and_cross_scope_fails(): void
    {
        [$owner,$company,,$b,$map,$evse,$url] = $this->setupReport();
        $this->sourceSession($b, $map, $evse, '2026-11-06 10:00:00', '2026-11-06 11:00:00');
        $this->getJson($url)->assertUnauthorized();
        $this->actingAs(User::factory()->create())->getJson($url)->assertNotFound();
        $staff = User::factory()->create();
        $member = VendorCompanyStaff::create(['company_id' => $company->id, 'user_id' => $staff->id, 'email' => $staff->email, 'status' => 'active', 'permissions' => ['inventory.read']]);
        $this->actingAs($staff)->getJson($url)->assertOk()->assertJsonPath('financial_visible', false)->assertJsonCount(0, 'invoice_facts')->assertJsonPath('sites.0.completed_energy_wh', 1000);
        $this->getJson($url.'?vendor_id='.$owner->id)->assertUnprocessable();
        $this->getJson($url.'?site_id=99999')->assertNotFound();
        $member->update(['status' => 'revoked']);
        $this->getJson($url)->assertNotFound();
        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);
        $this->getJson(str_replace('/api/', '/api/v1/', $url))->assertForbidden();
        $this->actingAs($admin, 'web')->getJson($url)->assertOk()->assertJsonPath('financial_visible', true);
    }

    public function test_unapplied_or_inconsistent_source_is_excluded_and_rebinding_drops_old_evidence(): void
    {
        [$owner,,$charger,$b,$map,$evse,$url] = $this->setupReport();
        $s = $this->sourceSession($b, $map, $evse, '2026-11-06 10:00:00', '2026-11-06 11:00:00');
        DB::table('csms_inbox_events')->where('type', 'ended')->update(['outcome' => 'stale']);
        $this->actingAs($owner)->getJson($url)->assertOk()->assertJsonPath('sites.0.completed_energy_wh', 0)->assertJsonPath('sites.0.excluded_sessions', 1)->assertJsonCount(0, 'invoice_facts');
        DB::table('csms_bindings')->where('id', $b->id)->update(['identifier' => 'ev-test-new-source']);
        $this->getJson($url)->assertOk()->assertJsonPath('sites.0.source_completed', 0);
        $this->getJson($url.'?from=2026-01-01&to=2026-11-06')->assertUnprocessable();
        $this->getJson($url.'?to=2026-11-07')->assertUnprocessable();
    }

    public function test_csv_formula_safety_private_pdf_and_date_bounds(): void
    {
        [$owner,$company,$charger,$b,$map,$evse,$url] = $this->setupReport();
        DB::table('vendor_sites')->where('id', $charger->site_id)->update(['name' => '=UNTRUSTED()']);
        $this->actingAs($owner);
        $csv = $this->get($url.'/export')->assertOk();
        $this->assertStringContainsString("'=UNTRUSTED()", $csv->getContent());
        foreach (['en', 'ar'] as $locale) {
            $pdf = $this->get($url.'/pdf?locale='.$locale)->assertOk()->assertHeader('Content-Type', 'application/pdf');
            $this->assertStringStartsWith('%PDF-', $pdf->getContent());
        }
        $this->getJson($url.'?from=2026-11-06&to=2026-11-05')->assertUnprocessable();
    }

    public function test_boundary_crossing_session_counts_busy_time_without_moving_energy_to_another_day(): void
    {
        [$owner, , , $b, $map, $evse, $url] = $this->setupReport();
        $this->sourceSession($b, $map, $evse, '2026-11-05 23:00:00', '2026-11-06 01:00:00');
        $this->actingAs($owner)->getJson($url.'?from=2026-11-05&to=2026-11-05')->assertOk()->assertJsonPath('sites.0.observed_busy_seconds', 3600)->assertJsonPath('sites.0.source_started', 1)->assertJsonPath('sites.0.source_completed', 0)->assertJsonPath('sites.0.completed_energy_wh', 0)->assertJsonCount(0, 'invoice_facts');
    }

    public function test_ambiguous_same_time_status_is_unknown_instead_of_invented_uptime(): void
    {
        [$owner, , , $b, , , $url] = $this->setupReport();
        app(FaultMonitor::class)->ingest($b, ['source_generation' => FaultMonitor::generation($b), 'events' => [
            ['id' => 1, 'evseId' => 0, 'connectorId' => 1, 'connectorStatus' => 'Faulted', 'errorCode' => 'GroundFailure', 'timestamp' => '2026-11-06T10:00:00Z'],
            ['id' => 2, 'evseId' => 0, 'connectorId' => 1, 'connectorStatus' => 'Available', 'errorCode' => 'NoError', 'timestamp' => '2026-11-06T10:00:00Z']]]);
        $this->actingAs($owner)->getJson($url.'?from=2026-11-06&to=2026-11-06')->assertOk()->assertJsonPath('sites.0.status_known_seconds', 0)->assertJsonPath('sites.0.status_unknown_seconds', 43200);
    }

    public function test_feedback_aggregates_include_only_current_public_revision_in_window_without_private_data_or_billing_changes(): void
    {
        [$owner, , , $b, $map, $evse, $url] = $this->setupReport();
        $session = $this->sourceSession($b, $map, $evse, '2026-11-06 10:00:00', '2026-11-06 11:00:00');
        $driver = User::findOrFail(Reservation::findOrFail($session->reservation_id)->user_id);
        $admin = User::factory()->create(['role' => 'admin']);
        $body = ['request_key' => (string) Str::uuid(), 'rating' => 4, 'body' => 'PRIVATE approved synthetic feedback', 'consent' => true, 'terms_version' => CustomerReviews::TERMS];
        $row = $this->actingAs($driver)->postJson('/api/charging-reviews/sessions/'.$session->id, $body)->assertOk()->json();
        $this->actingAs($owner)->getJson($url)->assertOk()->assertJsonCount(0, 'feedback_groups');
        $decision = ['request_key' => (string) Str::uuid(), 'expected_version' => 1, 'action' => 'publish', 'reason' => 'PRIVATE independent moderation'];
        $published = $this->actingAs($admin)->postJson('/api/charging-reviews/'.$row['id'].'/moderate', $decision)->assertOk()->json();
        $before = DB::table('finance_journals')->count();
        $report = $this->actingAs($owner)->getJson($url.'?from=2026-11-06&to=2026-11-06')->assertOk()->assertJsonPath('feedback_physical_charging_verified', false)->assertJsonPath('feedback_groups.0.ratings', 1)->assertJsonPath('feedback_groups.0.rating_sum', 4)->assertJsonPath('feedback_groups.0.evidence', 'SIMULATED_SOURCE_OBSERVED');
        foreach (['PRIVATE', $driver->email, 'public_id', 'user_id', 'session_id', 'source_fingerprint', 'body_ciphertext'] as $private) {
            $this->assertStringNotContainsString($private, $report->getContent());
        }
        $csv = $this->get($url.'/export')->assertOk();
        $this->assertStringContainsString('CURRENT_PUBLIC_SIMULATOR_FEEDBACK_NOT_PHYSICAL_VERIFICATION', $csv->getContent());
        $this->assertStringNotContainsString('PRIVATE', $csv->getContent());
        $this->get($url.'/pdf?locale=ar')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->getJson($url.'?from=2026-11-01&to=2026-11-05')->assertOk()->assertJsonCount(0, 'feedback_groups');
        $edit = array_replace($body, ['request_key' => (string) Str::uuid(), 'expected_version' => $published['version'], 'rating' => 1]);
        $this->actingAs($driver)->putJson('/api/charging-reviews/'.$row['id'], $edit)->assertOk();
        $this->actingAs($owner)->getJson($url)->assertOk()->assertJsonCount(0, 'feedback_groups');
        $this->assertSame($before, DB::table('finance_journals')->count());
    }
}
