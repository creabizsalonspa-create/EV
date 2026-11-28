<?php

namespace Tests\Feature;

use App\Models\ChargerCommand;
use App\Models\Charging\ConnectorMapping;
use App\Models\Charging\Evse;
use App\Models\Charging\SessionLink;
use App\Models\ChargingSession;
use App\Models\Reservation;
use App\Models\Station;
use App\Models\User;
use App\Models\VendorOps\VendorCharger;
use App\Models\VendorOps\VendorCompany;
use App\Models\VendorOps\VendorCompanyStaff;
use App\Models\VendorOps\VendorConnector;
use App\Models\VendorOps\VendorSite;
use App\Services\CsmsDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

final class VendorStaffSimulatorStopTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(string $protocol = '1.6'): array
    {
        config(['csms.session_bridge_enabled' => true, 'csms.simulator_enabled' => true, 'csms.base_url' => 'http://127.0.0.1:8080']);
        Http::preventStrayRequests();
        $owner = User::factory()->create(['role' => 'vendor']);
        $staff = User::factory()->create(['role' => 'customer']);
        $driver = User::factory()->create(['name' => 'PRIVATE-DRIVER']);
        $company = VendorCompany::create(['owner_id' => $owner->id, 'legal_name' => 'Staff stop company', 'country' => 'AE',
            'trade_license_number' => 'STAFF-STOP', 'license_expires_on' => now()->addYear(), 'status' => 'approved']);
        $grant = VendorCompanyStaff::create(['company_id' => $company->id, 'user_id' => $staff->id, 'email' => $staff->email,
            'permissions' => ['charging.read'], 'status' => 'active', 'accepted_at' => now()]);
        $station = Station::create(['vendor_id' => $owner->id, 'name' => 'Mapped simulator for staff stop', 'country' => 'AE',
            'currency' => 'AED', 'timezone' => 'Asia/Dubai', 'latitude' => 25, 'longitude' => 55, 'source' => 'simulator',
            'connector_type' => 'CCS2', 'power_kw' => 50]);
        $site = VendorSite::create(['company_id' => $company->id, 'station_id' => $station->id, 'name' => 'Stop fixture site',
            'country' => 'AE', 'timezone' => 'Asia/Dubai', 'address' => 'Synthetic', 'latitude' => 25, 'longitude' => 55]);
        $charger = VendorCharger::create(['company_id' => $company->id, 'site_id' => $site->id, 'asset_reference' => 'STAFF-STOP',
            'manufacturer' => 'Synthetic', 'model' => 'Fixture', 'source' => 'simulator', 'ocpp_protocol' => $protocol]);
        $identifier = 'ev-test-delegated-stop';
        $binding = DB::table('csms_bindings')->insertGetId(['station_id' => $station->id, 'vendor_charger_id' => $charger->id,
            'identifier' => $identifier, 'protocol' => $protocol, 'created_at' => now(), 'updated_at' => now()]);
        $number = $protocol === '1.6' ? 0 : 1;
        $evse = Evse::create(['charger_id' => $charger->id, 'number' => 1, 'mode' => 'csms', 'binding_id' => $binding,
            'source_evse_number' => $number]);
        $connector = VendorConnector::create(['charger_id' => $charger->id, 'connector_number' => 1, 'connector_type' => 'CCS2', 'max_power_kw' => 50]);
        $mapping = ConnectorMapping::create(['station_id' => $station->id, 'evse_id' => $evse->id, 'connector_id' => $connector->id,
            'source_connector_number' => 1, 'source_target_key' => hash('sha256', $binding.':'.$number.':1')]);
        $booking = Reservation::create(['user_id' => $driver->id, 'station_id' => $station->id, 'charging_evse_id' => $evse->id,
            'connector_mapping_id' => $mapping->id, 'starts_at' => now()->subMinutes(5), 'idempotency_key' => 'staff-stop-booking']);
        $session = ChargingSession::create(['reservation_id' => $booking->id, 'status' => 'charging', 'energy_wh' => 500,
            'rate_minor_per_kwh' => 140, 'currency' => 'AED', 'total_minor' => 70]);
        $start = ChargerCommand::create(['actor_id' => $driver->id, 'station_id' => $station->id, 'action' => 'start', 'status' => 'source_observed',
            'payload' => ['session_id' => $session->id], 'idempotency_key' => 'staff-stop-start', 'payload_hash' => hash('sha256', 'staff-stop-start')]);
        $transaction = $protocol === '1.6' ? '123' : 'PRIVATE-source-transaction';
        $link = SessionLink::create(['charging_session_id' => $session->id, 'binding_id' => $binding, 'start_command_id' => $start->id,
            'connector_mapping_id' => $mapping->id, 'identifier' => $identifier, 'protocol' => $protocol,
            'transaction_id' => $transaction, 'started_at' => now()->subMinutes(4)]);
        $sessions = '/api/vendor-ops/companies/'.$company->id.'/charging-sessions';
        $stop = $sessions.'/'.$session->id.'/stop';

        return compact('owner', 'staff', 'driver', 'company', 'grant', 'station', 'session', 'link', 'sessions', 'stop', 'transaction');
    }

    private function acceptStopPermission(array $f): array
    {
        $this->actingAs($f['owner'])->patchJson('/api/vendor-ops/companies/'.$f['company']->id.'/staff/'.$f['grant']->id.'/permissions',
            ['expected_version' => 1, 'permissions' => ['charging.read', 'charging.stop']])
            ->assertOk()->assertJsonPath('requires_acceptance', true);
        $payload = ['grant_id' => $f['grant']->id, 'grant_version' => 2, 'consent' => true];
        $this->actingAs($f['staff'])->postJson($f['stop'], $payload)->assertNotFound();
        $this->postJson('/api/vendor-ops/staff-invitations/'.$f['grant']->id.'/accept', ['expected_version' => 2, 'consent' => true])->assertOk();

        return $payload;
    }

    #[TestWith(['1.6'])]
    #[TestWith(['2.0.1'])]
    public function test_explicitly_accepted_grant_queues_one_current_simulator_stop(string $protocol): void
    {
        $f = $this->fixture($protocol);
        $this->actingAs($f['staff'])->getJson($f['sessions'])->assertOk()
            ->assertJsonMissingPath('staff_stop_grant')->assertJsonPath('data.0.can_request_stop', false);
        $body = $this->acceptStopPermission($f);
        $this->getJson($f['sessions'])->assertOk()->assertJsonPath('staff_stop_grant.id', $f['grant']->id)
            ->assertJsonPath('staff_stop_grant.version', 2)->assertJsonPath('data.0.can_request_stop', true)
            ->assertJsonPath('control_actions_exposed', false)->assertDontSee('PRIVATE-');
        $this->postJson($f['stop'], array_replace($body, ['consent' => false]))->assertUnprocessable();
        $this->postJson($f['stop'], array_replace($body, ['grant_version' => 1]))->assertNotFound();

        $id = $this->postJson($f['stop'], $body)->assertAccepted()->assertJsonPath('company_id', $f['company']->id)
            ->assertJsonPath('session_id', $f['session']->id)->assertJsonPath('command_status', 'pending')
            ->assertJsonPath('session_status', 'stopping')->assertJsonPath('evidence', 'SIMULATED_CSMS')
            ->assertJsonPath('physical_charging_verified', false)->assertDontSee('PRIVATE-')->json('command_id');
        $this->postJson($f['stop'], $body)->assertAccepted()->assertJsonPath('command_id', $id);
        $this->getJson($f['sessions'])->assertOk()->assertJsonPath('data.0.can_request_stop', false);
        $this->assertDatabaseCount('charger_commands', 2);
        $this->assertDatabaseHas('audit_events', ['actor_id' => $f['staff']->id, 'action' => 'csms.staff_stop_requested', 'subject' => (string) $f['session']->id]);
        $this->assertArrayNotHasKey('staff_stop_authority', ChargerCommand::findOrFail($id)->toArray());
        Http::assertNothingSent();

        Http::fake(function ($request) use ($protocol, $f) {
            if (str_ends_with($request->url(), '/dev/token')) {
                return Http::response(['accessToken' => 'synthetic-stop-token']);
            }
            $this->assertStringContainsString($protocol === '1.6' ? 'remoteStopTransaction?' : 'requestStopTransaction?', $request->url());
            $this->assertSame($protocol === '1.6' ? 123 : $f['transaction'], $request['transactionId']);

            return Http::response([['success' => true]]);
        });
        $this->assertSame('submitted', app(CsmsDispatcher::class)->dispatch($id)->status);
        $this->assertSame('submitted', app(CsmsDispatcher::class)->dispatch($id)->status);
        Http::assertSentCount(2);
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    public function test_stop_only_foreign_and_rebound_records_never_expose_or_authorize_the_action(): void
    {
        $f = $this->fixture();
        $body = $this->acceptStopPermission($f);
        $f['grant']->fresh()->update(['permissions' => ['charging.stop']]);
        $this->getJson($f['sessions'])->assertNotFound();
        $this->postJson($f['stop'], $body)->assertNotFound();
        $f['grant']->fresh()->update(['permissions' => ['charging.read', 'charging.stop']]);

        $this->actingAs(User::factory()->create())->postJson($f['stop'], $body)->assertNotFound();
        $this->actingAs($f['staff'])->postJson($f['sessions'].'/999999/stop', $body)->assertNotFound();
        DB::table('csms_bindings')->where('id', $f['link']->binding_id)->update(['identifier' => 'ev-test-rebound-stop']);
        $this->getJson($f['sessions'])->assertOk()->assertJsonPath('data.0.can_request_stop', false);
        $this->postJson($f['stop'], $body)->assertConflict();

        $this->assertDatabaseCount('charger_commands', 1);
        $this->assertSame('charging', $f['session']->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_revoked_stop_permission_cancels_only_the_provably_unsent_command(): void
    {
        $f = $this->fixture();
        $body = $this->acceptStopPermission($f);
        $id = $this->postJson($f['stop'], $body)->assertAccepted()->json('command_id');
        $f['grant']->fresh()->update(['permissions' => ['charging.read']]);
        $this->assertSame('cancelled', app(CsmsDispatcher::class)->dispatch($id)->status);
        $this->assertSame('pre_dispatch_staff_stop_revoked', ChargerCommand::find($id)->failure_code);
        $this->assertDatabaseHas('audit_events', ['actor_id' => $f['staff']->id,
            'action' => 'csms.staff_stop_cancelled_before_dispatch', 'subject' => (string) $id]);
        $this->assertNull($f['link']->fresh()->stop_command_id);
        $this->assertSame('charging', $f['session']->fresh()->status);
        $this->getJson($f['sessions'])->assertOk()->assertJsonMissingPath('staff_stop_grant');
        $this->postJson($f['stop'], $body)->assertNotFound();
        $this->actingAs($f['owner'])->postJson('/api/charging-sessions/'.$f['session']->id.'/csms-stop')
            ->assertAccepted()->assertJsonPath('session.status', 'stopping');
        $this->assertDatabaseCount('finance_invoices', 0);
        Http::assertNothingSent();
    }

    public function test_mobile_bearer_revoked_during_token_fetch_never_sends_the_stop_to_the_simulator(): void
    {
        $f = $this->fixture('2.0.1');
        $body = $this->acceptStopPermission($f);
        $this->app['auth']->forgetGuards();
        $token = $f['staff']->createToken('delegated mobile stop');
        $id = $this->withToken($token->plainTextToken)->postJson(str_replace('/api/', '/api/v1/', $f['stop']), $body)
            ->assertAccepted()->json('command_id');
        Http::fake(function ($request) use ($token) {
            $this->assertStringEndsWith('/dev/token', $request->url());
            $token->accessToken->delete();

            return Http::response(['accessToken' => 'synthetic-stop-token']);
        });
        $this->assertSame('cancelled', app(CsmsDispatcher::class)->dispatch($id)->status);
        $this->assertSame('charging', $f['session']->fresh()->status);
        $this->assertNull($f['link']->fresh()->stop_command_id);
        Http::assertSentCount(1);
        $this->assertDatabaseCount('finance_invoices', 0);
    }
}
