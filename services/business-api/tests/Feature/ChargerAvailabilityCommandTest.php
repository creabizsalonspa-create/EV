<?php

namespace Tests\Feature;

use App\Models\Station;
use App\Models\User;
use App\Models\VendorOps\VendorCharger;
use App\Models\VendorOps\VendorCompany;
use App\Models\VendorOps\VendorSite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class ChargerAvailabilityCommandTest extends TestCase
{
    use RefreshDatabase;

    #[TestWith(['1.6', false, 'Accepted', 'accepted'])]
    #[TestWith(['1.6', true, 'Rejected', 'rejected'])]
    #[TestWith(['2.0.1', false, 'Scheduled', 'scheduled'])]
    #[TestWith(['2.0.1', true, 'Accepted', 'accepted'])]
    #[TestWith(['2.0.1', true, 'Unmatched', 'transport_submitted'])]
    #[TestWith(['2.0.1', true, 'Ambiguous', 'transport_submitted'])]
    #[TestWith(['1.6', true, 'Uncertain', 'accepted'])]
    public function test_owned_commands_are_correlated_and_one_shot(string $protocol, bool $targetConnector, string $reply, string $outcome): void
    {
        $f = $this->fixture($protocol);
        $planned = $protocol === '1.6'
            ? ['connectorId' => $targetConnector ? 4 : 0, 'type' => 'Inoperative']
            : ['operationalStatus' => 'Inoperative'] + ($targetConnector ? ['evse' => ['id' => 3, 'connectorId' => 4]] : []);
        $this->source($f, $planned, $reply);
        $body = $this->body() + ['connector_id' => $targetConnector ? $f['connector'] : null];
        $this->actingAs($f['owner'])->getJson($f['path'])->assertOk()->assertJsonPath('can_request', true)->assertJsonPath('connectors.0.id', $f['connector']);
        $id = $this->postJson($f['path'], $body)->assertCreated()->assertJsonPath('status', $reply === 'Uncertain' ? 'outcome_unknown' : 'transport_submitted')
            ->assertJsonPath('physical_availability_verified', false)->json('id');
        $this->postJson($f['path'], $body)->assertOk()->assertJsonPath('id', $id);
        $this->postJson($f['path'], array_replace($body, ['requested_state' => 'Operative']))->assertConflict();
        $this->postJson($f['path'], array_replace($body, ['idempotency_key' => 'other']))->assertConflict();
        $this->travel(1)->seconds();
        $this->postJson($f['path'].'/'.$id.'/observe')->assertOk()->assertJsonPath('status', $outcome)->assertJsonPath('physical_availability_verified', false);
        $this->assertStringNotContainsString('PRIVATE', DB::table('charger_availability_operations')->where('id', $id)->value('reason'));
        $this->assertCount(1, Http::recorded(fn ($request) => str_contains($request->url(), '/configuration/changeAvailability?')));
        $this->actingAs(User::factory()->create(['role' => 'vendor']))->getJson($f['path'])->assertNotFound();
        $this->postJson($f['path'].'/'.$id.'/observe')->assertNotFound();
    }

    #[TestWith(['binding'])]
    #[TestWith(['actor'])]
    public function test_changes_during_token_io_cannot_dispatch(string $which): void
    {
        $f = $this->fixture('2.0.1');
        $this->source($f, ['operationalStatus' => 'Inoperative'], onToken: function () use ($f, $which) {
            if ($which === 'binding') {
                DB::table('csms_bindings')->where('id', $f['binding'])->update(['identifier' => 'ev-test-replacement']);
            } else {
                DB::table('users')->where('id', $f['owner']->id)->increment('auth_epoch');
            }
        });
        $this->actingAs($f['owner'])->postJson($f['path'], $this->body())->assertStatus($which === 'actor' ? 409 : 201);
        $this->assertDatabaseHas('charger_availability_operations', ['status' => 'not_dispatched', 'attempted_at' => null]);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/configuration/changeAvailability?'));
    }

    public function test_live_dispatch_cannot_be_observed_but_interrupted_dispatch_can_recover(): void
    {
        $f = $this->fixture('2.0.1');
        $this->source($f, ['operationalStatus' => 'Inoperative']);
        $id = $this->actingAs($f['owner'])->postJson($f['path'], $this->body())->assertCreated()->json('id');
        DB::table('charger_availability_operations')->where('id', $id)->update(['status' => 'dispatching']);

        $path = $f['path'].'/'.$id.'/observe';
        $this->postJson($path)->assertOk()->assertJsonPath('status', 'dispatching')->assertJsonPath('can_observe', false);
        Http::assertNotSent(fn ($request) => str_contains($request['query'] ?? '', 'requestMessageId'));

        // Age the claim while keeping the synthetic source messages inside their original window.
        DB::table('charger_availability_operations')->where('id', $id)->update(['created_at' => now()->subMinute()]);
        $this->postJson($path)->assertOk()->assertJsonPath('status', 'accepted')->assertJsonPath('can_observe', false);
    }

    public function test_source_station_replacement_during_confirmation_keeps_outcome_unresolved(): void
    {
        $fixture = $this->fixture('2.0.1');
        $this->source($fixture, ['operationalStatus' => 'Inoperative'], reply: 'StationReplaced');
        $id = $this->actingAs($fixture['owner'])->postJson($fixture['path'], $this->body())->assertCreated()->json('id');

        $this->postJson($fixture['path'].'/'.$id.'/observe')->assertOk()
            ->assertJsonPath('status', 'transport_submitted')->assertJsonPath('observed_at', null);
        $this->assertDatabaseHas('charger_availability_operations', ['id' => $id, 'source_reply_id' => null]);
    }

    #[TestWith(['1.6'])]
    #[TestWith(['2.0.1'])]
    public function test_source_key_change_during_confirmation_preserves_unresolved_command(string $protocol): void
    {
        $f = $this->fixture($protocol);
        $payload = $protocol === '1.6' ? ['connectorId' => 0, 'type' => 'Inoperative'] : ['operationalStatus' => 'Inoperative'];
        $change = true;
        $this->source($f, $payload, onConfirmation: function () use (&$change) {
            if ($change) {
                config(['csms.graphql_secret' => str_repeat('y', 32)]);
            }
        });
        $id = $this->actingAs($f['owner'])->postJson($f['path'], $this->body())
            ->assertCreated()->assertJsonPath('status', 'transport_submitted')->json('id');
        $this->travel(1)->seconds();
        $path = $f['path'].'/'.$id.'/observe';
        $this->postJson($path)->assertServiceUnavailable();
        $this->assertDatabaseHas('charger_availability_operations', ['id' => $id, 'status' => 'transport_submitted', 'source_reply_id' => null, 'observed_at' => null]);
        config(['csms.graphql_secret' => str_repeat('x', 32)]);
        $change = false;
        $this->postJson($path)->assertOk()->assertJsonPath('status', 'accepted')->assertJsonPath('physical_availability_verified', false);
        $this->assertCount(1, Http::recorded(fn ($request) => str_contains($request->url(), '/configuration/changeAvailability?')));
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    #[TestWith(['revocation'])]
    #[TestWith(['global_expiry'])]
    #[TestWith(['individual_expiry'])]
    public function test_mobile_authority_is_current_when_observation_commits(string $change): void
    {
        config(['sanctum.expiration' => 10]);
        $fixture = $this->fixture('2.0.1');
        $token = $fixture['owner']->createToken('mobile');
        $token->accessToken->forceFill([
            'created_at' => $change === 'individual_expiry' ? now() : now()->subMinutes(9),
            'expires_at' => $change === 'individual_expiry' ? now()->addMinute() : null,
        ])->save();
        $this->source($fixture, ['operationalStatus' => 'Inoperative'], onConfirmation: function () use ($token, $change) {
            if ($change === 'revocation') {
                $token->accessToken->delete();
            } else {
                $this->travel(2)->minutes();
            }
        });
        $path = str_replace('/api/', '/api/v1/', $fixture['path']);
        $id = $this->withToken($token->plainTextToken)->postJson($path, $this->body())->assertCreated()->json('id');

        $this->app['auth']->forgetGuards();
        $this->postJson($path.'/'.$id.'/observe')->assertForbidden();
        $this->assertDatabaseHas('charger_availability_operations', [
            'id' => $id, 'status' => 'transport_submitted', 'observed_at' => null, 'source_reply_id' => null,
        ]);
        $this->assertDatabaseMissing('audit_events', ['action' => 'charger.availability_source_observed', 'subject' => (string) $id]);
        $this->assertCount(1, Http::recorded(fn ($request) => str_contains($request->url(), '/configuration/changeAvailability?')));
    }

    private function body(): array
    {
        return ['requested_state' => 'Inoperative', 'reason' => 'PRIVATE data for simulator', 'idempotency_key' => 'sim-availability'];
    }

    private function fixture(string $protocol): array
    {
        config(['csms.availability_command_enabled' => true, 'csms.session_bridge_enabled' => true, 'csms.graphql_secret' => str_repeat('x', 32),
            'csms.graphql_url' => 'http://127.0.0.1:8095/v1/graphql', 'csms.operations_base_url' => 'http://127.0.0.1:8080']);
        $owner = User::factory()->create(['role' => 'vendor']);
        $station = Station::create(['vendor_id' => $owner->id, 'name' => 'Availability fixture', 'country' => 'AE', 'currency' => 'AED', 'timezone' => 'Asia/Dubai', 'latitude' => 25, 'longitude' => 55, 'source' => 'simulator', 'connector_type' => 'CCS2', 'power_kw' => 50]);
        $company = VendorCompany::create(['owner_id' => $owner->id, 'legal_name' => 'Synthetic availability', 'country' => 'AE', 'trade_license_number' => 'SYNTHETIC', 'license_expires_on' => now()->addYear()->toDateString(), 'status' => 'approved']);
        $site = VendorSite::create(['company_id' => $company->id, 'station_id' => $station->id, 'name' => 'Fixture', 'country' => 'AE', 'timezone' => 'Asia/Dubai', 'address' => 'Synthetic', 'latitude' => 25, 'longitude' => 55]);
        $charger = VendorCharger::create(['company_id' => $company->id, 'site_id' => $site->id, 'asset_reference' => 'AVAILABILITY', 'manufacturer' => 'Synthetic', 'model' => 'Fixture', 'source' => 'simulator', 'ocpp_protocol' => $protocol]);
        $identifier = 'ev-test-availability-'.$station->id;
        $binding = DB::table('csms_bindings')->insertGetId(['station_id' => $station->id, 'vendor_charger_id' => $charger->id, 'identifier' => $identifier, 'protocol' => $protocol]);
        $sourceEvse = $protocol === '1.6' ? 0 : 3;
        $evse = DB::table('charging_evses')->insertGetId(['charger_id' => $charger->id, 'number' => 1, 'mode' => 'csms', 'binding_id' => $binding, 'source_evse_number' => $sourceEvse]);
        $connector = DB::table('vendor_connectors')->insertGetId(['charger_id' => $charger->id, 'connector_number' => 1, 'connector_type' => 'CCS2', 'max_power_kw' => 50]);
        DB::table('charging_connector_mappings')->insert(['station_id' => $station->id, 'evse_id' => $evse, 'connector_id' => $connector, 'source_connector_number' => 4,
            'source_target_key' => hash('sha256', $binding.':'.$sourceEvse.':4')]);
        $path = '/api/vendor-ops/companies/'.$company->id.'/chargers/'.$charger->id.'/availability-operations';

        return compact('owner', 'binding', 'connector', 'identifier', 'path', 'protocol');
    }

    private function source(array $fixture, array $payload, string $reply = 'Accepted', ?callable $onToken = null, ?callable $onConfirmation = null): void
    {
        Http::preventStrayRequests();
        $stationId = 9;
        Http::fake(function ($request) use ($fixture, $payload, $reply, $onToken, $onConfirmation, &$stationId) {
            if (str_ends_with($request->url(), '/dev/token')) {
                $onToken?->__invoke();

                return Http::response(['accessToken' => 'synthetic']);
            }
            if (str_contains($request->url(), '/configuration/changeAvailability?')) {
                $this->assertStringContainsString('/ocpp/'.$fixture['protocol'].'/', $request->url());
                $this->assertStringContainsString('identifier='.$fixture['identifier'].'&tenantId=1&staleAfterSeconds=8', $request->url());
                $this->assertSame($payload, $request->data());

                return $reply === 'Uncertain' ? Http::response([], 503) : Http::response([['success' => true]]);
            }
            if (str_contains($request['query'], 'ChargingStations')) {
                return Http::response(['data' => ['ChargingStations' => [['id' => $stationId, 'isOnline' => true, 'protocol' => $fixture['protocol']]]]]);
            }
            $time = now()->toISOString();
            if (str_contains($request['query'], 'requestMessageId')) {
                $onConfirmation?->__invoke();
                if ($reply === 'StationReplaced') {
                    $stationId = 10;
                }
                $rows = [['id' => 92, 'type' => 3, 'createdAt' => $time, 'payload' => ['status' => in_array($reply, ['Uncertain', 'StationReplaced'], true) ? 'Accepted' : $reply],
                    'requestMessageId' => 91, 'correlationId' => $reply === 'Unmatched' ? 'different' : 'synthetic']];
            } elseif (str_contains($request['query'], 'watermark')) {
                $this->assertSame(90, $request['variables']['watermark']);
                $rows = [['id' => 91, 'createdAt' => $time, 'payload' => $payload, 'correlationId' => 'synthetic']];
                if ($reply === 'Ambiguous') {
                    $rows[] = ['id' => 94, 'createdAt' => $time, 'payload' => $payload, 'correlationId' => 'other'];
                }
            } else {
                $rows = [['id' => 90, 'createdAt' => $time]];
            }

            return Http::response(['data' => ['OCPPMessages' => $rows]]);
        });
    }
}
