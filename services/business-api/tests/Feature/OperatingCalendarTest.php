<?php

namespace Tests\Feature;

use App\Models\ChargerCommand;
use App\Models\Charging\ConnectorMapping;
use App\Models\Charging\Evse;
use App\Models\Charging\RiderAccessToken;
use App\Models\Charging\SessionLink;
use App\Models\ChargingSession;
use App\Models\Reservation;
use App\Models\Station;
use App\Models\User;
use App\Models\VendorOps\VendorCharger;
use App\Models\VendorOps\VendorCompany;
use App\Models\VendorOps\VendorConnector;
use App\Models\VendorOps\VendorSite;
use App\Services\Charging\RemoteSessionAuthorization;
use App\Services\Charging\SessionBridge;
use App\Services\CsmsDispatcher;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

class OperatingCalendarTest extends TestCase
{
    use RefreshDatabase;

    private User $vendor;

    private User $customer;

    private Station $station;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-11-16 03:00:00 UTC');
        $this->travelTo(CarbonImmutable::now());
        $this->vendor = User::factory()->create(['role' => 'vendor']);
        $this->customer = User::factory()->create(['role' => 'customer']);
        $this->station = Station::create(['vendor_id' => $this->vendor->id, 'name' => 'Calendar', 'country' => 'AE', 'currency' => 'AED', 'timezone' => 'Asia/Dubai', 'latitude' => 25.2, 'longitude' => 55.3, 'source' => 'simulator', 'connector_type' => 'CCS2', 'power_kw' => 50]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function details(int $revision = 0, bool $enabled = true): array
    {
        return ['revision' => $revision, 'enforce_operating_hours' => $enabled,
            'description_en' => null, 'description_ar' => null, 'access_en' => null, 'access_ar' => null,
            'weekly_hours' => array_fill_keys(['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'], ['open' => '08:00', 'close' => '18:00']),
            'closed_dates' => [], 'amenities' => [], 'accessibility' => []];
    }

    private function publish(array $details): void
    {
        $this->actingAs($this->vendor)->putJson('/api/stations/'.$this->station->id.'/information', $details)->assertOk();
    }

    private function book(string $start, string $key = 'calendar', array $extra = [])
    {
        return $this->actingAs($this->customer)->postJson('/api/reservations', ['station_id' => $this->station->id, 'starts_at' => $start, 'idempotency_key' => $key] + $extra);
    }

    public function test_advisory_default_is_compatible_but_older_editor_cannot_disable_opt_in(): void
    {
        $this->getJson('/api/stations/'.$this->station->id.'/information')->assertOk()->assertJsonPath('enforce_operating_hours', false);
        $details = $this->details();
        unset($details['enforce_operating_hours']);
        $this->publish($details);
        $this->book('2026-11-16T03:30:00Z')->assertCreated()->assertJsonPath('operating_calendar_snapshot', null);
        $this->publish($this->details(1));
        $details['revision'] = 2;
        $this->publish($details);
        $this->getJson('/api/stations/'.$this->station->id.'/information')->assertOk()->assertJsonPath('enforce_operating_hours', true);
        $this->book('2026-11-17T03:30:00Z', 'outside')->assertConflict();
    }

    public function test_entire_window_station_timezone_and_server_owned_snapshot_are_enforced(): void
    {
        $this->publish($this->details());
        $this->book('2026-11-16T03:30:00Z', 'before')->assertConflict();
        $this->book('2026-11-16T14:00:00Z', 'closing')->assertConflict();
        $reply = $this->book('2026-11-16T13:30:00Z', 'valid', ['operating_calendar_snapshot' => ['revision' => 999]])->assertCreated()
            ->assertJsonPath('operating_calendar_snapshot.revision', 1)
            ->assertJsonPath('operating_calendar_snapshot.timezone', 'Asia/Dubai')
            ->assertJsonPath('operating_calendar_snapshot.physical_availability_verified', false);
        $this->assertSame(1, Reservation::count());
        $this->station->update(['timezone' => 'Asia/Kathmandu']);
        // 12:00 UTC is 17:45 locally: the first 15 minutes fit, the last do not.
        $this->book('2026-11-17T12:00:00Z', 'partial')->assertConflict();
        $this->assertSame($reply->json('operating_calendar_snapshot'), Reservation::first()->operating_calendar_snapshot);
    }

    public function test_holiday_override_overnight_and_explicit_disable_preserve_existing_receipts(): void
    {
        $d = $this->details();
        $d['weekly_hours']['mon'] = ['open' => '22:00', 'close' => '02:00'];
        $d['weekly_hours']['tue'] = ['open' => null, 'close' => null];
        $this->publish($d);
        $receipt = $this->book('2026-11-16T21:30:00Z')->assertCreated()->json();
        $d['revision'] = 1;
        $d['closed_dates'] = ['2026-11-17'];
        $this->publish($d);
        $this->book('2026-11-16T21:00:00Z', 'holiday')->assertConflict();
        // Retrying the same accepted identity returns its historical receipt.
        $this->book('2026-11-16T21:30:00Z')->assertOk()->assertJsonPath('id', $receipt['id'])
            ->assertJsonPath('operating_calendar_snapshot.revision', 1);
        $d['revision'] = 2;
        $d['enforce_operating_hours'] = false;
        $this->publish($d);
        $this->book('2026-11-16T21:00:00Z', 'disabled')->assertCreated()->assertJsonPath('operating_calendar_snapshot', null);
    }

    public function test_calendar_write_remains_owner_only_revision_checked_and_strict_boolean(): void
    {
        $other = User::factory()->create(['role' => 'vendor']);
        $route = '/api/stations/'.$this->station->id.'/information';
        $this->actingAs($other)->putJson($route, $this->details())->assertNotFound();
        $this->publish($this->details());
        $this->actingAs($this->vendor)->putJson($route, $this->details())->assertConflict();
        $bad = $this->details(1);
        $bad['enforce_operating_hours'] = 'yes';
        $this->putJson($route, $bad)->assertUnprocessable();
        $this->assertDatabaseHas('station_information', ['station_id' => $this->station->id, 'revision' => 1, 'enforce_operating_hours' => true]);
    }

    public function test_calendar_snapshot_is_database_immutable_but_cancellation_remains_available(): void
    {
        $this->publish($this->details());
        $id = $this->book('2026-11-16T04:00:00Z')->assertCreated()->json('id');
        try {
            DB::transaction(fn () => DB::table('reservations')->where('id', $id)->update(['operating_calendar_snapshot' => null]));
            $this->fail('Calendar evidence was modified.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('Reservation calendar snapshot is immutable', $e->getMessage());
        }
        $this->assertSame(1, Reservation::findOrFail($id)->operating_calendar_snapshot['revision']);
        $d = $this->details(1);
        $d['closed_dates'] = ['2026-11-16'];
        $this->publish($d);
        $this->actingAs($this->customer)->deleteJson('/api/reservations/'.$id)->assertOk()->assertJsonPath('status', 'cancelled');
    }

    public function test_new_start_rechecks_current_calendar_but_existing_session_can_finish(): void
    {
        $this->publish($this->details());
        $id = $this->book('2026-11-16T04:00:00Z')->assertCreated()->json('id');
        $this->travelTo(CarbonImmutable::parse('2026-11-16 04:00:00 UTC'));
        $d = $this->details(1);
        $d['closed_dates'] = ['2026-11-16'];
        $this->publish($d);
        $this->actingAs($this->customer)->postJson('/api/reservations/'.$id.'/simulate-start')->assertConflict();
        $this->assertSame(0, ChargingSession::count());
        $this->publish($this->details(2));
        $sid = $this->actingAs($this->customer)->postJson('/api/reservations/'.$id.'/simulate-start')->assertCreated()->json('id');
        $d['revision'] = 3;
        $this->publish($d);
        $this->actingAs($this->customer)->postJson('/api/reservations/'.$id.'/simulate-start')->assertOk()->assertJsonPath('id', $sid);
        $this->actingAs($this->vendor)->putJson('/api/simulations/'.$sid.'/meter', ['energy_wh' => 1000])->assertOk();
        $this->actingAs($this->customer)->postJson('/api/simulations/'.$sid.'/stop')->assertOk();
        $this->assertDatabaseHas('charging_sessions', ['id' => $sid, 'status' => 'stopped', 'energy_wh' => 1000]);
        $this->assertDatabaseCount('finance_invoices', 0); // Issuance remains its existing explicit financial action.
    }

    public function test_both_protocol_pending_commands_recheck_hours_without_dispatch_and_remain_cancellable(): void
    {
        Http::preventStrayRequests();
        config(['csms.session_bridge_enabled' => true, 'csms.simulator_enabled' => true]);
        foreach (['1.6', '2.0.1'] as $protocol) {
            $this->station = $this->station->replicate();
            $this->station->save();
            $this->travelTo(CarbonImmutable::parse('2026-11-16 03:00:00 UTC'));
            $this->publish($this->details());
            DB::table('csms_bindings')->insert(['station_id' => $this->station->id, 'identifier' => 'ev-test-calendar-'.str_replace('.', '', $protocol), 'protocol' => $protocol, 'created_at' => now(), 'updated_at' => now()]);
            $id = $this->book('2026-11-16T04:00:00Z', 'command-'.$protocol)->assertCreated()->json('id');
            $this->travelTo(CarbonImmutable::parse('2026-11-16 04:00:00 UTC'));
            $reply = $this->actingAs($this->customer)->postJson('/api/reservations/'.$id.'/csms-start')->assertAccepted();
            $command = ChargerCommand::findOrFail($reply->json('start_command.id'));
            $d = $this->details(1);
            $d['closed_dates'] = ['2026-11-16'];
            $this->publish($d);
            $blocked = app(CsmsDispatcher::class)->dispatch($command->id);
            $this->assertSame('pending', $blocked->status);
            $this->assertSame('pre_dispatch_operating_calendar', $blocked->failure_code);
            $this->actingAs($this->customer)->getJson('/api/simulations')->assertOk()->assertJsonPath('data.0.start_admission_blocked', true);
            $this->deleteJson('/api/reservations/'.$id)->assertOk()->assertJsonPath('status', 'cancelled');
            $this->assertSame('cancelled', $command->fresh()->status);
        }
        Http::assertNothingSent();
        $this->assertDatabaseCount('command_source_observations', 0);
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    public static function protocols(): array
    {
        return [['1.6'], ['2.0.1']];
    }

    private function mappedSource(string $protocol): Reservation
    {
        config(['csms.remote_authorization_enabled' => true, 'csms.session_bridge_enabled' => true, 'csms.rider_enabled' => true, 'csms.rider_client_id' => 'calendar-source', 'csms.rider_client_secret' => str_repeat('s', 64), 'csms.ingress_allow_local_http' => true, 'csms.graphql_secret' => str_repeat('g', 64), 'csms.graphql_url' => 'https://source.test/graph']);
        Http::preventStrayRequests();
        $company = VendorCompany::create(['owner_id' => $this->vendor->id, 'legal_name' => 'Calendar synthetic source', 'country' => 'AE', 'trade_license_number' => 'TEST', 'license_expires_on' => now()->addYear()->toDateString(), 'status' => 'approved']);
        $site = VendorSite::create(['company_id' => $company->id, 'station_id' => $this->station->id, 'name' => 'Calendar source site', 'country' => 'AE', 'timezone' => 'Asia/Dubai', 'address' => 'Synthetic', 'latitude' => 25, 'longitude' => 55]);
        $charger = VendorCharger::create(['company_id' => $company->id, 'site_id' => $site->id, 'asset_reference' => 'CALENDAR', 'manufacturer' => 'Synthetic', 'model' => 'Fixture', 'source' => 'simulator', 'ocpp_protocol' => $protocol]);
        $binding = DB::table('csms_bindings')->insertGetId(['station_id' => $this->station->id, 'vendor_charger_id' => $charger->id, 'identifier' => 'ev-test-calendar-source', 'protocol' => $protocol, 'created_at' => now(), 'updated_at' => now()]);
        $evse = Evse::create(['charger_id' => $charger->id, 'number' => 1, 'mode' => 'csms', 'binding_id' => $binding, 'source_evse_number' => $protocol === '1.6' ? 0 : 1]);
        $connector = VendorConnector::create(['charger_id' => $charger->id, 'connector_number' => 1, 'connector_type' => 'CCS2', 'max_power_kw' => 50]);
        $map = ConnectorMapping::create(['station_id' => $this->station->id, 'evse_id' => $evse->id, 'connector_id' => $connector->id, 'source_connector_number' => 1, 'source_target_key' => hash('sha256', 'calendar-source')]);
        $this->publish($this->details());
        $id = $this->book('2026-11-16T04:00:00Z', 'source-booking', ['connector_mapping_id' => $map->id])->assertCreated()->json('id');
        $this->travelTo(CarbonImmutable::parse('2026-11-16 04:00:00 UTC'));

        return Reservation::findOrFail($id);
    }

    #[DataProvider('protocols')]
    public function test_online_remote_callback_rechecks_pending_calendar_but_keeps_ongoing_authorization(string $protocol): void
    {
        $booking = $this->mappedSource($protocol);
        $queued = $this->actingAs($this->customer)->postJson('/api/reservations/'.$booking->id.'/csms-start')->assertAccepted();
        $command = ChargerCommand::findOrFail($queued->json('start_command.id'));
        $command->update(['status' => 'submitted']);
        $link = SessionLink::firstOrFail();
        // This is a controlled GraphQL protocol contract, not a source observation.
        $link->update(['remote_authorization_id' => 42]);
        Http::fake(function ($request) use ($protocol, $command, $link) {
            if (str_contains($request['query'], 'Authorizations')) {
                return Http::response(['data' => ['Authorizations' => [['id' => 42, 'idToken' => $command->payload['id_token'], 'idTokenType' => 'Central', 'status' => 'Accepted', 'additionalInfo' => [['type' => 'CREABIZ_REMOTE', 'additionalIdToken' => $link->remote_registration_nonce]], 'realTimeAuth' => 'Never', 'realTimeAuthUrl' => RemoteSessionAuthorization::CALLBACK, 'realTimeAuthTimeout' => 0, 'cacheExpiryDateTime' => now()->addMinutes(30)->toISOString(), 'concurrentTransaction' => false, 'isPrepaid' => false]]]]);
            }
            if (str_contains($request['query'], 'ChargingStations')) {
                return Http::response(['data' => ['ChargingStations' => [['id' => 10, 'isOnline' => true, 'protocol' => $protocol]]]]);
            }

            return Http::response(['data' => ['Connectors' => [['id' => 7]], 'StatusNotifications' => [['evseId' => $protocol === '1.6' ? 0 : 1, 'connectorId' => 1, 'connectorStatus' => 'Available', 'timestamp' => now()->toISOString()]]]]);
        });
        $token = $this->postJson('/api/internal/rider-oauth/token', ['grant_type' => 'client_credentials', 'client_id' => 'calendar-source', 'client_secret' => str_repeat('s', 64), 'audience' => 'creabiz-rider-authorize'])->assertOk()->json('access_token');
        $body = ['idToken' => $command->payload['id_token'], 'idTokenType' => 'Central', 'ocppConnectionName' => $link->identifier, 'connectorId' => 7];
        $this->withToken($token)->postJson('/api/internal/remote-session-authorize', $body)->assertOk()->assertJsonPath('data.allowed', 'ALLOWED');
        $d = $this->details(1);
        $d['closed_dates'] = ['2026-11-16'];
        $this->publish($d);
        $this->withToken($token)->postJson('/api/internal/remote-session-authorize', $body)->assertOk()->assertJsonPath('data.allowed', 'NOT_ALLOWED');
        $session = ChargingSession::findOrFail($link->charging_session_id);
        $this->assertSame('pending_start', $session->status);
        foreach (['charging', 'stopping'] as $state) {
            $session->update(['status' => $state]);
            $this->withToken($token)->postJson('/api/internal/remote-session-authorize', $body)->assertOk()->assertJsonPath('data.allowed', 'ALLOWED');
        }
        $this->assertSame('submitted', $command->fresh()->status);
        $this->assertDatabaseCount('command_source_observations', 0);
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    #[DataProvider('protocols')]
    public function test_rider_pending_receipt_cannot_bypass_new_calendar_and_ongoing_receipt_survives(string $protocol): void
    {
        $booking = $this->mappedSource($protocol);
        $token = RiderAccessToken::create(['user_id' => $this->customer->id, 'label' => 'Synthetic source card', 'suffix' => 'TEST', 'token_type' => 'ISO14443', 'token_digest' => hash('sha256', 'CALENDARTEST'), 'token_ciphertext' => encrypt('CALENDARTEST'), 'expires_at' => now()->addDay(), 'idempotency_key' => 'calendar-card', 'payload_hash' => str_repeat('a', 64), 'source_registration_nonce' => (string) Str::uuid(), 'provisioning_state' => 'active', 'provisioned_at' => now(), 'source_authorization_id' => 7]);
        $grant = DB::table('rider_charging_grants')->insertGetId(['token_id' => $token->id, 'company_id' => VendorCompany::firstOrFail()->id, 'station_id' => $this->station->id, 'state' => 'approved', 'expires_at' => now()->addHour(), 'created_at' => now(), 'updated_at' => now()]);
        $bridge = app(SessionBridge::class);
        $receipt = $bridge->start($booking, $this->customer->id, $token, $grant);
        $d = $this->details(1);
        $d['closed_dates'] = ['2026-11-16'];
        $this->publish($d);
        try {
            $bridge->start($booking, $this->customer->id, $token, $grant);
            $this->fail('Pending RFID admission bypassed the current calendar.');
        } catch (HttpExceptionInterface $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        ChargingSession::findOrFail($receipt['session']['id'])->update(['status' => 'charging']);
        $this->assertSame($receipt['session']['id'], $bridge->start($booking, $this->customer->id, $token, $grant)['session']['id']);
        Http::assertNothingSent();
        $this->assertDatabaseCount('charger_commands', 0);
        $this->assertDatabaseCount('finance_invoices', 0);
    }
}
