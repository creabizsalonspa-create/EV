<?php

namespace Tests\Feature;

use App\Http\Controllers\RiderAccessController;
use App\Models\Station;
use App\Models\User;
use App\Models\VendorOps\VendorCompany;
use App\Services\Charging\RiderSourceProvisioner;
use App\Services\Charging\RiderTokenRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class RiderAuthorityTest extends TestCase
{
    use RefreshDatabase;

    private function payload(): array
    {
        return ['label' => 'Synthetic private card', 'token_type' => 'ISO14443', 'value' => 'AABBCC123456', 'expires_at' => now()->addMonth()->format('Y-m-d\TH:i:s\Z'), 'idempotency_key' => 'authority-card'];
    }

    private function denied(callable $operation, int $status): void
    {
        try {
            $operation();
            $this->fail('Changed authority must fail closed.');
        } catch (HttpException $error) {
            $this->assertSame($status, $error->getStatusCode());
        }
    }

    public static function changes(): array
    {
        return [['auth_epoch', 4, 409], ['role', 'vendor', 409], ['password', 'rotated-private-password-hash', 409], ['account_status', 'suspended', 403]];
    }

    #[DataProvider('changes')]
    public function test_stale_customer_cannot_issue_revoke_or_read_cards(string $field, mixed $value, int $status): void
    {
        $customer = User::factory()->create(['role' => 'customer']);
        $registry = app(RiderTokenRegistry::class);
        $token = $registry->issue($customer, $this->payload())['token'];
        DB::table('users')->where('id', $customer->id)->update([$field => $value]);
        $this->denied(fn () => $registry->issue($customer, $this->payload()), $status);
        $this->denied(fn () => $registry->revoke($customer, $token), $status);
        $request = Request::create('/private-rider-cards', 'GET');
        $request->setUserResolver(fn () => $customer);
        $this->denied(fn () => app(RiderAccessController::class)->callAction('index', ['r' => $request]), $status);
        $this->assertNull($token->fresh()->revoked_at);
        $this->assertDatabaseCount('rider_access_tokens', 1);
        $this->assertDatabaseCount('rider_authorization_events', 1);
    }

    private function publication(): array
    {
        config(['csms.rider_enabled' => true, 'csms.session_bridge_enabled' => true, 'csms.rider_client_secret' => str_repeat('s', 64), 'csms.graphql_secret' => str_repeat('g', 64), 'csms.graphql_url' => 'https://source.example/graphql', 'csms.rider_source_api_url' => 'https://source.example/rider', 'csms.rider_callback_url' => 'http://ev-business-api:8000/api/internal/rider-authorize']);
        Http::preventStrayRequests();
        $owner = User::factory()->create(['role' => 'vendor']);
        $customer = User::factory()->create(['role' => 'customer']);
        $company = VendorCompany::create(['owner_id' => $owner->id, 'legal_name' => 'Synthetic rider operator', 'country' => 'AE', 'trade_license_number' => 'SYNTHETIC', 'license_expires_on' => now()->addYear()->toDateString(), 'status' => 'approved']);
        $station = Station::create(['vendor_id' => $owner->id, 'name' => 'Synthetic authority station', 'country' => 'AE', 'currency' => 'AED', 'timezone' => 'Asia/Dubai', 'latitude' => 25, 'longitude' => 55, 'source' => 'simulator', 'connector_type' => 'CCS2', 'power_kw' => 50]);
        $token = app(RiderTokenRegistry::class)->issue($customer, $this->payload())['token'];
        DB::table('rider_charging_grants')->insert(['token_id' => $token->id, 'company_id' => $company->id, 'station_id' => $station->id, 'state' => 'approved', 'expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);

        return compact('owner', 'company', 'token');
    }

    private function source(array $fixture, ?callable $during = null): object
    {
        $state = (object) ['lookups' => 0, 'writes' => 0, 'row' => ['id' => 88, 'idToken' => 'AABBCC123456', 'idTokenType' => 'ISO14443', 'status' => 'Blocked', 'additionalInfo' => [['type' => 'CREABIZ_RIDER', 'additionalIdToken' => $fixture['token']->source_registration_nonce]]]];
        $transactionBaseline = DB::transactionLevel();
        Http::fake(function ($request) use ($state, $during, $transactionBaseline) {
            // No database transaction may span capability or GraphQL HTTP.
            $this->assertSame($transactionBaseline, DB::transactionLevel());
            if (str_contains($request->url(), '/capabilities')) {
                parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);
                $during?->__invoke('capabilities');

                return Http::response(['ready' => true, 'adapter_version' => 'creabiz-rider-v1', 'tenant_id' => 1, 'offline_fallback' => false, 'cache_seconds' => 0, 'proof' => hash_hmac('sha256', "creabiz-rider-v1\n".$query['nonce'], config('csms.rider_client_secret'))]);
            }
            $body = json_decode($request->body(), true, flags: JSON_THROW_ON_ERROR);
            if (str_starts_with($body['query'], 'mutation')) {
                $state->writes++;
                $state->row = array_replace($state->row, $body['variables']['set']);
                $during?->__invoke('write');

                return Http::response(['data' => ['update_Authorizations' => ['affected_rows' => 1]]]);
            }
            $state->lookups++;
            $during?->__invoke('lookup');

            return Http::response(['data' => ['Authorizations' => $state->row === null ? [] : [$state->row]]]);
        });

        return $state;
    }

    public function test_current_source_publication_is_verified_without_holding_database_locks_over_http(): void
    {
        $f = $this->publication();
        $state = $this->source($f);
        $result = app(RiderSourceProvisioner::class)->publish($f['owner'], $f['company'], $f['token']->id);
        $this->assertTrue($result['source_provisioned']);
        $this->assertFalse($result['hardware_verified']);
        $this->assertSame(1, $state->writes);
        $this->assertSame('active', $f['token']->fresh()->provisioning_state);
    }

    public function test_stale_operator_cannot_contact_source_and_capability_drift_cannot_claim(): void
    {
        $f = $this->publication();
        DB::table('users')->where('id', $f['owner']->id)->increment('auth_epoch');
        $this->denied(fn () => app(RiderSourceProvisioner::class)->publish($f['owner'], $f['company'], $f['token']->id), 409);
        Http::assertNothingSent();
        $f['owner'] = $f['owner']->fresh();
        $state = $this->source($f, function ($stage) use ($f) {
            if ($stage === 'capabilities') {
                DB::table('users')->where('id', $f['owner']->id)->increment('auth_epoch');
            }
        });
        $this->denied(fn () => app(RiderSourceProvisioner::class)->publish($f['owner'], $f['company'], $f['token']->id), 409);
        $this->assertSame(0, $state->lookups);
        $this->assertSame(0, $state->writes);
        $this->assertNull($f['token']->fresh()->provision_lease);
    }

    public static function publicationChanges(): array
    {
        return [['actor'], ['company'], ['lease'], ['grant'], ['token']];
    }

    #[DataProvider('publicationChanges')]
    public function test_changed_authority_or_scope_before_source_write_stays_unpublished(string $change): void
    {
        $f = $this->publication();
        $state = $this->source($f, function ($stage) use ($f, $change) {
            if ($stage !== 'lookup') {
                return;
            }
            match ($change) {
                'actor' => DB::table('users')->where('id', $f['owner']->id)->increment('auth_epoch'),
                'company' => DB::table('vendor_companies')->where('id', $f['company']->id)->update(['status' => 'suspended']),
                'lease' => $this->travel(61)->seconds(),
                'grant' => DB::table('rider_charging_grants')->where('token_id', $f['token']->id)->update(['state' => 'revoked', 'revoked_at' => now()]),
                'token' => DB::table('rider_access_tokens')->where('id', $f['token']->id)->update(['revoked_at' => now()]),
            };
        });
        $this->denied(fn () => app(RiderSourceProvisioner::class)->publish($f['owner'], $f['company'], $f['token']->id), 409);
        $this->assertSame(0, $state->writes);
        $token = $f['token']->fresh();
        $this->assertNull($token->provisioned_at);
        $this->assertSame('requires_inspection', $token->provisioning_state);
        $this->assertDatabaseMissing('rider_authorization_events', ['action' => 'source.provisioned']);
    }

    public function test_actor_revocation_after_source_write_cannot_promote_local_authorization(): void
    {
        $f = $this->publication();
        $state = $this->source($f, function ($stage) use ($f) {
            if ($stage === 'write') {
                DB::table('users')->where('id', $f['owner']->id)->increment('auth_epoch');
            }
        });
        $this->denied(fn () => app(RiderSourceProvisioner::class)->publish($f['owner'], $f['company'], $f['token']->id), 409);
        $this->assertSame(1, $state->writes);
        $this->assertNull($f['token']->fresh()->provisioned_at);
        $this->assertSame('requires_inspection', $f['token']->fresh()->provisioning_state);
        $this->assertDatabaseCount('csms_session_links', 0);
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    public function test_lost_authority_before_new_source_identity_creation_does_not_insert(): void
    {
        $f = $this->publication();
        $state = $this->source($f, function ($stage) use ($f) {
            if ($stage === 'lookup') {
                DB::table('users')->where('id', $f['owner']->id)->increment('auth_epoch');
            }
        });
        $state->row = null;
        $this->denied(fn () => app(RiderSourceProvisioner::class)->publish($f['owner'], $f['company'], $f['token']->id), 409);
        $this->assertSame(0, $state->writes);
        $this->assertSame('requires_inspection', $f['token']->fresh()->provisioning_state);
    }
}
