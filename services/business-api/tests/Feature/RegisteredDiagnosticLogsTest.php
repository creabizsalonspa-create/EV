<?php

namespace Tests\Feature;

use App\Models\Charging\AdmissionProfile;
use App\Models\Station;
use App\Models\User;
use App\Models\VendorOps\VendorCharger;
use App\Models\VendorOps\VendorCompany;
use App\Models\VendorOps\VendorSite;
use App\Services\Charging\ChargerSourceRegistration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RegisteredDiagnosticLogsTest extends TestCase
{
    use RefreshDatabase;

    private static mixed $key;

    private array $station = [];

    private array $wire = [];

    private bool $ready = false;

    private string $protocol = '1.6';

    private ?string $failure = null;

    private mixed $onToken = null;

    private mixed $onConfirmation = null;

    private bool $loseDispatchAck = false;

    private int $reportingAttempts = 0;

    private function jwt(): string
    {
        $encode = fn ($value) => rtrim(strtr(base64_encode(json_encode($value, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
        $payload = $encode(['alg' => 'RS256', 'typ' => 'JWT', 'kid' => 'diagnostic']).'.'.$encode(['iss' => 'https://identity.test', 'aud' => 'https://operations.test', 'sub' => 'business-diagnostics', 'tenantId' => 1, 'iat' => now()->timestamp, 'exp' => now()->addMinutes(5)->timestamp, 'roles' => ['admin'], 'scope' => 'csms:operations']);
        openssl_sign($payload, $signature, self::$key, OPENSSL_ALGO_SHA256);

        return $payload.'.'.rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');
    }

    private function fixture(string $protocol = '1.6'): array
    {
        $this->freezeTime();
        $this->protocol = $protocol;
        self::$key ??= openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        config(['csms.session_bridge_enabled' => false, 'csms.upstream_registration_enabled' => true, 'csms.graphql_url' => 'https://source.test/graphql', 'csms.graphql_secret' => str_repeat('s', 64), 'csms.production_diagnostics_enabled' => true, 'csms.production_diagnostic_upload_origin' => 'https://logs.test', 'csms.diagnostic_logs_enabled' => true, 'csms.operations_base_url' => 'https://operations.test', 'csms.operations_auth_mode' => 'oauth2', 'csms.operations_oauth_token_url' => 'https://identity.test/token', 'csms.operations_oauth_issuer' => 'https://identity.test', 'csms.operations_oauth_audience' => 'https://operations.test', 'csms.operations_oauth_client_id' => 'business-diagnostics', 'csms.operations_oauth_client_secret' => str_repeat('c', 64), 'csms.operations_oauth_scope' => 'csms:operations', 'csms.operations_oauth_public_keys' => ['diagnostic' => openssl_pkey_get_details(self::$key)['key']]]);
        $owner = User::factory()->create(['role' => 'vendor']);
        $admin = User::factory()->create(['role' => 'admin']);
        $company = VendorCompany::create(['owner_id' => $owner->id, 'legal_name' => 'Registered diagnostics', 'country' => 'AE', 'trade_license_number' => 'DIAGNOSTIC', 'license_expires_on' => now()->addYear(), 'status' => 'approved']);
        $station = Station::create(['vendor_id' => $owner->id, 'name' => 'Registered diagnostic fixture', 'country' => 'AE', 'currency' => 'AED', 'timezone' => 'Asia/Dubai', 'latitude' => 25, 'longitude' => 55, 'source' => 'direct_unverified', 'connector_type' => 'CCS2', 'power_kw' => 50]);
        $site = VendorSite::create(['company_id' => $company->id, 'station_id' => $station->id, 'name' => 'Direct diagnostic site', 'country' => 'AE', 'timezone' => 'Asia/Dubai', 'address' => 'Synthetic', 'latitude' => 25, 'longitude' => 55]);
        $charger = VendorCharger::create(['company_id' => $company->id, 'site_id' => $site->id, 'asset_reference' => 'DIAGNOSTICS', 'manufacturer' => 'Synthetic', 'model' => 'Fixture', 'source' => 'direct_unverified', 'ocpp_protocol' => $protocol]);
        Http::preventStrayRequests();
        Http::fake(['https://identity.test/token' => function () {
            if ($this->onToken) {
                ($this->onToken)();
            }

            return Http::response(['token_type' => 'Bearer', 'access_token' => $this->jwt()]);
        }, 'https://operations.test/*' => function (HttpRequest $request) {
            $this->assertTrue($request->hasHeader('Authorization', 'Bearer '.$this->jwt()));
            $this->reportingAttempts++;
            $this->wire = $request->data();
            if ($this->loseDispatchAck) {
                throw new ConnectionException('Controlled lost diagnostic acknowledgement');
            }

            return Http::response([['success' => true]]);
        }, 'https://source.test/graphql' => function (HttpRequest $request) {
            $query = $request['query'];
            if (str_contains($query, 'insert_ChargingStations_one')) {
                $this->station = $request['variables']['object'] + ['id' => 31];

                return Http::response(['data' => ['insert_ChargingStations_one' => $this->station]]);
            }
            if (str_contains($query, 'ChargingStations')) {
                $row = $this->station;
                if ($this->ready) {
                    $row['isOnline'] = true;
                    if ($this->failure === 'wrong_station') {
                        $row['id'] = 32;
                    }
                }

                return Http::response(['data' => ['ChargingStations' => $row ? [$row] : []]]);
            }
            $id = (int) DB::table('charger_diagnostic_logs')->max('id');
            $at = now()->toISOString();
            if (str_contains($query, 'requestMessageId')) {
                if ($this->onConfirmation) {
                    ($this->onConfirmation)();
                }
                $row = ['id' => 101, 'type' => 3, 'createdAt' => $at, 'payload' => $this->protocol === '1.6' ? ['fileName' => 'diagnostics.log'] : ['status' => 'Accepted']];
                if ($this->failure === 'string_reply_id') {
                    $row['id'] = '101';
                }
            } elseif (str_contains($query, 'order_by:{id:asc}')) {
                $row = ['id' => 102, 'createdAt' => $at, 'payload' => ['status' => 'Uploaded', 'requestId' => $id]];
                if ($this->failure === 'wrong_request_id') {
                    $row['payload']['requestId'] = $id + 1;
                }
            } else {
                $row = ['id' => 100, 'createdAt' => $at];
                if ($this->failure === 'string_request_id') {
                    $row['id'] = '100';
                }
            }
            $rows = [$row];
            if ($this->failure === 'object_rows') {
                $rows = ['unexpected' => $row];
            }

            return Http::response(['data' => ['OCPPMessages' => $rows]]);
        }]);
        $request = Request::create('/owned-registration', 'POST');
        $request->setUserResolver(fn () => $admin);
        $service = app(ChargerSourceRegistration::class);
        $registration = $service->prepare($request, $company, $charger, ['identity' => 'CB-DIAGNOSTIC-001', 'idempotency_key' => 'register-diagnostic']);
        $service->register($request, $company, $charger, $registration['registration_id']);
        $profile = AdmissionProfile::where('charger_id', $charger->id)->firstOrFail();
        $profile->update(['enabled' => true, 'credential_version' => 1, 'credential_digest' => hash('sha256', 'private-synthetic-credential'), 'expires_at' => now()->addDay()]);
        $this->ready = true;
        $path = '/api/vendor-ops/companies/'.$company->id.'/chargers/'.$charger->id.'/diagnostic-logs';
        $this->actingAs($owner);

        return compact('owner', 'company', 'charger', 'profile', 'path');
    }

    private function create(array $f): int
    {
        return $this->postJson($f['path'], ['request_key' => (string) Str::uuid(), 'reason' => 'Inspect registered source diagnostics'])->assertCreated()->assertJsonPath('dispatch_state', 'transport_submitted')->assertJsonPath('evidence', 'AUTHENTICATED_SOURCE')->json('id');
    }

    private function upload(int $id, string $bytes, string $suffix = '')
    {
        $url = $this->wire['location'] ?? $this->wire['log']['remoteLocation'];
        $this->assertSame('https', parse_url($url, PHP_URL_SCHEME));
        $token = parse_url($url, PHP_URL_PASS);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $token);

        return $this->call('PUT', 'https://localhost/api/internal/diagnostic-logs/'.$id.$suffix, [], [], [], ['CONTENT_TYPE' => 'application/octet-stream', 'CONTENT_LENGTH' => (string) strlen($bytes), 'PHP_AUTH_USER' => 'diagnostic', 'PHP_AUTH_PW' => $token, 'HTTP_ACCEPT' => 'application/json'], $bytes);
    }

    public static function protocols(): array
    {
        return [['1.6'], ['2.0.1']];
    }

    #[DataProvider('protocols')]
    public function test_registered_source_uses_pinned_oauth_https_upload_and_correlated_private_download(string $protocol): void
    {
        $f = $this->fixture($protocol);
        $id = $this->create($f);
        $content = "PRIVATE registered diagnostic\0content";
        $this->upload($id, $content, '/diagnostics.log')->assertCreated();
        $this->get($f['path'].'/'.$id.'/download')->assertNotFound();
        $this->postJson($f['path'].'/'.$id.'/observe')->assertOk()->assertJsonPath('can_download', true)->assertJsonPath('physical_device_verified', false);
        $this->assertSame($content, $this->get($f['path'].'/'.$id.'/download')->assertOk()->getContent());
        $this->getJson($f['path'])->assertOk()->assertJsonPath('evidence', 'AUTHENTICATED_SOURCE')->assertJsonMissingPath('data.0.payload_ciphertext')->assertJsonMissingPath('data.0.consent_password_hash');
        $this->assertDatabaseCount('charger_commands', 0);
        $this->assertDatabaseCount('charging_sessions', 0);
        $this->assertDatabaseCount('finance_journals', 0);
        $this->assertCount(1, Http::recorded(fn ($r) => str_contains($r->url(), '/reporting/')));
    }

    public static function revocations(): array
    {
        $cases = [];
        foreach (['1.6', '2.0.1'] as $protocol) {
            foreach (['epoch', 'password', 'role', 'profile_version', 'profile_digest', 'profile_disabled'] as $change) {
                $cases[] = [$protocol, $change];
            }
        }

        return $cases;
    }

    #[DataProvider('revocations')]
    public function test_incoming_upload_capability_cannot_outlive_original_actor_or_admission_authority(string $protocol, string $change): void
    {
        $f = $this->fixture($protocol);
        $id = $this->create($f);
        match ($change) {
            'epoch' => DB::table('users')->where('id', $f['owner']->id)->update(['auth_epoch' => 2]),
            'password' => DB::table('users')->where('id', $f['owner']->id)->update(['password' => Hash::make('changed-private-synthetic-password')]),
            'role' => DB::table('users')->where('id', $f['owner']->id)->update(['role' => 'customer']),
            'profile_version' => $f['profile']->update(['credential_version' => 2]),
            'profile_digest' => $f['profile']->update(['credential_digest' => hash('sha256', 'changed-credential')]),
            'profile_disabled' => $f['profile']->update(['enabled' => false]),
        };
        $response = $this->upload($id, 'must not be retained');
        $this->assertContains($response->status(), [401, 403, 404, 409]);
        $this->assertDatabaseHas('charger_diagnostic_logs', ['id' => $id, 'file_ciphertext' => null, 'file_size' => null]);
        $this->assertDatabaseCount('finance_journals', 0);
    }

    public static function malformedProof(): array
    {
        return [['1.6', 'string_request_id'], ['2.0.1', 'string_request_id'], ['1.6', 'string_reply_id'], ['2.0.1', 'string_reply_id'], ['1.6', 'object_rows'], ['2.0.1', 'object_rows'], ['2.0.1', 'wrong_request_id']];
    }

    #[DataProvider('malformedProof')]
    public function test_malformed_source_never_grants_private_download(string $protocol, string $failure): void
    {
        $f = $this->fixture($protocol);
        $id = $this->create($f);
        $this->upload($id, 'private bytes')->assertCreated();
        $this->failure = $failure;
        $r = $this->postJson($f['path'].'/'.$id.'/observe');
        $this->assertContains($r->status(), [409, 503]);
        $this->assertDatabaseHas('charger_diagnostic_logs', ['id' => $id, 'accepted_at' => null, 'source_reply_id' => null]);
        $this->get($f['path'].'/'.$id.'/download')->assertNotFound();
    }

    public function test_direct_diagnostics_stays_disabled_for_unsafe_transport_or_inactive_admission(): void
    {
        $f = $this->fixture();
        config(['csms.production_diagnostics_enabled' => false]);
        $this->getJson($f['path'])->assertOk()->assertJsonPath('can_request', false);
        $this->postJson($f['path'], ['request_key' => (string) Str::uuid(), 'reason' => 'No unsafe source dispatch'])->assertServiceUnavailable();
        config(['csms.production_diagnostics_enabled' => true, 'csms.production_diagnostic_upload_origin' => 'http://logs.test']);
        $this->postJson($f['path'], ['request_key' => (string) Str::uuid(), 'reason' => 'No unsafe source dispatch'])->assertServiceUnavailable();
        $this->assertDatabaseCount('charger_diagnostic_logs', 0);
        $this->assertCount(0, Http::recorded(fn ($r) => str_contains($r->url(), '/reporting/')));
    }

    #[DataProvider('protocols')]
    public function test_same_contract_enforces_production_environment_https_without_simulator_feature_gate(string $protocol): void
    {
        $f = $this->fixture($protocol);
        $this->app->instance('env', 'production');
        $csrf = Str::random(40);
        $this->withSession(['_token' => $csrf])->withHeader('X-CSRF-TOKEN', $csrf);
        $f['path'] = 'https://localhost'.$f['path'];
        $id = $this->create($f);
        $this->upload($id, 'private production-policy contract bytes')->assertCreated();
        $this->postJson($f['path'].'/'.$id.'/observe')->assertOk()->assertJsonPath('can_download', true)->assertJsonPath('physical_device_verified', false);
        $this->assertDatabaseCount('charger_commands', 0);
        $this->assertDatabaseCount('finance_journals', 0);
    }

    public static function diagnosticAuthorityDrift(): array
    {
        $cases = [];
        foreach (['1.6', '2.0.1'] as $protocol) {
            foreach (['operations_base_url', 'graphql_url', 'graphql_secret', 'production_diagnostic_upload_origin'] as $field) {
                $cases[] = [$protocol, $field];
            }
        }

        return $cases;
    }

    #[DataProvider('diagnosticAuthorityDrift')]
    public function test_source_authority_drift_during_token_fetch_cannot_dispatch(string $protocol, string $field): void
    {
        $f = $this->fixture($protocol);
        $original = config('csms.'.$field);
        $replacement = $field === 'graphql_secret' ? str_repeat('z', 64) : 'https://replacement.example.test';
        $this->onToken = fn () => config(['csms.'.$field => $replacement]);
        $intent = ['request_key' => (string) Str::uuid(), 'reason' => 'Reject changed diagnostic source authority'];
        $id = $this->postJson($f['path'], $intent)->assertCreated()->assertJsonPath('dispatch_state', 'not_dispatched')->json('id');
        config(['csms.'.$field => $original]);
        $this->postJson($f['path'], $intent)->assertOk()->assertJsonPath('id', $id)->assertJsonPath('dispatch_state', 'not_dispatched');
        $this->assertSame(0, $this->reportingAttempts);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'replacement.example.test'));
        $this->assertDatabaseCount('charger_diagnostic_logs', 1);
        $this->assertDatabaseCount('charger_commands', 0);
        $this->assertDatabaseCount('finance_journals', 0);
    }

    #[DataProvider('protocols')]
    public function test_profile_rotation_during_token_fetch_blocks_dispatch_without_sending_old_capability(string $protocol): void
    {
        $f = $this->fixture($protocol);
        $this->onToken = fn () => $f['profile']->update(['credential_version' => 2]);
        $this->postJson($f['path'], ['request_key' => (string) Str::uuid(), 'reason' => 'Recheck changed admission before dispatch'])->assertCreated()->assertJsonPath('dispatch_state', 'not_dispatched');
        $this->assertCount(0, Http::recorded(fn ($r) => str_contains($r->url(), '/reporting/')));
        $this->assertDatabaseCount('finance_journals', 0);
    }

    #[DataProvider('protocols')]
    public function test_revoked_mobile_credential_cannot_dispatch_after_source_token_fetch(string $protocol): void
    {
        $fixture = $this->fixture($protocol);
        $token = $fixture['owner']->createToken('mobile');
        $this->onToken = fn () => $token->accessToken->delete();
        $this->app['auth']->forgetGuards();
        $path = str_replace('/api/', '/api/v1/', $fixture['path']);
        $this->withToken($token->plainTextToken)->postJson($path, [
            'request_key' => (string) Str::uuid(), 'reason' => 'Validate mobile authority before diagnostics',
        ])->assertForbidden();
        $this->assertDatabaseHas('charger_diagnostic_logs', ['dispatch_state' => 'not_dispatched']);
        $this->assertSame(0, $this->reportingAttempts);
    }

    #[DataProvider('protocols')]
    public function test_expired_mobile_credential_cannot_record_source_confirmation(string $protocol): void
    {
        config(['sanctum.expiration' => 10]);
        $fixture = $this->fixture($protocol);
        $id = $this->create($fixture);
        $token = $fixture['owner']->createToken('mobile');
        $token->accessToken->forceFill(['created_at' => now()->subMinutes(9)])->save();
        $this->onConfirmation = fn () => $this->travel(2)->minutes();
        $this->app['auth']->forgetGuards();
        $path = str_replace('/api/', '/api/v1/', $fixture['path']);
        $this->withToken($token->plainTextToken)->postJson($path.'/'.$id.'/observe')->assertForbidden();
        $this->assertDatabaseHas('charger_diagnostic_logs', ['id' => $id, 'source_reply_id' => null, 'accepted_at' => null]);
        $this->assertSame(1, $this->reportingAttempts);
    }

    #[DataProvider('protocols')]
    public function test_lost_command_acknowledgement_returns_original_receipt_without_replaying(string $protocol): void
    {
        $f = $this->fixture($protocol);
        $this->loseDispatchAck = true;
        $intent = ['request_key' => (string) Str::uuid(), 'reason' => 'Retain uncertain source diagnostic request'];
        $created = $this->postJson($f['path'], $intent)->assertCreated()->assertJsonPath('dispatch_state', 'outcome_unknown')->json('id');
        $this->postJson($f['path'], $intent)->assertOk()->assertJsonPath('id', $created)->assertJsonPath('dispatch_state', 'outcome_unknown');
        $this->assertSame(1, $this->reportingAttempts, 'Count attempted dispatches even when a failed connection has no recorded response.');
        $this->assertDatabaseCount('charger_diagnostic_logs', 1);
        $this->assertDatabaseCount('charger_commands', 0);
        $this->assertDatabaseCount('finance_journals', 0);
    }

    public function test_suspended_company_cannot_dispatch_or_upload_but_can_revoke_retained_private_evidence(): void
    {
        $f = $this->fixture();
        $id = $this->create($f);
        $this->upload($id, 'retained private evidence')->assertCreated();
        $this->postJson($f['path'].'/'.$id.'/observe')->assertOk()->assertJsonPath('can_download', true);
        $f['company']->update(['status' => 'suspended']);
        $count = Http::recorded()->count();
        $this->getJson($f['path'])->assertOk()->assertJsonPath('can_request', false);
        $this->postJson($f['path'])->assertConflict();
        $this->upload($id, 'retained private evidence')->assertConflict();
        $this->assertSame('retained private evidence', $this->get($f['path'].'/'.$id.'/download')->assertOk()->getContent());
        $this->deleteJson($f['path'].'/'.$id)->assertOk()->assertJsonPath('source_command_sent', false);
        $this->assertDatabaseHas('charger_diagnostic_logs', ['id' => $id, 'file_ciphertext' => null, 'upload_token_digest' => null]);
        $this->assertSame($count, Http::recorded()->count());
    }

    public function test_source_reconfiguration_blocks_new_network_work_but_allows_private_revocation_and_retained_download(): void
    {
        $f = $this->fixture();
        $id = $this->create($f);
        $this->upload($id, 'retained owned log')->assertCreated();
        $this->postJson($f['path'].'/'.$id.'/observe')->assertOk()->assertJsonPath('can_download', true);
        $count = Http::recorded()->count();
        config(['csms.graphql_url' => 'https://replacement.test/graphql']);
        $this->getJson($f['path'])->assertOk()->assertJsonPath('can_request', false);
        $this->postJson($f['path'].'/'.$id.'/observe')->assertConflict();
        $this->assertSame('retained owned log', $this->get($f['path'].'/'.$id.'/download')->assertOk()->getContent());
        $this->deleteJson($f['path'].'/'.$id)->assertOk()->assertJsonPath('source_command_sent', false);
        $this->assertDatabaseHas('charger_diagnostic_logs', ['id' => $id, 'file_ciphertext' => null, 'upload_token_digest' => null]);
        $this->assertSame($count, Http::recorded()->count());
    }

    #[DataProvider('protocols')]
    public function test_public_filename_route_requires_exact_capability_even_for_an_authenticated_owner(string $protocol): void
    {
        $f = $this->fixture($protocol);
        $id = $this->create($f);
        $this->call('PUT', 'https://localhost/api/internal/diagnostic-logs/'.$id.'/diagnostics.log', [], [], [], ['CONTENT_TYPE' => 'application/octet-stream', 'CONTENT_LENGTH' => '1', 'HTTP_ACCEPT' => 'application/json'], 'x')->assertNotFound();
        $this->call('PUT', 'https://localhost/api/internal/diagnostic-logs/'.$id.'/diagnostics.log', [], [], [], ['CONTENT_TYPE' => 'application/octet-stream', 'CONTENT_LENGTH' => '1', 'PHP_AUTH_USER' => 'diagnostic', 'PHP_AUTH_PW' => str_repeat('a', 64), 'HTTP_ACCEPT' => 'application/json'], 'x')->assertNotFound();
        $this->upload($id, 'path traversal must fail', '/forbidden%2Fpath')->assertNotFound();
        $this->assertDatabaseHas('charger_diagnostic_logs', ['id' => $id, 'file_ciphertext' => null]);
    }

    public function test_production_plaintext_upload_is_rejected_without_reading_or_storing_capability_content(): void
    {
        $f = $this->fixture();
        $this->app->instance('env', 'production');
        $csrf = Str::random(40);
        $this->withSession(['_token' => $csrf])->withHeader('X-CSRF-TOKEN', $csrf);
        $f['path'] = 'https://localhost'.$f['path'];
        $id = $this->create($f);
        $url = $this->wire['location'];
        $this->call('PUT', 'http://localhost/api/internal/diagnostic-logs/'.$id.'/diagnostics.log', [], [], [], ['CONTENT_TYPE' => 'application/octet-stream', 'CONTENT_LENGTH' => '1', 'PHP_AUTH_USER' => 'diagnostic', 'PHP_AUTH_PW' => parse_url($url, PHP_URL_PASS), 'HTTP_ACCEPT' => 'application/json'], 'x')->assertServiceUnavailable();
        $this->assertDatabaseHas('charger_diagnostic_logs', ['id' => $id, 'file_ciphertext' => null]);
    }
}
