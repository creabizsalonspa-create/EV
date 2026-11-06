<?php

namespace Tests\Feature;

use App\Models\Station;
use App\Models\User;
use App\Models\VendorOps\VendorCharger;
use App\Models\VendorOps\VendorCompany;
use App\Models\VendorOps\VendorSite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ChargerConfigurationTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(string $protocol = '1.6'): array
    {
        config(['csms.configuration_read_enabled' => true, 'csms.session_bridge_enabled' => true, 'csms.graphql_secret' => str_repeat('x', 64), 'csms.graphql_url' => 'http://127.0.0.1:8095/v1/graphql', 'csms.operations_base_url' => 'http://127.0.0.1:8080']);
        $owner = User::factory()->create(['role' => 'vendor']);
        $station = Station::create(['vendor_id' => $owner->id, 'name' => 'Configuration fixture', 'country' => 'AE', 'currency' => 'AED', 'timezone' => 'Asia/Dubai', 'latitude' => 25, 'longitude' => 55, 'source' => 'simulator', 'connector_type' => 'CCS2', 'power_kw' => 50]);
        $company = VendorCompany::create(['owner_id' => $owner->id, 'legal_name' => 'Synthetic settings', 'country' => 'AE', 'trade_license_number' => 'SYNTHETIC', 'license_expires_on' => now()->addYear()->toDateString(), 'status' => 'approved']);
        $site = VendorSite::create(['company_id' => $company->id, 'station_id' => $station->id, 'name' => 'Fixture', 'country' => 'AE', 'timezone' => 'Asia/Dubai', 'address' => 'Synthetic', 'latitude' => 25, 'longitude' => 55]);
        $charger = VendorCharger::create(['company_id' => $company->id, 'site_id' => $site->id, 'asset_reference' => 'CONFIG', 'manufacturer' => 'Synthetic', 'model' => 'Fixture', 'source' => 'simulator', 'ocpp_protocol' => $protocol]);
        $binding = DB::table('csms_bindings')->insertGetId(['station_id' => $station->id, 'vendor_charger_id' => $charger->id, 'identifier' => 'ev-test-config-fixture-'.$station->id, 'protocol' => $protocol, 'created_at' => now(), 'updated_at' => now()]);
        $path = '/api/vendor-ops/companies/'.$company->id.'/chargers/'.$charger->id.'/configuration-reads';
        $this->actingAs($owner);

        return compact('owner', 'station', 'company', 'charger', 'binding', 'path', 'protocol');
    }

    private function source(string $protocol, bool $lost = false, ?callable $duringToken = null): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(function ($r) use ($protocol, $lost, $duringToken) {
            if (str_ends_with($r->url(), '/graphql')) {
                $query = $r->data()['query'];
                $data = match (true) {
                    str_contains($query, 'ChargingStations') => ['ChargingStations' => [['id' => 7, 'isOnline' => true, 'protocol' => 'ocpp'.$protocol]]],
                    str_contains($query, 'requestMessageId') => ['OCPPMessages' => [['id' => 12, 'createdAt' => now()->toISOString(), 'type' => 3, 'payload' => $protocol === '1.6'
                        ? ['configurationKey' => [['key' => 'HeartbeatInterval', 'readonly' => true, 'value' => '30'], ['key' => 'MeterValueSampleInterval', 'readonly' => true, 'value' => 'PRIVATE-SHOULD-NOT-RENDER'], ['key' => 'NumberOfConnectors', 'readonly' => true, 'value' => '1'], ['key' => 'AuthorizationKey', 'readonly' => true, 'value' => 'PRIVATE-SOURCE-CREDENTIAL']]]
                        : ['getVariableResult' => [['component' => ['name' => 'OCPPCommCtrlr'], 'variable' => ['name' => 'HeartbeatInterval'], 'attributeStatus' => 'Accepted', 'attributeValue' => '30'], ['component' => ['name' => 'SampledDataCtrlr'], 'variable' => ['name' => 'TxUpdatedInterval'], 'attributeStatus' => 'Accepted', 'attributeValue' => 'PRIVATE-SHOULD-NOT-RENDER'], ['component' => ['name' => 'ClockCtrlr'], 'variable' => ['name' => 'TimeZone'], 'attributeStatus' => 'Accepted', 'attributeValue' => 'UTC']]]]]],
                    default => ['OCPPMessages' => [['id' => 11, 'createdAt' => now()->toISOString()]]],
                };

                return Http::response(['data' => $data]);
            }
            if (str_ends_with($r->url(), '/dev/token')) {
                if ($duringToken) {
                    $duringToken();
                }

                return Http::response(['accessToken' => 'PRIVATE-SERVICE-TOKEN']);
            }

            return Http::response($lost ? [] : [['success' => true, 'payload' => 'PRIVATE-ACK-DETAIL']], $lost ? 503 : 200);
        });
    }

    public function test_both_protocols_use_fixed_reads_and_only_publish_sanitized_correlated_source_values(): void
    {
        foreach (['1.6', '2.0.1'] as $protocol) {
            $f = $this->fixture($protocol);
            $this->source($protocol);
            $body = ['idempotency_key' => 'read-'.$protocol, 'reason' => 'Inspect operating settings safely', 'keys' => ['AuthorizationKey']];
            $created = $this->postJson($f['path'], $body)->assertCreated()->assertJsonPath('status', 'transport_submitted')->assertJsonPath('settings_changed', false);
            $id = $created->json('id');
            $this->postJson($f['path'], $body)->assertOk()->assertJsonPath('id', $id);
            Http::assertSentCount(3);
            Http::assertSent(fn ($r) => str_contains($r->url(), $protocol === '1.6' ? '/configuration/getConfiguration?' : '/monitoring/getVariables?') && ! str_contains(json_encode($r->data()), 'AuthorizationKey'));
            $this->postJson($f['path'].'/'.$id.'/observe')->assertOk()->assertJsonPath('status', 'source_observed')->assertJsonCount(3, 'snapshot')->assertJsonPath('snapshot.0.value', '30')->assertJsonPath('snapshot.1.value', null)->assertJsonPath('snapshot.1.status', 'invalid_value')->assertDontSee('PRIVATE-')->assertJsonPath('physical_charging_verified', false);
            $this->getJson($f['path'])->assertOk()->assertJsonMissingPath('data.0.reason')->assertJsonMissingPath('data.0.source_payload_hash')->assertDontSee('PRIVATE-');
        }
        $this->assertDatabaseCount('charging_sessions', 0);
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    public function test_uncertain_transport_is_not_replayed_and_source_observation_does_not_dispatch(): void
    {
        $f = $this->fixture();
        $this->source('1.6', true);
        $body = ['idempotency_key' => 'lost', 'reason' => 'Inspect operating settings safely'];
        $id = $this->postJson($f['path'], $body)->assertCreated()->assertJsonPath('status', 'outcome_unknown')->json('id');
        $this->postJson($f['path'], $body)->assertOk()->assertJsonPath('status', 'outcome_unknown');
        Http::assertSentCount(3);
        $this->postJson($f['path'], array_replace($body, ['reason' => 'Changed private operator reason']))->assertConflict();
        $this->postJson($f['path'], array_replace($body, ['idempotency_key' => 'replacement']))->assertConflict();
        Http::assertSentCount(3);
        $this->postJson($f['path'].'/'.$id.'/observe')->assertOk()->assertJsonPath('status', 'source_observed');
        Http::assertSentCount(6);
    }

    public function test_offline_device_error_reply_can_be_observed_without_another_command_or_private_error_text(): void
    {
        $f = $this->fixture();
        $this->source('1.6');
        $id = $this->postJson($f['path'], ['idempotency_key' => 'offline-error', 'reason' => 'Inspect operating settings safely'])->assertCreated()->json('id');
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(function ($r) {
            $query = $r->data()['query'];
            $rows = str_contains($query, 'ChargingStations') ? ['ChargingStations' => [['id' => 7, 'isOnline' => false, 'protocol' => 'ocpp1.6']]]
                : ['OCPPMessages' => str_contains($query, 'requestMessageId') ? [['id' => 12, 'createdAt' => now()->toISOString(), 'type' => 4, 'payload' => 'PRIVATE DEVICE ERROR']] : [['id' => 11, 'createdAt' => now()->toISOString()]]];

            return Http::response(['data' => $rows]);
        });
        $this->postJson($f['path'].'/'.$id.'/observe')->assertOk()->assertJsonPath('status', 'device_rejected')->assertJsonPath('snapshot', [])->assertJsonPath('settings_changed', false)->assertDontSee('PRIVATE');
        Http::assertSentCount(3);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/ocpp/') || str_contains($r->url(), '/dev/token'));
    }

    public function test_current_role_recheck_blocks_dispatch_after_service_token_discovery(): void
    {
        $f = $this->fixture();
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);
        $this->source('1.6', false, fn () => $admin->forceFill(['role' => 'customer'])->save());
        $this->postJson($f['path'], ['idempotency_key' => 'revoked', 'reason' => 'Inspect operating settings safely'])->assertNotFound();
        Http::assertSentCount(2);
        $this->assertDatabaseHas('charger_configuration_reads', ['status' => 'not_dispatched']);
    }

    public function test_foreign_actors_and_disabled_or_production_adapters_cannot_request_device_reads(): void
    {
        $f = $this->fixture();
        $this->source('1.6');
        $body = ['idempotency_key' => 'forbidden', 'reason' => 'Inspect operating settings safely'];
        $this->actingAs(User::factory()->create(['role' => 'vendor']))->postJson($f['path'], $body)->assertNotFound();
        $this->actingAs($f['owner']);
        config(['csms.configuration_read_enabled' => false]);
        $this->postJson($f['path'], $body)->assertStatus(503);
        config(['csms.configuration_read_enabled' => true]);
        app()->detectEnvironment(fn () => 'production');
        $this->withSession(['_token' => 'configuration-production-csrf'])->postJson($f['path'], $body, ['X-CSRF-TOKEN' => 'configuration-production-csrf'])->assertStatus(503);
        Http::assertNothingSent();
        $this->assertDatabaseCount('charger_configuration_reads', 0);
    }
}
