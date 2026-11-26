<?php

namespace Tests\Feature;

use App\Models\Charging\AdmissionProfile;
use App\Models\Station;
use App\Models\User;
use App\Models\VendorOps\VendorCharger;
use App\Models\VendorOps\VendorCompany;
use App\Models\VendorOps\VendorSite;
use App\Services\Charging\AdmissionIdentity;
use App\Services\Charging\ChargerSourceRegistration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ChargerSourceRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private array $remote = [];

    private int $writes = 0;

    private function fixture(string $protocol = '1.6'): array
    {
        config(['csms.upstream_registration_enabled' => true, 'csms.upstream_registration_allow_local_http' => true, 'csms.graphql_secret' => str_repeat('s', 64), 'csms.graphql_url' => 'http://127.0.0.1:8095/v1/graphql']);
        Http::preventStrayRequests();
        $owner = User::factory()->create(['role' => 'vendor']);
        $admin = User::factory()->create(['role' => 'admin']);
        $company = VendorCompany::create(['owner_id' => $owner->id, 'legal_name' => 'Synthetic direct source owner', 'country' => 'AE', 'trade_license_number' => 'SOURCE-TEST', 'license_expires_on' => now()->addYear()->toDateString(), 'status' => 'approved']);
        $station = Station::create(['vendor_id' => $owner->id, 'name' => 'Synthetic registration metadata', 'country' => 'AE', 'currency' => 'AED', 'timezone' => 'Asia/Dubai', 'latitude' => 25, 'longitude' => 55, 'source' => 'direct_unverified', 'connector_type' => 'CCS2', 'power_kw' => 50]);
        $site = VendorSite::create(['company_id' => $company->id, 'station_id' => $station->id, 'name' => 'Source fixture', 'country' => 'AE', 'timezone' => 'Asia/Dubai', 'address' => 'Synthetic', 'latitude' => 25, 'longitude' => 55]);
        $charger = VendorCharger::create(['company_id' => $company->id, 'site_id' => $site->id, 'asset_reference' => 'SOURCE-TEST', 'manufacturer' => 'Synthetic', 'model' => 'ContractFixture', 'source' => 'direct_unverified', 'ocpp_protocol' => $protocol]);
        $path = '/api/vendor-ops/companies/'.$company->id.'/chargers/'.$charger->id.'/source-registration';

        return compact('owner', 'admin', 'company', 'station', 'site', 'charger', 'path');
    }

    private function source(?string $failure = null): void
    {
        Http::fake(['*' => function (Request $request) use ($failure) {
            $this->assertSame(config('csms.graphql_url'), $request->url());
            $this->assertTrue($request->hasHeader('x-hasura-admin-secret', str_repeat('s', 64)));
            if (str_contains($request['query'], 'insert_ChargingStations_one')) {
                $this->writes++;
                $this->remote = $request['variables']['object'] + ['id' => 401];
                if ($failure === 'lost_ack') {
                    throw new ConnectionException('Synthetic lost acknowledgement');
                }
                $row = $this->remote;
                if ($failure === 'string_id') {
                    $row['id'] = '401';
                } elseif ($failure === 'wrong_tenant') {
                    $row['tenantId'] = 2;
                } elseif ($failure === 'wrong_protocol') {
                    $row['protocol'] = 'ocpp2.1';
                } elseif ($failure === 'online') {
                    $row['isOnline'] = true;
                }

                return Http::response(['data' => ['insert_ChargingStations_one' => $row]]);
            }
            $rows = $this->remote ? [$this->remote] : [];
            if ($this->writes > 0 && $failure === 'changed_id') {
                $rows[0]['id'] = 402;
            } elseif ($this->writes > 0 && $failure === 'missing_readback') {
                $rows = [];
            } elseif ($failure === 'object_rows') {
                $rows = ['unexpected' => ['id' => 401]];
            }

            return Http::response(['data' => ['ChargingStations' => $rows]]);
        }]);
    }

    private function prepare(array $f): int
    {
        return $this->actingAs($f['admin'])->postJson($f['path'], ['identity' => 'CB-OWNED-001', 'idempotency_key' => 'prepare-source-once'])->assertCreated()->assertJsonPath('state', 'prepared')->assertJsonPath('device_commands_sent', 0)->json('registration_id');
    }

    public static function protocols(): array
    {
        return [['1.6'], ['2.0.1']];
    }

    #[DataProvider('protocols')]
    public function test_admin_can_prepare_register_and_replay_owned_source_metadata_without_energizing(string $protocol): void
    {
        $f = $this->fixture($protocol);
        $this->source();
        $id = $this->prepare($f);
        Http::assertNothingSent();
        $this->assertFalse(app(AdmissionIdentity::class)->compatible($f['company'], $f['charger'], 'CB-OWNED-001', $protocol, true));
        $this->postJson($f['path'], ['identity' => 'CB-OWNED-001', 'idempotency_key' => 'prepare-source-once'])->assertOk()->assertJsonPath('created', false);
        $this->postJson($f['path'].'/'.$id.'/register')->assertOk()->assertJsonPath('state', 'registered')->assertJsonPath('source_station_id', 401)->assertJsonPath('upstream_registration_verified', true)->assertJsonPath('charging_capability_granted', false)->assertJsonPath('charger_hardware_verified', false);
        $this->postJson($f['path'].'/'.$id.'/register')->assertOk()->assertJsonPath('state', 'registered');
        Http::assertSentCount(3);
        $this->assertSame(1, $this->writes);
        $this->assertFalse(AdmissionProfile::firstOrFail()->enabled);
        $this->assertNull(AdmissionProfile::firstOrFail()->credential_digest);
        $this->assertTrue(app(AdmissionIdentity::class)->compatible($f['company'], $f['charger'], 'CB-OWNED-001', $protocol, true));
        $this->actingAs($f['owner'])->getJson($f['path'])->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('can_prepare', false)->assertJsonPath('source_snapshot_only', true);
        $this->assertDatabaseCount('csms_bindings', 1);
        $this->assertDatabaseCount('charger_source_registrations', 1);
        $this->assertDatabaseCount('charger_admission_events', 1);
        $this->assertDatabaseCount('charger_commands', 0);
        $this->assertDatabaseCount('charging_sessions', 0);
        $this->assertDatabaseCount('finance_invoices', 0);
        $this->assertDatabaseCount('finance_journals', 0);
        $this->assertDatabaseHas('stations', ['id' => $f['station']->id, 'source' => 'direct_unverified']);
    }

    public static function failures(): array
    {
        $out = [];
        foreach (['1.6', '2.0.1'] as $protocol) {
            foreach (['lost_ack', 'string_id', 'wrong_tenant', 'wrong_protocol', 'online', 'changed_id', 'missing_readback', 'object_rows'] as $failure) {
                $out[] = [$protocol, $failure];
            }
        }

        return $out;
    }

    #[DataProvider('failures')]
    public function test_uncertain_or_malformed_source_never_replays_or_grants_charging(string $protocol, string $failure): void
    {
        $f = $this->fixture($protocol);
        $this->source($failure);
        $id = $this->prepare($f);
        $r = $this->postJson($f['path'].'/'.$id.'/register');
        $this->assertContains($r->status(), [409, 503]);
        $requests = Http::recorded()->count();
        $this->postJson($f['path'].'/'.$id.'/register')->assertOk()->assertJsonPath('state', 'requires_inspection')->assertJsonPath('automatic_retry_allowed', false);
        $this->assertSame($requests, Http::recorded()->count());
        $this->assertSame($failure === 'object_rows' ? 0 : 1, $this->writes);
        $this->assertDatabaseHas('charger_source_registrations', ['id' => $id, 'state' => 'requires_inspection', 'source_station_id' => null, 'verified_at' => null]);
        $this->assertDatabaseCount('charger_commands', 0);
        $this->assertDatabaseCount('finance_journals', 0);
        $this->assertFalse(AdmissionProfile::firstOrFail()->enabled);
    }

    public function test_existing_upstream_identity_is_never_adopted_or_overwritten(): void
    {
        $f = $this->fixture();
        $this->remote = ['id' => 777, 'tenantId' => 1, 'ocppConnectionName' => 'CB-OWNED-001', 'protocol' => 'ocpp1.6', 'isOnline' => false, 'chargePointVendor' => 'Synthetic', 'chargePointModel' => 'ContractFixture'];
        $original = $this->remote;
        $this->source();
        $id = $this->prepare($f);
        $this->postJson($f['path'].'/'.$id.'/register')->assertConflict();
        $this->assertSame($original, $this->remote);
        $this->assertSame(0, $this->writes);
        Http::assertSentCount(1);
    }

    public function test_customer_vendor_and_other_tenants_cannot_register_or_read_private_inventory(): void
    {
        $f = $this->fixture();
        $this->source();
        $this->actingAs($f['owner'])->postJson($f['path'], ['identity' => 'CB-OWNED-001', 'idempotency_key' => 'x'])->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'customer']))->getJson($f['path'])->assertNotFound();
        $this->postJson($f['path'], ['identity' => 'CB-OWNED-001', 'idempotency_key' => 'x'])->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'vendor']))->getJson($f['path'])->assertNotFound();
        $this->assertDatabaseCount('csms_bindings', 0);
        $this->assertDatabaseCount('charger_source_registrations', 0);
        Http::assertNothingSent();
    }

    public function test_registration_requires_explicit_flag_and_safe_source_before_preparation(): void
    {
        $f = $this->fixture();
        $this->source();
        config(['csms.upstream_registration_enabled' => false]);
        $this->actingAs($f['admin'])->postJson($f['path'], ['identity' => 'CB-OWNED-001', 'idempotency_key' => 'x'])->assertServiceUnavailable();
        config(['csms.upstream_registration_enabled' => true, 'csms.upstream_registration_allow_local_http' => false]);
        $this->postJson($f['path'], ['identity' => 'CB-OWNED-001', 'idempotency_key' => 'x'])->assertServiceUnavailable();
        $this->assertDatabaseCount('csms_bindings', 0);
        Http::assertNothingSent();
    }

    public function test_stale_request_actor_cannot_prepare_or_register(): void
    {
        $f = $this->fixture();
        $this->source();
        $id = $this->prepare($f);
        DB::table('users')->where('id', $f['admin']->id)->update(['auth_epoch' => 2]);
        $request = \Illuminate\Http\Request::create('/registration', 'POST');
        $request->setUserResolver(fn () => $f['admin']);
        try {
            app(ChargerSourceRegistration::class)->register($request, $f['company'], $f['charger'], $id);
            $this->fail('Stale authority admitted.');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        $this->assertDatabaseHas('charger_source_registrations', ['id' => $id, 'state' => 'prepared']);
        Http::assertNothingSent();
    }

    public function test_inventory_or_source_generation_change_fences_retained_registration(): void
    {
        $f = $this->fixture();
        $this->source();
        $id = $this->prepare($f);
        $f['charger']->update(['model' => 'ChangedModel']);
        $this->postJson($f['path'].'/'.$id.'/register')->assertConflict();
        $f['charger']->update(['model' => 'ContractFixture']);
        config(['csms.graphql_url' => 'https://other.example.test/v1/graphql']);
        $this->postJson($f['path'].'/'.$id.'/register')->assertConflict();
        $this->assertDatabaseHas('charger_source_registrations', ['id' => $id, 'state' => 'prepared']);
        Http::assertNothingSent();
    }

    public static function sourceDriftCases(): array
    {
        $cases = [];
        foreach (['1.6', '2.0.1'] as $protocol) {
            foreach (['lookup_url', 'mutation_url', 'lookup_disabled', 'readback_disabled', 'lookup_key', 'mutation_key', 'readback_key'] as $drift) {
                $cases[] = [$protocol, $drift];
            }
        }

        return $cases;
    }

    #[DataProvider('sourceDriftCases')]
    public function test_inflight_source_generation_or_adapter_revocation_cannot_fabricate_registration(string $protocol, string $drift): void
    {
        $f = $this->fixture($protocol);
        $id = $this->prepare($f);
        $original = config('csms.graphql_url');
        $alternate = 'https://alternate.example.test/v1/graphql';
        $calls = 0;
        Http::fake(function (Request $request) use ($drift, $original, $alternate, &$calls) {
            $calls++;
            $this->assertSame($original, $request->url());
            $this->assertTrue($request->hasHeader('x-hasura-admin-secret', str_repeat('s', 64)));
            if (str_contains($request['query'], 'insert_ChargingStations_one')) {
                $this->writes++;
                $this->remote = $request['variables']['object'] + ['id' => 401];
                if ($drift === 'mutation_url') {
                    config(['csms.graphql_url' => $alternate]);
                } elseif ($drift === 'mutation_key') {
                    config(['csms.graphql_secret' => str_repeat('t', 64)]);
                }

                return Http::response(['data' => ['insert_ChargingStations_one' => $this->remote]]);
            }
            if ($calls === 1) {
                if ($drift === 'lookup_url') {
                    config(['csms.graphql_url' => $alternate]);
                } elseif ($drift === 'lookup_key') {
                    config(['csms.graphql_secret' => str_repeat('t', 64)]);
                } elseif ($drift === 'lookup_disabled') {
                    config(['csms.upstream_registration_enabled' => false]);
                }
            } elseif ($drift === 'readback_disabled') {
                config(['csms.upstream_registration_enabled' => false]);
            } elseif ($drift === 'readback_key') {
                config(['csms.graphql_secret' => str_repeat('t', 64)]);
            }

            return Http::response(['data' => ['ChargingStations' => $this->remote ? [$this->remote] : []]]);
        });
        $response = $this->postJson($f['path'].'/'.$id.'/register');
        $this->assertContains($response->status(), [409, 503]);
        $expectedCalls = match ($drift) {
            'lookup_url', 'lookup_disabled', 'lookup_key' => 1,
            'mutation_url', 'mutation_key' => 2,
            'readback_disabled', 'readback_key' => 3,
        };
        $this->assertSame($expectedCalls, $calls);
        $this->assertSame($expectedCalls > 1 ? 1 : 0, $this->writes);
        $this->assertDatabaseHas('charger_source_registrations', [
            'id' => $id, 'state' => 'requires_inspection', 'source_station_id' => null, 'verified_at' => null,
        ]);
        config(['csms.graphql_url' => $original, 'csms.upstream_registration_enabled' => true, 'csms.graphql_secret' => str_repeat('s', 64)]);
        $this->postJson($f['path'].'/'.$id.'/register')->assertOk()->assertJsonPath('state', 'requires_inspection');
        $this->assertSame($expectedCalls, $calls);
        Http::assertNotSent(fn (Request $request) => $request->url() === $alternate);
        $this->assertFalse(AdmissionProfile::firstOrFail()->enabled);
        $this->assertDatabaseCount('charger_commands', 0);
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    public function test_dispatching_crash_receipt_is_inspectable_and_never_resent(): void
    {
        $f = $this->fixture();
        $this->source();
        $id = $this->prepare($f);
        DB::table('charger_source_registrations')->where('id', $id)->update(['state' => 'registering', 'attempted_at' => now()]);
        $this->postJson($f['path'].'/'.$id.'/register')->assertOk()->assertJsonPath('state', 'registering');
        Http::assertNothingSent();
    }

    public function test_current_mfa_is_required_before_any_registration_metadata(): void
    {
        $f = $this->fixture();
        config(['admin_security.enforce_in_local_tests' => true]);
        $this->actingAs($f['admin'])->postJson($f['path'], ['identity' => 'CB-OWNED-001', 'idempotency_key' => 'x'])->assertForbidden();
        $this->assertDatabaseCount('charger_source_registrations', 0);
        $this->assertDatabaseCount('csms_bindings', 0);
    }

    public function test_direct_inventory_can_be_created_selected_and_linked_by_existing_vendor_api(): void
    {
        $f = $this->fixture();
        $this->source();
        $this->actingAs($f['owner']);
        $created = $this->postJson('/api/stations', ['name' => 'New direct source', 'country' => 'AE', 'currency' => 'AED', 'timezone' => 'Asia/Dubai', 'latitude' => 25, 'longitude' => 55, 'source' => 'direct_unverified', 'connector_type' => 'CCS2', 'power_kw' => 50])->assertCreated()->assertJsonPath('source', 'direct_unverified')->json('id');
        $this->getJson('/api/vendor-ops/companies/'.$f['company']->id.'/catalog-stations?q=New%20direct%20source')->assertOk()->assertJsonPath('data.0.id', $created);
        $site = $this->postJson('/api/vendor-ops/companies/'.$f['company']->id.'/sites', ['name' => 'Linked direct site', 'country' => 'AE', 'timezone' => 'Asia/Dubai', 'address' => 'Synthetic', 'latitude' => 25, 'longitude' => 55, 'station_id' => $created, 'idempotency_key' => (string) Str::uuid()])->assertCreated()->json('id');
        $this->postJson('/api/vendor-ops/companies/'.$f['company']->id.'/sites/'.$site.'/chargers', ['asset_reference' => 'DIRECT-API', 'manufacturer' => 'Synthetic', 'model' => 'Fixture', 'source' => 'direct_unverified', 'ocpp_protocol' => '2.0.1', 'idempotency_key' => (string) Str::uuid()])->assertCreated()->assertJsonPath('remote_control_enabled', false);
        $this->assertDatabaseCount('charger_commands', 0);
        $this->assertDatabaseCount('finance_journals', 0);
        Http::assertNothingSent();
    }

    public function test_populated_registration_provenance_refuses_destructive_downgrade(): void
    {
        $f = $this->fixture();
        $id = $this->prepare($f);
        $migration = require database_path('migrations/2026_11_21_000001_create_charger_source_registrations.php');
        try {
            $migration->down();
            $this->fail('Populated source registration provenance dropped.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('Retain upstream registration provenance', $error->getMessage());
        }
        $this->assertDatabaseHas('charger_source_registrations', ['id' => $id, 'state' => 'prepared']);
        $this->assertDatabaseCount('csms_bindings', 1);
    }

    public function test_registration_route_ids_reject_overflow_before_a_typed_action_or_source_request(): void
    {
        $f = $this->fixture();
        $this->source();
        $id = $this->prepare($f);
        foreach ([str_repeat('9', 19), '1e300', '-1', '1.0', 'text'] as $invalid) {
            $this->getJson('/api/vendor-ops/companies/'.$invalid.'/chargers/'.$f['charger']->id.'/source-registration')->assertNotFound();
            $this->getJson('/api/vendor-ops/companies/'.$f['company']->id.'/chargers/'.$invalid.'/source-registration')->assertNotFound();
            $this->postJson($f['path'].'/'.$invalid.'/register')->assertNotFound();
        }
        $this->assertDatabaseHas('charger_source_registrations', ['id' => $id, 'state' => 'prepared']);
        Http::assertNothingSent();
    }
}
