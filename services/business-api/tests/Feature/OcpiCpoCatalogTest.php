<?php

namespace Tests\Feature;

use App\Models\ChargingSession;
use App\Models\OcpiProvider;
use App\Models\User;
use App\Services\Finance\FinanceService;
use App\Services\Ocpi\CpoCatalogPublisher;
use App\Services\Ocpi\CredentialExchange;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\HttpKernel\Exception\HttpException;
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

    private function peer(User $vendor, string $key, array $sources, array $extraConnectors = []): array
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
                    ] + $extraConnectors],
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

    public function test_expired_contract_blocks_new_cpo_catalog_delivery_but_preserves_withdrawal(): void
    {
        $owner = User::factory()->create(['role' => 'vendor']);
        $admin = User::factory()->create(['role' => 'admin']);
        $sources = $this->source($owner);
        [, $tariff] = $sources;
        [$provider, $token, $operations] = $this->peer($owner, 'review-cpo', $sources);
        $url = $operations.'/tariffs/'.$tariff;
        config(['ocpi.contract_review_authorization_required' => true, 'ocpi.cpo_push_enabled' => true]);
        $this->actingAs($admin);
        $this->publication($url)->assertConflict();
        $this->assertDatabaseCount('ocpi_cpo_catalog', 0);
        $review = ['decision' => 'review', 'reference_id' => 'CPO-REVIEW-01',
            'evidence_sha256' => str_repeat('a', 64), 'valid_until' => now('UTC')->addYear()->toDateString(),
            'expected_revision' => 0, 'idempotency_key' => 'cpo-review-fixture-001',
            'reason' => 'Synthetic internal approval for catalog delivery.'];
        $this->postJson('/api/ocpi/providers/'.$provider->id.'/contract-review', $review)->assertCreated();
        $stamp = $this->publication($url)->assertOk()->json('published_at');
        $this->getJson(self::SENDER.'/tariffs', $token)->assertOk()->assertJsonCount(1, 'data');
        $rows = DB::table('ocpi_partner_contract_reviews')->where('provider_id', $provider->id);
        $rows->update(['valid_until' => now('UTC')->subDay()->toDateString()]);
        Http::fake();
        $push = ['confirmed' => true, 'published_at' => $stamp, 'operation_key' => (string) Str::uuid()];
        $this->postJson($url.'/push', $push)->assertConflict();
        $this->getJson(self::SENDER.'/tariffs', $token)->assertOk()->assertJsonCount(0, 'data');
        Http::assertNothingSent();
        $rows->update(['valid_until' => $review['valid_until']]);
        Http::swap(new Factory);
        $received = null;
        Http::fake(function (Request $request) use ($rows, &$received) {
            $trace = ['X-Request-ID' => $request->header('X-Request-ID')[0],
                'X-Correlation-ID' => $request->header('X-Correlation-ID')[0]];
            if ($request->method() === 'PUT') {
                $received = $request->data();
                $rows->update(['valid_until' => now('UTC')->subDay()->toDateString()]);
            }

            return Http::response(['status_code' => 1000, 'timestamp' => now()->toISOString(),
                'data' => $request->method() === 'GET' ? $received : null], 200, $trace);
        });
        $this->postJson($url.'/push', $push)->assertOk()->assertJsonPath('delivery', 'unknown');
        $this->postJson($url.'/push', $push)->assertOk()->assertJsonPath('delivery', 'unknown');
        Http::assertSentCount(1);
        // An unresolved delivery is never overwritten by a withdrawal.
        $this->publication($url, 'withdraw', $stamp)->assertConflict();
        $this->postJson($url.'/reconcile', ['confirmed' => true, 'published_at' => $stamp])
            ->assertOk()->assertJsonPath('delivery', 'reconciled');
        $withdrawn = $this->publication($url, 'withdraw', $stamp)->assertOk()
            ->assertJsonPath('withdrawn', true)->json('published_at');
        $this->postJson($url.'/push', ['confirmed' => true, 'published_at' => $withdrawn,
            'operation_key' => (string) Str::uuid()])->assertOk()->assertJsonPath('delivery', 'acknowledged');
        Http::assertSentCount(3);
        $this->assertDatabaseCount('finance_invoices', 0);
        $this->assertDatabaseCount('charger_commands', 0);
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
        $read = fn (array $window) => $this->getJson(self::SENDER.'/tariffs?'.http_build_query($window), $token);
        $read(['date_from' => '2026-11-21T12:00:00+04:00', 'limit' => 1])
            ->assertOk()->assertHeader('X-Total-Count', '2')->assertJsonPath('data.0.id', 'T'.$tariff);
        $read(['date_from' => '2026-11-21T04:00:00.000001-04:00', 'date_to' => '2026-11-21T12:00:02.000001+04:00'])
            ->assertOk()->assertHeader('X-Total-Count', '1')->assertJsonPath('data.0.id', 'T'.$secondTariff);
        $read(['date_to' => '2026-11-21T12:00:02+04:00'])->assertOk()->assertJsonCount(1, 'data');
        foreach (['tomorrow', '2026-02-30T00:00:00Z', '2026-11-21T08:00:00+14:01',
            '2026-11-21T08:00:00+00:60', '2026-11-21T08:00:00.0000001Z',
            '0000-01-01T00:00:00Z', '0001-01-01T00:00:00+14:00', '9999-12-31T23:59:59-14:00'] as $invalid) {
            $read(['date_to' => $invalid])->assertBadRequest();
        }
        $read(['date_from' => '2026-11-21T12:00:00+04:00', 'date_to' => '2026-11-21T08:00:00Z'])->assertBadRequest();
        $this->getJson(self::SENDER.'/tariffs?date_to=2026-02-30T00%3A00%3A00Z', $token)->assertBadRequest();
        $this->getJson(self::SENDER.'/tariffs?limit[]=3', $token)->assertBadRequest();
        DB::table('ocpi_credential_links')->where('provider_id', $provider->id)->update(['generation' => 2]);
        $this->getJson(self::SENDER.'/tariffs', $token)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($operations)->assertOk()->assertJsonPath('history.data.0.authority_current', false);
        config(['ocpi.connections.owner.cpo_catalog.id' => 'changed-contract']);
        $this->getJson(self::SENDER.'/tariffs', $token)->assertUnauthorized();
    }

    public function test_sender_read_revalidates_the_complete_authenticated_credential_snapshot_under_lock(): void
    {
        Http::preventStrayRequests();
        $owner = User::factory()->create(['role' => 'vendor']);
        $admin = User::factory()->create(['role' => 'admin']);
        $sources = $this->source($owner);
        [, $tariff] = $sources;
        [$provider, , $operations] = $this->peer($owner, 'owner', $sources);
        $this->actingAs($admin)->publication($operations.'/tariffs/'.$tariff)->assertOk();
        $link = DB::table('ocpi_credential_links')->where('provider_id', $provider->id);
        $snapshot = $link->first();
        $publisher = app(CpoCatalogPublisher::class);
        $read = fn () => $publisher->read($provider, $snapshot, 'tariffs', ['offset' => 0, 'limit' => 25], $tariff);
        $this->assertSame('T'.$tariff, $read()[0]['id']);
        foreach ([
            'local_token_ciphertext' => Crypt::encryptString('other-local-synthetic-token'),
            'remote_token_ciphertext' => Crypt::encryptString('other-remote-synthetic-token'),
            'bootstrap_hash' => str_repeat('b', 64),
        ] as $field => $replacement) {
            $link->update([$field => $replacement]);
            try {
                $read();
                $this->fail('Changed credential snapshot granted a private Sender read.');
            } catch (HttpException $error) {
                $this->assertSame(401, $error->getStatusCode());
            }
            $link->update([$field => $snapshot->$field]);
            $this->assertSame('T'.$tariff, $read()[0]['id']);
        }
        Http::assertNothingSent();
        $this->assertDatabaseCount('ocpi_cpo_catalog', 1);
        $this->assertDatabaseCount('ocpi_cpo_publication_operations', 0);
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

        $reconcileDuringRead = null;
        Http::preventStrayRequests();
        Http::fake(function (Request $request) use (&$reconcileDuringRead) {
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
                if ($reconcileDuringRead !== null) {
                    $competing = $reconcileDuringRead;
                    $reconcileDuringRead = null;
                    $competing();
                }

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
        $reconcileDuringRead = function () use ($admin, $provider, $tariff, $withdrawn) {
            $competing = app(CpoCatalogPublisher::class)->reconcile($admin, $provider, 'tariffs', $tariff, $withdrawn);
            $this->assertSame('reconciled', $competing['delivery']);
        };
        $this->postJson($url.'/reconcile', ['confirmed' => true, 'published_at' => $withdrawn])->assertOk()->assertJsonPath('delivery', 'reconciled');
        $this->postJson($url.'/reconcile', ['confirmed' => true, 'published_at' => $withdrawn])->assertOk()->assertJsonPath('delivery', 'reconciled');
        Http::assertSentCount(6);
        $this->assertDatabaseCount('ocpi_cpo_publication_operations', 2);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'ocpi.cpo_reconciled')->count());
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

    public function test_cpo_push_rejects_changed_publication_bytes_after_claim(): void
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
        DB::listen(function ($query) use ($tariff) {
            if (str_starts_with($query->sql, 'insert into "ocpi_cpo_publication_operations"')) {
                $publication = DB::table('ocpi_cpo_catalog')->where('module', 'tariffs')->where('source_id', $tariff)->first();
                $payload = json_decode($publication->payload, true, flags: JSON_THROW_ON_ERROR);
                $payload['elements'][0]['price_components'][0]['price'] = 99;
                DB::table('ocpi_cpo_catalog')->where('id', $publication->id)->update([
                    'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
                ]);
            }
        });

        $this->postJson($url.'/push', ['confirmed' => true, 'published_at' => $stamp, 'operation_key' => (string) Str::uuid()])->assertConflict();
        Http::assertNothingSent();
        $this->assertDatabaseCount('ocpi_cpo_publication_operations', 1);
        $this->assertSame('unknown', DB::table('ocpi_cpo_publication_operations')->value('state'));
        $this->postJson($url.'/push', ['confirmed' => true, 'published_at' => $stamp, 'operation_key' => (string) Str::uuid()])->assertConflict();
        Http::assertNothingSent();
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    public function test_cpo_reconciliation_does_not_credit_changed_publication_bytes(): void
    {
        $owner = User::factory()->create(['role' => 'vendor']);
        $admin = User::factory()->create(['role' => 'admin']);
        $sources = $this->source($owner);
        [, $tariff] = $sources;
        [, , $operations] = $this->peer($owner, 'owner', $sources);
        config(['ocpi.cpo_push_enabled' => true]);
        $url = $operations.'/tariffs/'.$tariff;
        $stamp = $this->actingAs($admin)->publication($url)->assertOk()->json('published_at');
        $sent = null;
        Http::fake(function (Request $request) use (&$sent) {
            if ($request->method() === 'PUT') {
                $sent = $request->data();

                return Http::response('', 504);
            }
            $this->assertSame('GET', $request->method());
            $publication = DB::table('ocpi_cpo_catalog')->first();
            $changed = $sent;
            $changed['elements'][0]['price_components'][0]['price'] = 99;
            DB::table('ocpi_cpo_catalog')->where('id', $publication->id)->update([
                'payload' => json_encode($changed, JSON_THROW_ON_ERROR),
            ]);
            $trace = ['X-Request-ID' => $request->header('X-Request-ID')[0], 'X-Correlation-ID' => $request->header('X-Correlation-ID')[0]];

            return Http::response(['status_code' => 1000, 'data' => $sent, 'timestamp' => now()->toISOString()], 200, $trace);
        });

        $this->postJson($url.'/push', ['confirmed' => true, 'published_at' => $stamp, 'operation_key' => (string) Str::uuid()])->assertOk()->assertJsonPath('delivery', 'unknown');
        $this->postJson($url.'/reconcile', ['confirmed' => true, 'published_at' => $stamp])->assertConflict();
        $this->assertSame('unknown', DB::table('ocpi_cpo_publication_operations')->value('state'));
        $this->assertSame(0, DB::table('audit_events')->where('action', 'ocpi.cpo_reconciled')->count());
        Http::assertSentCount(2);
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    public function test_cpo_reconciliation_requires_original_durable_delivery_digest_between_requests(): void
    {
        $owner = User::factory()->create(['role' => 'vendor']);
        $admin = User::factory()->create(['role' => 'admin']);
        $sources = $this->source($owner);
        [, $tariff] = $sources;
        [, , $operations] = $this->peer($owner, 'owner', $sources);
        config(['ocpi.cpo_push_enabled' => true]);
        $url = $operations.'/tariffs/'.$tariff;
        $stamp = $this->actingAs($admin)->publication($url)->assertOk()->json('published_at');
        Http::fake(fn () => Http::response('', 504));
        $this->postJson($url.'/push', ['confirmed' => true, 'published_at' => $stamp, 'operation_key' => (string) Str::uuid()])->assertOk()->assertJsonPath('delivery', 'unknown');
        $row = DB::table('ocpi_cpo_catalog')->first();
        $operation = DB::table('ocpi_cpo_publication_operations')->first();
        $this->assertNotNull($operation->publication_digest);
        $payload = json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR);
        $payload['elements'][0]['price_components'][0]['price'] = 99;
        $altered = json_encode($payload, JSON_THROW_ON_ERROR);
        // Even a changed catalog row with an internally consistent new digest
        // is not the original attempted publication.
        DB::table('ocpi_cpo_catalog')->where('id', $row->id)->update(['payload' => $altered, 'payload_digest' => hash('sha256', $altered)]);
        $this->postJson($url.'/reconcile', ['confirmed' => true, 'published_at' => $stamp])->assertConflict();
        $this->postJson($url.'/push', ['confirmed' => true, 'published_at' => $stamp, 'operation_key' => (string) Str::uuid()])->assertConflict();
        DB::table('ocpi_cpo_catalog')->where('id', $row->id)->update(['payload' => $row->payload, 'payload_digest' => $row->payload_digest]);
        DB::table('ocpi_cpo_publication_operations')->where('id', $operation->id)->update(['publication_digest' => null]);
        $this->postJson($url.'/reconcile', ['confirmed' => true, 'published_at' => $stamp])->assertConflict();
        $this->postJson($url.'/push', ['confirmed' => true, 'published_at' => $stamp, 'operation_key' => (string) Str::uuid()])->assertConflict();
        $this->assertSame('unknown', DB::table('ocpi_cpo_publication_operations')->value('state'));
        $this->assertSame(0, DB::table('audit_events')->where('action', 'ocpi.cpo_reconciled')->count());
        Http::assertSentCount(1);
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    public function test_cpo_sender_and_dependent_publications_reject_corrupted_tariff_bytes(): void
    {
        $owner = User::factory()->create(['role' => 'vendor']);
        $admin = User::factory()->create(['role' => 'admin']);
        $sources = $this->source($owner);
        [$site, $tariff] = $sources;
        [, $token, $operations] = $this->peer($owner, 'owner', $sources);
        $url = $operations.'/tariffs/'.$tariff;
        $stamp = $this->actingAs($admin)->publication($url)->assertOk()->json('published_at');
        $row = DB::table('ocpi_cpo_catalog')->first();
        $payload = json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR);
        $payload['elements'][0]['price_components'][0]['price'] = 99;
        DB::table('ocpi_cpo_catalog')->where('id', $row->id)->update(['payload' => json_encode($payload, JSON_THROW_ON_ERROR)]);
        $this->getJson(self::SENDER.'/tariffs', $token)->assertConflict()->assertJsonPath('data', null);
        // The negotiated Tariffs Sender exposes lists only, not object reads.
        $this->getJson(self::SENDER.'/tariffs/T'.$tariff, $token)->assertNotFound();
        $this->publication($operations.'/locations/'.$site)->assertConflict();
        $this->assertDatabaseCount('ocpi_cpo_catalog', 1);
        DB::table('ocpi_cpo_catalog')->where('id', $row->id)->update(['payload' => $row->payload, 'payload_digest' => null]);
        $this->getJson(self::SENDER.'/tariffs', $token)->assertConflict();
        $this->publication($operations.'/locations/'.$site)->assertConflict();
        $this->publication($url, 'publish', $stamp)->assertOk();
        $this->getJson(self::SENDER.'/tariffs', $token)->assertOk()->assertJsonPath('data.0.elements.0.price_components.0.price', 1.29);
        $this->publication($operations.'/locations/'.$site)->assertOk();
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    public function test_cpo_location_sender_rejects_corrupted_object_and_nested_reads(): void
    {
        Http::preventStrayRequests();
        $owner = User::factory()->create(['role' => 'vendor']);
        $admin = User::factory()->create(['role' => 'admin']);
        $sources = $this->source($owner);
        [$site, $tariff, $evse, $connector] = $sources;
        [, $token, $operations] = $this->peer($owner, 'owner', $sources);
        $this->actingAs($admin)->publication($operations.'/tariffs/'.$tariff)->assertOk();
        $this->publication($operations.'/locations/'.$site)->assertOk();
        $url = self::SENDER.'/locations/S'.$site;
        $paths = [self::SENDER.'/locations', $url, $url.'/E'.$evse, $url.'/E'.$evse.'/C'.$connector];
        foreach ($paths as $path) {
            $this->getJson($path, $token)->assertOk();
        }
        $row = DB::table('ocpi_cpo_catalog')->where('module', 'locations')->first();
        $payload = json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR);
        $payload['evses'][0]['status'] = 'AVAILABLE';
        DB::table('ocpi_cpo_catalog')->where('id', $row->id)->update(['payload' => json_encode($payload, JSON_THROW_ON_ERROR)]);
        foreach ($paths as $path) {
            $this->getJson($path, $token)->assertConflict()->assertJsonPath('data', null);
        }
        DB::table('ocpi_cpo_catalog')->where('id', $row->id)->update(['payload' => $row->payload]);
        $this->getJson($url.'/E'.$evse, $token)->assertOk()->assertJsonPath('data.status', 'UNKNOWN');
        Http::assertNothingSent();
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    public function test_successful_push_envelope_cannot_credit_a_changed_publication_during_transport(): void
    {
        $owner = User::factory()->create(['role' => 'vendor']);
        $admin = User::factory()->create(['role' => 'admin']);
        $sources = $this->source($owner);
        [, $tariff] = $sources;
        [, , $operations] = $this->peer($owner, 'owner', $sources);
        config(['ocpi.cpo_push_enabled' => true]);
        $url = $operations.'/tariffs/'.$tariff;
        $stamp = $this->actingAs($admin)->publication($url)->assertOk()->json('published_at');
        Http::fake(function (Request $request) {
            $this->assertSame('PUT', $request->method());
            $row = DB::table('ocpi_cpo_catalog')->first();
            $payload = json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR);
            $payload['elements'][0]['price_components'][0]['price'] = 99;
            DB::table('ocpi_cpo_catalog')->where('id', $row->id)->update(['payload' => json_encode($payload, JSON_THROW_ON_ERROR)]);
            $trace = ['X-Request-ID' => $request->header('X-Request-ID')[0], 'X-Correlation-ID' => $request->header('X-Correlation-ID')[0]];

            return Http::response(['status_code' => 1000, 'data' => null, 'timestamp' => now()->toISOString()], 200, $trace);
        });
        $this->postJson($url.'/push', ['confirmed' => true, 'published_at' => $stamp, 'operation_key' => (string) Str::uuid()])->assertOk()->assertJsonPath('delivery', 'unknown');
        $this->postJson($url.'/push', ['confirmed' => true, 'published_at' => $stamp, 'operation_key' => (string) Str::uuid()])->assertConflict();
        $this->postJson($url.'/reconcile', ['confirmed' => true, 'published_at' => $stamp])->assertConflict();
        $this->assertSame('unknown', DB::table('ocpi_cpo_publication_operations')->value('state'));
        Http::assertSentCount(1);
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    #[TestWith(['endpoint'])]
    #[TestWith(['credential'])]
    public function test_cpo_delivery_requires_unchanged_receiver_and_exact_credential_bytes_during_transport(string $change): void
    {
        $owner = User::factory()->create(['role' => 'vendor']);
        $admin = User::factory()->create(['role' => 'admin']);
        $sources = $this->source($owner);
        [, $tariff] = $sources;
        [$provider, , $operations] = $this->peer($owner, 'owner', $sources);
        config(['ocpi.cpo_push_enabled' => true]);
        $url = $operations.'/tariffs/'.$tariff;
        $stamp = $this->actingAs($admin)->publication($url)->assertOk()->json('published_at');
        $originalEndpoints = $provider->endpoints;
        $credential = DB::table('ocpi_credential_links')->where('provider_id', $provider->id);
        $originalToken = (clone $credential)->value('remote_token_ciphertext');
        $alter = function () use ($provider, $change, $originalEndpoints, $credential) {
            if ($change === 'endpoint') {
                $provider->fresh()->update(['endpoints' => array_replace($originalEndpoints, ['tariffs_receiver' => 'https://emsp.example/replacement-tariffs'])]);
            } else {
                (clone $credential)->update(['remote_token_ciphertext' => Crypt::encryptString('replacement-peer-token')]);
            }
        };
        $restore = function () use ($provider, $originalEndpoints, $credential, $originalToken) {
            $provider->fresh()->update(['endpoints' => $originalEndpoints]);
            (clone $credential)->update(['remote_token_ciphertext' => $originalToken]);
        };
        $sent = null;
        $mutate = true;
        Http::preventStrayRequests();
        Http::fake(function (Request $request) use ($alter, &$mutate, &$sent, $tariff) {
            $this->assertSame('https://emsp.example/tariffs/AE/EVX/T'.$tariff, $request->url());
            if ($request->method() === 'PUT') {
                $this->assertNull($sent);
                $sent = $request->data();
            } else {
                $this->assertSame('GET', $request->method());
                $this->assertNotNull($sent);
            }
            if ($mutate) {
                $alter();
            }
            $trace = ['X-Request-ID' => $request->header('X-Request-ID')[0], 'X-Correlation-ID' => $request->header('X-Correlation-ID')[0]];

            return Http::response(['status_code' => 1000, 'timestamp' => now()->toISOString(), 'data' => $request->method() === 'GET' ? $sent : null], 200, $trace);
        });

        $this->postJson($url.'/push', ['confirmed' => true, 'published_at' => $stamp, 'operation_key' => (string) Str::uuid()])
            ->assertOk()->assertJsonPath('delivery', 'unknown');
        $this->assertSame('unknown', DB::table('ocpi_cpo_publication_operations')->value('state'));
        // A new request must not adopt the substituted authority, even when
        // its generation and publication bytes have not changed.
        $this->postJson($url.'/reconcile', ['confirmed' => true, 'published_at' => $stamp])->assertConflict();
        $this->postJson($url.'/push', ['confirmed' => true, 'published_at' => $stamp, 'operation_key' => (string) Str::uuid()])->assertConflict();
        Http::assertSentCount(1);
        $restore();
        $this->postJson($url.'/reconcile', ['confirmed' => true, 'published_at' => $stamp])->assertConflict();
        $this->assertSame('unknown', DB::table('ocpi_cpo_publication_operations')->value('state'));
        $restore();
        $mutate = false;
        $this->postJson($url.'/reconcile', ['confirmed' => true, 'published_at' => $stamp])
            ->assertOk()->assertJsonPath('delivery', 'reconciled');
        $this->assertDatabaseCount('ocpi_cpo_publication_operations', 1);
        $this->assertDatabaseCount('finance_invoices', 0);
        Http::assertSentCount(3);
    }

    public function test_payload_only_legacy_delivery_digest_cannot_certify_an_original_receiver(): void
    {
        $owner = User::factory()->create(['role' => 'vendor']);
        $admin = User::factory()->create(['role' => 'admin']);
        $sources = $this->source($owner);
        [, $tariff] = $sources;
        [, , $operations] = $this->peer($owner, 'owner', $sources);
        config(['ocpi.cpo_push_enabled' => true]);
        $url = $operations.'/tariffs/'.$tariff;
        $stamp = $this->actingAs($admin)->publication($url)->assertOk()->json('published_at');
        Http::preventStrayRequests();
        Http::fake(['https://emsp.example/*' => Http::response('', 504)]);
        $key = (string) Str::uuid();
        $this->postJson($url.'/push', ['confirmed' => true, 'published_at' => $stamp, 'operation_key' => $key])
            ->assertOk()->assertJsonPath('delivery', 'unknown');
        $row = DB::table('ocpi_cpo_catalog')->first();
        $operation = DB::table('ocpi_cpo_publication_operations')->first();
        $legacy = hash('sha256', json_encode([
            (string) $row->id, (string) $row->provider_id, (string) $row->owner_id,
            (string) $row->generation, (string) $row->authorization_revision, $row->configuration_hash,
            $row->module, (string) $row->source_id, $row->published_at, (bool) $row->withdrawn,
            $row->source_hash, hash('sha256', $row->payload),
        ], JSON_THROW_ON_ERROR));
        $this->assertNotSame($legacy, $operation->publication_digest);
        DB::table('ocpi_cpo_publication_operations')->where('id', $operation->id)->update(['publication_digest' => $legacy]);
        $this->postJson($url.'/reconcile', ['confirmed' => true, 'published_at' => $stamp])->assertConflict();
        $this->postJson($url.'/push', ['confirmed' => true, 'published_at' => $stamp, 'operation_key' => $key])->assertConflict();
        $this->postJson($url.'/push', ['confirmed' => true, 'published_at' => $stamp, 'operation_key' => (string) Str::uuid()])->assertConflict();
        $this->assertDatabaseCount('ocpi_cpo_publication_operations', 1);
        $this->assertSame('unknown', DB::table('ocpi_cpo_publication_operations')->value('state'));
        Http::assertSentCount(1);
    }

    public function test_cpo_reconciliation_can_read_the_full_size_of_a_published_location(): void
    {
        $owner = User::factory()->create(['role' => 'vendor']);
        $admin = User::factory()->create(['role' => 'admin']);
        $sources = $this->source($owner);
        [$site, $tariff] = $sources;
        $siteRecord = DB::table('vendor_sites')->find($site);
        $contractConnectors = [];
        for ($number = 1; $number <= 36; $number++) {
            $charger = DB::table('vendor_chargers')->insertGetId([
                'company_id' => $siteRecord->company_id, 'site_id' => $site, 'asset_reference' => 'large-'.$number,
                'manufacturer' => 'Test', 'model' => 'Test', 'source' => 'simulator',
            ]);
            $evse = DB::table('charging_evses')->insertGetId(['charger_id' => $charger, 'number' => 1, 'mode' => 'simulator']);
            for ($port = 1; $port <= 10; $port++) {
                $connector = DB::table('vendor_connectors')->insertGetId([
                    'charger_id' => $charger, 'connector_number' => $port, 'connector_type' => 'CCS2', 'max_power_kw' => 50,
                ]);
                DB::table('charging_connector_mappings')->insert(['station_id' => $siteRecord->station_id, 'evse_id' => $evse, 'connector_id' => $connector]);
                $contractConnectors[$connector] = ['format' => 'CABLE', 'power_type' => 'DC', 'max_voltage' => 500, 'max_amperage' => 100, 'tariff_ids' => [$tariff]];
            }
        }
        [, , $operations] = $this->peer($owner, 'owner', $sources, $contractConnectors);
        config(['ocpi.cpo_push_enabled' => true]);

        $sentRecord = null;
        $oversizedRead = true;
        $this->actingAs($admin)->publication($operations.'/tariffs/'.$tariff)->assertOk();
        $url = $operations.'/locations/'.$site;
        $stamp = $this->publication($url)->assertOk()->json('published_at');
        Http::preventStrayRequests();
        Http::fake(function (Request $request) use (&$sentRecord, &$oversizedRead) {
            if ($request->method() === 'PUT') {
                $this->assertNull($sentRecord);
                $sentRecord = $request->data();

                return Http::response('', 504);
            }
            $this->assertSame('GET', $request->method());
            $trace = ['X-Request-ID' => $request->header('X-Request-ID')[0], 'X-Correlation-ID' => $request->header('X-Correlation-ID')[0]];
            $response = ['status_code' => 1000, 'data' => $sentRecord, 'timestamp' => now()->toISOString()];
            $this->assertGreaterThan(65_536, strlen(json_encode($response, JSON_THROW_ON_ERROR)));
            if ($oversizedRead) {
                $response['status_message'] = str_repeat('a', 300_000);
                $oversizedRead = false;
            }

            return Http::response($response, 200, $trace);
        });

        $this->postJson($url.'/push', ['confirmed' => true, 'published_at' => $stamp, 'operation_key' => (string) Str::uuid()])->assertOk()->assertJsonPath('delivery', 'unknown');
        $this->postJson($url.'/reconcile', ['confirmed' => true, 'published_at' => $stamp])->assertOk()->assertJsonPath('delivery', 'unknown');
        $this->postJson($url.'/reconcile', ['confirmed' => true, 'published_at' => $stamp])->assertOk()->assertJsonPath('delivery', 'reconciled');
        Http::assertSentCount(3);
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

    #[TestWith([true])]
    #[TestWith([false])]
    public function test_approved_final_sandbox_session_and_cdr_are_private_immutable_and_not_replayed(bool $validReceipt): void
    {
        $owner = User::factory()->create(['role' => 'vendor']);
        $admin = User::factory()->create(['role' => 'admin']);
        $customer = User::factory()->create(['role' => 'customer']);
        $sources = $this->source($owner);
        [$site, $tariff, $evse, $connector] = $sources;
        [, $otherToken] = $this->peer(User::factory()->create(['role' => 'vendor']), 'other', $sources);
        [$provider, $token, $operations] = $this->peer($owner, 'owner', $sources);
        $station = DB::table('vendor_sites')->where('id', $site)->value('station_id');
        $map = DB::table('charging_connector_mappings')->where('connector_id', $connector)->value('id');
        $start = now()->subHour()->startOfSecond();
        $end = now()->startOfSecond();
        $reservation = DB::table('reservations')->insertGetId([
            'user_id' => $customer->id, 'station_id' => $station, 'starts_at' => $start, 'status' => 'confirmed',
            'idempotency_key' => 'cpo-test-reservation', 'connector_mapping_id' => $map, 'charging_evse_id' => $evse,
        ]);
        $session = ChargingSession::create([
            'reservation_id' => $reservation, 'status' => 'stopped', 'energy_wh' => 1000, 'rate_minor_per_kwh' => 129,
            'currency' => 'AED', 'total_minor' => 129, 'stopped_at' => $end, 'charging_evse_id' => $evse,
        ]);
        $binding = DB::table('csms_bindings')->insertGetId(['station_id' => $station, 'identifier' => 'ev-test-cpo-session', 'protocol' => '2.0.1']);
        $command = DB::table('charger_commands')->insertGetId([
            'actor_id' => $customer->id, 'station_id' => $station, 'idempotency_key' => 'cpo-test-start',
            'payload_hash' => str_repeat('a', 64), 'action' => 'start', 'payload' => '{}',
        ]);
        $link = DB::table('csms_session_links')->insertGetId([
            'charging_session_id' => $session->id, 'binding_id' => $binding, 'start_command_id' => $command,
            'protocol' => '2.0.1', 'identifier' => 'ev-test-cpo-session', 'transaction_id' => 'test-session',
            'connector_mapping_id' => $map, 'started_at' => $start, 'ended_at' => $end, 'meter_start_wh' => 10,
            'last_meter_wh' => 1010, 'last_sequence' => 2,
        ]);
        $contractPath = 'ocpi.connections.owner.cpo_catalog.session_exports';
        config([$contractPath => [$session->id => [
            'site_id' => $site, 'token_uid' => 'TEST-PEER-TOKEN', 'contract_id' => 'TEST-PEER-CONTRACT', 'evse_id' => 'AE*EVX*ETEST',
        ]], 'ocpi.cpo_push_enabled' => true]);
        DB::table('ocpi_credential_links')->where('provider_id', $provider->id)->update([
            'configuration_hash' => app(CredentialExchange::class)->configurationHash($provider),
        ]);
        $provider->update(['endpoints' => $provider->endpoints + ['sessions_receiver' => 'https://emsp.example/sessions', 'cdrs_receiver' => 'https://emsp.example/cdrs']]);
        app(FinanceService::class)->issue($session, $admin->id);
        $this->actingAs($admin)->publication($operations.'/tariffs/'.$tariff)->assertOk();
        $this->publication($operations.'/locations/'.$site)->assertOk();
        $sessionPath = $operations.'/sessions/'.$session->id;
        $cdrPath = $operations.'/cdrs/'.$session->id;
        $this->publication($sessionPath)->assertConflict();

        DB::table('csms_inbox_events')->insert([
            ['link_id' => $link, 'event_id' => 'cpo-sandbox-start', 'fingerprint' => str_repeat('1', 64), 'sequence' => 1, 'type' => 'started',
                'meter_wh' => 10, 'occurred_at' => $start, 'outcome' => 'applied', 'created_at' => $start],
            ['link_id' => $link, 'event_id' => 'cpo-sandbox-end', 'fingerprint' => str_repeat('2', 64), 'sequence' => 2, 'type' => 'ended',
                'meter_wh' => 1010, 'occurred_at' => $end, 'outcome' => 'applied', 'created_at' => $end],
        ]);
        $this->publication($cdrPath)->assertConflict();
        // An immutable invoice must not be exported against subsequently changed
        // session prices even if the meter and energy evidence still match.
        DB::table('charging_sessions')->where('id', $session->id)->update(['total_minor' => 130]);
        $this->publication($sessionPath)->assertConflict();
        DB::table('charging_sessions')->where('id', $session->id)->update(['total_minor' => 129, 'rate_minor_per_kwh' => 130]);
        $this->publication($sessionPath)->assertConflict();
        DB::table('charging_sessions')->where('id', $session->id)->update(['rate_minor_per_kwh' => 129]);
        $this->assertDatabaseCount('ocpi_cpo_catalog', 2);
        $sessionStamp = $this->publication($sessionPath)->assertOk()->json('published_at');
        $cdrStamp = $this->publication($cdrPath)->assertOk()->json('published_at');
        $this->publication($cdrPath, 'withdraw', $cdrStamp)->assertConflict();
        $this->getJson(self::SENDER.'/sessions', $otherToken)->assertNotFound();
        $this->getJson(self::SENDER.'/sessions', $token)->assertOk()->assertJsonPath('data.0.id', 'Q'.$session->id)
            ->assertJsonPath('data.0.status', 'COMPLETED')->assertJsonPath('data.0.cdr_token.uid', 'TEST-PEER-TOKEN')->assertJsonMissingPath('data.0.user_id');
        $this->getJson(self::SENDER.'/cdrs', $token)->assertOk()->assertJsonPath('data.0.session_id', 'Q'.$session->id)
            ->assertJsonPath('data.0.cdr_location.evse_id', 'AE*EVX*ETEST')->assertJsonPath('data.0.total_cost.incl_vat', 1.29);
        $this->assertStringNotContainsString('TEST-PEER-TOKEN', DB::table('ocpi_cpo_catalog')->where('module', 'cdrs')->value('payload'));
        $cdrPayload = $this->getJson(self::SENDER.'/cdrs', $token)->json('data.0');

        Http::preventStrayRequests();
        Http::fake(function (Request $request) use ($session, $cdrPayload, $validReceipt) {
            $trace = ['X-Request-ID' => $request->header('X-Request-ID')[0], 'X-Correlation-ID' => $request->header('X-Correlation-ID')[0]];
            if ($request->method() === 'PUT') {
                $this->assertSame('https://emsp.example/sessions/AE/EVX/Q'.$session->id, $request->url());

                return Http::response(['status_code' => 1000, 'timestamp' => now()->toISOString()], 200, $trace);
            }
            if ($request->method() === 'GET') {
                $this->assertSame('https://emsp.example/cdrs/peer-record', $request->url());

                return Http::response(['status_code' => 1000, 'timestamp' => now()->toISOString(), 'data' => $cdrPayload], 200, $trace);
            }
            $this->assertSame('POST', $request->method());
            $this->assertSame('https://emsp.example/cdrs', $request->url());
            $this->assertSame('D'.$session->id, $request['id']);

            return Http::response(['status_code' => 1000, 'timestamp' => now()->toISOString()], 201, $trace + ['Location' => $validReceipt ? 'https://emsp.example/cdrs/peer-record' : 'https://unexpected.example/cdrs/receipt']);
        });
        $this->postJson($sessionPath.'/push', ['confirmed' => true, 'published_at' => $sessionStamp, 'operation_key' => (string) Str::uuid()])
            ->assertOk()->assertJsonPath('delivery', 'acknowledged');
        $delivery = $validReceipt ? 'acknowledged' : 'unknown';
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $this->postJson($cdrPath.'/push', ['confirmed' => true, 'published_at' => $cdrStamp, 'operation_key' => (string) Str::uuid()])
                ->assertOk()->assertJsonPath('delivery', $delivery);
        }
        if (! $validReceipt) {
            $this->postJson($cdrPath.'/reconcile', ['confirmed' => true, 'published_at' => $cdrStamp])->assertConflict();
            $this->postJson($cdrPath.'/reconcile', ['confirmed' => true, 'published_at' => $cdrStamp, 'cdr_receipt' => 'https://unexpected.example/receipt'])
                ->assertUnprocessable();
            $this->postJson($cdrPath.'/reconcile', ['confirmed' => true, 'published_at' => $cdrStamp, 'cdr_receipt' => 'peer-record'])
                ->assertOk()->assertJsonPath('delivery', 'reconciled');
        }
        Http::assertSentCount($validReceipt ? 2 : 3);
        $this->assertDatabaseCount('finance_invoices', 1);
        $this->assertDatabaseCount('finance_payments', 0);
    }
}
