<?php

namespace Tests\Feature;

use App\Models\Station;
use App\Models\InboxNotification;
use App\Models\User;
use App\Models\VendorOps\VendorCharger;
use App\Models\VendorOps\VendorCompany;
use App\Models\VendorOps\VendorCompanyStaff;
use App\Models\VendorOps\VendorSite;
use App\Services\Charging\FaultMonitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ChargingRepairTest extends TestCase
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

    private function repairFixture(): array
    {
        [$owner, $company, $charger, $url] = $this->fixture();
        $binding = DB::table('csms_bindings')->where('vendor_charger_id', $charger->id)->first();
        app(FaultMonitor::class)->ingest($binding, ['source_generation' => FaultMonitor::generation($binding), 'events' => [['id' => 10, 'evseId' => 1, 'connectorId' => 1, 'connectorStatus' => 'Faulted', 'errorCode' => 'GroundFailure', 'timestamp' => now()->subMinute()->toISOString()]]]);
        $alert = DB::table('charging_fault_alerts')->first()->id;

        return [$owner, $company, $charger, str_replace('source-diagnostics', 'repair-orders', $url), $alert, $binding];
    }

    private function intent(int $alert, int $version, string $state): array
    {
        return ['request_key' => (string) Str::uuid(), 'alert_id' => $alert, 'expected_version' => $version, 'state' => $state, 'note' => 'PRIVATE repair inspection note'];
    }

    public function test_versioned_lifecycle_requires_recovery_and_preserves_immutable_encrypted_history(): void
    {
        [$owner, , , $url, $alert, $binding] = $this->repairFixture();
        $this->actingAs($owner);
        $create = $this->intent($alert, 0, 'open');
        $id = $this->postJson($url, $create)->assertOk()->assertJsonPath('version', 1)->json('id');
        $this->postJson($url, $create)->assertOk()->assertJsonPath('replayed', true);
        $this->postJson($url, $create + [])->assertOk();
        $changed = $create;
        $changed['note'] = 'Another different repair note';
        $this->postJson($url, $changed)->assertConflict();
        $this->postJson($url, $this->intent($alert, 0, 'open'))->assertConflict();
        $this->postJson($url, $this->intent($alert, 1, 'closed'))->assertConflict();
        $this->postJson($url, $this->intent($alert, 1, 'in_progress'))->assertOk()->assertJsonPath('version', 2);
        $this->postJson($url, $this->intent($alert, 2, 'review'))->assertOk();
        $this->postJson($url, $this->intent($alert, 3, 'closed'))->assertConflict();
        app(FaultMonitor::class)->ingest($binding, ['source_generation' => FaultMonitor::generation($binding), 'events' => [['id' => 11, 'evseId' => 1, 'connectorId' => 1, 'connectorStatus' => 'Available', 'errorCode' => 'NoError', 'timestamp' => now()->toISOString()]]]);
        $this->postJson($url, $this->intent($alert, 3, 'closed'))->assertOk()->assertJsonPath('capacity_changed', false)->assertJsonPath('commands_sent', false);
        $this->postJson($url, $this->intent($alert, 4, 'open'))->assertOk();
        $list = $this->getJson($url)->assertOk()->assertJsonPath('data.0.state', 'open')->assertJsonPath('physical_repair_verified', false);
        $this->assertStringNotContainsString('PRIVATE repair', $list->getContent());
        $this->assertStringContainsString('no-store', $list->headers->get('Cache-Control'));
        $this->getJson($url.'/'.$id.'/history')->assertOk()->assertJsonCount(5, 'data')->assertJsonPath('data.0.note', $create['note']);
        $this->assertDatabaseCount('charging_repair_events', 5);
        $this->assertStringNotContainsString('PRIVATE repair', DB::table('charging_repair_events')->first()->note_ciphertext);
        $this->assertDatabaseCount('charger_commands', 0);
        $this->assertDatabaseCount('finance_invoices', 0);
        Http::assertNothingSent();
    }

    public function test_private_scopes_read_only_staff_and_rebinding_fences(): void
    {
        [$owner, $company, $charger, $url, $alert, $binding] = $this->repairFixture();
        $this->getJson($url)->assertUnauthorized();
        $this->actingAs(User::factory()->create())->getJson($url)->assertNotFound();
        $this->actingAs($owner)->postJson($url, $this->intent($alert, 0, 'open'))->assertOk();
        $staff = User::factory()->create();
        VendorCompanyStaff::create(['company_id' => $company->id, 'user_id' => $staff->id, 'email' => $staff->email, 'status' => 'active', 'permissions' => ['inventory.read']]);
        $this->actingAs($staff)->getJson($url)->assertOk()->assertJsonPath('can_manage', false);
        $this->postJson($url, $this->intent($alert, 1, 'in_progress'))->assertNotFound();
        $this->actingAs($owner);
        DB::table('csms_bindings')->where('id', $binding->id)->update(['identifier' => 'ev-test-rebound']);
        $this->getJson($url)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($url.'/1/history')->assertNotFound();
        $this->postJson($url, $this->intent($alert, 1, 'in_progress'))->assertNotFound();
    }

    public function test_admin_repair_review_and_source_based_closure_notify_owner_once(): void
    {
        [$owner, $company, $charger, $url, $alert, $binding] = $this->repairFixture();
        $admin = User::factory()->create(['role' => 'admin']);
        $outsider = User::factory()->create(['role' => 'vendor']);
        $this->actingAs($admin);

        $open = $this->intent($alert, 0, 'open');
        $this->postJson($url, $open)->assertOk();
        $this->postJson($url, $this->intent($alert, 1, 'in_progress'))->assertOk();
        $review = $this->intent($alert, 2, 'review');
        $this->postJson($url, $review)->assertOk();
        $this->postJson($url, $review)->assertOk()->assertJsonPath('replayed', true);
        $note = InboxNotification::where('kind', 'charging.repair.review_requested.simulated')
            ->firstOrFail();
        $this->assertSame($owner->id, $note->user_id);
        $this->assertSame($company->id, $note->metadata['company_id']);
        $this->assertSame($charger->id, $note->metadata['charger_id']);
        $this->assertSame($alert, $note->metadata['alert_id']);
        $this->assertSame('simulator', $note->metadata['source']);
        $this->assertFalse($note->metadata['physical_verification']);
        $this->assertStringNotContainsString($review['note'], json_encode($note->metadata));
        $this->assertDatabaseCount('inbox_notifications', 1);

        // A case cannot be closed merely by an operator assertion.
        $this->postJson($url, $this->intent($alert, 3, 'closed'))->assertConflict();
        $this->assertDatabaseCount('inbox_notifications', 1);
        app(FaultMonitor::class)->ingest($binding, [
            'source_generation' => FaultMonitor::generation($binding),
            'events' => [[
                'id' => 11, 'evseId' => 1, 'connectorId' => 1,
                'connectorStatus' => 'Available', 'errorCode' => 'NoError',
                'timestamp' => now()->toISOString(),
            ]],
        ]);
        $close = $this->intent($alert, 3, 'closed');
        $this->postJson($url, $close)->assertOk();
        $this->postJson($url, $close)->assertOk()->assertJsonPath('replayed', true);
        $closed = InboxNotification::where('kind', 'charging.repair.review_closed.simulated')
            ->firstOrFail();
        $this->assertSame($owner->id, $closed->user_id);
        $this->assertFalse($closed->metadata['physical_verification']);
        $this->assertTrue($closed->metadata['review_only']);
        $this->assertDatabaseCount('inbox_notifications', 2);
        $this->actingAs($owner)->getJson('/api/notifications?category=charging')
            ->assertOk()->assertJsonPath('total', 2);
        $this->actingAs($outsider)->getJson('/api/notifications?category=charging')
            ->assertOk()->assertJsonPath('total', 0);
        $this->assertDatabaseCount('charger_commands', 0);
        $this->assertDatabaseCount('finance_invoices', 0);
        Http::assertNothingSent();
    }

    public function test_owner_repair_updates_do_not_generate_self_notices(): void
    {
        [$owner, , , $url, $alert, $binding] = $this->repairFixture();
        $this->actingAs($owner);
        foreach ([
            [0, 'open'], [1, 'in_progress'], [2, 'review'],
        ] as [$version, $state]) {
            $this->postJson($url, $this->intent($alert, $version, $state))->assertOk();
        }
        app(FaultMonitor::class)->ingest($binding, [
            'source_generation' => FaultMonitor::generation($binding),
            'events' => [[
                'id' => 11, 'evseId' => 1, 'connectorId' => 1,
                'connectorStatus' => 'Available', 'errorCode' => 'NoError',
                'timestamp' => now()->toISOString(),
            ]],
        ]);
        $this->postJson($url, $this->intent($alert, 3, 'closed'))->assertOk();
        $this->assertDatabaseCount('inbox_notifications', 0);
    }

    public function test_native_admin_uses_same_version_and_source_guards(): void
    {
        [, , , $url, $alert] = $this->repairFixture();
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $url = str_replace('/api/', '/api/v1/', $url);
        $this->postJson($url, $this->intent($alert, 0, 'open'))->assertOk();
        $this->getJson($url)->assertOk()->assertJsonPath('can_manage', true);
        $this->postJson($url, $this->intent($alert, 1, 'review'))->assertConflict();
    }
}
