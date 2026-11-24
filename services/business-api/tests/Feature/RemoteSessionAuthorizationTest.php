<?php

namespace Tests\Feature;

use App\Models\ChargerCommand;
use App\Models\Charging\SessionLink;
use App\Models\Reservation;
use App\Models\Station;
use App\Models\User;
use App\Services\Charging\RemoteSessionAuthorization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class RemoteSessionAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_remote_callback_requests_share_the_unchanged_identity_budget(): void
    {
        config(['csms.rider_enabled' => true, 'csms.session_bridge_enabled' => true, 'csms.ingress_allow_local_http' => true]);
        // Unlike provisioning, online callbacks carry ocppConnectionName rather
        // than identity. Distinct stations still share this middleware bucket.
        for ($i = 0; $i < 20; $i++) {
            $this->postJson('/api/internal/remote-session-authorize', [
                'ocppConnectionName' => 'ev-test-budget-'.$i,
            ])->assertUnauthorized();
        }
        $this->postJson('/api/internal/remote-session-authorize', [
            'ocppConnectionName' => 'ev-test-budget-new-station',
        ])->assertStatus(429)->assertHeader('Retry-After');
        $this->travel(61)->seconds();
        $this->postJson('/api/internal/remote-session-authorize')->assertUnauthorized();
    }

    private function fixture(): array
    {
        Http::preventStrayRequests();
        config(['csms.remote_authorization_enabled' => true, 'csms.session_bridge_enabled' => true, 'csms.rider_enabled' => true, 'csms.rider_client_secret' => str_repeat('s', 64), 'csms.rider_client_id' => 'creabiz-csms-rider', 'csms.ingress_allow_local_http' => true, 'csms.rider_source_api_url' => 'http://source.test']);
        $vendor = User::factory()->create(['role' => 'vendor']);
        $customer = User::factory()->create(['role' => 'customer']);
        $station = Station::create(['vendor_id' => $vendor->id, 'name' => 'Remote fixture', 'country' => 'AE', 'currency' => 'AED', 'timezone' => 'Asia/Dubai', 'latitude' => 25, 'longitude' => 55, 'source' => 'simulator', 'connector_type' => 'CCS2', 'power_kw' => 50]);
        DB::table('csms_bindings')->insert(['station_id' => $station->id, 'identifier' => 'ev-test-remote', 'protocol' => '1.6', 'created_at' => now(), 'updated_at' => now()]);
        $reservation = Reservation::create(['station_id' => $station->id, 'user_id' => $customer->id, 'starts_at' => now()->subMinutes(5), 'idempotency_key' => 'remote']);
        $response = $this->actingAs($customer)->postJson('/api/reservations/'.$reservation->id.'/csms-start')->assertAccepted();

        return [$customer, $reservation, $response, ChargerCommand::firstOrFail(), SessionLink::firstOrFail()];
    }

    public function test_generated_remote_credential_is_opaque_stable_and_not_exposed_in_session_responses(): void
    {
        [$customer, $reservation, $response, $command, $link] = $this->fixture();
        $tag = $command->payload['id_token'];
        $this->assertMatchesRegularExpression('/^EV-[A-F0-9]{16}$/D', $tag);
        $this->assertSame(hash('sha256', $tag), $link->remote_token_digest);
        $this->assertTrue(Str::isUuid($link->remote_registration_nonce));
        $this->postJson('/api/reservations/'.$reservation->id.'/csms-start')->assertAccepted()->assertJsonPath('start_command.id', $command->id);
        $this->assertSame($tag, $command->fresh()->payload['id_token']);
        $this->assertStringNotContainsString($tag, $response->getContent());
        $this->assertStringNotContainsString($tag, $this->getJson('/api/simulations')->assertOk()->getContent());
        foreach (['remote_token_digest', 'remote_registration_nonce', 'remote_authorization_id'] as $key) {
            $this->assertArrayNotHasKey($key, $link->toArray());
        }
    }

    public static function malformedSourceProjections(): array
    {
        return [
            'missing_list' => ['missing_list'],
            'object_list' => ['object_list'],
            'null_row' => ['null_row'],
            'string_row_id' => ['string_row_id'],
            'missing_row_id' => ['missing_row_id'],
            'object_marker' => ['object_marker'],
            'null_marker' => ['null_marker'],
            'additional_marker' => ['additional_marker'],
        ];
    }

    #[DataProvider('malformedSourceProjections')]
    public function test_malformed_remote_authorization_source_does_not_claim_or_mutate_identity(string $kind): void
    {
        [, , , $command, $link] = $this->fixture();
        $tag = $command->payload['id_token'];
        config([
            'csms.graphql_secret' => str_repeat('g', 64),
            'csms.graphql_url' => 'https://source.test/graphql',
        ]);
        Http::fake(function ($request) use ($kind, $link, $tag) {
            $this->assertSame('https://source.test/graphql', $request->url());
            $this->assertStringContainsString('Authorizations', $request['query']);
            $row = [
                'id' => 7, 'idToken' => $tag, 'idTokenType' => 'Central',
                'status' => 'Accepted',
                'additionalInfo' => [[
                    'type' => 'CREABIZ_REMOTE',
                    'additionalIdToken' => $link->remote_registration_nonce,
                ]],
                'realTimeAuth' => 'Never',
                'realTimeAuthUrl' => RemoteSessionAuthorization::CALLBACK,
                'realTimeAuthTimeout' => 0,
                'cacheExpiryDateTime' => now()->addMinutes(15)->toISOString(),
                'concurrentTransaction' => false,
                'isPrepaid' => false,
            ];
            switch ($kind) {
                case 'missing_list':
                    return Http::response(['data' => []]);
                case 'object_list':
                    return Http::response(['data' => ['Authorizations' => ['row' => $row]]]);
                case 'null_row':
                    return Http::response(['data' => ['Authorizations' => [null]]]);
                case 'string_row_id':
                    $row['id'] = '7';
                    break;
                case 'missing_row_id':
                    unset($row['id']);
                    break;
                case 'object_marker':
                    $row['additionalInfo'] = ['marker' => $row['additionalInfo'][0]];
                    break;
                case 'null_marker':
                    $row['additionalInfo'] = [null];
                    break;
                case 'additional_marker':
                    $row['additionalInfo'][] = ['type' => 'UNEXPECTED', 'additionalIdToken' => 'private'];
                    break;
            }

            return Http::response(['data' => ['Authorizations' => [$row]]]);
        });

        try {
            app(RemoteSessionAuthorization::class)->verified($link, $tag);
            $this->fail('Malformed remote source identity was accepted.');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        Http::assertSentCount(1);
        Http::assertNotSent(fn ($request) => str_starts_with((string) $request['query'], 'mutation'));
        $this->assertNull($link->fresh()->remote_authorization_id);
        $this->assertDatabaseCount('charger_commands', 1);
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    public function test_unverified_source_cannot_receive_an_accepted_remote_authorization(): void
    {
        $fixture = $this->fixture();
        $command = $fixture[3];
        $link = $fixture[4];
        Http::fake(['source.test/*' => Http::response(['ready' => true, 'remote_session_authorization' => true, 'tenant_id' => 1, 'offline_fallback' => false, 'cache_seconds' => 0, 'proof' => str_repeat('0', 64)])]);
        try {
            app(RemoteSessionAuthorization::class)->publish($command);
            $this->fail('Unverified source must be rejected.');
        } catch (HttpException $e) {
            $this->assertSame(503, $e->getStatusCode());
        }
        Http::assertSentCount(1);
        $this->assertNull($link->fresh()->remote_authorization_id);
        $this->assertSame('pending', $command->fresh()->status);
    }

    public function test_remote_source_expiry_requires_an_explicit_absolute_future_instant(): void
    {
        [, , , $command, $link] = $this->fixture();
        $tag = $command->payload['id_token'];
        $link->update(['remote_authorization_id' => 7]);
        config([
            'csms.graphql_secret' => str_repeat('g', 64),
            'csms.graphql_url' => 'https://source.test/graphql',
        ]);
        $sourceExpiry = now()->addMinutes(15)->toISOString();
        Http::fake(function ($request) use ($link, $tag, &$sourceExpiry) {
            $this->assertSame('https://source.test/graphql', $request->url());

            return Http::response(['data' => ['Authorizations' => [[
                'id' => 7, 'idToken' => $tag, 'idTokenType' => 'Central',
                'status' => 'Accepted',
                'additionalInfo' => [[
                    'type' => 'CREABIZ_REMOTE',
                    'additionalIdToken' => $link->remote_registration_nonce,
                ]],
                'realTimeAuth' => 'Never',
                'realTimeAuthUrl' => RemoteSessionAuthorization::CALLBACK,
                'realTimeAuthTimeout' => 0,
                'cacheExpiryDateTime' => $sourceExpiry,
                'concurrentTransaction' => false, 'isPrepaid' => false,
            ]]]]);
        });
        foreach (['tomorrow', '2030-02-30T12:00:00Z',
            '2030-01-01T12:00:00', '2030-01-01T12:00:00+25:00',
            now()->subSecond()->toISOString(), null] as $invalid) {
            $sourceExpiry = $invalid;
            $this->assertFalse(app(RemoteSessionAuthorization::class)->verified($link->fresh(), $tag));
        }
        $sourceExpiry = now()->addMinutes(15)->toISOString();
        $this->assertTrue(app(RemoteSessionAuthorization::class)->verified($link->fresh(), $tag));
        Http::assertSentCount(7);
        Http::assertNotSent(fn ($request) => str_starts_with((string) $request['query'], 'mutation'));
        $this->assertSame(7, (int) $link->fresh()->remote_authorization_id);
        $this->assertDatabaseCount('charger_commands', 1);
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    public static function remoteSourceChanges(): array
    {
        return [['endpoint'], ['key'], ['capability_endpoint'], ['capability_key'], ['disabled']];
    }

    #[DataProvider('remoteSourceChanges')]
    public function test_verified_remote_source_rejects_authority_drift_during_read(string $kind): void
    {
        [, , , $command, $link] = $this->fixture();
        $tag = $command->payload['id_token'];
        $link->update(['remote_authorization_id' => 7]);
        config(['csms.graphql_secret' => str_repeat('g', 64), 'csms.graphql_url' => 'https://source.test/graphql']);
        Http::fake(function ($request) use ($kind, $link, $tag) {
            $this->assertSame('https://source.test/graphql', $request->url());
            $this->assertSame(str_repeat('g', 64), $request->header('x-hasura-admin-secret')[0] ?? null);
            match ($kind) {
                'endpoint' => config(['csms.graphql_url' => 'https://alternate.example.test/graphql']),
                'key' => config(['csms.graphql_secret' => str_repeat('h', 64)]),
                'capability_endpoint' => config(['csms.rider_source_api_url' => 'https://alternate.example.test']),
                'capability_key' => config(['csms.rider_client_secret' => str_repeat('t', 64)]),
                'disabled' => config(['csms.remote_authorization_enabled' => false]),
            };

            return Http::response(['data' => ['Authorizations' => [[
                'id' => 7, 'idToken' => $tag, 'idTokenType' => 'Central', 'status' => 'Accepted',
                'additionalInfo' => [['type' => 'CREABIZ_REMOTE', 'additionalIdToken' => $link->remote_registration_nonce]],
                'realTimeAuth' => 'Never', 'realTimeAuthUrl' => RemoteSessionAuthorization::CALLBACK,
                'realTimeAuthTimeout' => 0, 'cacheExpiryDateTime' => now()->addMinutes(15)->toISOString(),
                'concurrentTransaction' => false, 'isPrepaid' => false,
            ]]]]);
        });
        try {
            app(RemoteSessionAuthorization::class)->verified($link->fresh(), $tag);
            $this->fail('Changed remote source authority was accepted.');
        } catch (HttpException $error) {
            $this->assertSame(503, $error->getStatusCode());
        }
        Http::assertSentCount(1);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'alternate.example.test')
            || str_starts_with((string) $request['query'], 'mutation'));
        $this->assertSame('pending', $command->fresh()->status);
        $this->assertSame(7, (int) $link->fresh()->remote_authorization_id);
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    #[DataProvider('remoteSourceChanges')]
    public function test_capability_probe_authority_drift_prevents_remote_publication(string $kind): void
    {
        [, , , $command, $link] = $this->fixture();
        config(['csms.graphql_secret' => str_repeat('g', 64), 'csms.graphql_url' => 'https://source.test/graphql']);
        Http::fake(function ($request) use ($kind) {
            $this->assertStringContainsString('/creabiz/rider-auth/capabilities', $request->url());
            parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);
            $proof = hash_hmac('sha256', "creabiz-rider-v1\n".$query['nonce'], str_repeat('s', 64));
            match ($kind) {
                'endpoint' => config(['csms.graphql_url' => 'https://alternate.example.test/graphql']),
                'key' => config(['csms.graphql_secret' => str_repeat('h', 64)]),
                'capability_endpoint' => config(['csms.rider_source_api_url' => 'https://alternate.example.test']),
                'capability_key' => config(['csms.rider_client_secret' => str_repeat('t', 64)]),
                'disabled' => config(['csms.remote_authorization_enabled' => false]),
            };

            return Http::response(['proof' => $proof, 'remote_session_authorization' => true,
                'ready' => true, 'tenant_id' => 1, 'offline_fallback' => false, 'cache_seconds' => 0]);
        });
        try {
            app(RemoteSessionAuthorization::class)->publish($command);
            $this->fail('Changed capability authority was accepted.');
        } catch (HttpException $error) {
            $this->assertSame(503, $error->getStatusCode());
        }
        Http::assertSentCount(1);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/graphql')
            || str_contains($request->url(), 'alternate.example.test'));
        $this->assertNull($link->fresh()->remote_authorization_id);
        $this->assertSame('pending', $command->fresh()->status);
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    public function test_unknown_service_credentials_and_unknown_remote_token_fail_closed(): void
    {
        $this->fixture();
        $body = ['idToken' => 'EV-0123456789ABCDEF', 'idTokenType' => 'Central', 'ocppConnectionName' => 'ev-test-remote'];
        $this->postJson('/api/internal/remote-session-authorize', $body)->assertUnauthorized();
        $bearer = $this->postJson('/api/internal/rider-oauth/token', ['grant_type' => 'client_credentials', 'client_id' => 'creabiz-csms-rider', 'client_secret' => str_repeat('s', 64), 'audience' => 'creabiz-rider-authorize'])->assertOk()->json('access_token');
        $this->withToken($bearer)->postJson('/api/internal/remote-session-authorize', $body)->assertOk()->assertJsonPath('data.allowed', 'NOT_ALLOWED');
        Http::assertNothingSent();
        $this->assertDatabaseCount('csms_session_links', 1);
    }

    public function test_empty_remote_authorization_schema_can_roundtrip_without_dangling_indexes(): void
    {
        $migration = require database_path('migrations/2026_11_08_000005_add_remote_session_authorization.php');
        $this->assertDatabaseCount('csms_session_links', 0);
        $migration->down();
        $this->assertFalse(Schema::hasColumn('csms_session_links', 'remote_token_digest'));
        $this->assertFalse(Schema::hasColumn('csms_session_links', 'remote_registration_nonce'));
        $migration->up();
        $this->assertTrue(Schema::hasColumns('csms_session_links', ['remote_token_digest', 'remote_registration_nonce', 'remote_authorization_id']));
        $indexes = collect(Schema::getIndexes('csms_session_links'));
        $this->assertTrue($indexes->contains(fn ($index) => $index['unique'] && $index['columns'] === ['remote_token_digest']));
        $this->assertTrue($indexes->contains(fn ($index) => $index['unique'] && $index['columns'] === ['remote_registration_nonce']));
    }

    public function test_populated_remote_authorization_rollback_retains_opaque_credentials(): void
    {
        [, , , , $link] = $this->fixture();
        $digest = $link->remote_token_digest;
        $migration = require database_path('migrations/2026_11_08_000005_add_remote_session_authorization.php');
        try {
            $migration->down();
            $this->fail('Retained remote credentials must block rollback.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Preserve remote authorization history before rollback.', $error->getMessage());
        }
        $this->assertTrue(Schema::hasColumn('csms_session_links', 'remote_token_digest'));
        $this->assertSame($digest, $link->fresh()->remote_token_digest);
    }
}
