<?php

namespace Tests\Feature;

use App\Models\OcpiProvider;
use App\Models\OcpiRecord;
use App\Models\User;
use App\Services\Ocpi\CredentialExchange;
use App\Services\Ocpi\RecordSynchronizer;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OcpiCatalogPushTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-14T11:00:00Z'));
    }

    private function context(): array
    {
        Http::preventStrayRequests();
        config(['ocpi.connections.catalog' => ['managed_credentials' => true, 'incoming_modules' => ['locations', 'tariffs', 'sessions', 'cdrs'], 'origin' => 'https://partner.example', 'authorization_token' => base64_encode('SYNTHETIC-BOOTSTRAP'), 'party_country' => 'NL', 'party_id' => 'ABC', 'party_role' => 'CPO'],
            'ocpi.local_credentials' => ['url' => 'https://platform.example/api/roaming-protocol/versions', 'roles' => [['role' => 'EMSP', 'country_code' => 'AE', 'party_id' => 'EVX', 'business_details' => ['name' => 'Synthetic EMSP']]]]]);
        $owner = User::factory()->create(['role' => 'vendor']);
        $provider = OcpiProvider::create(['owner_id' => $owner->id, 'name' => 'Approved catalog fixture', 'connection_key' => 'catalog', 'authorized' => true, 'version' => '2.2.1', 'endpoints' => array_fill_keys(['locations', 'tariffs', 'sessions', 'cdrs'], 'https://partner.example/module')]);
        $token = bin2hex(random_bytes(32));
        DB::table('ocpi_credential_links')->insert(['provider_id' => $provider->id, 'owner_id' => $owner->id, 'generation' => 1, 'state' => 'active', 'configuration_hash' => app(CredentialExchange::class)->configurationHash($provider), 'bootstrap_hash' => hash('sha256', 'SYNTHETIC-BOOTSTRAP'), 'local_token_digest' => hash('sha256', $token), 'local_token_ciphertext' => Crypt::encryptString($token), 'remote_token_ciphertext' => Crypt::encryptString('SYNTHETIC-PEER'), 'created_at' => now(), 'updated_at' => now()]);

        return [$provider, $owner, ['Authorization' => 'Token '.base64_encode($token)], '/api/roaming-protocol/2.2.1/locations/NL/ABC/location-1'];
    }

    private function location(): array
    {
        return ['country_code' => 'NL', 'party_id' => 'ABC', 'id' => 'location-1', 'last_updated' => now()->subMinutes(5)->toISOString(), 'publish' => true, 'name' => 'Pushed partner station', 'address' => 'Synthetic address', 'city' => 'Amsterdam', 'country' => 'NLD', 'coordinates' => ['latitude' => '52.37', 'longitude' => '4.89'], 'private_extension' => 'PRIVATE-PARTNER-PAYLOAD', 'evses' => [['uid' => 'evse-1', 'status' => 'AVAILABLE', 'connectors' => [['id' => '1', 'standard' => 'IEC_62196_T2_COMBO']]]]];
    }

    public function test_catalog_routes_require_the_scoped_peer_token_even_with_an_admin_browser_session(): void
    {
        [, , , $path] = $this->context();
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);
        foreach ([['GET', $path], ['PUT', $path], ['PATCH', $path.'/evse-1/1'], ['GET', '/api/roaming-protocol/2.2.1/tariffs/NL/ABC/tariff-1'], ['PUT', '/api/roaming-protocol/2.2.1/tariffs/NL/ABC/tariff-1'], ['DELETE', '/api/roaming-protocol/2.2.1/tariffs/NL/ABC/tariff-1']] as [$method, $route]) {
            $this->json($method, $route, $this->location())->assertUnauthorized();
            $this->json($method, $route, $this->location(), ['Authorization' => 'Token '.base64_encode('INVALID-SYNTHETIC-TOKEN')])->assertUnauthorized();
        }
        $this->assertDatabaseCount('ocpi_records', 0);
        $this->assertDatabaseCount('ocpi_record_receipts', 0);
    }

    public function test_catalog_push_reuses_owned_operations_and_public_discovery_without_financial_or_charging_authority(): void
    {
        [$provider, $owner, $headers, $path] = $this->context();
        $this->getJson('/api/roaming-protocol/2.2.1', $headers)->assertOk()->assertJsonCount(5, 'data.endpoints');
        $this->putJson($path, $this->location(), $headers)->assertCreated();
        $this->putJson($path, $this->location(), $headers)->assertOk();
        $this->getJson($path.'/evse-1/1', $headers)->assertOk()->assertJsonPath('data.standard', 'IEC_62196_T2_COMBO');
        $this->getJson('/api/roaming/locations?q=Pushed')->assertOk()->assertJsonPath('data.0.availability', 'unknown')->assertJsonPath('data.0.remote_control', false)->assertJsonPath('data.0.reservable', false)->assertDontSee('PRIVATE-PARTNER-PAYLOAD');
        $this->actingAs($owner)->getJson('/api/ocpi/providers/'.$provider->id.'/records/locations')->assertOk()->assertJsonPath('data.0.normalized.name', 'Pushed partner station');
        $this->app['auth']->forgetGuards();
        $this->withToken($owner->createToken('native-ocpi')->plainTextToken)->getJson('/api/v1/ocpi/providers/'.$provider->id.'/records/locations')->assertOk()->assertJsonPath('data.0.normalized.availability', 'unknown');
        $this->getJson('/api/v1/ocpi/providers/'.$provider->id.'/credentials')->assertOk()->assertJsonPath('active_receiver_modules', ['cdrs', 'locations', 'sessions', 'tariffs']);
        $this->app['auth']->forgetGuards();
        $this->withToken(User::factory()->create(['role' => 'admin'])->createToken('forbidden-native-admin')->plainTextToken)->getJson('/api/v1/ocpi/providers/'.$provider->id.'/records/locations')->assertForbidden();
        $this->assertDatabaseCount('ocpi_record_receipts', 1);
        $this->assertDatabaseCount('finance_invoices', 0);
        $this->assertDatabaseCount('charger_commands', 0);
        Http::assertNothingSent();
    }

    public function test_nested_location_updates_preserve_other_components_and_reject_stale_conflicting_or_ambiguous_data(): void
    {
        [, , $headers, $path] = $this->context();
        $location = $this->location();
        $this->putJson($path, $location, $headers)->assertCreated();
        $time = now()->subMinutes(3)->toISOString();
        $this->patchJson($path.'/evse-1/1', ['last_updated' => $time, 'standard' => 'IEC_62196_T2'], $headers)->assertOk();
        $this->getJson($path, $headers)->assertOk()->assertJsonPath('data.evses.0.connectors.0.standard', 'IEC_62196_T2')->assertJsonPath('data.address', 'Synthetic address');
        $this->patchJson($path.'/evse-1/1', ['last_updated' => $time, 'standard' => 'CHADEMO'], $headers)->assertConflict();
        $this->putJson($path, $location, $headers)->assertConflict();
        $this->patchJson($path.'/missing', ['status' => 'REMOVED', 'last_updated' => now()->subMinute()->toISOString()], $headers)->assertNotFound();
        $this->putJson($path.'/evse-2', ['uid' => 'evse-2', 'last_updated' => now()->subMinutes(2)->toISOString(), 'status' => 'AVAILABLE', 'connectors' => [['id' => '1', 'standard' => 'CHADEMO']]], $headers)->assertOk();
        $this->patchJson($path.'/evse-1', ['status' => 'REMOVED', 'last_updated' => now()->subMinute()->toISOString()], $headers)->assertOk();
        $record = OcpiRecord::where('module', 'locations')->firstOrFail();
        $this->assertSame(['CHADEMO'], $record->normalized['connector_standards']);
        $this->assertCount(2, $record->normalized['evses']);
        $bad = $record->payload;
        $bad['last_updated'] = now()->toISOString();
        $bad['evses'][] = $bad['evses'][0];
        $this->putJson($path, $bad, $headers)->assertConflict();
        $this->assertDatabaseCount('ocpi_records', 1);
    }

    public function test_tariffs_are_partner_reported_non_billable_and_unpublishing_location_removes_discovery(): void
    {
        [, , $headers, $path] = $this->context();
        $this->putJson($path, $this->location(), $headers)->assertCreated();
        $tariff = ['country_code' => 'NL', 'party_id' => 'ABC', 'id' => 'tariff-1', 'last_updated' => now()->subMinute()->toISOString(), 'currency' => 'EUR', 'elements' => [['price_components' => [['type' => 'ENERGY', 'price' => '0.45', 'step_size' => 1, 'vat' => '21']]]]];
        $this->putJson('/api/roaming-protocol/2.2.1/tariffs/NL/ABC/tariff-1', $tariff, $headers)->assertCreated();
        $this->getJson('/api/roaming-protocol/2.2.1/tariffs/NL/ABC/tariff-1', $headers)->assertOk()->assertJsonPath('data.currency', 'EUR');
        $this->assertFalse(OcpiRecord::where('module', 'tariffs')->firstOrFail()->normalized['billing_eligible']);
        $this->patchJson($path, ['publish' => false, 'last_updated' => now()->toISOString()], $headers)->assertOk();
        $this->getJson('/api/roaming/locations?q=Pushed')->assertOk()->assertJsonPath('total', 0);
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    public function test_peer_identity_module_scope_revocation_and_suspended_owner_are_rechecked(): void
    {
        [$provider, $owner, $headers, $path] = $this->context();
        $this->putJson(str_replace('/NL/ABC/', '/NL/BAD/', $path), $this->location(), $headers)->assertForbidden();
        $this->putJson($path.'/evse-1', ['uid' => 'different', 'last_updated' => now()->toISOString()], $headers)->assertForbidden();
        $this->putJson($path, $this->location(), $headers)->assertCreated();
        $owner->forceFill(['account_status' => 'suspended'])->save();
        $this->getJson($path, $headers)->assertUnauthorized();
        $this->patchJson($path, ['publish' => false, 'last_updated' => now()->toISOString()], $headers)->assertUnauthorized();
        $owner->forceFill(['account_status' => 'active'])->save();
        config(['ocpi.connections.catalog.incoming_modules' => ['sessions', 'cdrs']]);
        $this->putJson($path, $this->location(), $headers)->assertUnauthorized();
        $provider->update(['authorized' => false]);
        $this->getJson($path, $headers)->assertUnauthorized();
        $this->assertDatabaseCount('ocpi_record_receipts', 1);
    }

    public function test_tariff_withdrawal_keeps_encrypted_source_history_and_fences_delayed_push_and_pull_reactivation(): void
    {
        [$provider, $owner, $headers] = $this->context();
        $path = '/api/roaming-protocol/2.2.1/tariffs/NL/ABC/tariff-1';
        $tariff = ['country_code' => 'NL', 'party_id' => 'ABC', 'id' => 'tariff-1', 'last_updated' => now()->subMinute()->toISOString(), 'currency' => 'EUR', 'elements' => [['price_components' => [['type' => 'ENERGY', 'price' => '0.45', 'step_size' => 1]]]]];
        $this->putJson($path, $tariff, $headers)->assertCreated();
        $record = OcpiRecord::where('module', 'tariffs')->firstOrFail();
        $this->deleteJson($path, [], $headers)->assertOk();
        $this->deleteJson($path, [], $headers)->assertOk();
        $this->getJson($path, $headers)->assertNotFound();
        $this->putJson($path, $tariff, $headers)->assertConflict();
        $this->actingAs($owner)->getJson('/api/ocpi/providers/'.$provider->id.'/records/tariffs')->assertOk()->assertJsonPath('data.0.catalog_retired', true)->assertHeader('Cache-Control', 'no-store, private');
        $retired = DB::table('ocpi_catalog_retirements')->first();
        $this->assertSame($record->content_hash, $retired->content_hash);
        $this->assertStringNotContainsString('tariff-1', $retired->payload_encrypted);
        $this->assertSame('EUR', json_decode(Crypt::decryptString($retired->payload_encrypted), true)['currency']);
        $delayed = array_replace($tariff, ['last_updated' => now()->subSeconds(30)->toISOString()]);
        $counts = app(RecordSynchronizer::class)->ingest($provider, 'tariffs', [$delayed]);
        $this->assertSame(1, $counts['stale']);
        $this->assertSame($record->content_hash, $record->fresh()->content_hash);
        $this->travel(1)->seconds();
        $fresh = array_replace($tariff, ['last_updated' => now()->toISOString()]);
        $this->putJson($path, $fresh, $headers)->assertOk();
        $this->getJson($path, $headers)->assertOk();
        $this->getJson('/api/ocpi/providers/'.$provider->id.'/records/tariffs')->assertOk()->assertJsonPath('data.0.catalog_retired', false);
        $this->assertDatabaseCount('ocpi_catalog_retirements', 1);
        $this->assertDatabaseCount('ocpi_record_receipts', 3);
        $this->assertDatabaseCount('finance_invoices', 0);
        $this->expectException(QueryException::class);
        DB::table('ocpi_catalog_retirements')->where('id', $retired->id)->delete();
    }
}
