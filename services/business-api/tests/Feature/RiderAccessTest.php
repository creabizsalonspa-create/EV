<?php

namespace Tests\Feature;

use App\Models\Charging\RiderAccessToken;
use App\Models\Station;
use App\Models\User;
use App\Models\VendorOps\VendorCompany;
use App\Models\VendorOps\VendorSite;
use App\Services\Charging\RiderSourceProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class RiderAccessTest extends TestCase
{
    use RefreshDatabase;

    private function payload(): array
    {
        return ['label' => 'Private card', 'token_type' => 'ISO14443', 'value' => 'AABBCC123456', 'expires_at' => now()->addMonth()->format('Y-m-d\TH:i:s\Z'), 'idempotency_key' => 'card-registration'];
    }

    public function test_card_identity_is_encrypted_scoped_and_replay_never_reveals_it_again(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);
        $payload = $this->payload();
        $created = $this->actingAs($customer)->postJson('/api/rider-access/tokens', $payload)->assertCreated()->assertJsonPath('value_once', $payload['value'])->assertJsonPath('charging_authorized', false);
        $id = $created->json('token.id');
        $this->postJson('/api/rider-access/tokens', $payload)->assertOk()->assertJsonMissingPath('value_once')->assertJsonPath('token.id', $id);
        $this->postJson('/api/rider-access/tokens', array_replace($payload, ['label' => 'Changed']))->assertConflict();
        $list = $this->getJson('/api/rider-access/tokens')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->assertStringNotContainsString($payload['value'], $list->getContent());
        $this->assertStringNotContainsString($payload['value'], DB::table('rider_access_tokens')->value('token_ciphertext'));
        $this->assertArrayNotHasKey('token_digest', $list->json('data.0'));
        $other = User::factory()->create(['role' => 'customer']);
        $this->actingAs($other)->getJson('/api/rider-access/tokens')->assertJsonCount(0, 'data');
        $this->deleteJson('/api/rider-access/tokens/'.$id)->assertNotFound();
        $this->postJson('/api/rider-access/tokens', $payload)->assertConflict();
    }

    public function test_revocation_is_terminal_idempotent_and_does_not_issue_charger_commands(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);
        $id = $this->actingAs($customer)->postJson('/api/rider-access/tokens', $this->payload())->assertCreated()->json('token.id');
        $this->deleteJson('/api/rider-access/tokens/'.$id)->assertOk()->assertJsonPath('active_sessions_stopped', false);
        $this->deleteJson('/api/rider-access/tokens/'.$id)->assertOk();
        $this->patchJson('/api/rider-access/tokens/'.$id, ['expires_at' => now()->addMonths(2)->format('Y-m-d\TH:i:s\Z')])->assertConflict();
        $this->assertSame(1, DB::table('rider_authorization_events')->where('action', 'token.revoked')->count());
        $this->assertDatabaseCount('csms_session_links', 0);
    }

    public function test_vendor_cannot_register_customer_cards_and_physical_identifier_is_required(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'vendor']))->postJson('/api/rider-access/tokens', $this->payload())->assertForbidden();
        $payload = $this->payload();
        unset($payload['value']);
        $this->actingAs(User::factory()->create(['role' => 'customer']))->postJson('/api/rider-access/tokens', $payload)->assertUnprocessable();
        $this->postJson('/api/rider-access/tokens', array_replace($payload, ['token_type' => 'Central']))->assertCreated();
    }

    public function test_internal_authorization_requires_service_credentials_and_local_adapter(): void
    {
        config(['csms.rider_enabled' => true, 'csms.session_bridge_enabled' => true, 'csms.ingress_allow_local_http' => true, 'csms.rider_client_id' => 'creabiz-csms-rider', 'csms.rider_client_secret' => str_repeat('s', 64)]);
        $this->postJson('/api/internal/rider-authorize', ['idToken' => 'AABBCC123456'])->assertUnauthorized();
        $credentials = ['grant_type' => 'client_credentials', 'client_id' => 'creabiz-csms-rider', 'client_secret' => str_repeat('s', 64), 'audience' => 'creabiz-rider-authorize'];
        $this->postJson('/api/internal/rider-oauth/token', array_replace($credentials, ['client_secret' => 'wrong']))->assertUnauthorized();
        $bearer = $this->postJson('/api/internal/rider-oauth/token', $credentials)->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json('access_token');
        $this->withToken($bearer)->postJson('/api/internal/rider-authorize', ['idToken' => 'AABBCC123456', 'idTokenType' => 'Central', 'ocppConnectionName' => 'ev-test-unregistered'])->assertOk()->assertJsonPath('data.allowed', 'BLOCKED');
        $this->withToken($bearer.'tampered')->postJson('/api/internal/rider-authorize', [])->assertUnauthorized();
        config(['csms.rider_enabled' => false]);
        $this->postJson('/api/internal/rider-oauth/token', $credentials)->assertServiceUnavailable();
        $this->assertDatabaseCount('csms_session_links', 0);
    }

    private function approvedSourceFixture(): array
    {
        config([
            'csms.rider_enabled' => true,
            'csms.session_bridge_enabled' => true,
            'csms.rider_client_secret' => str_repeat('s', 64),
            'csms.rider_callback_url' => 'http://ev-business-api:8000/api/internal/rider-authorize',
            'csms.rider_source_api_url' => 'http://citrine:8080',
            'csms.graphql_url' => 'https://source.example.test/v1/graphql',
            'csms.graphql_secret' => str_repeat('g', 64),
        ]);
        $owner = User::factory()->create(['role' => 'vendor']);
        $customer = User::factory()->create(['role' => 'customer']);
        $station = Station::create(['vendor_id' => $owner->id, 'name' => 'Rider source fixture', 'country' => 'AE', 'currency' => 'AED', 'timezone' => 'Asia/Dubai', 'latitude' => 25, 'longitude' => 55, 'source' => 'simulator', 'connector_type' => 'CCS2', 'power_kw' => 50]);
        $company = VendorCompany::create(['owner_id' => $owner->id, 'legal_name' => 'Synthetic source', 'country' => 'AE', 'trade_license_number' => 'SYNTHETIC', 'license_expires_on' => now()->addYear()->toDateString(), 'status' => 'approved']);
        VendorSite::create(['company_id' => $company->id, 'station_id' => $station->id, 'name' => 'Fixture', 'country' => 'AE', 'timezone' => 'Asia/Dubai', 'address' => 'Synthetic', 'latitude' => 25, 'longitude' => 55]);
        $id = $this->actingAs($customer)->postJson('/api/rider-access/tokens', $this->payload())->assertCreated()->json('token.id');
        $grant = $this->postJson('/api/rider-access/tokens/'.$id.'/grants', ['station_id' => $station->id])->assertCreated()->json('id');
        $this->actingAs($owner)->postJson('/api/vendor-ops/companies/'.$company->id.'/rider-grants/'.$grant.'/review', [
            'state' => 'approved', 'expires_at' => now()->addDays(2)->format('Y-m-d\\TH:i:s\\Z'), 'note' => 'Approve synthetic source inspection',
        ])->assertOk();

        return [$owner, $customer, $company, $id];
    }

    public static function capabilityAuthorityCases(): array
    {
        $cases = [];
        foreach (['publish', 'retire'] as $operation) {
            foreach (['csms.graphql_secret', 'csms.rider_client_secret', 'csms.rider_source_api_url', 'csms.rider_callback_url'] as $field) {
                $cases[] = [$operation, $field];
            }
        }

        return $cases;
    }

    #[DataProvider('capabilityAuthorityCases')]
    public function test_capability_proof_cannot_authorize_a_replaced_source_policy(string $operation, string $field): void
    {
        [$owner, $customer, $company, $id] = $this->approvedSourceFixture();
        if ($operation === 'retire') {
            $this->actingAs($customer)->deleteJson('/api/rider-access/tokens/'.$id)->assertOk();
        }
        $before = RiderAccessToken::findOrFail($id);
        Http::preventStrayRequests();
        Http::fake(function ($request) use ($field) {
            $this->assertStringStartsWith('http://citrine:8080/creabiz/rider-auth/capabilities?', $request->url());
            parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);
            config([$field => str_starts_with($field, 'csms.rider_') && str_ends_with($field, '_url')
                ? 'https://other.example.test/adapter' : str_repeat('r', 64)]);

            return Http::response(['ready' => true, 'adapter_version' => 'creabiz-rider-v1', 'tenant_id' => 1,
                'offline_fallback' => false, 'cache_seconds' => 0,
                'proof' => hash_hmac('sha256', "creabiz-rider-v1\n".$query['nonce'], str_repeat('s', 64))]);
        });
        try {
            app(RiderSourceProvisioner::class)->$operation($owner, $company, $id);
            $this->fail('A capability proof authorized a changed trust policy.');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        $after = $before->fresh();
        $this->assertSame($before->provisioning_state, $after->provisioning_state);
        $this->assertNull($after->provisioned_at);
        $this->assertNull($after->source_authorization_id);
        $this->assertNull($after->provision_lease);
        Http::assertSentCount(1);
        $this->assertDatabaseCount('charger_commands', 0);
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    public static function unreadableTokenSourceActions(): array
    {
        return [
            'publish damaged token' => ['provision', 'publication', false],
            'publish mismatched token' => ['provision', 'publication', true],
            'retire damaged token' => ['retire-source', 'retirement', false],
            'retire mismatched token' => ['retire-source', 'retirement', true],
        ];
    }

    #[DataProvider('unreadableTokenSourceActions')]
    public function test_source_operation_requires_the_registered_local_token_identity(string $operation, string $audit, bool $validCiphertext): void
    {
        [$owner, $customer, $company, $id] = $this->approvedSourceFixture();
        if ($operation === 'retire-source') {
            $this->actingAs($customer)->deleteJson('/api/rider-access/tokens/'.$id)->assertOk();
        }
        RiderAccessToken::findOrFail($id)->update([
            // Keep the original suffix while testing a different card identity.
            'token_ciphertext' => $validCiphertext ? Crypt::encryptString('OTHER3456') : 'unreadable',
        ]);
        Http::preventStrayRequests();
        Http::fake([
            'http://citrine:8080/creabiz/rider-auth/capabilities?*' => function ($request) {
                parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);

                return Http::response(['ready' => true, 'adapter_version' => 'creabiz-rider-v1', 'tenant_id' => 1,
                    'offline_fallback' => false, 'cache_seconds' => 0,
                    'proof' => hash_hmac('sha256', "creabiz-rider-v1\n".$query['nonce'], str_repeat('s', 64))]);
            },
        ]);

        $this->actingAs($owner)->postJson('/api/vendor-ops/companies/'.$company->id.'/rider-tokens/'.$id.'/'.$operation)
            ->assertServiceUnavailable()->assertJsonPath('message', 'Local rider token identity requires inspection.');
        $this->assertDatabaseHas('rider_access_tokens', [
            'id' => $id,
            'provisioning_state' => 'requires_inspection',
            'source_authorization_id' => null,
            'provisioned_at' => null,
        ]);
        $this->assertDatabaseHas('rider_authorization_events', [
            'token_id' => $id,
            'actor_id' => $owner->id,
            'action' => 'source.'.$audit.'_requires_inspection',
        ]);
        Http::assertSentCount(1);
        $this->assertDatabaseCount('charger_commands', 0);
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    public static function publicationSourceCases(): array
    {
        return [['valid'], ['lookup_origin'], ['insert_origin'], ['update_disabled'], ['changed_insert_id'], ['changed_update_id'], ['relative_expiry'], ['lookup_key'], ['insert_client'], ['update_callback'], ['lookup_capability_url']];
    }

    #[DataProvider('publicationSourceCases')]
    public function test_rider_publication_requires_stable_source_and_typed_exact_receipt(string $kind): void
    {
        [$owner, , $company, $id] = $this->approvedSourceFixture();
        $original = config('csms.graphql_url');
        $row = null;
        $writes = 0;
        Http::preventStrayRequests();
        Http::fake(function ($request) use ($original, $kind, &$row, &$writes) {
            if (str_contains($request->url(), '/capabilities')) {
                parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);

                return Http::response(['ready' => true, 'adapter_version' => 'creabiz-rider-v1', 'tenant_id' => 1, 'offline_fallback' => false, 'cache_seconds' => 0,
                    'proof' => hash_hmac('sha256', "creabiz-rider-v1\n".$query['nonce'], str_repeat('s', 64))]);
            }
            $this->assertSame($original, $request->url());
            if (str_contains($request['query'], 'insert_Authorizations_one')) {
                $writes++;
                $row = $request['variables']['object'] + ['id' => 71];
                if ($kind === 'insert_origin') {
                    config(['csms.graphql_url' => 'https://other.example.test/v1/graphql']);
                } elseif ($kind === 'insert_client') {
                    config(['csms.rider_client_secret' => str_repeat('r', 64)]);
                }
                if ($kind === 'changed_insert_id') {
                    $row['id'] = 72;
                }

                return Http::response(['data' => ['insert_Authorizations_one' => ['id' => 71]]]);
            }
            if (str_contains($request['query'], 'update_Authorizations')) {
                $writes++;
                $row = array_replace($row, $request['variables']['set']);
                if ($kind === 'update_disabled') {
                    config(['csms.rider_enabled' => false]);
                } elseif ($kind === 'changed_update_id') {
                    $row['id'] = 72;
                } elseif ($kind === 'relative_expiry') {
                    $row['cacheExpiryDateTime'] = 'next month';
                } elseif ($kind === 'update_callback') {
                    config(['csms.rider_callback_url' => 'https://other.example.test/authorize']);
                }

                return Http::response(['data' => ['update_Authorizations' => ['affected_rows' => 1]]]);
            }
            if ($row === null) {
                match ($kind) {
                    'lookup_origin' => config(['csms.graphql_url' => 'https://other.example.test/v1/graphql']),
                    'lookup_key' => config(['csms.graphql_secret' => str_repeat('h', 64)]),
                    'lookup_capability_url' => config(['csms.rider_source_api_url' => 'https://other.example.test']),
                    default => null,
                };
            }

            return Http::response(['data' => ['Authorizations' => $row === null ? [] : [$row]]]);
        });
        try {
            $result = app(RiderSourceProvisioner::class)->publish($owner, $company, $id);
            $this->assertSame('valid', $kind);
            $this->assertTrue($result['source_provisioned']);
        } catch (HttpException $e) {
            $this->assertNotSame('valid', $kind);
            $this->assertContains($e->getStatusCode(), [409, 503]);
        }
        $token = RiderAccessToken::findOrFail($id);
        $this->assertSame($kind === 'valid' ? 'active' : 'requires_inspection', $token->provisioning_state);
        if ($kind === 'valid') {
            $this->assertNotNull($token->provisioned_at);
            $this->assertSame(71, (int) $token->source_authorization_id);
        } else {
            $this->assertNull($token->provisioned_at);
            $this->assertNull($token->source_authorization_id);
        }
        $this->assertSame(match ($kind) {
            'lookup_origin', 'lookup_key', 'lookup_capability_url' => 0,
            'insert_origin', 'insert_client', 'changed_insert_id' => 1,
            default => 2,
        }, $writes);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'other.example.test') || str_contains($request->url(), '/ocpp/'));
        $this->assertDatabaseCount('csms_session_links', 0);
        $this->assertDatabaseCount('charger_commands', 0);
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    public static function retirementSourceCases(): array
    {
        return [['valid'], ['lookup_origin'], ['changed_readback_id'], ['update_disabled'], ['lookup_key'], ['update_client']];
    }

    #[DataProvider('retirementSourceCases')]
    public function test_rider_retirement_never_confirms_a_different_or_revoked_source(string $kind): void
    {
        [$owner, $customer, $company, $id] = $this->approvedSourceFixture();
        $this->actingAs($customer)->deleteJson('/api/rider-access/tokens/'.$id)->assertOk();
        $token = RiderAccessToken::findOrFail($id);
        $original = config('csms.graphql_url');
        $row = [
            'id' => 71, 'idToken' => $this->payload()['value'], 'idTokenType' => $token->token_type,
            'status' => 'Accepted',
            'additionalInfo' => [['additionalIdToken' => $token->source_registration_nonce, 'type' => 'CREABIZ_RIDER']],
        ];
        $writes = 0;
        Http::preventStrayRequests();
        Http::fake(function ($request) use ($original, $kind, &$row, &$writes) {
            if (str_contains($request->url(), '/capabilities')) {
                parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);

                return Http::response(['ready' => true, 'adapter_version' => 'creabiz-rider-v1', 'tenant_id' => 1, 'offline_fallback' => false, 'cache_seconds' => 0,
                    'proof' => hash_hmac('sha256', "creabiz-rider-v1\n".$query['nonce'], str_repeat('s', 64))]);
            }
            $this->assertSame($original, $request->url());
            if (str_contains($request['query'], 'update_Authorizations')) {
                $writes++;
                $row = array_replace($row, $request['variables']['set']);
                if ($kind === 'changed_readback_id') {
                    $row['id'] = 72;
                } elseif ($kind === 'update_disabled') {
                    config(['csms.rider_enabled' => false]);
                } elseif ($kind === 'update_client') {
                    config(['csms.rider_client_secret' => str_repeat('r', 64)]);
                }

                return Http::response(['data' => ['update_Authorizations' => ['affected_rows' => 1]]]);
            }
            if ($writes === 0) {
                match ($kind) {
                    'lookup_origin' => config(['csms.graphql_url' => 'https://other.example.test/v1/graphql']),
                    'lookup_key' => config(['csms.graphql_secret' => str_repeat('h', 64)]),
                    default => null,
                };
            }

            return Http::response(['data' => ['Authorizations' => [$row]]]);
        });
        try {
            $result = app(RiderSourceProvisioner::class)->retire($owner, $company, $id);
            $this->assertSame('valid', $kind);
            $this->assertTrue($result['source_retirement_observed']);
            $this->assertFalse($result['active_sessions_stopped']);
        } catch (HttpException $e) {
            $this->assertNotSame('valid', $kind);
            $this->assertContains($e->getStatusCode(), [409, 503]);
        }
        $token->refresh();
        $this->assertSame($kind === 'valid' ? 'retired' : 'requires_inspection', $token->provisioning_state);
        $this->assertNotNull($token->revoked_at);
        $this->assertNull($token->provisioned_at);
        $this->assertSame(in_array($kind, ['lookup_origin', 'lookup_key'], true) ? 0 : 1, $writes);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'other.example.test') || str_contains($request->url(), '/ocpp/'));
        $this->assertDatabaseCount('csms_session_links', 0);
        $this->assertDatabaseCount('charger_commands', 0);
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    public function test_station_approval_is_operator_scoped_expiry_bounded_and_private(): void
    {
        $owner = User::factory()->create(['role' => 'vendor']);
        $customer = User::factory()->create(['role' => 'customer']);
        $station = Station::create(['vendor_id' => $owner->id, 'name' => 'Rider operator fixture', 'country' => 'AE', 'currency' => 'AED', 'timezone' => 'Asia/Dubai', 'latitude' => 25, 'longitude' => 55, 'source' => 'simulator', 'connector_type' => 'CCS2', 'power_kw' => 50]);
        $company = VendorCompany::create(['owner_id' => $owner->id, 'legal_name' => 'Synthetic rider operator', 'country' => 'AE', 'trade_license_number' => 'SYNTHETIC', 'license_expires_on' => now()->addYear()->toDateString(), 'status' => 'approved']);
        VendorSite::create(['company_id' => $company->id, 'station_id' => $station->id, 'name' => 'Fixture', 'country' => 'AE', 'timezone' => 'Asia/Dubai', 'address' => 'Synthetic', 'latitude' => 25, 'longitude' => 55]);
        $id = $this->actingAs($customer)->postJson('/api/rider-access/tokens', $this->payload())->assertCreated()->json('token.id');
        $base = '/api/rider-access/tokens/'.$id.'/grants';
        $grant = $this->postJson($base, ['station_id' => $station->id])->assertCreated()->json('id');
        $this->postJson($base, ['station_id' => $station->id])->assertOk()->assertJsonPath('id', $grant);
        $operator = '/api/vendor-ops/companies/'.$company->id.'/rider-grants';
        $this->actingAs(User::factory()->create(['role' => 'vendor']))->getJson($operator)->assertNotFound();
        $body = ['state' => 'approved', 'expires_at' => now()->addDays(2)->format('Y-m-d\TH:i:s\Z'), 'note' => 'PRIVATE operator review evidence'];
        $this->postJson($operator.'/'.$grant.'/review', $body)->assertNotFound();
        $this->actingAs($owner)->postJson($operator.'/'.$grant.'/review', array_replace($body, ['expires_at' => now()->addMonths(2)->format('Y-m-d\TH:i:s\Z')]))->assertUnprocessable();
        $this->postJson($operator.'/'.$grant.'/review', $body)->assertOk()->assertJsonPath('charging_authorized', false);
        $this->assertStringNotContainsString('PRIVATE', DB::table('rider_charging_grants')->value('review_note'));
        $this->assertStringNotContainsString('PRIVATE', $this->getJson($operator)->assertOk()->getContent());
        $this->actingAs($customer)->deleteJson($base.'/'.$grant)->assertOk();
        $this->actingAs($owner)->postJson($operator.'/'.$grant.'/review', $body)->assertConflict();
        $this->assertDatabaseCount('csms_session_links', 0);
    }
}
