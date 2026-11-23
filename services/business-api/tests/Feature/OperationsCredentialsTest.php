<?php

namespace Tests\Feature;

use App\Models\ChargerCommand;
use App\Models\Station;
use App\Models\User;
use App\Services\Charging\OperationsCredentials;
use App\Services\CsmsDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class OperationsCredentialsTest extends TestCase
{
    use RefreshDatabase;

    private static mixed $key;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        self::$key ??= openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        config(['csms.operations_auth_mode' => 'oauth2', 'csms.operations_oauth_token_url' => 'https://identity.example.test/token', 'csms.operations_oauth_client_id' => 'business-operations', 'csms.operations_oauth_client_secret' => str_repeat('private-secret-', 4), 'csms.operations_oauth_issuer' => 'https://identity.example.test', 'csms.operations_oauth_audience' => 'https://csms.example.test', 'csms.operations_oauth_scope' => 'csms:operations', 'csms.operations_oauth_public_keys' => ['current' => openssl_pkey_get_details(self::$key)['key']]]);
        Http::preventStrayRequests();
    }

    private function jwt(array $claims = [], array $header = []): string
    {
        $encode = fn ($v) => rtrim(strtr(base64_encode(json_encode($v, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
        $input = $encode(array_replace(['alg' => 'RS256', 'typ' => 'JWT', 'kid' => 'current'], $header)).'.'.$encode(array_replace(['iss' => 'https://identity.example.test', 'aud' => 'https://csms.example.test', 'sub' => 'business-operations', 'tenantId' => 1, 'iat' => now()->timestamp, 'exp' => now()->addMinutes(5)->timestamp, 'roles' => ['admin'], 'scope' => 'csms:operations'], $claims));
        openssl_sign($input, $signature, self::$key, OPENSSL_ALGO_SHA256);

        return $input.'.'.rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');
    }

    private function serve(string $jwt, array $response = []): void
    {
        Http::fake(['https://identity.example.test/token' => Http::response(array_replace(['token_type' => 'Bearer', 'access_token' => $jwt], $response))]);
    }

    public function test_pinned_credentials_are_memory_only_and_provider_is_called_once_per_operation(): void
    {
        $token = $this->jwt();
        $this->serve($token);
        $service = app(OperationsCredentials::class);
        $this->assertSame($token, $service->token('https://csms.example.test'));
        $this->assertSame($token, $service->token('https://csms.example.test'));
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $r) => $r->url() === 'https://identity.example.test/token' && $r['grant_type'] === 'client_credentials' && $r['scope'] === 'csms:operations' && $r->hasHeader('Authorization', 'Basic '.base64_encode('business-operations:'.str_repeat('private-secret-', 4))));
    }

    public static function invalidClaims(): array
    {
        return [
            'issuer' => [['iss' => 'https://other.test']], 'audience' => [['aud' => 'https://other.test']], 'audience_array' => [['aud' => ['https://csms.example.test']]], 'subject' => [['sub' => 'other-service']], 'tenant' => [['tenantId' => 2]], 'string_tenant' => [['tenantId' => '1']], 'no_admin' => [['roles' => ['viewer']]], 'no_scope' => [['scope' => 'other:operations']], 'scope_substring' => [['scope' => 'xcsms:operations']], 'string_expiry' => [['exp' => '9999999999']], 'string_issued' => [['iat' => '1']], 'future_not_before' => [['nbf' => 9999999999]], 'null_not_before' => [['nbf' => null]], 'long_lifetime' => [['exp' => 9999999999]],
        ];
    }

    #[DataProvider('invalidClaims')]
    public function test_untrusted_authority_is_rejected_without_command_or_fallback(array $claims): void
    {
        $this->serve($this->jwt($claims));
        try {
            app(OperationsCredentials::class)->token('https://csms.example.test');
            $this->fail('Invalid service authority accepted.');
        } catch (HttpException $e) {
            $this->assertSame(503, $e->getStatusCode());
            $this->assertStringNotContainsString('private-secret', $e->getMessage());
        }
        Http::assertSentCount(1);
    }

    public static function invalidHeaders(): array
    {
        return [['alg', 'none'], ['alg', 'HS256'], ['kid', 'unknown'], ['crit', ['exp']], ['jku', 'https://attacker.test/keys'], ['jwk', ['kty' => 'RSA']], ['x5u', 'https://attacker.test/cert'], ['typ', 'other']];
    }

    #[DataProvider('invalidHeaders')]
    public function test_header_cannot_select_an_unpinned_key_or_algorithm(string $field, mixed $value): void
    {
        $this->serve($this->jwt([], [$field => $value]));
        $this->expectException(HttpException::class);
        app(OperationsCredentials::class)->token('https://csms.example.test');
    }

    public function test_tampered_signature_and_short_remaining_lifetime_are_rejected(): void
    {
        foreach ([$this->jwt(['exp' => now()->addSeconds(10)->timestamp]), $this->jwt(['iat' => now()->addSecond()->timestamp]), $this->jwt(['iat' => now()->subSeconds(901)->timestamp]), substr($this->jwt(), 0, -4).'AAAA'] as $token) {
            $this->serve($token);
            try {
                app(OperationsCredentials::class)->token('https://csms.example.test');
                $this->fail('Unsafe JWT accepted.');
            } catch (HttpException $e) {
                $this->assertSame(503, $e->getStatusCode());
            }
        }
    }

    public static function unsafeConfig(): array
    {
        return [['operations_oauth_token_url', 'http://identity.example.test/token'], ['operations_oauth_token_url', 'https://user@identity.example.test/token'], ['operations_oauth_token_url', 'https://identity.example.test/token?secret=x'], ['operations_oauth_token_url', 'https://identity.example.test/token#x'], ['operations_auth_mode', 'unknown'], ['operations_oauth_client_secret', 'short'], ['operations_oauth_scope', 'csms:operations another'], ['operations_oauth_public_keys', []]];
    }

    #[DataProvider('unsafeConfig')]
    public function test_unsafe_configuration_fails_closed(string $field, mixed $value): void
    {
        config(['csms.'.$field => $value]);
        $this->serve($this->jwt());
        $this->expectException(HttpException::class);
        app(OperationsCredentials::class)->token('https://csms.example.test');
    }

    public function test_production_never_uses_development_issuer(): void
    {
        config(['csms.operations_auth_mode' => 'development']);
        $this->app->instance('env', 'production');
        try {
            app(OperationsCredentials::class)->token('https://csms.example.test');
            $this->fail('Development token requested in production.');
        } catch (HttpException $e) {
            $this->assertSame(503, $e->getStatusCode());
        }
        Http::assertNothingSent();
    }

    public function test_oauth_failure_never_falls_back_or_retries(): void
    {
        foreach ([Http::response(['error' => 'secret-provider-diagnostic'], 429), Http::response('', 302, ['Location' => 'https://other.test']), Http::failedConnection()] as $response) {
            Http::fake(['*' => $response]);
            try {
                app(OperationsCredentials::class)->token('https://csms.example.test');
                $this->fail('Provider failure accepted.');
            } catch (HttpException $e) {
                $this->assertSame(503, $e->getStatusCode());
                $this->assertStringNotContainsString('secret-provider', $e->getMessage());
            }
        }
    }

    public static function protocols(): array
    {
        return [['1.6'], ['2.0.1']];
    }

    #[DataProvider('protocols')]
    public function test_verified_oauth_identity_drives_canonical_dispatch_once_for_both_protocols(string $protocol): void
    {
        config(['csms.simulator_enabled' => true, 'csms.base_url' => 'https://csms.example.test']);
        $vendor = User::factory()->create(['role' => 'vendor']);
        $station = Station::create(['vendor_id' => $vendor->id, 'name' => 'OAuth protocol fixture', 'country' => 'AE', 'currency' => 'AED', 'timezone' => 'Asia/Dubai', 'latitude' => 25, 'longitude' => 55, 'source' => 'simulator', 'connector_type' => 'CCS2', 'power_kw' => 50]);
        DB::table('csms_bindings')->insert(['station_id' => $station->id, 'identifier' => 'ev-test-oauth', 'protocol' => $protocol, 'created_at' => now(), 'updated_at' => now()]);
        $command = ChargerCommand::create(['consent_auth_epoch' => 0, 'actor_id' => $vendor->id, 'station_id' => $station->id, 'action' => 'start', 'payload' => [], 'payload_hash' => str_repeat('a', 64), 'idempotency_key' => 'oauth-once']);
        $jwt = $this->jwt();
        Http::fake(['https://identity.example.test/token' => Http::response(['token_type' => 'Bearer', 'access_token' => $jwt]), 'https://csms.example.test/ocpp/*' => Http::response([['success' => true]])]);
        $this->assertSame('submitted', app(CsmsDispatcher::class)->dispatch($command->id)->status);
        app(CsmsDispatcher::class)->dispatch($command->id);
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/ocpp/'.$protocol.'/evdriver/') && $r->hasHeader('Authorization', 'Bearer '.$jwt));
        $this->assertDatabaseCount('charger_commands', 1);
        $this->assertDatabaseCount('csms_inbox_events', 0);
        $this->assertDatabaseCount('finance_invoices', 0);
        $this->assertDatabaseCount('finance_journals', 0);
    }

    #[DataProvider('protocols')]
    public function test_rejected_service_authority_cannot_dispatch_or_replay_either_protocol(string $protocol): void
    {
        config(['csms.simulator_enabled' => true, 'csms.base_url' => 'https://csms.example.test']);
        $vendor = User::factory()->create(['role' => 'vendor']);
        $station = Station::create(['vendor_id' => $vendor->id, 'name' => 'Rejected OAuth fixture', 'country' => 'AE', 'currency' => 'AED', 'timezone' => 'Asia/Dubai', 'latitude' => 25, 'longitude' => 55, 'source' => 'simulator', 'connector_type' => 'CCS2', 'power_kw' => 50]);
        DB::table('csms_bindings')->insert(['station_id' => $station->id, 'identifier' => 'ev-test-oauth-rejected', 'protocol' => $protocol, 'created_at' => now(), 'updated_at' => now()]);
        $command = ChargerCommand::create(['consent_auth_epoch' => 0, 'actor_id' => $vendor->id, 'station_id' => $station->id, 'action' => 'start', 'payload' => [], 'payload_hash' => str_repeat('b', 64), 'idempotency_key' => 'oauth-rejected']);
        $this->serve($this->jwt(['tenantId' => 2]));
        $this->assertSame('outcome_unknown', app(CsmsDispatcher::class)->dispatch($command->id)->status);
        app(CsmsDispatcher::class)->dispatch($command->id);
        Http::assertSentCount(1);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/ocpp/') || str_contains($r->url(), '/dev/token'));
        $this->assertDatabaseCount('csms_inbox_events', 0);
        $this->assertDatabaseCount('finance_invoices', 0);
        $this->assertDatabaseCount('finance_journals', 0);
    }
}
