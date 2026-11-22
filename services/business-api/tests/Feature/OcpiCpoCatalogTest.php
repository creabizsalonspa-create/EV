<?php

namespace Tests\Feature;

use App\Models\OcpiProvider;
use App\Models\User;
use App\Services\Ocpi\CredentialExchange;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class OcpiCpoCatalogTest extends TestCase
{
    use RefreshDatabase;

    private const SENDER = '/api/roaming-protocol/2.2.1/cpo';

    private const PROTOCOL = '/api/roaming-protocol/2.2.1';

    private function source(User $vendor): array
    {
        $station = DB::table('stations')->insertGetId([
            'vendor_id' => $vendor->id, 'name' => 'Test station', 'country' => 'AE', 'currency' => 'AED', 'timezone' => 'Asia/Dubai',
            'latitude' => 25.687, 'longitude' => 55.778, 'source' => 'simulator', 'connector_type' => 'CCS2', 'power_kw' => 50,
        ]);
        $company = DB::table('vendor_companies')->insertGetId([
            'owner_id' => $vendor->id, 'legal_name' => 'CPO Sandbox', 'country' => 'AE', 'trade_license_number' => 'fixture',
            'license_expires_on' => now()->addYear()->toDateString(), 'status' => 'approved',
        ]);
        $site = DB::table('vendor_sites')->insertGetId([
            'company_id' => $company, 'station_id' => $station, 'name' => 'Sandbox CPO Site', 'country' => 'AE', 'timezone' => 'Asia/Dubai',
            'address' => 'Test Road', 'latitude' => 25.687, 'longitude' => 55.778,
        ]);
        $charger = DB::table('vendor_chargers')->insertGetId([
            'company_id' => $company, 'site_id' => $site, 'asset_reference' => 'test-50', 'manufacturer' => 'Test', 'model' => 'Test', 'source' => 'simulator',
        ]);
        $connector = DB::table('vendor_connectors')->insertGetId(['charger_id' => $charger, 'connector_number' => 1, 'connector_type' => 'CCS2', 'max_power_kw' => 50]);
        $evse = DB::table('charging_evses')->insertGetId(['charger_id' => $charger, 'number' => 1, 'mode' => 'simulator']);
        DB::table('charging_connector_mappings')->insert(['station_id' => $station, 'evse_id' => $evse, 'connector_id' => $connector]);
        $tariff = DB::table('finance_tariff_versions')->insertGetId([
            'station_id' => $station, 'created_by' => $vendor->id, 'rate_minor_per_kwh' => 129, 'commission_bps' => 0,
            'tax_bps' => 500, 'currency' => 'AED', 'minor_units' => 2, 'effective_from' => now()->subDay(), 'created_at' => now(),
        ]);

        return [$site, $tariff, $evse, $connector];
    }

    private function peer(User $vendor, string $key, array $sources): array
    {
        [$site, $tariff, , $connector] = $sources;
        config([
            'ocpi.local_credentials' => ['url' => 'https://platform.example/api/roaming-protocol/versions', 'roles' => [
                ['role' => 'EMSP', 'country_code' => 'AE', 'party_id' => 'EVY', 'business_details' => ['name' => 'Synthetic eMSP']],
            ]],
            'ocpi.local_credential_profiles.catalog' => ['url' => 'https://platform.example/api/roaming-protocol/versions', 'roles' => [
                ['role' => 'CPO', 'country_code' => 'AE', 'party_id' => 'EVX', 'business_details' => ['name' => 'Synthetic CPO']],
            ]],
            'ocpi.connections.'.$key => [
                'managed_credentials' => true, 'origin' => 'https://emsp.example', 'authorization_token' => 'synthetic-bootstrap',
                'party_country' => 'NL', 'party_id' => 'ABC', 'party_role' => 'EMSP', 'environment' => 'sandbox', 'local_credential_profile' => 'catalog',
                'cpo_catalog' => ['id' => 'catalog-'.$key, 'sites' => [
                    $site => ['city' => 'Ras Al Khaimah', 'country' => 'ARE', 'country_code' => 'AE', 'connectors' => [
                        $connector => ['format' => 'CABLE', 'power_type' => 'DC', 'max_voltage' => 500, 'max_amperage' => 100, 'tariff_ids' => [$tariff]],
                    ]],
                ], 'tariffs' => [$tariff]],
            ],
        ]);
        $provider = OcpiProvider::create(['owner_id' => $vendor->id, 'name' => 'Approved eMSP', 'connection_key' => $key, 'authorized' => true, 'version' => '2.2.1', 'endpoints' => [
            'locations_receiver' => 'https://emsp.example/locations', 'tariffs_receiver' => 'https://emsp.example/tariffs',
        ]]);
        $token = Str::random(32);
        DB::table('ocpi_credential_links')->insert([
            'provider_id' => $provider->id, 'owner_id' => $vendor->id, 'generation' => 1, 'state' => 'active',
            'configuration_hash' => app(CredentialExchange::class)->configurationHash($provider), 'bootstrap_hash' => hash('sha256', 'synthetic-bootstrap'),
            'local_token_digest' => hash('sha256', $token), 'local_token_ciphertext' => Crypt::encryptString($token),
            'remote_token_ciphertext' => Crypt::encryptString('synthetic-peer-token'), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$provider, ['Authorization' => 'Token '.base64_encode($token)], '/api/ocpi/providers/'.$provider->id.'/cpo-catalog'];
    }

    private function publication(string $url, string $operation = 'publish', ?string $expected = null)
    {
        return $this->putJson($url, ['confirmed' => true, 'action' => $operation, 'expected' => $expected]);
    }

    public function test_contract_scopes_sender_objects_and_canonical_publication_and_withdrawal(): void
    {
        Http::preventStrayRequests();
        $owner = User::factory()->create(['role' => 'vendor']);
        $outsider = User::factory()->create(['role' => 'vendor']);
        $admin = User::factory()->create(['role' => 'admin']);
        $source = $this->source($owner);
        [$site, $tariff, $evse, $connector] = $source;
        [, $otherToken, $other] = $this->peer($outsider, 'outsider', $source);
        [, $token, $operations] = $this->peer($owner, 'owner', $source);
        $this->actingAs($owner)->getJson($operations)->assertForbidden();
        $this->actingAs($admin)->getJson($operations)->assertOk()->assertJsonPath('sources.locations.0', (string) $site);
        $this->publication($other.'/tariffs/'.$tariff)->assertNotFound();

        $this->getJson(self::SENDER.'/locations')->assertUnauthorized();
        $this->getJson(self::PROTOCOL.'/credentials', $token)->assertOk()->assertJsonPath('data.roles.0.role', 'CPO')->assertJsonPath('data.roles.0.party_id', 'EVX');
        $this->getJson(self::SENDER.'/locations', $token + ['OCPI-to-party-id' => 'EVY'])->assertForbidden();
        $this->getJson(self::PROTOCOL, $token)->assertOk()->assertJsonFragment(['identifier' => 'locations', 'role' => 'SENDER', 'url' => 'https://platform.example'.self::SENDER.'/locations']);
        $this->getJson(self::SENDER.'/locations', $token)->assertOk()->assertJsonCount(0, 'data');
        $this->publication($operations.'/locations/'.$site)->assertConflict();
        $publishedTariff = $this->publication($operations.'/tariffs/'.$tariff)->assertOk()->json('published_at');
        $locationTime = $this->publication($operations.'/locations/'.$site)->assertOk()->json('published_at');
        $this->publication($operations.'/locations/'.$site)->assertOk()->assertJsonPath('published_at', $locationTime);
        $this->getJson(self::SENDER.'/tariffs', $token)->assertOk()->assertJsonPath('data.0.elements.0.price_components.0.price', 1.29);
        $this->getJson(self::SENDER.'/locations/S'.$site.'/E'.$evse.'/C'.$connector, $token)->assertOk()
            ->assertJsonPath('data.max_electric_power', 50000)->assertJsonPath('data.tariff_ids.0', 'T'.$tariff);
        $this->getJson(self::SENDER.'/locations/S'.$site.'/E'.$evse, $token)->assertOk()->assertJsonPath('data.status', 'UNKNOWN');
        $this->getJson(self::SENDER.'/locations/S'.$site, $otherToken)->assertNotFound();

        $this->publication($operations.'/tariffs/'.$tariff, 'withdraw', $publishedTariff)->assertConflict();
        $this->publication($operations.'/locations/'.$site, 'withdraw', 'stale')->assertConflict();
        $this->publication($operations.'/locations/'.$site, 'withdraw', $locationTime)->assertOk()->assertJsonPath('withdrawn', true);
        $this->getJson(self::SENDER.'/locations/S'.$site, $token)->assertOk()->assertJsonPath('data.publish', false)
            ->assertJsonPath('data.evses.0.status', 'REMOVED')->assertJsonMissingPath('data.evses.0.connectors.0.tariff_ids');
        $this->publication($operations.'/tariffs/'.$tariff, 'withdraw', $publishedTariff)->assertOk();
        $this->getJson(self::SENDER.'/tariffs', $token)->assertOk()->assertJsonCount(0, 'data');
        $this->assertDatabaseCount('ocpi_cpo_catalog', 2);
        $this->assertDatabaseCount('finance_invoices', 0);
        $this->assertDatabaseCount('charger_commands', 0);
        Http::assertNothingSent();
    }

    public function test_publish_is_fenced_by_binding_generation_and_sender_pagination_uses_utc_and_configured_origin(): void
    {
        $owner = User::factory()->create(['role' => 'vendor']);
        $admin = User::factory()->create(['role' => 'admin']);
        $sources = $this->source($owner);
        [$site, $tariff] = $sources;
        [$provider, $token, $operations] = $this->peer($owner, 'owner', $sources);
        $secondTariff = DB::table('finance_tariff_versions')->insertGetId([
            'station_id' => DB::table('vendor_sites')->where('id', $site)->value('station_id'),
            'created_by' => $owner->id, 'rate_minor_per_kwh' => 150, 'commission_bps' => 0, 'tax_bps' => 0,
            'currency' => 'AED', 'minor_units' => 2, 'effective_from' => now()->addDay(), 'created_at' => now(),
        ]);
        config(['ocpi.connections.owner.cpo_catalog.tariffs' => [$tariff, $secondTariff]]);
        DB::table('ocpi_credential_links')->where('provider_id', $provider->id)->update(['configuration_hash' => app(CredentialExchange::class)->configurationHash($provider)]);
        $this->actingAs($admin)->getJson($operations)->assertOk();
        $this->travelTo(now()->setDate(2026, 11, 21)->setTime(8, 0));
        $this->publication($operations.'/tariffs/'.$tariff)->assertOk();
        $this->travel(2)->seconds();
        $this->publication($operations.'/tariffs/'.$secondTariff)->assertOk();
        $this->getJson(self::SENDER.'/tariffs?date_from=2026-11-21T08%3A00%3A00&limit=1', $token + ['X-Request-ID' => 'test-page'])
            ->assertOk()->assertHeader('X-Request-ID', 'test-page')->assertHeader('X-Total-Count', '2')->assertHeader('X-Limit', '1')
            ->assertHeader('Link', '<https://platform.example'.self::SENDER.'/tariffs?date_from=2026-11-21T08%3A00%3A00.000000Z&limit=1&offset=1>; rel="next"')
            ->assertJsonPath('data.0.id', 'T'.$tariff);
        $this->getJson(self::SENDER.'/tariffs?date_to=2026-11-21T08%3A00%3A02Z', $token)->assertOk()->assertJsonCount(1, 'data');
        $this->getJson(self::SENDER.'/tariffs?date_to=2026-02-30T00%3A00%3A00Z', $token)->assertBadRequest();
        $this->getJson(self::SENDER.'/tariffs?limit[]=3', $token)->assertBadRequest();
        DB::table('ocpi_credential_links')->where('provider_id', $provider->id)->update(['generation' => 2]);
        $this->getJson(self::SENDER.'/tariffs', $token)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($operations)->assertOk()->assertJsonPath('history.data.0.authority_current', false);
        config(['ocpi.connections.owner.cpo_catalog.id' => 'changed-contract']);
        $this->getJson(self::SENDER.'/tariffs', $token)->assertUnauthorized();
    }

    public function test_authority_refresh_cannot_rebind_a_withdrawal_to_another_cpo_identity(): void
    {
        $owner = User::factory()->create(['role' => 'vendor']);
        $admin = User::factory()->create(['role' => 'admin']);
        $sources = $this->source($owner);
        [, $tariff] = $sources;
        [$provider, $token, $operations] = $this->peer($owner, 'owner', $sources);
        $this->actingAs($admin);
        $url = $operations.'/tariffs/'.$tariff;
        $published = $this->publication($url)->assertOk()->json('published_at');
        config(['ocpi.local_credential_profiles.catalog.roles.0.party_id' => 'EVZ']);
        DB::table('ocpi_credential_links')->where('provider_id', $provider->id)->update([
            'generation' => 2, 'configuration_hash' => app(CredentialExchange::class)->configurationHash($provider),
        ]);
        $this->publication($url, 'withdraw', $published)->assertConflict();
        $this->getJson(self::SENDER.'/tariffs', $token)->assertOk()->assertJsonCount(0, 'data');
        $this->publication($url, 'publish', $published)->assertOk();
        $this->getJson(self::SENDER.'/tariffs', $token)->assertOk()->assertJsonPath('data.0.party_id', 'EVZ');
    }

    public function test_only_negotiated_sandbox_receiver_gets_one_attempt_per_immutable_publication(): void
    {
        $owner = User::factory()->create(['role' => 'vendor']);
        $admin = User::factory()->create(['role' => 'admin']);
        $sources = $this->source($owner);
        [, $tariff] = $sources;
        [$provider, , $operations] = $this->peer($owner, 'owner', $sources);
        config(['ocpi.cpo_push_enabled' => true]);

        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            $reply = ['status_code' => 1000, 'timestamp' => now()->toISOString()];
            if ($request->url() === 'https://emsp.example/ocpi/versions') {
                return Http::response($reply + ['data' => [['version' => '2.2.1', 'url' => 'https://emsp.example/details']]]);
            }
            if ($request->url() === 'https://emsp.example/details') {
                return Http::response($reply + ['data' => ['version' => '2.2.1', 'endpoints' => [
                    ['identifier' => 'locations', 'role' => 'RECEIVER', 'url' => 'https://emsp.example/locations'],
                    ['identifier' => 'tariffs', 'role' => 'RECEIVER', 'url' => 'https://emsp.example/tariffs'],
                ]]]);
            }
            $this->assertSame('https://emsp.example/tariffs/AE/EVX/T'.DB::table('finance_tariff_versions')->value('id'), $request->url());
            $this->assertTrue($request->hasHeader('Authorization', 'Token '.base64_encode('synthetic-peer-token')));
            $this->assertTrue($request->hasHeader('OCPI-to-party-id', 'ABC'));
            $trace = ['X-Request-ID' => $request->header('X-Request-ID')[0], 'X-Correlation-ID' => $request->header('X-Correlation-ID')[0]];
            if ($request->method() === 'DELETE') {
                return Http::response('', 504);
            }
            if ($request->method() === 'GET') {
                return Http::response(['status_code' => 2001, 'data' => null, 'timestamp' => now()->toISOString()], 404, $trace);
            }
            $this->assertSame('PUT', $request->method());
            $this->assertSame('AED', $request->data()['currency']);

            return Http::response($reply, 200, $trace);
        });
        $this->actingAs($admin)->postJson('/api/ocpi/providers/'.$provider->id.'/negotiate')->assertOk();
        $url = $operations.'/tariffs/'.$tariff;
        $stamp = $this->publication($url)->assertOk()->json('published_at');
        $this->postJson($url.'/push', ['confirmed' => true, 'published_at' => $stamp, 'operation_key' => (string) Str::uuid()])->assertOk()->assertJsonPath('delivery', 'acknowledged');
        $this->postJson($url.'/push', ['confirmed' => true, 'published_at' => $stamp, 'operation_key' => (string) Str::uuid()])->assertOk()->assertJsonPath('delivery', 'acknowledged');
        $withdrawn = $this->publication($url, 'withdraw', $stamp)->assertOk()->json('published_at');
        $this->postJson($url.'/push', ['confirmed' => true, 'published_at' => $withdrawn, 'operation_key' => (string) Str::uuid()])->assertOk()->assertJsonPath('delivery', 'unknown');
        $this->postJson($url.'/push', ['confirmed' => true, 'published_at' => $withdrawn, 'operation_key' => (string) Str::uuid()])->assertOk()->assertJsonPath('delivery', 'unknown');
        $this->publication($url, 'publish', $withdrawn)->assertConflict();
        $this->postJson($url.'/reconcile', ['confirmed' => true, 'published_at' => $withdrawn])->assertOk()->assertJsonPath('delivery', 'reconciled');
        $this->postJson($url.'/reconcile', ['confirmed' => true, 'published_at' => $withdrawn])->assertOk()->assertJsonPath('delivery', 'reconciled');
        Http::assertSentCount(5);
        $this->assertDatabaseCount('ocpi_cpo_publication_operations', 2);
    }

    public function test_cpo_push_rechecks_the_admin_after_the_delivery_claim(): void
    {
        $owner = User::factory()->create(['role' => 'vendor']);
        $admin = User::factory()->create(['role' => 'admin']);
        $sources = $this->source($owner);
        [, $tariff] = $sources;
        [, , $operations] = $this->peer($owner, 'owner', $sources);
        config(['ocpi.cpo_push_enabled' => true]);

        $url = $operations.'/tariffs/'.$tariff;
        $stamp = $this->actingAs($admin)->publication($url)->assertOk()->json('published_at');
        Http::fake();
        DB::listen(function ($query) use ($admin) {
            if (str_starts_with($query->sql, 'insert into "ocpi_cpo_publication_operations"')) {
                DB::table('users')->where('id', $admin->id)->increment('auth_epoch');
            }
        });

        $this->postJson($url.'/push', ['confirmed' => true, 'published_at' => $stamp, 'operation_key' => (string) Str::uuid()])->assertConflict();
        Http::assertNothingSent();
        $this->assertDatabaseCount('ocpi_cpo_publication_operations', 0);
    }

    public function test_cpo_push_rejects_canonical_edits_after_the_delivery_claim(): void
    {
        $owner = User::factory()->create(['role' => 'vendor']);
        $admin = User::factory()->create(['role' => 'admin']);
        $sources = $this->source($owner);
        [$site, $tariff] = $sources;
        [, , $operations] = $this->peer($owner, 'owner', $sources);
        config(['ocpi.cpo_push_enabled' => true]);

        $this->actingAs($admin)->publication($operations.'/tariffs/'.$tariff)->assertOk();
        $url = $operations.'/locations/'.$site;
        $stamp = $this->publication($url)->assertOk()->json('published_at');
        Http::fake();
        DB::listen(function ($query) use ($site) {
            if (str_starts_with($query->sql, 'insert into "ocpi_cpo_publication_operations"')) {
                DB::table('vendor_sites')->where('id', $site)->update(['address' => 'Revised station address']);
            }
        });

        $this->postJson($url.'/push', ['confirmed' => true, 'published_at' => $stamp, 'operation_key' => (string) Str::uuid()])->assertConflict();
        Http::assertNothingSent();
        $this->assertDatabaseCount('ocpi_cpo_publication_operations', 0);
    }
}
